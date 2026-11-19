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
 * Amounts charged by CMI and refund maths.
 *
 * CMI charges in the currency the module sends: MAD on a standard (inshore) account, or the
 * order's own currency on an offshore account. When the order is in another currency than the
 * charged one, the charged amount and the rate are recorded at payment time, and every refund
 * is calculated from those recorded values, so it always matches what CMI charged.
 *
 * A rate is "charged currency per 1 unit of the order currency" (e.g. 10.8 MAD for 1 EUR).
 * No PrestaShop call in here.
 */
final class Amounts
{
    /** Amounts below this count as zero (rounding tolerance). */
    public const EPSILON = 0.005;

    public static function toCharged(float $orderAmount, float $rate): float
    {
        return round($orderAmount * $rate, 2);
    }

    public static function fromCharged(float $chargedAmount, float $rate): float
    {
        return $rate > 0 ? round($chargedAmount / $rate, 2) : 0.0;
    }

    /**
     * Rate between two PrestaShop currencies, from their conversion rates (both relative to the
     * shop's default currency): charged units per 1 order unit.
     *
     * A rate of exactly 1 between two different currencies means the rates were never set up
     * (PrestaShop's default for a new currency); using it would undercharge, so it is refused.
     *
     * @return float the rate, or 0.0 when no usable rate exists
     */
    public static function rateBetween(float $orderCurrencyRate, float $chargedCurrencyRate, bool $sameCurrency): float
    {
        if ($sameCurrency) {
            return 1.0;
        }
        if ($orderCurrencyRate <= 0 || $chargedCurrencyRate <= 0 || !is_finite($orderCurrencyRate) || !is_finite($chargedCurrencyRate)) {
            return 0.0;
        }
        $rate = $chargedCurrencyRate / $orderCurrencyRate;
        if (abs($rate - 1.0) < 0.0000001) {
            return 0.0;
        }

        return $rate;
    }

    /**
     * Refund balance of a payment, in the charged currency.
     *
     * @return array{total: float, refunded: float, remaining: float}
     */
    public static function balance(float $chargedTotal, float $refunded): array
    {
        $refunded = min($chargedTotal, max(0.0, $refunded));

        return [
            'total' => round($chargedTotal, 2),
            'refunded' => round($refunded, 2),
            'remaining' => round(max(0.0, $chargedTotal - $refunded), 2),
        ];
    }

    /**
     * Split a refund entered in the charged currency into the amount sent to CMI and the
     * order-currency amount it stands for. A refund reaching the remaining balance uses the exact
     * remaining amounts on both sides, so no rounding leftover (e.g. 0.01) remains.
     *
     * @return array{charged: float, order: float, full: bool}
     */
    public static function splitRefund(float $refundCharged, float $remainingCharged, float $remainingOrder, float $rate): array
    {
        if ($refundCharged >= $remainingCharged - self::EPSILON) {
            return ['charged' => round($remainingCharged, 2), 'order' => round($remainingOrder, 2), 'full' => true];
        }

        return [
            'charged' => round($refundCharged, 2),
            'order' => min(round($remainingOrder, 2), self::fromCharged($refundCharged, $rate)),
            'full' => false,
        ];
    }

    /** Parse an amount typed by the merchant ("10,50", "10.50", " 10.5 "); 0.0 when invalid. */
    public static function parse($raw): float
    {
        $value = str_replace([',', ' '], ['.', ''], trim((string) $raw));
        if ($value === '' || !is_numeric($value)) {
            return 0.0;
        }
        $amount = (float) $value;

        return ($amount > 0 && is_finite($amount)) ? $amount : 0.0;
    }
}
