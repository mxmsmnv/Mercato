<?php
namespace ProcessWire;

final class MercatoManualTaxProvider implements MercatoTaxProviderInterface {
    public function __construct(protected Mercato $commerce) {}
    public function getTaxProviderKey(): string { return 'manual'; }

    public function estimate(array $context): array {
        $lines = [];
        $taxable = 0.0;
        $totalTax = 0.0;
        foreach ((array) ($context['items'] ?? []) as $item) {
            $lineTotal = round((float) ($item['line_total'] ?? 0), 2);
            $rate = max(0, (float) ($item['tax_rate'] ?? 0));
            $tax = $this->commerce->calculateTax($lineTotal, $rate);
            $lines[] = [
                'line_id' => (string) ($item['line_id'] ?? ''), 'tax_code' => (string) ($item['tax_code'] ?? ''),
                'taxable_amount' => $lineTotal, 'tax' => round($tax, 2), 'rate' => $rate,
                'jurisdiction' => (string) ($context['destination']['country'] ?? ''),
            ];
            $taxable += $lineTotal;
            $totalTax += $tax;
        }
        $shipping = (array) ($context['shipping'] ?? []);
        $shippingTax = 0.0;
        if (!empty($shipping['taxable']) && (float) ($shipping['amount'] ?? 0) > 0) {
            $shippingTax = $this->commerce->calculateTax((float) $shipping['amount'], (float) ($shipping['tax_rate'] ?? 0));
            $taxable += (float) $shipping['amount'];
            $totalTax += $shippingTax;
        }
        return [
            'provider' => 'manual', 'operation' => 'estimate', 'currency' => (string) $context['currency'],
            'display_mode' => (string) $context['display_mode'], 'total_tax' => round($totalTax, 2),
            'taxable_amount' => round($taxable, 2), 'exempt_amount' => 0, 'lines' => $lines,
            'shipping' => ['taxable_amount' => round((float) ($shipping['amount'] ?? 0), 2), 'tax' => round($shippingTax, 2), 'rate' => (float) ($shipping['tax_rate'] ?? 0)],
            'jurisdictions' => [], 'provider_reference' => '', 'idempotency_key' => (string) $context['idempotency_key'],
        ];
    }
    public function commit(array $context): array { return ['provider_reference' => (string) ($context['quote']['provider_reference'] ?? ''), 'status' => 'committed']; }
    public function refund(array $context): array { return ['provider_reference' => (string) ($context['quote']['provider_reference'] ?? ''), 'status' => 'refunded', 'amount' => (float) ($context['amount'] ?? 0)]; }
    public function void(array $context): array { return ['provider_reference' => (string) ($context['quote']['provider_reference'] ?? ''), 'status' => 'voided']; }
}
