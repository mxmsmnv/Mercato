<?php
namespace ProcessWire;

final class MercatoManualTaxProvider implements MercatoTaxProviderInterface {
    public function __construct(protected Mercato $commerce) {}
    public function getTaxProviderKey(): string { return 'manual'; }

    public function estimate(array $context): array {
        $precision = MercatoCurrency::decimalPlaces((string) ($context['currency'] ?? ''));
        $behavior = in_array((string) ($context['tax_behavior'] ?? $context['display_mode'] ?? 'included'), ['included', 'excluded'], true)
            ? (string) ($context['tax_behavior'] ?? $context['display_mode'])
            : 'included';
        $override = (string) ($context['customer']['taxability_override'] ?? 'none');
        $customerExempt = in_array($override, ['customer_exempt', 'reverse_charge'], true);
        $lines = [];
        $taxable = 0.0;
        foreach ((array) ($context['items'] ?? []) as $item) {
            $lineTotal = round((float) ($item['line_total'] ?? 0), $precision);
            $rate = max(0, (float) ($item['tax_rate'] ?? 0));
            $allocatedDiscount = min($lineTotal, max(0.0, (float) ($context['discount']['allocations'][(string) ($item['line_id'] ?? '')] ?? 0)));
            $base = round(max(0.0, $lineTotal - $allocatedDiscount), $precision);
            $tax = $customerExempt ? 0.0 : ($behavior === 'excluded'
                ? $base * $rate / 100
                : $this->commerce->calculateTax($base, $rate));
            $lines[] = [
                'line_id' => (string) ($item['line_id'] ?? ''), 'tax_code' => (string) ($item['tax_code'] ?? ''),
                'taxable_amount' => $customerExempt ? 0.0 : $base, 'exempt_amount' => $customerExempt ? $base : 0.0,
                'tax' => $tax, 'rate' => $customerExempt ? 0.0 : $rate,
                'jurisdiction' => (string) ($context['destination']['country'] ?? ''),
            ];
            if (!$customerExempt) $taxable += $base;
        }
        $shipping = (array) ($context['shipping'] ?? []);
        $shippingTax = 0.0;
        $shippingAmount = max(0.0, (float) ($shipping['amount'] ?? 0) - (float) ($context['discount']['shipping_discount'] ?? 0));
        $shippingTaxableAmount = !$customerExempt && !empty($shipping['taxable']) ? $shippingAmount : 0.0;
        if (!$customerExempt && !empty($shipping['taxable']) && $shippingAmount > 0) {
            $shippingTax = $behavior === 'excluded'
                ? $shippingAmount * (float) ($shipping['tax_rate'] ?? 0) / 100
                : $this->commerce->calculateTax($shippingAmount, (float) ($shipping['tax_rate'] ?? 0));
            $taxable += $shippingAmount;
        }
        $shippingQuote = ['taxable_amount' => round($shippingTaxableAmount, $precision), 'exempt_amount' => round($shippingAmount - $shippingTaxableAmount, $precision), 'tax' => $shippingTax, 'rate' => !empty($shipping['taxable']) ? (float) ($shipping['tax_rate'] ?? 0) : 0.0];
        [$lines, $shippingQuote, $totalTax] = $this->roundTaxes($lines, $shippingQuote, $this->commerce->getTaxRoundingMode(), $precision);
        return [
            'provider' => 'manual', 'operation' => 'estimate', 'currency' => (string) $context['currency'],
            'display_mode' => (string) ($context['display_mode'] ?? $behavior), 'tax_behavior' => $behavior, 'tax_added_to_total' => $behavior === 'excluded', 'total_tax' => round($totalTax, $precision),
            'taxable_amount' => round($taxable, $precision), 'exempt_amount' => round($customerExempt ? array_sum(array_column($lines, 'exempt_amount')) + $shippingAmount : 0, $precision), 'lines' => $lines,
            'shipping' => $shippingQuote,
            'jurisdictions' => [], 'provider_reference' => '', 'idempotency_key' => (string) $context['idempotency_key'],
        ];
    }
    private function roundTaxes(array $lines, array $shipping, string $mode, int $precision): array {
        $entries = [];
        foreach ($lines as $index => $line) $entries[] = ['kind' => 'line', 'index' => $index, 'rate' => (string) (float) ($line['rate'] ?? 0), 'raw' => (float) ($line['tax'] ?? 0)];
        if ((float) ($shipping['tax'] ?? 0) > 0) $entries[] = ['kind' => 'shipping', 'index' => 0, 'rate' => (string) (float) ($shipping['rate'] ?? 0), 'raw' => (float) $shipping['tax']];
        $groups = [];
        foreach ($entries as $entryIndex => $entry) $groups[$mode === 'total' ? 'total' : ($mode === 'tax_rate' ? $entry['rate'] : 'line-' . $entryIndex)][] = $entryIndex;
        $factor = 10 ** $precision;
        foreach ($groups as $entryIndexes) {
            $targetMinor = (int) round(array_sum(array_map(static fn(int $i): float => max(0.0, $entries[$i]['raw']), $entryIndexes)) * $factor);
            $assignedMinor = 0; $remainders = [];
            foreach ($entryIndexes as $i) {
                $exactMinor = max(0.0, $entries[$i]['raw']) * $factor;
                $entries[$i]['minor'] = (int) floor($exactMinor + 1.0e-9);
                $assignedMinor += $entries[$i]['minor'];
                $remainders[] = ['index' => $i, 'fraction' => $exactMinor - $entries[$i]['minor']];
            }
            usort($remainders, static fn(array $a, array $b): int => ($b['fraction'] <=> $a['fraction']) ?: ($a['index'] <=> $b['index']));
            for ($n = 0; $n < $targetMinor - $assignedMinor; $n++) $entries[$remainders[$n % count($remainders)]['index']]['minor']++;
            foreach ($entryIndexes as $i) $entries[$i]['rounded'] = $entries[$i]['minor'] / $factor;
        }
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'line') $lines[$entry['index']]['tax'] = $entry['rounded'];
            else $shipping['tax'] = $entry['rounded'];
        }
        foreach ($lines as &$line) $line['tax'] = round((float) ($line['tax'] ?? 0), $precision); unset($line);
        $shipping['tax'] = round((float) ($shipping['tax'] ?? 0), $precision);
        return [$lines, $shipping, round(array_sum(array_column($lines, 'tax')) + $shipping['tax'], $precision)];
    }
    public function commit(array $context): array { return ['provider_reference' => (string) ($context['quote']['provider_reference'] ?? ''), 'status' => 'committed']; }
    public function refund(array $context): array { return ['provider_reference' => (string) ($context['quote']['provider_reference'] ?? ''), 'status' => 'refunded', 'amount' => (float) ($context['amount'] ?? 0)]; }
    public function void(array $context): array { return ['provider_reference' => (string) ($context['quote']['provider_reference'] ?? ''), 'status' => 'voided']; }
}
