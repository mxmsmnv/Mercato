<?php
namespace ProcessWire;

final class MercatoTaxQuote {
    public static function normalize(array $quote, array $context = []): array {
        $currency = strtoupper(trim((string) ($quote['currency'] ?? $context['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new \InvalidArgumentException('Tax quote currency must be an ISO 4217 code.');
        $totalTax = round((float) ($quote['total_tax'] ?? 0), 2);
        $taxable = round((float) ($quote['taxable_amount'] ?? 0), 2);
        $exempt = round((float) ($quote['exempt_amount'] ?? 0), 2);
        if ($totalTax < 0 || $taxable < 0 || $exempt < 0) throw new \InvalidArgumentException('Tax quote amounts cannot be negative.');
        $lines = [];
        foreach ((array) ($quote['lines'] ?? []) as $line) {
            if (!is_array($line)) continue;
            $tax = round((float) ($line['tax'] ?? $line['tax_amount'] ?? 0), 2);
            if ($tax < 0) throw new \InvalidArgumentException('Tax line amount cannot be negative.');
            $lines[] = [
                'line_id' => substr(trim((string) ($line['line_id'] ?? $line['id'] ?? '')), 0, 160),
                'tax_code' => substr(trim((string) ($line['tax_code'] ?? '')), 0, 120),
                'taxable_amount' => round(max(0, (float) ($line['taxable_amount'] ?? 0)), 2),
                'exempt_amount' => round(max(0, (float) ($line['exempt_amount'] ?? 0)), 2),
                'tax' => $tax,
                'rate' => round(max(0, (float) ($line['rate'] ?? $line['tax_rate'] ?? 0)), 6),
                'jurisdiction' => substr(trim((string) ($line['jurisdiction'] ?? '')), 0, 160),
            ];
        }
        $jurisdictions = [];
        foreach ((array) ($quote['jurisdictions'] ?? []) as $jurisdiction) {
            if (!is_array($jurisdiction)) continue;
            $jurisdictions[] = [
                'country' => strtoupper(substr(trim((string) ($jurisdiction['country'] ?? '')), 0, 2)),
                'region' => strtoupper(substr(trim((string) ($jurisdiction['region'] ?? '')), 0, 80)),
                'name' => substr(trim((string) ($jurisdiction['name'] ?? '')), 0, 160),
                'type' => substr(trim((string) ($jurisdiction['type'] ?? '')), 0, 80),
                'rate' => round(max(0, (float) ($jurisdiction['rate'] ?? 0)), 6),
                'tax' => round(max(0, (float) ($jurisdiction['tax'] ?? 0)), 2),
            ];
        }
        $shipping = is_array($quote['shipping'] ?? null) ? $quote['shipping'] : [];
        $normalizedShipping = [
            'taxable_amount' => round(max(0, (float) ($shipping['taxable_amount'] ?? 0)), 2),
            'exempt_amount' => round(max(0, (float) ($shipping['exempt_amount'] ?? 0)), 2),
            'tax' => round(max(0, (float) ($shipping['tax'] ?? 0)), 2),
            'rate' => round(max(0, (float) ($shipping['rate'] ?? 0)), 6),
            'jurisdiction' => substr(trim((string) ($shipping['jurisdiction'] ?? '')), 0, 160),
        ];
        $exemptions = [];
        foreach ((array) ($quote['exemptions'] ?? []) as $exemption) {
            if (is_array($exemption)) {
                $exemptions[] = [
                    'type' => substr(trim((string) ($exemption['type'] ?? '')), 0, 80),
                    'code' => substr(trim((string) ($exemption['code'] ?? '')), 0, 120),
                    'reason' => substr(trim((string) ($exemption['reason'] ?? '')), 0, 240),
                ];
            } elseif (trim((string) $exemption) !== '') {
                $exemptions[] = ['type' => '', 'code' => '', 'reason' => substr(trim((string) $exemption), 0, 240)];
            }
        }
        $input = (array) ($quote['input_snapshot'] ?? []);
        return [
            'provider' => substr(trim((string) ($quote['provider'] ?? $context['provider'] ?? 'manual')), 0, 120),
            'operation' => substr(trim((string) ($quote['operation'] ?? $context['operation'] ?? 'estimate')), 0, 40),
            'currency' => $currency,
            'display_mode' => in_array((string) ($quote['display_mode'] ?? $context['display_mode'] ?? 'included'), ['included', 'excluded', 'none'], true) ? (string) ($quote['display_mode'] ?? $context['display_mode'] ?? 'included') : 'included',
            'total_tax' => $totalTax,
            'taxable_amount' => $taxable,
            'exempt_amount' => $exempt,
            'lines' => $lines,
            'jurisdictions' => $jurisdictions,
            'shipping' => $normalizedShipping,
            'exemptions' => $exemptions,
            'input_snapshot' => $input,
            'provider_reference' => substr(trim((string) ($quote['provider_reference'] ?? $quote['reference'] ?? '')), 0, 240),
            'idempotency_key' => substr(trim((string) ($quote['idempotency_key'] ?? $context['idempotency_key'] ?? '')), 0, 240),
            'calculated_at' => (string) ($quote['calculated_at'] ?? date(DATE_ATOM)),
            'fallback' => !empty($quote['fallback']),
            'fallback_reason' => substr(trim((string) ($quote['fallback_reason'] ?? '')), 0, 500),
        ];
    }
}
