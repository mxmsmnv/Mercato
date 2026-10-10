<?php
namespace ProcessWire;

/** Stripe Tax adapter for custom payment flows. Network access is supplied by the module. */
final class MercatoStripeTaxProvider implements MercatoTaxProviderInterface {
    /** @var callable(string,array,string):array */
    private $request;
    private array $taxCodeMap;
    private string $shippingTaxCode;
    private ?bool $expectedLivemode;

    public function __construct(callable $request, array $config = []) {
        $this->request = $request;
        $this->taxCodeMap = is_array($config['tax_code_map'] ?? null) ? $config['tax_code_map'] : [];
        $this->shippingTaxCode = $this->stripeTaxCode((string) ($config['shipping_tax_code'] ?? ''));
        $this->expectedLivemode = array_key_exists('expected_livemode', $config) ? (bool) $config['expected_livemode'] : null;
    }
    public function getTaxProviderKey(): string { return 'stripe_tax'; }

    public function estimate(array $context): array {
        $behavior = (string) ($context['tax_behavior'] ?? 'included');
        if ($behavior === 'none') return $this->zeroQuote($context);
        $currency = MercatoCurrency::normalizeCode((string) ($context['currency'] ?? ''));
        $discount = (array) ($context['discount'] ?? []);
        $lines = [];
        foreach ((array) ($context['items'] ?? []) as $item) {
            $amount = max(0.0, (float) ($item['line_total'] ?? 0));
            $allocated = min($amount, max(0.0, (float) ($discount['allocations'][(string) ($item['line_id'] ?? '')] ?? 0)));
            $line = [
                'amount' => MercatoCurrency::minorUnitAmount($amount - $allocated, $currency),
                'reference' => substr((string) ($item['line_id'] ?? ''), 0, 200),
                'tax_behavior' => $behavior === 'excluded' ? 'exclusive' : 'inclusive',
            ];
            $sourceCode = trim((string) ($item['tax_code'] ?? ''));
            $taxCode = $this->stripeTaxCode((string) ($this->taxCodeMap[$sourceCode] ?? $sourceCode));
            if ($taxCode !== '') $line['tax_code'] = $taxCode;
            $lines[] = $line;
        }
        $destination = (array) ($context['destination'] ?? []);
        $address = array_filter([
            'line1' => (string) ($destination['address'] ?? $destination['line1'] ?? ''),
            'line2' => (string) ($destination['line2'] ?? ''),
            'city' => (string) ($destination['city'] ?? ''),
            'state' => (string) ($destination['region'] ?? ''),
            'postal_code' => (string) ($destination['postal_code'] ?? ''),
            'country' => strtoupper((string) ($destination['country'] ?? '')),
        ], static fn(string $value): bool => $value !== '');
        if (empty($address['country'])) throw new \InvalidArgumentException('Stripe Tax requires a destination country.');
        $payload = [
            'currency' => strtolower($currency),
            'customer_details' => ['address' => $address, 'address_source' => (string) ($context['address_source'] ?? 'shipping') === 'billing' ? 'billing' : 'shipping'],
            'line_items' => $lines,
            'expand' => ['line_items'],
            'tax_date' => strtotime((string) ($context['tax_date'] ?? date('Y-m-d')) . ' 12:00:00 UTC'),
        ];
        $customer = (array) ($context['customer'] ?? []);
        $override = (string) ($customer['taxability_override'] ?? (!empty($customer['tax_exempt']) ? 'customer_exempt' : 'none'));
        if (in_array($override, ['customer_exempt', 'reverse_charge'], true)) $payload['customer_details']['taxability_override'] = $override;
        $taxNumber = trim((string) ($customer['tax_number'] ?? ''));
        $taxNumberType = trim((string) ($customer['tax_number_type'] ?? ''));
        if ($taxNumber !== '' && $taxNumberType !== '' && !empty($customer['tax_number_validated'])) $payload['customer_details']['tax_ids'] = [['type' => $taxNumberType, 'value' => $taxNumber]];
        $shipping = (array) ($context['shipping'] ?? []);
        if ((float) ($shipping['amount'] ?? 0) > 0) {
            $shippingAmount = max(0.0, (float) $shipping['amount'] - (float) ($discount['shipping_discount'] ?? 0));
            $payload['shipping_cost'] = [
                'amount' => MercatoCurrency::minorUnitAmount($shippingAmount, $currency),
                'tax_behavior' => $behavior === 'excluded' ? 'exclusive' : 'inclusive',
            ];
            if ($this->shippingTaxCode !== '') $payload['shipping_cost']['tax_code'] = $this->shippingTaxCode;
        }
        $shipFrom = (array) ($context['ship_from'] ?? []);
        if (!empty($shipFrom['country'])) $payload['ship_from_details'] = ['address' => array_filter([
            'line1' => (string) ($shipFrom['line1'] ?? ''), 'line2' => (string) ($shipFrom['line2'] ?? ''),
            'city' => (string) ($shipFrom['city'] ?? ''), 'state' => (string) ($shipFrom['region'] ?? ''),
            'postal_code' => (string) ($shipFrom['postal_code'] ?? ''), 'country' => strtoupper((string) $shipFrom['country']),
        ], static fn(string $value): bool => $value !== '')];

        $response = ($this->request)('calculate', $payload, (string) ($context['idempotency_key'] ?? ''));
        $this->assertResponse($response, 'taxcalc_');
        if ($this->expectsRegistration($context) && $this->containsReason($response, 'not_collecting')) throw new MercatoTaxProviderException('Stripe Tax is not collecting in a jurisdiction configured as registered.', false, 422);
        return $this->normalizeCalculation($response, $context);
    }

