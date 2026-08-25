<?php
namespace ProcessWire;

final class MercatoAnalyticsSchema {
    public const VERSION = 'mercato.analytics.v1';
    public const EVENTS = ['product_view','search','collection_view','cart_change','checkout_start','shipping_selection','payment_result','purchase','refund','account_registered','account_verified','account_login','account_profile_updated','guest_order_claimed'];
    private const FORBIDDEN_KEYS = ['email','phone','address','street','password','secret','token','signed_url','payment_details','customer_name','first_name','last_name'];

    public static function normalize(string $name, array $payload, string $identifierMode = 'invoice'): array {
        if (!in_array($name, self::EVENTS, true)) throw new \InvalidArgumentException('Unsupported analytics event: ' . $name);
        $payload = self::sanitize($payload); $event = ['schema' => self::VERSION, 'event' => $name, 'event_id' => substr((string) ($payload['event_id'] ?? hash('sha256', $name . '|' . json_encode($payload))), 0, 128), 'occurred_at' => (string) ($payload['occurred_at'] ?? date(DATE_ATOM)), 'consent_category' => 'analytics']; unset($payload['event_id'], $payload['occurred_at']);
        if (isset($payload['order_identifier'])) { $id = (string) $payload['order_identifier']; $payload['order_identifier'] = match ($identifierMode) { 'hash' => $id === '' ? '' : hash('sha256', $id), 'omit' => '', default => substr($id, 0, 120) }; if ($payload['order_identifier'] === '') unset($payload['order_identifier']); }
        return $event + $payload;
    }

    public static function sanitize(array $payload): array {
        $safe = []; foreach ($payload as $key => $value) { $key = strtolower(trim((string) $key)); if ($key === '' || in_array($key, self::FORBIDDEN_KEYS, true) || preg_match('/(?:email|address|secret|token|password|signed)/', $key)) continue; if (is_array($value)) $safe[$key] = array_is_list($value) ? array_values(array_map(static fn($item) => is_array($item) ? self::sanitize($item) : self::scalar($item), $value)) : self::sanitize($value); else $safe[$key] = self::scalar($value); } return $safe;
    }
    private static function scalar(mixed $value): string|int|float|bool|null { if (is_bool($value)||is_int($value)||is_float($value)||$value===null)return $value; return substr(trim((string)$value),0,500); }
}
