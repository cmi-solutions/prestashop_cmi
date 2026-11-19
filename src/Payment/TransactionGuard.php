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
 * Protects CMI back-office operations against order-ID collisions.
 *
 * CMI's PostAuth, Void and Credit requests name a transaction by its OrderId only. When the
 * merchant's CMI account is also used by another website, an OrderId may belong to that
 * website's payment. Before any operation the module therefore checks, with the read-only
 * ORDERSTATUS / ORDERHISTORY queries, that the transaction exists and is this order's own.
 * No PrestaShop call in here.
 */
final class TransactionGuard
{
    public const OWNED = 'owned';
    public const FOREIGN = 'foreign';
    public const UNVERIFIED = 'unverified';

    /**
     * Allowed difference between CMI's and the shop's clocks when comparing dates. Wide enough
     * for a time-zone difference; another website's old payments are normally far older.
     */
    private const CLOCK_MARGIN_SECONDS = 7200;

    /** CMI back-office times are Moroccan local time. */
    private const CMI_TIMEZONE = 'Africa/Casablanca';

    public const PREFIX_MAX_LENGTH = 20;

    /** Random prefix for a new install, e.g. "PS7F3A-". */
    public static function generateOidPrefix(): string
    {
        return 'PS' . strtoupper(bin2hex(random_bytes(2))) . '-';
    }