    public function commit(array $context): array {
        $calculation = trim((string) ($context['quote']['provider_reference'] ?? ''));
        if ($calculation === '') throw new \RuntimeException('Stripe Tax calculation reference is missing.');
        $namespace = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($context['order']['store_namespace'] ?? 'store'))) ?: 'store';
        $reference = 'mrc_' . substr($namespace, 0, 16) . '_order_' . (int) ($context['order']['id'] ?? 0);
        $response = ($this->request)('commit', ['calculation' => $calculation, 'reference' => substr($reference, 0, 200)], (string) ($context['idempotency_key'] ?? ''));
        $this->assertResponse($response, 'tax_');
        return ['status' => 'committed', 'provider_reference' => (string) ($response['id'] ?? ''), 'calculation_reference' => $calculation];
    }

    public function refund(array $context): array {
        $transaction = $this->transactionReference($context);
        $currency = (string) ($context['order']['currency'] ?? $context['quote']['currency'] ?? '');
        $amount = max(0.0, (float) ($context['amount'] ?? 0));
        if ($transaction === '' || $amount <= 0) throw new \InvalidArgumentException('Stripe Tax refund requires a committed transaction and positive amount.');
        $orderTotal = max(0.0, (float) ($context['order']['total'] ?? 0));
        $full = $orderTotal > 0 && $amount >= $orderTotal - (0.5 / (10 ** MercatoCurrency::decimalPlaces($currency)));
        $namespace = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($context['order']['store_namespace'] ?? 'store'))) ?: 'store';
        $payload = ['mode' => $full ? 'full' : 'partial', 'original_transaction' => $transaction, 'reference' => 'mrc_' . substr($namespace, 0, 16) . '_refund_' . substr(hash('sha256', (string) ($context['refund_id'] ?? $context['idempotency_key'] ?? '')), 0, 32)];
        if (!$full) $payload['flat_amount'] = -MercatoCurrency::minorUnitAmount($amount, $currency);
        $response = ($this->request)('reverse', $payload, (string) ($context['idempotency_key'] ?? ''));
        $this->assertResponse($response, 'tax_');
        if (($response['object'] ?? 'tax.transaction') !== 'tax.transaction'
            || ($response['type'] ?? 'reversal') !== 'reversal'
            || (string) ($response['reversal']['original_transaction'] ?? $transaction) !== $transaction) {
            throw new MercatoTaxProviderException('Stripe Tax returned a malformed reversal transaction.', false, 502);
        }
        return ['status' => 'refunded', 'provider_reference' => (string) ($response['id'] ?? ''), 'original_transaction' => $transaction, 'amount' => $amount];
    }

    public function void(array $context): array {
        $transaction = $this->transactionReference($context);
        if ($transaction !== '') throw new MercatoTaxProviderException('A committed Stripe Tax transaction cannot be voided without a confirmed payment refund.', false, 409);
        return ['status' => 'voided', 'provider_reference' => '', 'remote_action' => 'not_required'];
    }

    private function normalizeCalculation(array $response, array $context): array {
        $currency = MercatoCurrency::normalizeCode((string) ($response['currency'] ?? $context['currency'] ?? ''));
        $factor = 10 ** MercatoCurrency::decimalPlaces($currency);
        $lineItems = (array) (($response['line_items']['data'] ?? $response['line_items']) ?: []);
        $lines = []; $taxableMinor = 0.0;
        foreach ($lineItems as $line) {
            if (!is_array($line)) continue;
            $breakdown = (array) ($line['tax_breakdown'] ?? []);
            $lineAmount = (float) ($line['amount'] ?? 0);
            $lineTax = (float) ($line['amount_tax'] ?? 0);
            $taxable = max(0.0, $lineAmount - ((string) ($line['tax_behavior'] ?? '') === 'inclusive' ? $lineTax : 0.0));
            $taxableMinor += $taxable;
            $rate = $taxable > 0 ? round($lineTax / $taxable * 100, 6) : 0.0;
            $lines[] = [
                'line_id' => (string) ($line['reference'] ?? ''), 'tax_code' => (string) ($line['tax_code'] ?? ''),
                'taxable_amount' => round($taxable / $factor, MercatoCurrency::decimalPlaces($currency)),
                'tax' => round($lineTax / $factor, MercatoCurrency::decimalPlaces($currency)),
                'rate' => $rate, 'jurisdiction' => trim((string) ($context['destination']['country'] ?? '') . '-' . (string) ($context['destination']['region'] ?? ''), '-'),
            ];
        }
        $breakdown = (array) ($response['tax_breakdown'] ?? []);
        $jurisdictions = [];
        foreach ($breakdown as $part) {
            if (!is_array($part)) continue;
            $rate = (array) ($part['tax_rate_details'] ?? []);
            $jurisdictions[] = [
                'country' => (string) ($rate['country'] ?? ''), 'region' => (string) ($rate['state'] ?? ''),
                'name' => (string) ($rate['display_name'] ?? ''), 'type' => (string) ($rate['tax_type'] ?? ''),
                'rate' => (float) ($rate['percentage_decimal'] ?? 0), 'tax' => round((float) ($part['amount'] ?? 0) / $factor, MercatoCurrency::decimalPlaces($currency)),
            ];
        }
        $totalTaxMinor = (float) ($response['tax_amount_exclusive'] ?? 0) + (float) ($response['tax_amount_inclusive'] ?? 0);
        $shipping = (array) ($response['shipping_cost'] ?? []);
        $shippingTaxableMinor = max(0.0, (float) ($shipping['amount'] ?? 0) - ((string) ($shipping['tax_behavior'] ?? '') === 'inclusive' ? (float) ($shipping['amount_tax'] ?? 0) : 0.0));
        $taxableMinor += $shippingTaxableMinor;
        return [
            'provider' => $this->getTaxProviderKey(), 'currency' => $currency, 'tax_behavior' => (string) $context['tax_behavior'],
            'total_tax' => round($totalTaxMinor / $factor, MercatoCurrency::decimalPlaces($currency)),
            'taxable_amount' => round($taxableMinor / $factor, MercatoCurrency::decimalPlaces($currency)), 'exempt_amount' => 0.0,
            'lines' => $lines, 'jurisdictions' => $jurisdictions,
            'shipping' => ['taxable_amount' => round($shippingTaxableMinor / $factor, MercatoCurrency::decimalPlaces($currency)), 'tax' => round((float) ($shipping['amount_tax'] ?? 0) / $factor, MercatoCurrency::decimalPlaces($currency)), 'rate' => $shippingTaxableMinor > 0 ? round((float) ($shipping['amount_tax'] ?? 0) / $shippingTaxableMinor * 100, 6) : 0.0, 'jurisdiction' => ''],
            'provider_reference' => (string) ($response['id'] ?? ''), 'idempotency_key' => (string) ($context['idempotency_key'] ?? ''),
            'rule_version' => 'stripe-tax-api', 'tax_date' => (string) ($context['tax_date'] ?? date('Y-m-d')),
        ];
    }

    private function zeroQuote(array $context): array {
        return ['provider' => $this->getTaxProviderKey(), 'currency' => (string) $context['currency'], 'tax_behavior' => 'none', 'total_tax' => 0.0, 'taxable_amount' => 0.0, 'exempt_amount' => array_sum(array_column((array) ($context['items'] ?? []), 'line_total')), 'lines' => [], 'provider_reference' => '', 'idempotency_key' => (string) ($context['idempotency_key'] ?? '')];
    }

    private function transactionReference(array $context): string {
        return trim((string) ($context['transaction']['provider_reference'] ?? ''));
    }

    private function stripeTaxCode(string $value): string {
        $value = trim($value);
        return preg_match('/^txcd_[0-9]{8}$/', $value) ? $value : '';
    }

    private function assertResponse(array $response, string $idPrefix): void {
        $id = (string) ($response['id'] ?? '');
        if ($id === '' || !str_starts_with($id, $idPrefix)) throw new MercatoTaxProviderException('Stripe Tax returned a malformed object reference.', false, 502);
        if ($this->expectedLivemode !== null && array_key_exists('livemode', $response) && (bool) $response['livemode'] !== $this->expectedLivemode) throw new MercatoTaxProviderException('Stripe Tax response mode does not match the configured key.', false, 502);
    }

    private function containsReason(array $response, string $reason): bool {
        foreach ((array) ($response['tax_breakdown'] ?? []) as $part) if (is_array($part) && (string) ($part['taxability_reason'] ?? '') === $reason) return true;
        foreach ((array) ($response['line_items']['data'] ?? []) as $line) foreach ((array) ($line['tax_breakdown'] ?? []) as $part) if (is_array($part) && (string) ($part['taxability_reason'] ?? '') === $reason) return true;
        return false;
    }

    private function expectsRegistration(array $context): bool {
        $country = strtoupper((string) ($context['destination']['country'] ?? '')); $region = strtoupper((string) ($context['destination']['region'] ?? ''));
        foreach ((array) ($context['nexus_regions'] ?? []) as $nexus) if (in_array(strtoupper((string) $nexus), [$country, trim($country . '-' . $region, '-')], true)) return true;
        foreach ((array) ($context['registrations'] ?? []) as $registration) if (is_array($registration) && strtoupper((string) ($registration['country'] ?? '')) === $country && (empty($registration['region']) || strtoupper((string) $registration['region']) === $region)) return true;
        return false;
    }
}
