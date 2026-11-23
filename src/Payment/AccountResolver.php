<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Cmi\PrestaShop\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Which CMI merchant account and currency a payment uses.
 *
 * A merchant has a standard (inshore) account, charged in MAD, and may have an offshore
 * account, charged in foreign currencies. The "CMI store currencies" setting lists the
 * currencies the merchant's CMI store accepts:
 *  - MAD only: everything is charged in MAD on the standard account; an order in another
 *    currency is converted to MAD.
 *  - foreign currencies only: the order is charged in its own currency on the offshore account.
 *  - MAD and foreign currencies: MAD orders on the standard account, orders in a listed
 *    currency on the offshore account, other currencies converted to MAD.
 * No PrestaShop call in here.
 */
final class AccountResolver
{
    public const INSHORE = 'inshore';
    public const OFFSHORE = 'offshore';

    public const MAD = 'MAD';
    public const MAD_NUMERIC = '504';

    /** Currencies a CMI store can accept: ISO 4217 alphabetic => numeric code. */
    public const SUPPORTED = [
        'MAD' => '504',
        'EUR' => '978',
        'USD' => '840',
        'GBP' => '826',
        'AED' => '784',
        'CHF' => '756',
        'SAR' => '682',
    ];

    public static function numericCode(string $isoCode): ?string
    {
        return self::SUPPORTED[strtoupper($isoCode)] ?? null;
    }

    public static function alphaCode(string $numericCode): ?string
    {
        $alpha = array_search($numericCode, self::SUPPORTED, true);

        return $alpha === false ? null : $alpha;
    }

    /**
     * @param string[] $storeCurrencies numeric codes selected in the settings
     * @param string $orderIso alphabetic code of the order currency
     *
     * @return array{account: string, currency: string, currency_iso: string, convert: bool}|null
     *                                                                                            null when the order currency cannot be charged
     */
    public static function resolve(array $storeCurrencies, string $orderIso): ?array
    {
        $storeCurrencies = array_values(array_intersect(array_map('strval', $storeCurrencies), self::SUPPORTED));
        if ($storeCurrencies === []) {
            $storeCurrencies = [self::MAD_NUMERIC];
        }
        $orderIso = strtoupper($orderIso);
        $orderNumeric = self::numericCode($orderIso);
        $acceptsMad = in_array(self::MAD_NUMERIC, $storeCurrencies, true);

        if ($orderIso !== self::MAD && $orderNumeric !== null && in_array($orderNumeric, $storeCurrencies, true)) {
            return ['account' => self::OFFSHORE, 'currency' => $orderNumeric, 'currency_iso' => $orderIso, 'convert' => false];
        }
        if (!$acceptsMad) {
            return null;
        }

        return ['account' => self::INSHORE, 'currency' => self::MAD_NUMERIC, 'currency_iso' => self::MAD, 'convert' => $orderIso !== self::MAD];
    }
}
