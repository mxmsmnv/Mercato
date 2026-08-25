<?php
namespace ProcessWire;

final class MercatoShippingQuote {
    public static function normalizeRates(array $rates, array $context): array {
        $normalized = [];
        foreach ($rates as $rate) {
            if (!is_array($rate)) continue;
            $id = substr(trim((string) ($rate['id'] ?? $rate['rate_id'] ?? '')), 0, 160);
            $service = substr(trim((string) ($rate['service'] ?? $rate['service_code'] ?? '')), 0, 160);
            $amount = round((float) ($rate['amount'] ?? 0), 2);
            if ($id === '' || $service === '' || $amount < 0) continue;
            $expires = trim((string) ($rate['expires_at'] ?? ''));
            $normalized[] = [
                'id' => $id,
                'provider' => substr(trim((string) ($rate['provider'] ?? $context['provider'] ?? '')), 0, 120),
                'service' => $service,
                'label' => substr(trim((string) ($rate['label'] ?? $service)), 0, 160),
                'amount' => $amount,
                'currency' => strtoupper(substr(trim((string) ($rate['currency'] ?? $context['currency'] ?? '')), 0, 3)),
                'delivery_days_min' => max(0, (int) ($rate['delivery_days_min'] ?? 0)),
                'delivery_days_max' => max(0, (int) ($rate['delivery_days_max'] ?? 0)),
                'provider_reference' => substr(trim((string) ($rate['provider_reference'] ?? '')), 0, 240),
                'expires_at' => $expires !== '' ? $expires : date(DATE_ATOM, time() + max(60, (int) ($context['quote_ttl_seconds'] ?? 900))),
            ];
        }
        usort($normalized, static fn(array $a, array $b): int => $a['amount'] <=> $b['amount']);
        return $normalized;
    }
}
