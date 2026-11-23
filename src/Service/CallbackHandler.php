<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Service;

use Cmi\PrestaShop\Config\Settings;
use Cmi\PrestaShop\Model\CmiTransaction;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Protocol\CmiProtocol;
use Cmi\PrestaShop\Repository\TransactionRepository as Repo;
use Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Processes the payment result CMI sends: server to server (callbackUrl) and through the
 * customer's browser (okUrl / failUrl). Both are signed with the store key.
 */
class CallbackHandler
{
    /** Answers CMI understands. */
    public const ANSWER_POSTAUTH = 'ACTION=POSTAUTH';
    public const ANSWER_APPROVED = 'APPROVED';
    public const ANSWER_FAILURE = 'FAILURE';

    /** @var \PaymentModule */
    private $module;
    /** @var Settings */
    private $settings;
    /** @var Repo */
    private $repository;
    /** @var OrderUpdater */
    private $orders;
    /** @var TransactionManager */
    private $manager;
    /** @var LegacyTransactions */
    private $legacy;
    /** @var Texts */
    private $texts;

    public function __construct(\PaymentModule $module, Settings $settings, Repo $repository, OrderUpdater $orders, TransactionManager $manager, LegacyTransactions $legacy, Texts $texts)
    {
        $this->module = $module;
        $this->settings = $settings;
        $this->repository = $repository;
        $this->orders = $orders;
        $this->manager = $manager;
        $this->legacy = $legacy;
        $this->texts = $texts;
    }

    /**
     * @param array<string, string> $fields the POST fields CMI sent
     * @param bool $isServer server callback (true) or browser return (false)
     *
     * @return array{transaction: CmiTransaction|null, answer: string, valid: bool}
     */
    public function handle(array $fields, bool $isServer, int $idShop): array
    {
        $oid = CmiProtocol::sanitizeOid((string) ($fields['oid'] ?? $fields['ReturnOid'] ?? ''));
        if (!CmiProtocol::isValidCallback($fields, $this->settings->storeKey())) {
            \PrestaShopLogger::addLog(sprintf('CMI %s rejected: invalid signature (OrderId %s, IP %s).', $isServer ? 'callback' : 'browser return', $oid, (string) \Tools::getRemoteAddr()), 2, null, null, null, true);

            return ['transaction' => null, 'answer' => self::ANSWER_FAILURE, 'valid' => false];
        }

        $transaction = $oid !== '' ? $this->repository->findByOid($oid, $idShop) : null;
        if (!$isServer && !$this->returnUpdatesPayment()) {
            // With the server callback, the customer's return only shows the result: CMI's
            // callback (or the automatic checks) is the only source that updates the payment.
            return ['transaction' => $transaction, 'answer' => self::ANSWER_APPROVED, 'valid' => $transaction !== null];
        }
        if (!$transaction && ctype_digit($oid)) {
            $transaction = $this->legacy->forInFlightCart((int) $oid, $fields);
        }
        if (!$transaction) {
            \PrestaShopLogger::addLog('CMI callback for an unknown OrderId: ' . $oid, 2, null, null, null, true);

            return ['transaction' => null, 'answer' => self::ANSWER_FAILURE, 'valid' => false];
        }

        // A callback and the browser return often arrive together: process them one at a time.
        $locked = $this->repository->acquireLockWaiting($transaction, $isServer ? 15 : 5);
        if (!$locked) {
            if (!$isServer) {
                // The server callback is processing it: the result page shows its outcome.
                return ['transaction' => $this->repository->refresh($transaction), 'answer' => self::ANSWER_APPROVED, 'valid' => true];
            }
            \PrestaShopLogger::addLog('CMI callback processed while the payment was held by another request: OrderId ' . $oid, 2, null, 'Cart', (int) $transaction->id_cart, true);
        }
        try {
            $transaction = $this->repository->refresh($transaction);
            $answer = $this->process($transaction, $fields, $isServer);
        } finally {
            if ($locked) {
                $this->repository->releaseLock($transaction);
            }
        }

        return ['transaction' => $transaction, 'answer' => $answer, 'valid' => true];
    }