    /** Only the characters CMI accepts in an OrderId; 20 characters at most. */
    public static function sanitizeOidPrefix(string $prefix): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix), 0, self::PREFIX_MAX_LENGTH);
    }

    /** Prefix + base, shortening the base (never the prefix) to CMI's 64-character limit. */
    public static function applyOidPrefix(string $prefix, string $base): string
    {
        $prefix = self::sanitizeOidPrefix($prefix);
        $base = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $base);

        return $prefix . substr($base, 0, 64 - strlen($prefix));
    }

    /** CMI rule: the store key must not contain the word "SKS", in any letter case. */
    public static function isValidStoreKey(string $key): bool
    {
        return stripos($key, 'SKS') === false;
    }

    /** Whether an ORDERSTATUS answer describes an actual transaction. */
    public static function hasTransaction(array $status): bool
    {
        foreach (['TRANS_STAT', 'TRANS_ID', 'TransId', 'ORIG_TRANS_AMT'] as $key) {
            if (trim((string) ($status[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an ORDERSTATUS answer says no transaction exists for the OrderId.
     *
     * A customer who cancels on CMI's page leaves no transaction. CMI's wording varies
     * ("not found", "No record found", ProcReturnCode 99...), so any answer without
     * transaction data counts as not found. Such an answer cannot be another website's payment.
     */
    public static function isNotFound(array $status): bool
    {
        $errMsg = (string) ($status['ErrMsg'] ?? '');
        $response = strtolower(trim((string) ($status['Response'] ?? '')));
        $procCode = trim((string) ($status['ProcReturnCode'] ?? ''));
        if (stripos($errMsg, 'not found') !== false || stripos($errMsg, 'no record') !== false || ($response === 'error' && $procCode === '')) {
            return true;
        }

        return !self::hasTransaction($status);
    }

    /** Amount in minor units (centimes), the unit of ORIG_TRANS_AMT and CAPTURE_AMT. */
    public static function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** CMI date ("2017-05-20 14:18:00.427") as a Unix time, or null. */
    public static function parseCmiDatetime(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable((string) preg_replace('/\.\d+$/', '', $value), new \DateTimeZone(self::CMI_TIMEZONE));

            return $date->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * ORDERHISTORY lines (TRX1..TRXn) split into tokens. Each line starts with
     * CHARGE_TYPE_CD, TRANS_STAT, ORIG_TRANS_AMT, CAPTURE_AMT, then the date and time.
     *
     * @param string[] $lines
     *
     * @return array<int, string[]>
     */
    public static function historyTokens(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $tokens = preg_split('/\s+/', trim((string) $line), -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens) {
                $rows[] = $tokens;
            }
        }

        return $rows;
    }

    /**
     * Captured and refunded totals (minor units) from ORDERHISTORY lines.
     *
     * @param string[] $lines
     *
     * @return array{captured: int, refunded: int}
     */
    public static function historyTotals(array $lines): array
    {
        $captured = 0;
        $refunded = 0;
        foreach (self::historyTokens($lines) as $tokens) {
            if (count($tokens) < 4 || !in_array(strtoupper($tokens[1]), ['C', 'S'], true) || !ctype_digit($tokens[3])) {
                continue;
            }
            if (strtoupper($tokens[0]) === 'S') {
                $captured += (int) $tokens[3];
            } elseif (strtoupper($tokens[0]) === 'C') {
                $refunded += (int) $tokens[3];
            }
        }

        return ['captured' => $captured, 'refunded' => $refunded];
    }

    /**
     * Whether the CMI transaction behind an OrderId is this order's own payment.
     *
     * @param array    $status  parsed ORDERSTATUS answer
     * @param string[] $history ORDERHISTORY lines (may be empty when not fetched)
     * @param array    $ours    ids (string[]: TransId / HostRefNum from our signed callbacks and
     *                          our own API calls), amount_minor (?int, amount sent, minor units),
     *                          sent_at (?int, when first sent to CMI)
     */
    public static function ownership(array $status, array $history, array $ours): string
    {
        $ownIds = array_values(array_filter(array_map('strval', (array) ($ours['ids'] ?? []))));

        // 1. One of the identifiers CMI gave us is on the transaction.
        if ($ownIds) {
            $cmiIds = [
                (string) ($status['TransId'] ?? ''),
                (string) ($status['TRANS_ID'] ?? ''),
                (string) ($status['HostRefNum'] ?? ''),
                (string) ($status['HOST_REF_NUM'] ?? ''),
            ];
            foreach (self::historyTokens($history) as $tokens) {
                $cmiIds = array_merge($cmiIds, $tokens);
            }
            if (array_intersect($ownIds, array_filter($cmiIds))) {
                return self::OWNED;
            }
        }

        // 2. No identifier matches. That alone doesn't prove it is foreign: each operation gets
        // a new TransId, the PreAuth's is no longer listed after a PostAuth, and CMI captures by
        // itself when answered ACTION=POSTAUTH. Compare the amount and date of the payment
        // (CHARGE_TYPE_CD S), from ORDERSTATUS or, after a refund, from the history.
        $amountMinor = $ours['amount_minor'] ?? null;
        if ($amountMinor === null) {
            return $ownIds ? self::FOREIGN : self::UNVERIFIED;
        }
        $candidates = [];
        if (strtoupper(trim((string) ($status['CHARGE_TYPE_CD'] ?? ''))) !== 'C') {
            $candidates[] = [(string) ($status['ORIG_TRANS_AMT'] ?? ''), (string) ($status['AUTH_DTTM'] ?? '')];
        }
        foreach (self::historyTokens($history) as $tokens) {
            if (count($tokens) >= 6 && strtoupper($tokens[0]) === 'S') {
                $candidates[] = [$tokens[2], $tokens[4] . ' ' . $tokens[5]];
            }
        }
        $sentAt = $ours['sent_at'] ?? null;
        foreach ($candidates as [$cmiAmount, $authDate]) {
            $cmiAmount = trim($cmiAmount);
            if ($cmiAmount === '' || !ctype_digit($cmiAmount) || (int) $cmiAmount !== (int) $amountMinor) {
                continue;
            }
            $authTime = self::parseCmiDatetime($authDate);
            if ($sentAt !== null && $authTime !== null && $authTime < (int) $sentAt - self::CLOCK_MARGIN_SECONDS) {
                continue;
            }

            return self::OWNED;
        }

        return self::FOREIGN;
    }

    /**
     * Why CMI's current state of a transaction does not allow an action ('' when it does).
     *
     * @param string $action confirm | void | refund
     *
     * @return string '' | not_preauthorized | already_captured | not_captured | voided | unknown_state
     */
    public static function actionBlockReason(string $action, string $transStat, string $chargeTypeCd): string
    {
        $transStat = strtoupper(trim($transStat));
        $chargeTypeCd = strtoupper(trim($chargeTypeCd));
        if ($transStat === '') {
            return 'unknown_state';
        }
        if ($transStat === 'V') {
            return 'voided';
        }
        $captured = in_array($transStat, ['C', 'S'], true);
        if ($action === 'refund') {
            // After a partial refund the last transaction is a Credit (CHARGE_TYPE_CD C): still refundable.
            return $captured ? '' : 'not_captured';
        }
        if ($transStat === 'A') {
            return '';
        }

        return ($captured && $chargeTypeCd !== 'C') ? 'already_captured' : 'not_preauthorized';
    }
}
