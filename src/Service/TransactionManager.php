<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Service;

use Cmi\PrestaShop\Api\ApiClient;
use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Model\CmiTransaction;
use Cmi\PrestaShop\Payment\Amounts;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Payment\TransactionGuard;
use Cmi\PrestaShop\Protocol\ApiException;
use Cmi\PrestaShop\Protocol\CmiProtocol;
use Cmi\PrestaShop\Repository\TransactionRepository as Repo;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Merchant operations on a CMI payment (Complete Kit): Confirm, Void, Refund and Check CMI,
 * and bringing an order in line with CMI's records.
 *
 * Every operation first checks, read-only, that the CMI transaction behind the OrderId is
 * this order's own payment, and that CMI's current state allows it.
 */
class TransactionManager
{
    /** @var Settings */
    private $settings;
    /** @var Repo */
    private $repository;
    /** @var OrderUpdater */
    private $orders;
    /** @var ApiClient */
    private $api;
    /** @var Texts */
    private $texts;

    public function __construct(Settings $settings, Repo $repository, OrderUpdater $orders, ApiClient $api, Texts $texts)
    {
        $this->settings = $settings;
        $this->repository = $repository;
        $this->orders = $orders;
        $this->api = $api;
        $this->texts = $texts;
    }

    // ── Merchant actions ─────────────────────────────────────────────────────

    /** Capture a pre-authorization (PostAuth). */
    public function confirm(CmiTransaction $transaction): ActionResult
    {
        if ((string) $transaction->payment_state === PaymentState::CONFIRMED || $this->orders->isPaid($transaction)) {
            return ActionResult::ok($this->t('This payment is already confirmed.'), 'already_confirmed');
        }
        if (!PaymentState::canConfirm((string) $transaction->payment_state)) {
            return ActionResult::error($this->t('Only a pre-authorized CMI payment can be confirmed.'), 'not_preauthorized');
        }

        return $this->locked($transaction, function () use ($transaction): ActionResult {
            return $this->capture($transaction, false);
        });
    }

    /** Cancel a pre-authorization (Void). */
    public function void(CmiTransaction $transaction): ActionResult
    {
        if (in_array((string) $transaction->payment_state, [PaymentState::VOIDED, PaymentState::CLIENT_CANCELLED], true)) {
            return ActionResult::ok($this->t('This payment is already cancelled.'), 'already_cancelled');
        }
        if (!PaymentState::canConfirm((string) $transaction->payment_state)) {
            return ActionResult::error($this->t('Only a pre-authorized CMI payment can be voided. A confirmed payment must be refunded.'), 'not_preauthorized');
        }

        return $this->locked($transaction, function () use ($transaction): ActionResult {
            $guard = $this->guard($transaction, 'void');
            if ($guard !== null) {
                return $guard;
            }
            $answer = $this->call('Void', $transaction);
            if ($answer instanceof ActionResult) {
                return $answer;
            }
            if (($answer['Response'] ?? '') !== 'Approved') {
                return $this->rejected($transaction, $answer, $this->t('CMI refused to void the payment.'));
            }
            $transaction->addOwnIds($answer);
            $this->setPaymentState($transaction, PaymentState::VOIDED, $answer);
            $this->orders->setState($transaction, (int) \Configuration::get('PS_OS_CANCELED'));
            $message = $this->t('Pre-authorization voided on CMI (TransId: %transid%).', ['%transid%' => $this->na($answer['TransId'] ?? '')]);
            $this->repository->log($transaction, Repo::LOG_INFO, $message);

            return ActionResult::ok($message);
        });
    }

