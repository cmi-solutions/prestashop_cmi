<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Model;

use Cmi\PrestaShop\Payment\Amounts;
use Cmi\PrestaShop\Payment\TransactionGuard;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One CMI payment: the OrderId sent to CMI and everything recorded about it.
 *
 * A cart paid with CMI gives one transaction, shared by every order PrestaShop created for
 * that cart (several carriers give several orders with the same reference).
 */
class CmiTransaction extends \ObjectModel
{
    /** @var int */
    public $id_shop;
    /** @var int */
    public $id_cart;
    /** @var int first order of the cart */
    public $id_order;
    /** @var string */
    public $order_reference = '';
    /** @var string OrderId sent to CMI */
    public $oid;
    /** @var string one of PaymentState's constants */
    public $payment_state = '';
    /** @var string inshore | offshore */
    public $account = 'inshore';
    /** @var string merchant client ID the payment was sent with */
    public $client_id = '';
    /** @var string alphabetic code of the charged currency (MAD on a standard account) */
    public $currency_iso = 'MAD';
    /** @var string numeric code of the charged currency (504 for MAD) */
    public $currency_num = '504';
    /** @var float amount charged by CMI, in the charged currency */
    public $amount_charged = 0.0;
    /** @var string alphabetic code of the order currency */
    public $order_currency = '';
    /** @var float order total, in the order currency */
    public $amount_order = 0.0;
    /** @var float charged currency per 1 unit of order currency, frozen at payment */
    public $rate = 1.0;
    /** @var float refunded on CMI, in the charged currency */
    public $refunded = 0.0;
    /** @var string JSON list of TransId / HostRefNum values that belong to this payment */
    public $own_ids = '[]';
    /** @var string JSON of the last CMI answer (selected fields) */
    public $last_response = '{}';
    /** @var string last TRANS_STAT reported by CMI */
    public $trans_stat = '';
    /** @var bool created for an order paid with an earlier version of the module */
    public $legacy = false;
    /** @var int Unix time the payment was first sent to CMI */
    public $sent_at = 0;
    /** @var int Unix time of the pre-authorization (0 when unknown) */
    public $preauth_at = 0;
    /** @var int automatic status checks done */
    public $pending_checks = 0;
    /** @var int Unix time of the next automatic status check (0: none) */
    public $next_check_at = 0;
    /** @var int Unix time until which an operation holds the transaction */
    public $lock_until = 0;
    /** @var string */
    public $date_add;
    /** @var string */
    public $date_upd;

    public static $definition = [
        'table' => 'cmi_transaction',
        'primary' => 'id_cmi_transaction',
        'fields' => [
            'id_shop' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_cart' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'order_reference' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64],
            'oid' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64, 'required' => true],
            'payment_state' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32],
            'account' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16],
            'client_id' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32],
            'currency_iso' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 3],
            'currency_num' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 3],
            'amount_charged' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'order_currency' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 3],
            'amount_order' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'rate' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'refunded' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'own_ids' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'last_response' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'trans_stat' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 8],
            'legacy' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'sent_at' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'preauth_at' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'pending_checks' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'next_check_at' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            // lock_until is left out on purpose: only TransactionRepository's atomic queries write it.
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /** @return string[] */
    public function getOwnIds(): array
    {
        $ids = json_decode((string) $this->own_ids, true);

        return is_array($ids) ? array_values(array_filter(array_map('strval', $ids))) : [];
    }

    /** Remember the TransId / HostRefNum of an answer that belongs to this payment. */
    public function addOwnIds(array $answer): bool
    {
        $known = $this->getOwnIds();
        $ids = array_values(array_unique(array_filter(array_merge($known, [
            trim((string) ($answer['TransId'] ?? '')),
            trim((string) ($answer['HostRefNum'] ?? '')),
        ]))));
        if (array_diff($ids, $known)) {
            $this->own_ids = (string) json_encode($ids);

            return true;
        }

        return false;
    }

    /** @return array<string, string> */
    public function getLastResponse(): array
    {
        $data = json_decode((string) $this->last_response, true);

        return is_array($data) ? $data : [];
    }

    /** Keep the useful fields of a CMI answer (never card data beyond the masked number). */
    public function setLastResponse(array $answer): void
    {
        $keep = ['Response', 'ProcReturnCode', 'ErrMsg', 'TransId', 'HostRefNum', 'AuthCode', 'mdStatus', 'TRANS_STAT', 'CHARGE_TYPE_CD', 'CAPTURE_AMT', 'MaskedPan', 'EXTRA_CARDBRAND'];
        // The card is only reported by the payment callback: keep it across later API answers.
        $data = array_intersect_key($this->getLastResponse(), ['MaskedPan' => true, 'EXTRA_CARDBRAND' => true]);
        foreach ($keep as $field) {
            if (isset($answer[$field]) && is_scalar($answer[$field]) && trim((string) $answer[$field]) !== '') {
                $data[$field] = substr(strip_tags(trim((string) $answer[$field])), 0, 255);
            }
        }
        $this->last_response = (string) json_encode($data);
    }

    /** Whether the amount CMI charged is known (it is not for some orders paid before 2.0.0). */
    public function hasKnownAmount(): bool
    {
        return (float) $this->amount_charged > 0 && (float) $this->rate > 0;
    }

    /** @return array{total: float, refunded: float, remaining: float} */
    public function balance(): array
    {
        return Amounts::balance((float) $this->amount_charged, (float) $this->refunded);
    }

    /** Values the ownership guard compares with CMI's records. */
    public function ownershipData(): array
    {
        return [
            'ids' => $this->getOwnIds(),
            'amount_minor' => $this->hasKnownAmount() ? TransactionGuard::toMinorUnits((float) $this->amount_charged) : null,
            'sent_at' => (int) $this->sent_at > 0 ? (int) $this->sent_at : null,
        ];
    }

    public function isConverted(): bool
    {
        return strtoupper((string) $this->order_currency) !== strtoupper((string) $this->currency_iso);
    }
}
