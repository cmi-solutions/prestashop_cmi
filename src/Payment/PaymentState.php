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
 * The state of a CMI payment and the rules that move it.
 *
 * Stored on the module's transaction record, next to (not instead of) the PrestaShop order
 * status, which also describes shipping and the rest of the shop's workflow.
 * No PrestaShop call in here.
 */
final class PaymentState
{
    /** Sent to CMI, no answer yet. */
    public const INITIATED = 'initiated';
    /** Declined by the bank, or the customer gave up after a decline. */
    public const FAILED = 'failed';
    /** The customer clicked Cancel on CMI's payment page. */
    public const CLIENT_CANCELLED = 'client_cancelled';
    /** Pre-authorization voided (by the merchant or in the CMI back office). */
    public const VOIDED = 'voided';
    /** Pre-authorized, waiting for the merchant to confirm (capture) it. */
    public const PRE_CONFIRMATION_REQUIRED = 'pre_confirmation_required';
    /** Captured (possibly partially refunded since). */
    public const CONFIRMED = 'confirmed';
    /** Fully refunded. */
    public const REFUNDED = 'refunded';

    public const ALL = [
        self::INITIATED, self::FAILED, self::CLIENT_CANCELLED, self::VOIDED,
        self::PRE_CONFIRMATION_REQUIRED, self::CONFIRMED, self::REFUNDED,
    ];

    /** Past this age, the merchant is advised to inform the customer before capturing. */
    public const PREAUTH_WARNING_DAYS = 5;

    /** Seconds between automatic status checks of an order left awaiting payment (15 min, then 1 h, then 6 h). */
    public const PENDING_CHECK_DELAYS = [900, 3600, 21600];

    /** State after an approved or declined CMI callback, in a given confirmation mode. */
    public static function fromCallback(string $procReturnCode, string $confirmationMode): string
    {
        if ($procReturnCode !== '00') {
            return self::FAILED;
        }

        return $confirmationMode === 'manual' ? self::PRE_CONFIRMATION_REQUIRED : self::CONFIRMED;
    }

    /** Only a pre-authorization can be confirmed or voided. */
    public static function canConfirm(string $state): bool
    {
        return $state === self::PRE_CONFIRMATION_REQUIRED;
    }

    /** Only a captured payment can be refunded (also after a partial refund). */
    public static function canRefund(string $state): bool
    {
        return $state === self::CONFIRMED;
    }

    /** Whether the order still waits for its payment (and may be paid again by the customer). */
    public static function isAwaitingPayment(string $state): bool
    {
        return in_array($state, ['', self::INITIATED, self::FAILED], true);
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, [self::FAILED, self::CLIENT_CANCELLED, self::VOIDED, self::REFUNDED, self::CONFIRMED], true);
    }

    /**
     * Whether a signed CMI callback may still change a payment in the given state.
     *
     * Callbacks can arrive late or twice (for example the failUrl post of an earlier declined
     * attempt). A decline never overwrites an authorized, paid or closed payment, and an
     * approval never reopens a voided or refunded one.
     */
    public static function acceptsCallback(string $currentState, bool $approved): bool
    {
        if ($approved) {
            return !in_array($currentState, [self::VOIDED, self::REFUNDED], true);
        }

        return in_array($currentState, ['', self::INITIATED, self::FAILED], true);
    }

    /**
     * Full days since the pre-authorization when it is older than the warning age, else 0.
     *
     * @param int $preauthAt Unix time of the pre-authorization (0 when unknown)
     */
    public static function preauthWarningDays(int $preauthAt, int $now): int
    {
        if ($preauthAt <= 0 || $now - $preauthAt <= self::PREAUTH_WARNING_DAYS * 86400) {
            return 0;
        }

        return (int) floor(($now - $preauthAt) / 86400);
    }

    /** Delay before the next automatic check, or null once every check has been made. */
    public static function pendingCheckDelay(int $checksDone): ?int
    {
        return self::PENDING_CHECK_DELAYS[$checksDone] ?? null;
    }

    /**
     * Whether an automatic check should update an order awaiting payment from CMI's status.
     *
     * A final state of a real payment (pre-authorized, captured, settled, voided) always does.
     * A decline only on the last check: until then the customer may still try again.
     * "Not found" and in-progress results never do: nothing was paid.
     *
     * @param string $transStat CMI's TRANS_STAT
     * @param bool $lastCheck whether no check follows this one
     * @param string $procCode CMI's ProcReturnCode ('' when not reported)
     */
    public static function pendingCheckApplies(string $transStat, bool $lastCheck = false, string $procCode = ''): bool
    {
        $transStat = strtoupper(trim($transStat));
        if (in_array($transStat, ['A', 'C', 'S', 'V'], true)) {
            return true;
        }
        $procCode = trim($procCode);

        return $lastCheck && ($transStat === 'D' || ($procCode !== '' && $procCode !== '00'));
    }

    /**
     * Whether the customer's Cancel link may cancel the order, given the result of a CMI status
     * check ('' when no check was possible). Only when CMI shows no live payment of this order:
     * a pre-authorization, a capture or a transaction in progress is never undone by this link.
     */
    public static function customerCancelAllowed(string $checkResult): bool
    {
        return in_array($checkResult, ['', 'NOT_FOUND', 'DECLINED', 'FOREIGN', 'VOIDED'], true);
    }

    /**
     * Whether an ORDERSTATUS TRANS_STAT proves the payment was captured.
     * Response=Approved / ProcReturnCode=00 alone only means the status query worked.
     */
    public static function isCapturedTransStat(string $transStat): bool
    {
        return in_array(strtoupper(trim($transStat)), ['C', 'S'], true);
    }
}