    /**
     * Refund a captured payment (Credit), fully or partly.
     *
     * @param float|null $amount amount in the charged currency (null: the whole remaining balance)
     */
    public function refund(CmiTransaction $transaction, ?float $amount, string $reason = ''): ActionResult
    {
        if (!PaymentState::canRefund((string) $transaction->payment_state)) {
            return ActionResult::error($this->t('Only a confirmed CMI payment can be refunded. A pre-authorization must be voided.'), 'not_captured');
        }
        if (!$transaction->hasKnownAmount()) {
            return ActionResult::error($this->t('The amount charged by CMI was not recorded for this older order, so the refund cannot be calculated. Please refund it from the CMI back office.'), 'amount_unknown');
        }
        $balance = $transaction->balance();
        $refund = $amount ?? $balance['remaining'];
        if ($refund <= 0) {
            return ActionResult::error($this->t('Invalid refund amount.'), 'invalid_amount');
        }
        if ($refund > $balance['remaining'] + 0.001) {
            return ActionResult::error($this->t('The refund (%amount% %currency%) is more than the remaining balance (%remaining% %currency%).', [
                '%amount%' => CmiProtocol::formatAmount($refund),
                '%remaining%' => CmiProtocol::formatAmount($balance['remaining']),
                '%currency%' => $transaction->currency_iso,
            ]), 'amount_exceeded');
        }

        return $this->locked($transaction, function () use ($transaction, $refund, $balance, $reason): ActionResult {
            $guard = $this->guard($transaction, 'refund');
            if ($guard !== null) {
                return $guard;
            }
            // A refund made in the CMI back office is not recorded here yet: the balance would be wrong.
            $cmiRefunded = $this->refundedOnCmi($transaction);
            if ($cmiRefunded !== null && $cmiRefunded > $balance['refunded'] + 0.009) {
                return ActionResult::error($this->t('CMI shows %cmi% %currency% already refunded, but only %recorded% %currency% is recorded here: a refund was probably made in the CMI back office. Click Check CMI to update the order, then refund the remaining balance.', [
                    '%cmi%' => CmiProtocol::formatAmount($cmiRefunded),
                    '%recorded%' => CmiProtocol::formatAmount($balance['refunded']),
                    '%currency%' => $transaction->currency_iso,
                ]), 'out_of_sync');
            }
            $remainingOrder = max(0.0, (float) $transaction->amount_order - Amounts::fromCharged($balance['refunded'], (float) $transaction->rate));
            $split = Amounts::splitRefund($refund, $balance['remaining'], $remainingOrder, (float) $transaction->rate);

            $answer = $this->call('Credit', $transaction, CmiProtocol::formatAmount($split['charged']));
            if ($answer instanceof ActionResult) {
                return $answer;
            }
            if (($answer['Response'] ?? '') !== 'Approved') {
                return $this->rejected($transaction, $answer, $this->t('CMI refused the refund.'));
            }
            $transaction->addOwnIds($answer);
            $transaction->refunded = round((float) $transaction->refunded + $split['charged'], 2);
            $after = $transaction->balance();
            $full = $split['full'] || $after['remaining'] <= 0.001;
            $this->setPaymentState($transaction, $full ? PaymentState::REFUNDED : PaymentState::CONFIRMED, $answer);
            if ($full) {
                $this->orders->setState($transaction, (int) \Configuration::get('PS_OS_REFUND'));
            }
            $message = $this->t('Refund of %amount% %currency%%order_amount% made on CMI (TransId: %transid%). Refunded in total: %refunded% of %total% %currency%.%reason%', [
                '%amount%' => CmiProtocol::formatAmount($split['charged']),
                '%currency%' => $transaction->currency_iso,
                '%order_amount%' => $transaction->isConverted() ? sprintf(' (%s %s)', CmiProtocol::formatAmount($split['order']), $transaction->order_currency) : '',
                '%transid%' => $this->na($answer['TransId'] ?? ''),
                '%refunded%' => CmiProtocol::formatAmount($after['refunded']),
                '%total%' => CmiProtocol::formatAmount($after['total']),
                '%reason%' => $reason !== '' ? ' ' . $this->t('Reason: %reason%', ['%reason%' => $reason]) : '',
            ]);
            $this->repository->log($transaction, Repo::LOG_INFO, $message);

            return ActionResult::ok($message);
        });
    }

    /** Check CMI: read the payment's state at CMI and bring the orders in line. */
    public function check(CmiTransaction $transaction): ActionResult
    {
        return $this->locked($transaction, function () use ($transaction): ActionResult {
            $result = $this->sync($transaction);

            return new ActionResult($result['status'] !== 'ERROR', $result['message'], $result['status']);
        });
    }

    /**
     * Days since the pre-authorization, according to CMI, when the merchant should inform the
     * customer before confirming (0 otherwise). Advisory only.
     */
    public function preauthWarningDays(CmiTransaction $transaction): int
    {
        $preauthAt = (int) $transaction->preauth_at;
        if ($this->settings->isApiEnabled()) {
            try {
                $status = $this->api->request('ORDERSTATUS', $transaction);
                if ($status['TRANS_STAT'] === 'A') {
                    $preauthAt = (int) TransactionGuard::parseCmiDatetime((string) $status['AUTH_DTTM']) ?: $preauthAt;
                }
            } catch (ApiException $e) {
                // Advisory only: confirm() runs its own checks.
            }
        }

        return PaymentState::preauthWarningDays($preauthAt, time());
    }

