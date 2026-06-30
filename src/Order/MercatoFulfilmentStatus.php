<?php
namespace ProcessWire;

/**
 * Delivery/fulfilment status, intentionally separate from payment status.
 */
final class MercatoFulfilmentStatus {

    public const UNFULFILLED = 'unfulfilled';
    public const PARTIALLY_FULFILLED = 'partially_fulfilled';
    public const FULFILLED = 'fulfilled';
    public const SHIPPED = 'shipped';
    public const READY_FOR_PICKUP = 'ready_for_pickup';
    public const COLLECTED = 'collected';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERED = 'delivered';
    public const RETURNED = 'returned';

    public static function all(): array {
        return [
            self::UNFULFILLED,
            self::PARTIALLY_FULFILLED,
            self::FULFILLED,
            self::SHIPPED,
            self::READY_FOR_PICKUP,
            self::COLLECTED,
            self::OUT_FOR_DELIVERY,
            self::DELIVERED,
            self::RETURNED,
        ];
    }

    public static function isValid(string $status): bool {
        return in_array($status, self::all(), true);
    }
}