    private function process(CmiTransaction $transaction, array $fields, bool $isServer): string
    {
        $approved = (string) ($fields['ProcReturnCode'] ?? '') === '00';
        $current = (string) $transaction->payment_state;
        $source = $isServer ? $this->t('CMI callback') : $this->t('Customer return from CMI');

        if ($approved && (int) $transaction->id_order === 0 && !$this->createInFlightOrder($transaction)) {
            return self::ANSWER_FAILURE;
        }
        if ($current === PaymentState::CONFIRMED || ((int) $transaction->id_order && $this->orders->isPaid($transaction) && $current !== PaymentState::PRE_CONFIRMATION_REQUIRED)) {
            return self::ANSWER_APPROVED;
        }
        if (!$isServer && ($current === PaymentState::PRE_CONFIRMATION_REQUIRED || ($current === PaymentState::FAILED && !$approved))) {
            // The server callback already recorded this result.
            return self::ANSWER_APPROVED;
        }
        if (!PaymentState::acceptsCallback($current, $approved)) {
            // Late or repeated message (e.g. an earlier declined attempt): never downgrade the payment.
            $this->repository->log($transaction, Repo::LOG_INFO, $this->t('%source% ignored: it reports %result% (ProcReturnCode %code%) but the payment is already %state%.', [
                '%source%' => $source,
                '%result%' => $approved ? $this->t('an approval') : $this->t('a failure'),
                '%code%' => (string) ($fields['ProcReturnCode'] ?? ''),
                '%state%' => $current,
            ]));

            return self::ANSWER_APPROVED;
        }

        if (!$approved) {
            if ((int) $transaction->id_order === 0) {
                // A declined payment started with version 1.x: there is no order to update.
                return self::ANSWER_APPROVED;
            }
            $this->setState($transaction, PaymentState::FAILED, $fields);
            $this->orders->setStateIfIn($transaction, (int) \Configuration::get('PS_OS_ERROR'), [$this->settings->awaitingStateId()]);
            $this->repository->log($transaction, Repo::LOG_WARNING, $this->t('%source%: payment declined (%error%, ProcReturnCode %code%).', [
                '%source%' => $source,
                '%error%' => trim((string) ($fields['ErrMsg'] ?? '')) !== '' ? (string) $fields['ErrMsg'] : $this->t('declined'),
                '%code%' => (string) ($fields['ProcReturnCode'] ?? ''),
            ]));

            return self::ANSWER_APPROVED;
        }

        $currency = trim((string) ($fields['currency'] ?? ''));
        if ($currency !== (string) $transaction->currency_num) {
            // Signed by CMI, so this should never happen: don't let CMI capture it.
            $this->repository->log($transaction, Repo::LOG_ERROR, $this->t('%source%: CMI approved the payment in currency %currency%, not in the currency sent (%sent%). The payment was not accepted; check it in the CMI back office.', [
                '%source%' => $source,
                '%currency%' => $currency !== '' ? $currency : '?',
                '%sent%' => (string) $transaction->currency_num,
            ]));

            return self::ANSWER_FAILURE;
        }
        $amount = round((float) str_replace(',', '.', (string) ($fields['amount'] ?? '0')), 2);
        if (!CmiProtocol::amountMatches($amount, (float) $transaction->amount_charged, (bool) $transaction->legacy)) {
            // Signed by CMI, so this should never happen: don't let CMI capture it.
            $this->repository->log($transaction, Repo::LOG_ERROR, $this->t('%source%: the amount approved by CMI (%amount%) is not the amount sent (%sent%). The payment was not accepted; check it in the CMI back office.', [
                '%source%' => $source,
                '%amount%' => CmiProtocol::formatAmount($amount),
                '%sent%' => CmiProtocol::formatAmount((float) $transaction->amount_charged),
            ]));

            return self::ANSWER_FAILURE;
        }

        $transaction->addOwnIds($fields);
        if ($current === PaymentState::CLIENT_CANCELLED) {
            $this->repository->log($transaction, Repo::LOG_WARNING, $this->t('A payment was approved after the customer cancelled the order: the order was reopened. Check the stock of its products.'));
        }

        // Automatic confirmation, server callback: CMI captures the payment itself on the answer ACTION=POSTAUTH.
        if ($isServer && $this->capturesOnCallback()) {
            $this->setState($transaction, PaymentState::CONFIRMED, $fields);
            $this->manager->markPaid($transaction, $fields);
            $this->repository->log($transaction, Repo::LOG_INFO, $this->t('CMI callback: payment approved and captured (TransId: %transid%).', ['%transid%' => (string) ($fields['TransId'] ?? '')]));

            return self::ANSWER_POSTAUTH;
        }

        // Otherwise the payment is pre-authorized for now.
        $transaction->preauth_at = time();
        $this->setState($transaction, PaymentState::PRE_CONFIRMATION_REQUIRED, $fields);
        if (!$this->orders->isPaid($transaction)) {
            // This state sends no e-mail, so a browser return arriving before the callback
            // never sends the customer a confusing intermediate e-mail.
            $this->orders->setState($transaction, $this->settings->preauthorizedStateId());
        }

        if ($this->settings->confirmationMode() === Settings::MODE_AUTOMATIC && !$isServer
            && $this->settings->callbackMode() === Settings::CALLBACK_BROWSER) {
            // CMI does not call this shop: capture through the API, as version 1.x did.
            if ($this->settings->isApiEnabled()) {
                $result = $this->manager->capture($transaction, true);
                if (!$result->success) {
                    $this->repository->log($transaction, Repo::LOG_ERROR, $this->t('Automatic capture failed: %error% Confirm the payment from this page or the CMI back office.', ['%error%' => $result->message]));
                }
            } else {
                $this->repository->log($transaction, Repo::LOG_WARNING, $this->t('Payment pre-authorized. Without the Complete Kit it cannot be captured automatically in "browser return" mode: confirm it in the CMI back office.'));
            }

            return self::ANSWER_APPROVED;
        }

        $this->repository->log($transaction, Repo::LOG_INFO, $this->settings->confirmationMode() === Settings::MODE_MANUAL
            ? $this->t('%source%: payment pre-authorized (TransId: %transid%). Confirm it to capture the funds.', ['%source%' => $source, '%transid%' => (string) ($fields['TransId'] ?? '')])
            : $this->t('Customer return from CMI: payment approved (TransId: %transid%), waiting for CMI\'s callback to complete it.', ['%transid%' => (string) ($fields['TransId'] ?? '')]));

        return self::ANSWER_APPROVED;
    }