    // ── Shared with the callback handler and the automatic checks ─────────────

    /**
     * Capture through the API (PostAuth), the caller holding the transaction's lock. Also used
     * when CMI only returns through the browser in automatic confirmation mode.
     */
    public function capture(CmiTransaction $transaction, bool $automatic): ActionResult
    {
        $guard = $this->guard($transaction, 'confirm');
        if ($guard !== null) {
            return $guard;
        }
        $answer = $this->call('PostAuth', $transaction);
        if ($answer instanceof ActionResult) {
            return $answer;
        }
        if (($answer['Response'] ?? '') !== 'Approved') {
            return $this->rejected($transaction, $answer, $this->t('CMI refused to confirm the payment.'));
        }
        $transaction->addOwnIds($answer);
        $this->setPaymentState($transaction, PaymentState::CONFIRMED, $answer);
        $this->markPaid($transaction, $answer);
        $message = $automatic
            ? $this->t('Payment captured through the CMI API (automatic confirmation, TransId: %transid%).', ['%transid%' => $this->na($answer['TransId'] ?? '')])
            : $this->t('Pre-authorization confirmed and captured on CMI (TransId: %transid%).', ['%transid%' => $this->na($answer['TransId'] ?? '')]);
        $this->repository->log($transaction, Repo::LOG_INFO, $message);

        return ActionResult::ok($message);
    }

    /** Move the orders to the "captured" state chosen in the settings, unless already paid. */
    public function markPaid(CmiTransaction $transaction, array $answer): void
    {
        if (!$this->orders->isPaid($transaction)) {
            $this->orders->setState($transaction, $this->settings->capturedStateId());
        }
        $this->orders->recordPaymentDetails($transaction, array_merge($transaction->getLastResponse(), array_filter($answer, 'is_scalar')));
    }

    /** @throws ApiException */
    public function queryStatus(CmiTransaction $transaction): array
    {
        return $this->api->request('ORDERSTATUS', $transaction);
    }

