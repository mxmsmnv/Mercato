<?php
namespace ProcessWire;

final class MercatoQuoteStatus {
    public const SUBMITTED = 'submitted';
    public const UNDER_REVIEW = 'under-review';
    public const QUOTED = 'quoted';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const EXPIRED = 'expired';
    public const CONVERTED = 'converted';

    public static function all(): array {
        return [
            self::SUBMITTED,
            self::UNDER_REVIEW,
            self::QUOTED,
            self::ACCEPTED,
            self::DECLINED,
            self::EXPIRED,
            self::CONVERTED,
        ];
    }

    public static function canTransition(string $from, string $to): bool {
        if ($from === $to) return true;
        return in_array($to, match ($from) {
            self::SUBMITTED => [self::UNDER_REVIEW, self::QUOTED, self::DECLINED, self::EXPIRED],
            self::UNDER_REVIEW => [self::QUOTED, self::DECLINED, self::EXPIRED],
            self::QUOTED => [self::ACCEPTED, self::DECLINED, self::EXPIRED],
            self::ACCEPTED => [self::CONVERTED, self::EXPIRED],
            default => [],
        }, true);
    }
}