    /** Whether the server callback answer makes CMI capture the payment. */
    /**
     * Whether the customer's return from CMI may update the payment: only in "Browser return
     * only" mode, where CMI does not call the shop and the return is the only signed result.
     */
    public function returnUpdatesPayment(): bool
    {
        return $this->settings->callbackMode() === Settings::CALLBACK_BROWSER;
    }

    private function capturesOnCallback(): bool
    {
        return $this->settings->confirmationMode() === Settings::MODE_AUTOMATIC
            && $this->settings->callbackMode() === Settings::CALLBACK_SERVER;
    }

    /** Payment started with version 1.x: its order is created now, as 1.x did. */
    private function createInFlightOrder(CmiTransaction $transaction): bool
    {
        $existing = \Order::getByCartId((int) $transaction->id_cart);
        if (!$existing || !$existing->id) {
            $cart = new \Cart((int) $transaction->id_cart);
            $customer = new \Customer((int) $cart->id_customer);
            try {
                // The order total checked against the amount CMI approved (see process()).
                $this->module->validateOrder(
                    (int) $cart->id,
                    $this->settings->awaitingStateId(),
                    (float) $transaction->amount_order,
                    $this->module->displayName,
                    null,
                    [],
                    (int) $cart->id_currency,
                    false,
                    $customer->secure_key
                );
            } catch (\Exception $e) {
                \PrestaShopLogger::addLog('CMI could not create the order of cart ' . (int) $cart->id . ': ' . $e->getMessage(), 3, null, 'Cart', (int) $cart->id, true);

                return false;
            }
            $existing = \Order::getByCartId((int) $cart->id);
        }
        if (!$existing || !$existing->id) {
            return false;
        }
        $transaction->id_order = (int) $existing->id;
        $transaction->order_reference = (string) $existing->reference;
        $this->repository->save($transaction);

        return true;
    }

    private function setState(CmiTransaction $transaction, string $state, array $fields): void
    {
        $transaction->payment_state = $state;
        $transaction->setLastResponse($fields);
        $transaction->next_check_at = 0;
        $this->repository->save($transaction);
    }

    private function t(string $text, array $parameters = []): string
    {
        return $this->texts->admin($text, $parameters);
    }
}
