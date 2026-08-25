<?php
namespace ProcessWire;

final class MercatoAccountPolicy {
    public const GENERIC_AUTH_MESSAGE = 'If the account exists, the requested instructions have been sent.';

    public static function normalizeMode(mixed $mode): string {
        $mode = strtolower(trim((string) $mode));
        return in_array($mode, ['disabled', 'optional', 'required_verified'], true) ? $mode : 'disabled';
    }

    public static function normalizeEmail(string $email): string {
        return strtolower(trim($email));
    }

    public static function tokenExpired(int $expires, ?int $now = null): bool {
        return $expires <= 0 || $expires < ($now ?? time());
    }

    public static function ownsRecord(int $ownerId, int $userId): bool {
        return $ownerId > 0 && $userId > 0 && $ownerId === $userId;
    }

    public static function canClaim(string $recordEmail, string $verifiedEmail, int $ownerId = 0): bool {
        return $ownerId === 0
            && self::normalizeEmail($recordEmail) !== ''
            && hash_equals(self::normalizeEmail($recordEmail), self::normalizeEmail($verifiedEmail));
    }

    public static function mergeConflict(int $existingOwnerId, int $targetUserId): bool {
        return $existingOwnerId > 0 && $existingOwnerId !== $targetUserId;
    }
}
