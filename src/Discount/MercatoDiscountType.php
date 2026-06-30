<?php
namespace ProcessWire;

/**
 * Supported discount calculation types.
 */
final class MercatoDiscountType {

    public const PERCENTAGE = 'percentage';
    public const FIXED = 'fixed';
    public const FREE_SHIPPING = 'free_shipping';

    public static function labels(): array {
        return [
            self::PERCENTAGE => 'Percentage',
            self::FIXED => 'Fixed amount',
            self::FREE_SHIPPING => 'Free shipping',
        ];
    }

    public static function isValid(string $type): bool {
        return array_key_exists($type, self::labels());
    }
}