    /**
     * Bring the orders in line with CMI's state of the payment (the caller holds the lock).
     *
     * @param array|null $known ORDERSTATUS answer already fetched
     *
     * @return array{status: string, message: string}
     *                                                status: VOIDED, REFUNDED, PARTIALLY_REFUNDED, PRE_AUTHORIZED, APPROVED, DECLINED,
     *                                                PENDING_BANK, FIRST_COMMIT, REVERSAL, NOT_FOUND, FOREIGN, UNKNOWN or ERROR
     */
    public function sync(CmiTransaction $transaction, ?array $known = null): array
    {
        if (!$this->settings->isApiEnabled()) {
            return ['status' => 'ERROR', 'message' => $this->t('The CMI API is not configured (Complete Kit with API URL, username and password).')];
        }
        try {
            $status = $known ?? $this->api->request('ORDERSTATUS', $transaction);
        } catch (ApiException $e) {
            return ['status' => 'ERROR', 'message' => $this->t('Could not check the payment with CMI: %error%', ['%error%' => $e->getMessage()])];
        }

        $verified = $this->verify($transaction, $status);
        if ($verified instanceof ActionResult) {
            if ($verified->code === 'not_found') {
                $this->repository->log($transaction, Repo::LOG_INFO, $this->t('Check CMI: no transaction found on CMI for this order (the customer did not pay).'));

                return ['status' => 'NOT_FOUND', 'message' => $this->t('No transaction found on CMI: the customer did not pay.')];
            }

            return ['status' => $verified->code === 'verification_failed' ? 'ERROR' : 'FOREIGN', 'message' => $verified->message];
        }
        $history = $verified;

        $transStat = (string) $status['TRANS_STAT'];
        $chargeType = (string) $status['CHARGE_TYPE_CD'];
        $procCode = trim((string) $status['ProcReturnCode']);
        $transId = $this->na((string) $status['TransId']);
        $authCode = $this->na((string) $status['AuthCode']);
        $transaction->trans_stat = substr($transStat, 0, 8);
        $states = [
            'canceled' => (int) \Configuration::get('PS_OS_CANCELED'),
            'refund' => (int) \Configuration::get('PS_OS_REFUND'),
            'error' => (int) \Configuration::get('PS_OS_ERROR'),
            'awaiting' => $this->settings->awaitingStateId(),
            'preauth' => $this->settings->preauthorizedStateId(),
        ];

        // Voided on CMI.
        if ($transStat === 'V') {
            $this->setPaymentState($transaction, PaymentState::VOIDED, $status);
            $this->orders->setState($transaction, $states['canceled']);

            return $this->synced($transaction, 'VOIDED', $this->t('Check CMI: the payment is voided on CMI (TransId: %transid%).', ['%transid%' => $transId]));
        }

        // Refunded (the last transaction is a Credit).
        if ($chargeType === 'C' && PaymentState::isCapturedTransStat($transStat)) {
            if (!$history) {
                $history = $this->history($transaction);
            }
            $totals = TransactionGuard::historyTotals($history);
            $this->recordBackOfficeRefunds($transaction, $totals);
            $partial = $totals['captured'] > 0
                ? $totals['refunded'] < $totals['captured']
                : $transaction->balance()['remaining'] > 0.001;
            if ($partial) {
                $this->setPaymentState($transaction, PaymentState::CONFIRMED, $status);
                $balance = $transaction->balance();

                return $this->synced($transaction, 'PARTIALLY_REFUNDED', $this->t('Check CMI: the payment is partly refunded on CMI (%refunded% of %total% %currency%).', [
                    '%refunded%' => CmiProtocol::formatAmount($balance['refunded']),
                    '%total%' => CmiProtocol::formatAmount($balance['total']),
                    '%currency%' => $transaction->currency_iso,
                ]));
            }
            $this->setPaymentState($transaction, PaymentState::REFUNDED, $status);
            $this->orders->setState($transaction, $states['refund']);

            return $this->synced($transaction, 'REFUNDED', $this->t('Check CMI: the payment is fully refunded on CMI (TransId: %transid%).', ['%transid%' => $transId]));
        }

        // Pre-authorized, waiting for confirmation.
        if ($transStat === 'A') {
            $this->setPaymentState($transaction, PaymentState::PRE_CONFIRMATION_REQUIRED, $status);
            $authTime = TransactionGuard::parseCmiDatetime((string) $status['AUTH_DTTM']);
            if ($authTime) {
                $transaction->preauth_at = $authTime;
                $this->repository->save($transaction);
            }
            $this->orders->setStateIfIn($transaction, $states['preauth'], [$states['awaiting'], $states['error']]);

            return $this->synced($transaction, 'PRE_AUTHORIZED', $this->t('Check CMI: the payment is pre-authorized on CMI (AuthCode: %authcode%, TransId: %transid%). Confirm it to capture the funds; do not ship before.', ['%authcode%' => $authCode, '%transid%' => $transId]));
        }

        // Captured or settled.
        if (PaymentState::isCapturedTransStat($transStat)) {
            $this->setPaymentState($transaction, PaymentState::CONFIRMED, $status);
            if (!$history) {
                $history = $this->history($transaction);
            }
            $this->recordBackOfficeRefunds($transaction, TransactionGuard::historyTotals($history));
            $this->markPaid($transaction, $status);
            $label = $transStat === 'S' ? $this->t('settled') : $this->t('captured');

            return $this->synced($transaction, 'APPROVED', $this->t('Check CMI: the payment is %label% on CMI (AuthCode: %authcode%, TransId: %transid%). The order can be shipped.', ['%label%' => $label, '%authcode%' => $authCode, '%transid%' => $transId]));
        }

        // The query worked but CMI did not report the state: change nothing.
        if ($transStat === '' && ($procCode === '00' || strtolower((string) $status['Response']) === 'approved')) {
            return $this->synced($transaction, 'UNKNOWN', $this->t('Check CMI: CMI did not report the payment state. Nothing was changed; please check the CMI back office.'));
        }

        // Declined.
        if ($transStat === 'D' || ($procCode !== '' && $procCode !== '00')) {
            if (PaymentState::isAwaitingPayment((string) $transaction->payment_state)) {
                $this->setPaymentState($transaction, PaymentState::FAILED, $status);
                $this->orders->setStateIfIn($transaction, $states['error'], [$states['awaiting']]);
            }

            return $this->synced($transaction, 'DECLINED', $this->t('Check CMI: the payment was declined (ProcReturnCode: %code%, %error%).', [
                '%code%' => $procCode,
                '%error%' => (string) $status['ErrMsg'] !== '' ? (string) $status['ErrMsg'] : $this->t('declined'),
            ]));
        }

        $transient = [
            'PN' => ['PENDING_BANK', $this->t('Check CMI: the payment waits for the bank\'s confirmation (PN).')],
            'NW' => ['FIRST_COMMIT', $this->t('Check CMI: the payment is in progress on CMI (NW).')],
            'R' => ['REVERSAL', $this->t('Check CMI: CMI reports a reversal is required (R).')],
        ];
        if (isset($transient[$transStat])) {
            return $this->synced($transaction, $transient[$transStat][0], $transient[$transStat][1]);
        }

        return $this->synced($transaction, 'UNKNOWN', $this->t('Check CMI: unexpected answer from CMI (%answer%).', ['%answer%' => (string) $status['Response']]));
    }

