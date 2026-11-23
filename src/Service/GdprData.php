<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Service;

use Cmi\PrestaShop\Repository\TransactionRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Personal data the module keeps about a customer, for PrestaShop's GDPR module (psgdpr):
 * the card payments of their carts, with the card's last digits and brand.
 *
 * Deleting a customer anonymizes the card data but keeps the payments (amounts, OrderId,
 * states): they belong to the shop's accounting, like the orders PrestaShop keeps.
 */
class GdprData
{
    /** Card fields stored in a payment's last CMI answer. */
    private const CARD_FIELDS = ['MaskedPan', 'EXTRA_CARDBRAND'];

    /** @return array<int, array<string, string>> one row per CMI payment */
    public function export(int $idCustomer): array
    {
        $rows = [];
        foreach ($this->transactionsOf($idCustomer) as $row) {
            $answer = json_decode((string) $row['last_response'], true);
            $answer = is_array($answer) ? $answer : [];
            $digits = substr((string) preg_replace('/\D/', '', (string) ($answer['MaskedPan'] ?? '')), -4);
            $rows[] = [
                'CMI OrderId' => (string) $row['oid'],
                'Order reference' => (string) $row['order_reference'],
                'Payment state' => (string) $row['payment_state'],
                'Amount charged' => (string) $row['amount_charged'] . ' ' . (string) $row['currency_iso'],
                'Order amount' => (string) round((float) $row['amount_order'], 2) . ' ' . (string) $row['order_currency'],
                'Refunded' => (string) $row['refunded'] . ' ' . (string) $row['currency_iso'],
                'Card' => $digits !== '' ? '**** ' . $digits : '',
                'Card brand' => (string) ($answer['EXTRA_CARDBRAND'] ?? ''),
                'Date' => (string) $row['date_add'],
            ];
        }

        return $rows;
    }

    /** Remove the card data of the customer's payments. */
    public function anonymize(int $idCustomer): bool
    {
        $ok = true;
        foreach ($this->transactionsOf($idCustomer) as $row) {
            $answer = json_decode((string) $row['last_response'], true);
            if (!is_array($answer) || !array_intersect_key($answer, array_flip(self::CARD_FIELDS))) {
                continue;
            }
            $ok = \Db::getInstance()->update(
                'cmi_transaction',
                ['last_response' => pSQL((string) json_encode(array_diff_key($answer, array_flip(self::CARD_FIELDS))))],
                '`id_cmi_transaction` = ' . (int) $row['id_cmi_transaction']
            ) && $ok;
        }

        return $ok;
    }

    /** @return array<int, array<string, mixed>> */
    private function transactionsOf(int $idCustomer): array
    {
        if ($idCustomer <= 0) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT t.* FROM `' . TransactionRepository::table() . '` t'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cart` c ON c.`id_cart` = t.`id_cart`'
            . ' WHERE c.`id_customer` = ' . $idCustomer
            . ' ORDER BY t.`id_cmi_transaction` ASC'
        );

        return is_array($rows) ? $rows : [];
    }
}
