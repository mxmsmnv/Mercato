<?php
namespace ProcessWire;

final class MercatoTaxQuote {
    public static function normalize(array $quote, array $context = []): array {
        $currency = strtoupper(trim((string) ($quote['currency'] ?? $context['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new \InvalidArgumentException('Tax quote currency must be an ISO 4217 code.');
        $precision = MercatoCurrency::decimalPlaces($currency);
        $contextCurrency = strtoupper(trim((string) ($context['currency'] ?? '')));
        if ($contextCurrency !== '' && $currency !== $contextCurrency) throw new \InvalidArgumentException('Tax quote currency does not match the requested currency.');
        $totalTax = round((float) ($quote['total_tax'] ?? 0), $precision);
        $taxable = round((float) ($quote['taxable_amount'] ?? 0), $precision);
        $exempt = round((float) ($quote['exempt_amount'] ?? 0), $precision);
        if (!is_finite($totalTax) || !is_finite($taxable) || !is_finite($exempt)) throw new \InvalidArgumentException('Tax quote amounts must be finite.');
        if ($totalTax < 0 || $taxable < 0 || $exempt < 0) throw new \InvalidArgumentException('Tax quote amounts cannot be negative.');
        $lines = [];
        foreach ((array) ($quote['lines'] ?? []) as $line) {
            if (!is_array($line)) continue;
            $tax = round((float) ($line['tax'] ?? $line['tax_amount'] ?? 0), $precision);
            $lineTaxable = round((float) ($line['taxable_amount'] ?? 0), $precision);
            $lineExempt = round((float) ($line['exempt_amount'] ?? 0), $precision);
            $lineRate = round((float) ($line['rate'] ?? $line['tax_rate'] ?? 0), 6);
            if (!is_finite($tax) || !is_finite($lineTaxable) || !is_finite($lineExempt) || !is_finite($lineRate)) throw new \InvalidArgumentException('Tax line amounts and rates must be finite.');
            if ($tax < 0 || $lineTaxable < 0 || $lineExempt < 0 || $lineRate < 0) throw new \InvalidArgumentException('Tax line amounts and rates cannot be negative.');
            $components = self::normalizeComponents((array) ($line['components'] ?? []), $precision);
            $lines[] = [
                'line_id' => substr(trim((string) ($line['line_id'] ?? $line['id'] ?? '')), 0, 160),
                'tax_code' => substr(trim((string) ($line['tax_code'] ?? '')), 0, 120),
                'taxable_amount' => max(0, $lineTaxable),
                'exempt_amount' => max(0, $lineExempt),
                'tax' => $tax,
                'rate' => max(0, $lineRate),
                'jurisdiction' => substr(trim((string) ($line['jurisdiction'] ?? '')), 0, 160),
                'components' => $components,
            ];
        }
        $jurisdictions = [];
        foreach ((array) ($quote['jurisdictions'] ?? []) as $jurisdiction) {
            if (!is_array($jurisdiction)) continue;
            $jurisdictionRate = round((float) ($jurisdiction['rate'] ?? 0), 6);
            $jurisdictionTax = round((float) ($jurisdiction['tax'] ?? 0), $precision);
            if (!is_finite($jurisdictionRate) || !is_finite($jurisdictionTax)) throw new \InvalidArgumentException('Tax jurisdiction amounts and rates must be finite.');
            $jurisdictions[] = [
                'country' => strtoupper(substr(trim((string) ($jurisdiction['country'] ?? '')), 0, 2)),
                'region' => strtoupper(substr(trim((string) ($jurisdiction['region'] ?? '')), 0, 80)),
                'name' => substr(trim((string) ($jurisdiction['name'] ?? '')), 0, 160),
                'type' => substr(trim((string) ($jurisdiction['type'] ?? '')), 0, 80),
                'rate' => max(0, $jurisdictionRate),
                'tax' => max(0, $jurisdictionTax),
            ];
        }
        $shipping = is_array($quote['shipping'] ?? null) ? $quote['shipping'] : [];
        $shippingTaxable = round((float) ($shipping['taxable_amount'] ?? 0), $precision);
        $shippingExempt = round((float) ($shipping['exempt_amount'] ?? 0), $precision);
        $shippingTax = round((float) ($shipping['tax'] ?? 0), $precision);
        $shippingRate = round((float) ($shipping['rate'] ?? 0), 6);
        if (!is_finite($shippingTaxable) || !is_finite($shippingExempt) || !is_finite($shippingTax) || !is_finite($shippingRate)) throw new \InvalidArgumentException('Shipping tax amounts and rates must be finite.');
        if ($shippingTaxable < 0 || $shippingExempt < 0 || $shippingTax < 0 || $shippingRate < 0) throw new \InvalidArgumentException('Shipping tax amounts and rates cannot be negative.');
        $normalizedShipping = [
            'taxable_amount' => max(0, $shippingTaxable),
            'exempt_amount' => max(0, $shippingExempt),
            'tax' => max(0, $shippingTax),
            'rate' => max(0, $shippingRate),
            'jurisdiction' => substr(trim((string) ($shipping['jurisdiction'] ?? '')), 0, 160),
            'components' => self::normalizeComponents((array) ($shipping['components'] ?? []), $precision),
        ];
        if ($lines !== [] || $shipping !== []) {
            $breakdownTax = array_sum(array_column($lines, 'tax')) + (float) $normalizedShipping['tax'];
            $minorUnit = 1 / (10 ** $precision);
            if (abs($totalTax - $breakdownTax) > $minorUnit + 1e-9) throw new \InvalidArgumentException('Tax quote total does not match its line and shipping breakdown.');
        }
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
        $displayMode = (string) ($quote['display_mode'] ?? $context['display_mode'] ?? 'included');
        if (!in_array($displayMode, ['included', 'excluded', 'none'], true)) $displayMode = 'included';
        $taxBehavior = (string) ($context['tax_behavior'] ?? $quote['tax_behavior'] ?? ($displayMode === 'excluded' ? 'excluded' : 'included'));
        if (!in_array($taxBehavior, ['included', 'excluded'], true)) $taxBehavior = 'included';
        $taxAddedToTotal = $taxBehavior === 'excluded';
        return [
            'snapshot_version' => 2,
            'provider' => substr(trim((string) ($quote['provider'] ?? $context['provider'] ?? 'manual')), 0, 120),
            'operation' => substr(trim((string) ($quote['operation'] ?? $context['operation'] ?? 'estimate')), 0, 40),
            'currency' => $currency,
            'display_mode' => $displayMode,
            'tax_behavior' => $taxBehavior,
            'tax_added_to_total' => $taxAddedToTotal,
            'total_tax' => $totalTax,
            'taxable_amount' => $taxable,
            'exempt_amount' => $exempt,
            'lines' => $lines,
            'jurisdictions' => $jurisdictions,
            'shipping' => $normalizedShipping,
            'exemptions' => $exemptions,
            'input_snapshot' => $input,
            'provider_reference' => substr(trim((string) ($quote['provider_reference'] ?? $quote['reference'] ?? '')), 0, 240),
            'idempotency_key' => substr(trim((string) ($context['idempotency_key'] ?? $quote['idempotency_key'] ?? '')), 0, 240),
            'calculated_at' => (string) ($quote['calculated_at'] ?? date(DATE_ATOM)),
            'tax_date' => (string) ($quote['tax_date'] ?? $context['tax_date'] ?? date('Y-m-d')),
            'rule_version' => substr(trim((string) ($quote['rule_version'] ?? $context['rule_version'] ?? 'mercato-tax-v2')), 0, 120),
            'seller_entity' => substr(trim((string) ($quote['seller_entity'] ?? $context['seller_entity'] ?? '')), 0, 120),
            'market_id' => substr(trim((string) ($quote['market_id'] ?? $context['market_id'] ?? 'default')), 0, 80),
            'requested_provider' => substr(trim((string) ($quote['requested_provider'] ?? $context['provider'] ?? 'manual')), 0, 120),
            'fallback' => !empty($quote['fallback']),
            'fallback_reason' => substr(trim((string) ($quote['fallback_reason'] ?? '')), 0, 500),
        ];
    }

    private static function normalizeComponents(array $components, int $precision): array {
        $normalized = [];
        foreach ($components as $component) {
            if (!is_array($component)) continue;
            $rate = round((float) ($component['rate'] ?? 0), 6);
            $taxablePart = round((float) ($component['taxable_part'] ?? 100), 6);
            $tax = round((float) ($component['tax'] ?? 0), $precision);
            if (!is_finite($rate) || !is_finite($taxablePart) || !is_finite($tax) || $rate < 0 || $taxablePart < 0 || $taxablePart > 100 || $tax < 0) throw new \InvalidArgumentException('Tax component values are invalid.');
            $normalized[] = [
                'name' => substr(trim((string) ($component['name'] ?? '')), 0, 160), 'rate' => $rate,
                'taxable_part' => $taxablePart, 'tax' => $tax,
                'country' => strtoupper(substr(trim((string) ($component['country'] ?? '')), 0, 2)),
                'region' => strtoupper(substr(trim((string) ($component['region'] ?? '')), 0, 80)),
                'tax_code' => substr(trim((string) ($component['tax_code'] ?? '')), 0, 120),
            ];
        }
        return $normalized;
    }
}
