<?php
namespace ProcessWire;

final class MercatoFulfilmentMethodType {

    public const CARRIER_DELIVERY = 'carrier_delivery';
    public const STORE_PICKUP = 'store_pickup';
    public const LOCAL_DELIVERY = 'local_delivery';

    public static function all(): array {
        return [
            self::CARRIER_DELIVERY,
            self::STORE_PICKUP,
            self::LOCAL_DELIVERY,
        ];
    }

    public static function isValid(string $method): bool {
        return in_array($method, self::all(), true);
    }
}
