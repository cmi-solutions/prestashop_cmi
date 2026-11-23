<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Admin;

use Cmi\PrestaShop\Model\CmiTransaction;
use Cmi\PrestaShop\Payment\PaymentState;
use Cmi\PrestaShop\Protocol\CmiProtocol;
use Cmi\PrestaShop\Services;
use Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The "CMI payment" panel of the back-office order page: what CMI charged, the payment's
 * state, its history, and the Confirm / Void / Refund / Check CMI actions (Complete Kit).
 */
class OrderPanel
{
    public const FLASH_COOKIE = 'cmi_flash';
    private const DOMAIN = 'Modules.Cmi.Admin';

    /** @var \PaymentModule */
    private $module;
    /** @var Services */
    private $services;
    /** @var \Context */
    private $context;

    public function __construct(\PaymentModule $module, Services $services, \Context $context)
    {
        $this->module = $module;
        $this->services = $services;
        $this->context = $context;
    }

    /** @return array<string, mixed>|null template variables, null when the order was not paid with CMI */
    public function variables(\Order $order): ?array
    {
        $transaction = $this->services->repository()->findByOrder($order)
            ?? $this->services->legacy()->forOrder($order, $this->module->name);
        if (!$transaction) {
            return null;
        }
        $settings = $this->services->settings();
        $api = $settings->isApiEnabled();
        $state = (string) $transaction->payment_state;
        $balance = $transaction->balance();
        $known = $transaction->hasKnownAmount();
        $logs = $this->services->repository()->logs($transaction, 30);

        return [
            'transaction_id' => (int) $transaction->id,
            'oid' => (string) $transaction->oid,
            'state' => $state,
            'state_label' => self::stateLabel($state, $this->module),
            'state_class' => self::stateClass($state),
            'legacy' => (bool) $transaction->legacy,
            // The actions go to CMI's test platform: say so on every order.
            'test_mode' => $settings->isTestMode(),
            'account' => (string) $transaction->account,
            'client_id' => (string) $transaction->client_id,
            'currency' => (string) $transaction->currency_iso,
            'amount_known' => $known,
            'charged' => CmiProtocol::formatAmount($balance['total']),
            'refunded' => CmiProtocol::formatAmount($balance['refunded']),
            'remaining' => CmiProtocol::formatAmount($balance['remaining']),
            'converted' => $transaction->isConverted() && $known,
            'order_currency' => (string) $transaction->order_currency,
            'amount_order' => CmiProtocol::formatAmount((float) $transaction->amount_order),
            'rate' => rtrim(rtrim(number_format((float) $transaction->rate, 6, '.', ''), '0'), '.'),
            'last' => $transaction->getLastResponse(),
            'logs' => $logs,
            'log_count' => count($logs),
            'api_enabled' => $api,
            'can_confirm' => $api && PaymentState::canConfirm($state),
            'can_refund' => $api && PaymentState::canRefund($state) && $known && $balance['remaining'] > 0.001,
            'refund_in_back_office' => PaymentState::canRefund($state) && !$known,
            'action_url' => $this->context->link->getAdminLink('AdminCmiPayment', true),
            'flash' => $this->takeFlash(),
            'next_check' => (int) $transaction->next_check_at > 0 ? date('Y-m-d H:i', (int) $transaction->next_check_at) : '',
            // Questions asked by admin-order.js; %days% and %amount% are filled in there.
            'texts' => [
                'confirm' => $this->t('Confirm this payment and capture %amount% %currency%?', ['%amount%' => CmiProtocol::formatAmount($balance['total']), '%currency%' => $transaction->currency_iso]),
                'confirm_old' => $this->t('This pre-authorization is %days% days old. Banks may refuse to capture it, and the customer may not expect the charge any more: we advise you to inform the customer first. Confirm anyway?', ['%days%' => '%days%']),
                'void' => $this->t('Void this pre-authorization? The customer will not be charged and the order will be cancelled.'),
                'refund' => $this->t('Refund %amount% %currency% to the customer\'s card?', ['%amount%' => '%amount%', '%currency%' => $transaction->currency_iso]),
                'refund_invalid' => $this->t('Enter an amount between 0.01 and %max%.', ['%max%' => CmiProtocol::formatAmount($balance['remaining'])]),
            ],
        ];
    }

    private function t(string $text, array $parameters = []): string
    {
        return $this->module->getTranslator()->trans($text, $parameters, self::DOMAIN);
    }

    public static function stateLabel(string $state, \Module $module): string
    {
        $labels = [
            PaymentState::INITIATED => 'Awaiting payment',
            PaymentState::FAILED => 'Failed',
            PaymentState::CLIENT_CANCELLED => 'Cancelled by the customer',
            PaymentState::VOIDED => 'Voided',
            PaymentState::PRE_CONFIRMATION_REQUIRED => 'Pre-authorized, to confirm',
            PaymentState::CONFIRMED => 'Captured',
            PaymentState::REFUNDED => 'Refunded',
        ];

        return $module->getTranslator()->trans($labels[$state] ?? 'Unknown (click Check CMI)', [], self::DOMAIN);
    }

    private static function stateClass(string $state): string
    {
        switch ($state) {
            case PaymentState::CONFIRMED:
                return 'success';
            case PaymentState::PRE_CONFIRMATION_REQUIRED:
                return 'warning';
            case PaymentState::FAILED:
                return 'danger';
            case PaymentState::INITIATED:
                return 'info';
        }

        return 'secondary';
    }

    /** @return array{type: string, message: string}|null */
    private function takeFlash(): ?array
    {
        $cookie = $this->context->cookie;
        $raw = $cookie->__isset(self::FLASH_COOKIE) ? (string) $cookie->__get(self::FLASH_COOKIE) : '';
        if ($raw === '') {
            return null;
        }
        $cookie->__unset(self::FLASH_COOKIE);
        $cookie->write();
        $flash = json_decode($raw, true);

        return is_array($flash) && isset($flash['type'], $flash['message']) ? ['type' => (string) $flash['type'], 'message' => (string) $flash['message']] : null;
    }

    public static function setFlash(\Context $context, bool $success, string $message): void
    {
        $context->cookie->__set(self::FLASH_COOKIE, (string) json_encode(['type' => $success ? 'success' : 'danger', 'message' => $message]));
        $context->cookie->write();
    }

    public static function findTransaction(int $id): ?CmiTransaction
    {
        $transaction = new CmiTransaction($id);

        return \Validate::isLoadedObject($transaction) ? $transaction : null;
    }
}