    // ── Ownership and state guard ────────────────────────────────────────────

    /**
     * Read-only check that the CMI transaction behind the OrderId exists and is this order's
     * own payment, run before any PostAuth, Void, Credit and before any status sync.
     *
     * @return string[]|ActionResult the ORDERHISTORY lines read (possibly none), or why not
     */
    private function verify(CmiTransaction $transaction, array $status)
    {
        if (TransactionGuard::isNotFound($status)) {
            return ActionResult::error($this->t('OrderId %oid% was not found in the CMI back office. Nothing was sent to CMI.', ['%oid%' => $transaction->oid]), 'not_found');
        }
        $ours = $transaction->ownershipData();
        // ORDERSTATUS only shows the last transaction (e.g. the PostAuth or a refund): our
        // identifiers may only appear in the full history.
        $history = [];
        $verdict = TransactionGuard::ownership($status, $history, $ours);
        if ($verdict === TransactionGuard::FOREIGN) {
            try {
                $history = $this->api->request('ORDERHISTORY', $transaction)['HISTORY'];
            } catch (ApiException $e) {
                return ActionResult::error($this->t('Could not verify the payment with CMI, so nothing was sent: %error%', ['%error%' => $e->getMessage()]), 'verification_failed');
            }
            $verdict = TransactionGuard::ownership($status, $history, $ours);
        }
        if ($verdict !== TransactionGuard::OWNED) {
            $message = $verdict === TransactionGuard::UNVERIFIED
                ? $this->t('The CMI transaction of OrderId %oid% cannot be matched to this order (no payment confirmation or amount was recorded). Nothing was sent to CMI; please manage it from the CMI back office.', ['%oid%' => $transaction->oid])
                : $this->t('OrderId %oid% exists in the CMI back office but belongs to another payment, not to this order. Nothing was sent to CMI.', ['%oid%' => $transaction->oid]);
            \PrestaShopLogger::addLog('CMI ownership check failed for OrderId ' . $transaction->oid, 2, null, 'Cart', (int) $transaction->id_cart, true);
            $this->repository->log($transaction, Repo::LOG_WARNING, $message);

            return ActionResult::error($message, $verdict === TransactionGuard::UNVERIFIED ? 'unverified' : 'foreign');
        }
        if ($transaction->addOwnIds($status)) {
            $this->repository->save($transaction);
        }

        return $history;
    }

