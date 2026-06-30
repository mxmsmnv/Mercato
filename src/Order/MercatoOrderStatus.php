<?php
namespace ProcessWire;

/**
 * Merchant-facing order workflow statuses.
 */
final class MercatoOrderStatus {

    public const DRAFT = 'draft';
    public const PENDING_PAYMENT = 'pending_payment';
    public const PAYMENT_PROCESSING = 'payment_processing';
    public const PROCESSING = 'processing';
    public const READY_FOR_PICKUP = 'ready_for_pickup';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const SHIPPED = 'shipped';
    public const DELIVERED = 'delivered';
    public const COMPLETE = 'complete';
    public const CANCELED = 'canceled';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';
    public const PARTIALLY_REFUNDED = 'partially_refunded';

    public static function all(): array {
        return [
            self::DRAFT,
            self::PENDING_PAYMENT,
            self::PAYMENT_PROCESSING,
            self::PROCESSING,
            self::READY_FOR_PICKUP,
            self::OUT_FOR_DELIVERY,
            self::SHIPPED,
            self::DELIVERED,
            self::COMPLETE,
            self::CANCELED,
            self::FAILED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
        ];
    }

    public static function isValid(string $status): bool {
        return in_array($status, self::all(), true);
    }

    public static function derive(Page $order): string {
        $payment = strtolower(trim((string) ($order->mrc_payment_status ?? '')));
        $paid = (int) ($order->mrc_payment_complete ?? 0) === 1 || $payment === MercatoPaymentStatus::PAID;

        if ($payment === MercatoPaymentStatus::REFUNDED) {
            return self::REFUNDED;
        }
        if ($payment === MercatoPaymentStatus::PARTIALLY_REFUNDED) {
            return self::PARTIALLY_REFUNDED;
        }
        if (in_array($payment, [MercatoPaymentStatus::FAILED, MercatoPaymentStatus::EXPIRED], true)) {
            return self::FAILED;
        }
        if ($payment === MercatoPaymentStatus::CANCELED) {
            return self::CANCELED;
        }
        if (!$paid) {
            return in_array($payment, [MercatoPaymentStatus::PROCESSING, MercatoPaymentStatus::REQUIRES_ACTION, MercatoPaymentStatus::REQUIRES_CONFIRMATION], true)
                ? self::PAYMENT_PROCESSING
                : self::PENDING_PAYMENT;
        }

        $fulfilment = $order->hasField('mrc_fulfilment_status')
            ? strtolower(trim((string) $order->mrc_fulfilment_status))
            : '';

        return match ($fulfilment) {
            MercatoFulfilmentStatus::READY_FOR_PICKUP => self::READY_FOR_PICKUP,
            MercatoFulfilmentStatus::OUT_FOR_DELIVERY => self::OUT_FOR_DELIVERY,
            MercatoFulfilmentStatus::SHIPPED => self::SHIPPED,
            MercatoFulfilmentStatus::DELIVERED => self::DELIVERED,
            MercatoFulfilmentStatus::COLLECTED,
            MercatoFulfilmentStatus::FULFILLED => self::COMPLETE,
            MercatoFulfilmentStatus::RETURNED => self::REFUNDED,
            default => self::PROCESSING,
        };
    }

    public static function label(string $status): string {
        return match ($status) {
            self::DRAFT => 'Draft',
            self::PENDING_PAYMENT => 'Awaiting payment',
            self::PAYMENT_PROCESSING => 'Payment processing',
            self::PROCESSING => 'Processing',
            self::READY_FOR_PICKUP => 'Ready for pickup',
            self::OUT_FOR_DELIVERY => 'Out for delivery',
            self::SHIPPED => 'Shipped',
            self::DELIVERED => 'Delivered',
            self::COMPLETE => 'Complete',
            self::CANCELED => 'Canceled',
            self::FAILED => 'Failed',
            self::REFUNDED => 'Refunded',
            self::PARTIALLY_REFUNDED => 'Partially refunded',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function statusClass(string $status): string {
        return match ($status) {
            self::COMPLETE,
            self::DELIVERED,
            self::SHIPPED => 'is-paid',
            self::FAILED,
            self::CANCELED,
            self::REFUNDED => 'is-failed',
            default => 'is-pending',
        };
    }
}
