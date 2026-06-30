<?php
namespace ProcessWire;

/**
 * Currency helpers shared by gateway amount builders and checkout validation.
 */
final class MercatoCurrency {

    /**
     * Stripe-compatible zero-decimal currencies.
     *
     * Most Mercato amounts are stored as major units. Gateways need a single
     * place that decides how many minor-unit decimal places to use.
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'SOS', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    private const THREE_DECIMAL = [
        'BHD', 'JOD', 'KWD', 'OMR', 'TND',
    ];

    public static function normalizeCode(string $currency): string {
        return strtoupper(trim($currency));
    }

    public static function isIsoCode(string $currency): bool {
        return preg_match('/^[A-Z]{3}$/', self::normalizeCode($currency)) === 1;
    }

    public static function isZeroDecimal(string $currency): bool {
        return in_array(self::normalizeCode($currency), self::ZERO_DECIMAL, true);
    }

    public static function decimalPlaces(string $currency): int {
        $currency = self::normalizeCode($currency);
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 0;
        }
        if (in_array($currency, self::THREE_DECIMAL, true)) {
            return 3;
        }
        return 2;
    }

    public static function minorUnitAmount(float $amount, string $currency): int {
        $amount = max(0.0, $amount);
        return (int) round($amount * (10 ** self::decimalPlaces($currency)));
    }

    public static function decimalAmount(float $amount, string $currency): string {
        $amount = max(0.0, $amount);
        return number_format($amount, self::decimalPlaces($currency), '.', '');
    }
}
