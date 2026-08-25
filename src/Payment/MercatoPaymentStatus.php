<?php
namespace ProcessWire;

/**
 * Canonical Mercato payment statuses.
 *
 * These constants intentionally include the existing MVP statuses and the
 * statuses needed for retries, authorisations, refunds, and webhook logs.
 */
final class MercatoPaymentStatus {

    public const CREATED = 'created';
    public const REQUIRES_PAYMENT = 'requires_payment';
    public const REQUIRES_CONFIRMATION = 'requires_confirmation';
    public const REQUIRES_ACTION = 'requires_action';
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const AUTHORIZED = 'authorized';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';
    public const EXPIRED = 'expired';
    public const REFUNDED = 'refunded';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const REFUND_PENDING = 'refund_pending';
    public const PARTIAL_REFUND_PENDING = 'partial_refund_pending';

    public static function all(): array {
        return [
            self::CREATED,
            self::REQUIRES_PAYMENT,
            self::REQUIRES_CONFIRMATION,
            self::REQUIRES_ACTION,
            self::PENDING,
            self::PROCESSING,
            self::AUTHORIZED,
            self::PAID,
            self::FAILED,
            self::CANCELED,
            self::EXPIRED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
            self::REFUND_PENDING,
            self::PARTIAL_REFUND_PENDING,
        ];
    }

    public static function isValid(string $status): bool {
        return in_array($status, self::all(), true);
    }

    public static function isPayable(string $status): bool {
        return in_array($status, [
            self::CREATED,
            self::REQUIRES_PAYMENT,
            self::REQUIRES_CONFIRMATION,
            self::REQUIRES_ACTION,
            self::PENDING,
            self::FAILED,
            self::CANCELED,
            self::EXPIRED,
        ], true);
    }

    public static function isSuccessful(string $status): bool {
        return in_array($status, [
            self::AUTHORIZED,
            self::PAID,
            self::PROCESSING,
        ], true);
    }

    public static function isFailureOutcome(string $status): bool {
        return in_array($status, [
            self::FAILED,
            self::CANCELED,
            self::EXPIRED,
        ], true);
    }

    public static function isTerminal(string $status): bool {
        return in_array($status, [
            self::PAID,
            self::FAILED,
            self::CANCELED,
            self::EXPIRED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
        ], true);
    }

    /**
     * Settled payments must not be moved backwards by delayed webhook events.
     * Refund transitions are handled by the dedicated refund reconciliation path.
     */
    public static function wouldRegressSettled(string $current, string $incoming): bool {
        $current = strtolower(trim($current));
        $incoming = strtolower(trim($incoming));
        $settled = [self::PAID, self::PARTIALLY_REFUNDED, self::REFUNDED];

        return in_array($current, $settled, true) && !in_array($incoming, $settled, true);
    }
}