    /**
     * Verify ownership and CMI's state before an action; null when it may be sent.
     * When CMI's state differs (e.g. confirmed in the CMI back office), the order is brought in
     * line first, then the refusal is explained.
     */
    private function guard(CmiTransaction $transaction, string $action): ?ActionResult
    {
        try {
            $status = $this->api->request('ORDERSTATUS', $transaction);
        } catch (ApiException $e) {
            return ActionResult::error($this->t('Could not verify the payment with CMI, so nothing was sent: %error%', ['%error%' => $e->getMessage()]), 'verification_failed');
        }
        $verified = $this->verify($transaction, $status);
        if ($verified instanceof ActionResult) {
            return $verified;
        }
        $reason = TransactionGuard::actionBlockReason($action, (string) $status['TRANS_STAT'], (string) $status['CHARGE_TYPE_CD']);
        if ($reason === '') {
            return null;
        }
        $messages = [
            'not_preauthorized' => $this->t('CMI shows no active pre-authorization for this order, so it cannot be confirmed or voided.'),
            'already_captured' => $this->t('This payment is already captured on CMI. Refund it instead.'),
            'not_captured' => $this->t('This payment is not captured on CMI, so it cannot be refunded. Void the pre-authorization instead.'),
            'voided' => $this->t('This payment is already voided on CMI.'),
            'unknown_state' => $this->t('CMI did not report the payment state, so nothing was sent. Please check the order in the CMI back office.'),
        ];
        $message = $messages[$reason] ?? $reason;
        if ($reason !== 'unknown_state') {
            $before = [(string) $transaction->payment_state, $this->orders->currentStates($transaction)];
            $this->sync($transaction, $status);
            if ($before !== [(string) $transaction->payment_state, $this->orders->currentStates($transaction)]) {
                $message .= ' ' . $this->t('The order was updated to match CMI.');
            }
        }

        return ActionResult::error($message, $reason);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return string[] ORDERHISTORY lines, or none when CMI's history can't be read */
    private function history(CmiTransaction $transaction): array
    {
        try {
            return $this->api->request('ORDERHISTORY', $transaction)['HISTORY'];
        } catch (ApiException $e) {
            return [];
        }
    }

    /** Total refunded on CMI (charged currency), or null when CMI's history can't be read. */
    private function refundedOnCmi(CmiTransaction $transaction): ?float
    {
        $totals = TransactionGuard::historyTotals($this->history($transaction));

        return $totals['captured'] > 0 ? round($totals['refunded'] / 100, 2) : null;
    }

    /** Record refunds made directly in the CMI back office, so the balance here matches CMI. */
    private function recordBackOfficeRefunds(CmiTransaction $transaction, array $totals): void
    {
        if ($totals['captured'] <= 0 || !$transaction->hasKnownAmount()) {
            return;
        }
        $cmiRefunded = round(min($totals['refunded'] / 100, (float) $transaction->amount_charged), 2);
        $missing = round($cmiRefunded - (float) $transaction->refunded, 2);
        if ($missing <= 0.009) {
            return;
        }
        $transaction->refunded = $cmiRefunded;
        $this->repository->save($transaction);
        $this->repository->log($transaction, Repo::LOG_INFO, $this->t('Check CMI: a refund of %amount% %currency% made in the CMI back office was recorded.', [
            '%amount%' => CmiProtocol::formatAmount($missing),
            '%currency%' => $transaction->currency_iso,
        ]));
    }

    /** @return array<string, mixed>|ActionResult */
    private function call(string $type, CmiTransaction $transaction, ?string $total = null)
    {
        try {
            return $this->api->request($type, $transaction, $total);
        } catch (ApiException $e) {
            $message = $this->t('The CMI API could not be reached, nothing was changed: %error%', ['%error%' => $e->getMessage()]);
            $this->repository->log($transaction, Repo::LOG_ERROR, $message);

            return ActionResult::error($message, 'api_error');
        }
    }

    private function rejected(CmiTransaction $transaction, array $answer, string $fallback): ActionResult
    {
        $message = trim((string) ($answer['ErrMsg'] ?? '')) !== '' ? $fallback . ' ' . $answer['ErrMsg'] : $fallback;
        $this->repository->log($transaction, Repo::LOG_ERROR, $message);

        return ActionResult::error($message, 'rejected');
    }

    private function setPaymentState(CmiTransaction $transaction, string $state, array $answer): void
    {
        $transaction->payment_state = $state;
        $transaction->setLastResponse($answer);
        if ($state !== PaymentState::INITIATED) {
            $transaction->next_check_at = 0;
        }
        $this->repository->save($transaction);
    }

    /** @return array{status: string, message: string} */
    private function synced(CmiTransaction $transaction, string $status, string $message): array
    {
        $this->repository->save($transaction);
        $this->repository->log($transaction, Repo::LOG_INFO, $message);

        return ['status' => $status, 'message' => $message];
    }

    private function locked(CmiTransaction $transaction, callable $operation): ActionResult
    {
        if (!$this->settings->isApiEnabled()) {
            return ActionResult::error($this->t('The CMI API is not configured (Complete Kit with API URL, username and password).'), 'not_configured');
        }
        if (!$this->repository->acquireLock($transaction)) {
            return ActionResult::error($this->t('Another operation is in progress on this payment. Please try again in a moment.'), 'in_progress');
        }
        try {
            $fresh = $this->repository->refresh($transaction);
            foreach (array_keys(CmiTransaction::$definition['fields']) as $field) {
                $transaction->{$field} = $fresh->{$field};
            }

            return $operation();
        } finally {
            $this->repository->releaseLock($transaction);
        }
    }

    private function na(string $value): string
    {
        $value = trim($value);

        return $value !== '' ? $value : $this->t('n/a');
    }

    private function t(string $text, array $parameters = []): string
    {
        return $this->texts->admin($text, $parameters);
    }
}
