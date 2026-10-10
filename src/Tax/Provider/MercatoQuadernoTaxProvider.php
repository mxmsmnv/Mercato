<?php
namespace ProcessWire;

/** Quaderno stable-API adapter. Transport is injected so the contract is testable without network access. */
final class MercatoQuadernoTaxProvider implements MercatoTaxProviderInterface {
    /** @var callable(string,array,string):array */
    private $request;
    private array $taxCodeMap;
    private array $productTypeMap;
    private string $shippingTaxCode;

    public function __construct(callable $request, array $config = []) {
        $this->request = $request;
        $this->taxCodeMap = is_array($config['tax_code_map'] ?? null) ? $config['tax_code_map'] : [];
        $this->productTypeMap = is_array($config['product_type_map'] ?? null) ? $config['product_type_map'] : [];
        $this->shippingTaxCode = (string) ($config['shipping_tax_code'] ?? 'standard');
    }
    public function getTaxProviderKey(): string { return 'quaderno_tax'; }

    public function estimate(array $context): array {
        $currency = MercatoCurrency::normalizeCode((string) ($context['currency'] ?? ''));
        $behavior = (string) ($context['tax_behavior'] ?? 'included');
        $discount = (array) ($context['discount'] ?? []);
        $lines = []; $jurisdictions = []; $exemptions = []; $totalTax = 0.0; $taxable = 0.0; $exempt = 0.0;
        foreach ((array) ($context['items'] ?? []) as $item) {
            $lineId = (string) ($item['line_id'] ?? '');
            $amount = max(0.0, (float) ($item['line_total'] ?? 0) - (float) ($discount['allocations'][$lineId] ?? 0));
            $mappedTaxCode = $this->taxCode((string) ($item['tax_code'] ?? ''));
            $result = $this->calculate($context, $amount, $mappedTaxCode, $this->productType((string) ($item['product_type'] ?? 'physical')));
            $mapped = $this->mapCalculation($result, $amount, $currency, $lineId, $mappedTaxCode);
            $lines[] = $mapped['line']; $jurisdictions = array_merge($jurisdictions, $mapped['jurisdictions']); $exemptions = array_merge($exemptions, $mapped['exemptions']);
            $totalTax += $mapped['line']['tax']; $taxable += $mapped['line']['taxable_amount']; $exempt += $mapped['line']['exempt_amount'];
        }
        $shippingContext = (array) ($context['shipping'] ?? []); $shipping = ['taxable_amount' => 0.0, 'exempt_amount' => 0.0, 'tax' => 0.0, 'rate' => 0.0, 'jurisdiction' => ''];
        $shippingAmount = max(0.0, (float) ($shippingContext['amount'] ?? 0) - (float) ($discount['shipping_discount'] ?? 0));
        if (!empty($shippingContext['taxable']) && $shippingAmount > 0) {
            $result = $this->calculate($context, $shippingAmount, $this->shippingTaxCode, (string) ($shippingContext['product_type'] ?? 'good'));
            $mapped = $this->mapCalculation($result, $shippingAmount, $currency, 'shipping', $this->shippingTaxCode);
            $shipping = $mapped['line']; unset($shipping['line_id'], $shipping['tax_code']);
            $jurisdictions = array_merge($jurisdictions, $mapped['jurisdictions']); $exemptions = array_merge($exemptions, $mapped['exemptions']);
            $totalTax += $shipping['tax']; $taxable += $shipping['taxable_amount']; $exempt += $shipping['exempt_amount'];
        } elseif ($shippingAmount > 0) {
            $shipping = ['taxable_amount' => 0.0, 'exempt_amount' => $shippingAmount, 'tax' => 0.0, 'rate' => 0.0, 'jurisdiction' => '', 'components' => []];
            $exempt += $shippingAmount;
        }
        return [
            'provider' => $this->getTaxProviderKey(), 'currency' => $currency, 'tax_behavior' => $behavior,
            'total_tax' => $totalTax, 'taxable_amount' => $taxable, 'exempt_amount' => $exempt,
            'lines' => $lines, 'shipping' => $shipping, 'jurisdictions' => $jurisdictions, 'exemptions' => $exemptions,
            'provider_reference' => 'qdn_calc_' . substr(hash('sha256', (string) ($context['idempotency_key'] ?? '')), 0, 24),
            'idempotency_key' => (string) ($context['idempotency_key'] ?? ''), 'rule_version' => 'quaderno-20241028',
        ];
    }

    public function commit(array $context): array {
        $order = (array) ($context['order'] ?? []); $quote = (array) ($context['quote'] ?? []);
        $namespace = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($order['store_namespace'] ?? 'store'))) ?: 'store';
        $processorId = 'mercato-' . substr($namespace, 0, 16) . '-order-' . (int) ($order['id'] ?? 0);
        $items = $this->transactionItems($quote, $order); $this->assertTransactionTotal($items, (float) ($order['total'] ?? 0), (string) ($order['currency'] ?? ''));
        $payload = [
            'type' => 'sale', 'currency' => (string) ($order['currency'] ?? ''), 'date' => substr((string) ($order['created_at'] ?? date(DATE_ATOM)), 0, 10),
            'processor' => 'mercato', 'processor_id' => $processorId, 'po_number' => (string) ($order['invoice'] ?? ''),
            'customer' => $this->customer($order), 'items' => $items,
            'payment' => $this->payment((array) ($order['payment'] ?? [])),
            'evidence' => ['billing_country' => (string) ($order['billing_address']['country'] ?? '')],
            'custom_metadata' => ['mercato_idempotency_key' => (string) ($context['idempotency_key'] ?? '')],
        ];
        $shippingAddress = $this->address((array) ($order['shipping_address'] ?? [])); if ($shippingAddress !== []) $payload['shipping_address'] = $shippingAddress;
        $result = ($this->request)('transaction', $payload, (string) ($context['idempotency_key'] ?? ''));
        $this->assertDocument($result);
        return ['status' => 'committed', 'provider_reference' => (string) ($result['id'] ?? ''), 'document_type' => (string) ($result['type'] ?? 'invoice'), 'number' => (string) ($result['number'] ?? ''), 'processor' => 'mercato', 'processor_id' => $processorId];
    }

    public function refund(array $context): array {
        $order = (array) ($context['order'] ?? []); $amount = max(0.0, (float) ($context['amount'] ?? 0));
        if ($amount <= 0 || $amount + 0.0001 < (float) ($order['total'] ?? 0)) throw new MercatoTaxProviderException('Quaderno partial refunds require line-level allocation and are blocked to prevent an incorrect credit note.', false, 422);
        $quote = (array) ($context['quote'] ?? []); $transaction = (array) ($context['transaction'] ?? []);
        $processorId = trim((string) ($transaction['processor_id'] ?? ''));
        if ($processorId === '') throw new MercatoTaxProviderException('Quaderno refund requires the original sale processor reference.', false, 422);
        $items = $this->transactionItems($quote, $order); $this->assertTransactionTotal($items, (float) ($order['total'] ?? 0), (string) ($order['currency'] ?? ''));
        $payload = [
            'type' => 'refund', 'currency' => (string) ($order['currency'] ?? ''), 'date' => date('Y-m-d'),
            'processor' => (string) ($transaction['processor'] ?? 'mercato'), 'processor_id' => $processorId,
            'customer' => $this->customer($order), 'items' => $items, 'payment' => $this->payment((array) ($order['payment'] ?? [])),
            'evidence' => ['billing_country' => (string) ($order['billing_address']['country'] ?? '')],
            'custom_metadata' => ['mercato_idempotency_key' => (string) ($context['idempotency_key'] ?? ''), 'mercato_refund_id' => substr((string) ($context['refund_id'] ?? ''), 0, 200)],
        ];
        $shippingAddress = $this->address((array) ($order['shipping_address'] ?? [])); if ($shippingAddress !== []) $payload['shipping_address'] = $shippingAddress;
        $result = ($this->request)('transaction', $payload, (string) ($context['idempotency_key'] ?? ''));
        $this->assertDocument($result);
        return ['status' => 'refunded', 'provider_reference' => (string) ($result['id'] ?? ''), 'amount' => $amount, 'document_type' => (string) ($result['type'] ?? 'credit')];
    }

    public function void(array $context): array {
        $reference = trim((string) ($context['transaction']['provider_reference'] ?? ''));
        if ($reference === '') return ['status' => 'voided', 'provider_reference' => '', 'remote_action' => 'not_required'];
        $type = (string) ($context['transaction']['document_type'] ?? 'invoice');
        $reason = substr(trim((string) ($context['reason'] ?? '')), 0, 240); if ($reason === '') $reason = 'Mercato order void';
        $result = ($this->request)('void:' . $type . ':' . $reference, ['void_reason' => $reason], (string) ($context['idempotency_key'] ?? ''));
        $this->assertDocument($result);
        return ['status' => 'voided', 'provider_reference' => (string) ($result['id'] ?? $reference), 'document_type' => $type];
    }

    private function calculate(array $context, float $amount, string $taxCode, string $productType): array {
        $destination = (array) ($context['destination'] ?? []); $origin = (array) ($context['ship_from'] ?? []);
        if (empty($destination['country'])) throw new \InvalidArgumentException('Quaderno requires a destination country.');
        $params = array_filter([
            'from_country' => (string) ($origin['country'] ?? ''), 'from_postal_code' => (string) ($origin['postal_code'] ?? ''),
            'to_country' => (string) ($destination['country'] ?? ''), 'to_postal_code' => (string) ($destination['postal_code'] ?? ''),
            'to_city' => (string) ($destination['city'] ?? ''), 'to_street' => (string) ($destination['address'] ?? ''),
            'tax_id' => !empty($context['customer']['tax_number_format_valid']) ? (string) ($context['customer']['tax_number'] ?? '') : '',
            'tax_code' => $taxCode, 'tax_behavior' => (string) ($context['tax_behavior'] ?? 'included'), 'product_type' => $productType,
            'date' => (string) ($context['tax_date'] ?? date('Y-m-d')), 'amount' => MercatoCurrency::decimalAmount($amount, (string) $context['currency']),
            'currency' => (string) $context['currency'],
        ], static fn(string $value): bool => $value !== '');
        $result = ($this->request)('calculate', $params, (string) ($context['idempotency_key'] ?? ''));
        $this->validateCalculation($result, $context, $taxCode);
        return $result;
    }

    private function mapCalculation(array $result, float $amount, string $currency, string $lineId, string $taxCode): array {
        $status = strtolower((string) ($result['status'] ?? ''));
        if (!in_array($status, ['taxable', 'non_taxable', 'reverse_charge', 'not_registered', 'exempt'], true)) throw new MercatoTaxProviderException('Quaderno returned an unknown tax status.', false, 502);
        if ($status === 'not_registered') throw new MercatoTaxProviderException('Quaderno reports that the merchant is not registered for this jurisdiction.', false, 422);
        $tax = (float) ($result['tax_amount'] ?? 0) + (float) ($result['additional_tax_amount'] ?? 0);
        if (!is_finite($tax) || $tax < 0) throw new MercatoTaxProviderException('Quaderno returned an invalid tax amount.', false, 502);
        $isExempt = in_array($status, ['non_taxable', 'reverse_charge', 'exempt'], true);
        $subtotal = (float) ($result['subtotal'] ?? $amount); $taxablePart = max(0.0, min(100.0, (float) ($result['taxable_part'] ?? 100)));
        if (!is_finite($subtotal) || $subtotal < 0) throw new MercatoTaxProviderException('Quaderno returned an invalid subtotal.', false, 502);
        $taxableAmount = $isExempt ? 0.0 : $subtotal * $taxablePart / 100;
        $exemptAmount = max(0.0, $subtotal - $taxableAmount);
        $components = [['name' => (string) ($result['name'] ?? ''), 'rate' => (float) ($result['rate'] ?? 0), 'taxable_part' => $taxablePart, 'tax' => (float) ($result['tax_amount'] ?? 0), 'country' => (string) ($result['country'] ?? ''), 'region' => (string) ($result['region'] ?? ''), 'tax_code' => $taxCode]];
        if ((float) ($result['additional_tax_amount'] ?? 0) > 0 || (float) ($result['additional_rate'] ?? 0) > 0) $components[] = ['name' => (string) ($result['additional_name'] ?? ''), 'rate' => (float) ($result['additional_rate'] ?? 0), 'taxable_part' => max(0.0, min(100.0, (float) ($result['additional_taxable_part'] ?? 100))), 'tax' => (float) ($result['additional_tax_amount'] ?? 0), 'country' => (string) ($result['country'] ?? ''), 'region' => (string) ($result['region'] ?? ''), 'tax_code' => $taxCode];
        $line = ['line_id' => $lineId, 'tax_code' => $taxCode, 'taxable_amount' => $taxableAmount, 'exempt_amount' => $isExempt ? $subtotal : $exemptAmount, 'tax' => $tax, 'rate' => $taxableAmount > 0 ? $tax / $taxableAmount * 100 : 0.0, 'jurisdiction' => trim((string) ($result['country'] ?? '') . '-' . (string) ($result['region'] ?? ''), '-'), 'components' => $components];
        $jurisdictions = [['country' => (string) ($result['country'] ?? ''), 'region' => (string) ($result['region'] ?? ''), 'name' => (string) ($result['name'] ?? ''), 'type' => 'primary', 'rate' => (float) ($result['rate'] ?? 0), 'tax' => (float) ($result['tax_amount'] ?? 0)]];
        if ((float) ($result['additional_tax_amount'] ?? 0) > 0) $jurisdictions[] = ['country' => (string) ($result['country'] ?? ''), 'region' => (string) ($result['region'] ?? ''), 'name' => (string) ($result['additional_name'] ?? ''), 'type' => 'additional', 'rate' => (float) ($result['additional_rate'] ?? 0), 'tax' => (float) ($result['additional_tax_amount'] ?? 0)];
        return ['line' => $line, 'jurisdictions' => $jurisdictions, 'exemptions' => $isExempt ? [['type' => $status, 'code' => $taxCode, 'reason' => (string) ($result['reason'] ?? $status)]] : []];
    }

    private function taxCode(string $source): string {
        $value = (string) ($this->taxCodeMap[$source] ?? ($source === '' ? 'standard' : ''));
        if (!in_array($value, ['standard', 'reduced', 'exempt', 'eservice', 'ebook', 'saas', 'consulting'], true)) throw new \InvalidArgumentException('No valid Quaderno tax-code mapping exists for ' . $source . '.');
        return $value;
    }
    private function productType(string $source): string {
        $defaults = ['physical' => 'good', 'digital' => 'service', 'service' => 'service', 'recurring' => 'service'];
        if (!array_key_exists($source, $this->productTypeMap) && !array_key_exists($source, $defaults)) throw new \InvalidArgumentException('No valid Quaderno product type mapping exists for ' . $source . '.');
        $value = (string) ($this->productTypeMap[$source] ?? $defaults[$source]);
        if (!in_array($value, ['good', 'service'], true)) throw new \InvalidArgumentException('Invalid Quaderno product type mapping.');
        return $value;
    }

    private function transactionItems(array $quote, array $order): array {
        $currency = (string) ($quote['currency'] ?? $order['currency'] ?? '');
        $country = trim((string) ($order['shipping_address']['country'] ?? '')) ?: (string) ($order['billing_address']['country'] ?? '');
        $descriptions = [];
        foreach ((array) ($quote['input_snapshot']['items'] ?? []) as $item) if (is_array($item)) $descriptions[(string) ($item['line_id'] ?? '')] = $item;
        $items = [];
        foreach ((array) ($quote['lines'] ?? []) as $line) { $source = (array) ($descriptions[(string) ($line['line_id'] ?? '')] ?? []); $items[] = [
            'description' => substr((string) ($source['title'] ?? $line['line_id'] ?? 'Mercato item'), 0, 200), 'product_code' => substr((string) ($source['sku'] ?? ''), 0, 120), 'quantity' => 1,
            'amount' => MercatoCurrency::decimalAmount((float) ($line['taxable_amount'] ?? 0) + (float) ($line['exempt_amount'] ?? 0) + (float) ($line['tax'] ?? 0), $currency),
            'tax' => $this->transactionTax((array) $line, $country),
        ]; }
        $shipping = (array) ($quote['shipping'] ?? []);
        if ((float) ($shipping['taxable_amount'] ?? 0) > 0 || (float) ($shipping['exempt_amount'] ?? 0) > 0 || (float) ($shipping['tax'] ?? 0) > 0) $items[] = [
            'description' => 'Shipping', 'quantity' => 1,
            'amount' => MercatoCurrency::decimalAmount((float) ($shipping['taxable_amount'] ?? 0) + (float) ($shipping['exempt_amount'] ?? 0) + (float) ($shipping['tax'] ?? 0), $currency),
            'tax' => $this->transactionTax($shipping + ['tax_code' => $this->shippingTaxCode], $country),
        ];
        if ($items === []) throw new MercatoTaxProviderException('Quaderno transaction requires at least one tax line.', false, 422);
        return $items;
    }

    private function customer(array $order): array {
        $customer = (array) ($order['customer'] ?? []); $billing = (array) ($order['billing_address'] ?? []);
        return array_filter($customer + [
            'street_line_1' => (string) ($billing['street_line_1'] ?? $billing['address'] ?? ''),
            'street_line_2' => (string) ($billing['street_line_2'] ?? $billing['address_2'] ?? ''),
            'city' => (string) ($billing['city'] ?? ''), 'postal_code' => (string) ($billing['postal_code'] ?? $billing['zip'] ?? ''),
            'region' => (string) ($billing['region'] ?? ''), 'country' => (string) ($billing['country'] ?? ''),
            'tax_id' => (string) ($billing['tax_number'] ?? ''),
        ], static fn($value): bool => $value !== '' && $value !== null);
    }

    private function address(array $address): array {
        return array_filter([
            'street_line_1' => (string) ($address['street_line_1'] ?? $address['address'] ?? ''),
            'street_line_2' => (string) ($address['street_line_2'] ?? $address['address_2'] ?? ''),
            'city' => (string) ($address['city'] ?? ''), 'postal_code' => (string) ($address['postal_code'] ?? $address['zip'] ?? ''),
            'region' => (string) ($address['region'] ?? ''), 'country' => (string) ($address['country'] ?? ''),
        ], static fn(string $value): bool => $value !== '');
    }

    private function payment(array $payment): array {
        $source = strtolower(trim((string) ($payment['method'] ?? '')));
        $method = match ($source) {
            'stripe-card', 'mollie' => 'credit_card', 'paypal' => 'paypal', 'bank-transfer' => 'wire_transfer',
            default => 'other',
        };
        return ['method' => $method, 'processor' => $source !== '' ? $source : 'mercato', 'processor_id' => (string) ($payment['processor_id'] ?? '')];
    }

    private function transactionTax(array $line, string $fallbackCountry): array {
        $components = (array) ($line['components'] ?? []); $primary = (array) ($components[0] ?? []); $additional = (array) ($components[1] ?? []);
        $tax = ['name' => (string) ($primary['name'] ?? ''), 'rate' => (float) ($primary['rate'] ?? $line['rate'] ?? 0), 'taxable_part' => (float) ($primary['taxable_part'] ?? ((float) ($line['taxable_amount'] ?? 0) > 0 ? 100 : 0)), 'country' => (string) ($primary['country'] ?? $fallbackCountry), 'region' => (string) ($primary['region'] ?? ''), 'tax_code' => (string) ($primary['tax_code'] ?? $line['tax_code'] ?? '')];
        if ($additional !== []) $tax += ['additional_name' => (string) ($additional['name'] ?? ''), 'additional_rate' => (float) ($additional['rate'] ?? 0), 'additional_taxable_part' => (float) ($additional['taxable_part'] ?? 100)];
        return $tax;
    }

    private function assertDocument(array $result): void {
        if (trim((string) ($result['id'] ?? '')) === '') throw new MercatoTaxProviderException('Quaderno returned a transaction without a document id.', false, 502);
    }

    private function validateCalculation(array $result, array $context, string $taxCode): void {
        $status = strtolower((string) ($result['status'] ?? ''));
        if (!in_array($status, ['taxable', 'non_taxable', 'reverse_charge', 'not_registered', 'exempt'], true)) throw new MercatoTaxProviderException('Quaderno returned an unknown tax status.', false, 502);
        foreach (['subtotal', 'tax_amount', 'total_amount'] as $field) if (!array_key_exists($field, $result) || !is_numeric($result[$field]) || !is_finite((float) $result[$field]) || (float) $result[$field] < 0) throw new MercatoTaxProviderException('Quaderno calculation is missing a valid ' . $field . '.', false, 502);
        if ($status === 'taxable') foreach (['rate', 'taxable_part'] as $field) if (!array_key_exists($field, $result) || !is_numeric($result[$field]) || !is_finite((float) $result[$field])) throw new MercatoTaxProviderException('Quaderno taxable calculation is missing a valid ' . $field . '.', false, 502);
        if (array_key_exists('taxable_part', $result) && ((float) $result['taxable_part'] < 0 || (float) $result['taxable_part'] > 100)) throw new MercatoTaxProviderException('Quaderno returned an invalid taxable part.', false, 502);
        $checks = ['currency' => strtoupper((string) ($context['currency'] ?? '')), 'tax_code' => $taxCode, 'tax_behavior' => (string) ($context['tax_behavior'] ?? '')];
        foreach ($checks as $field => $expected) if (isset($result[$field]) && (string) $result[$field] !== '' && strtolower((string) $result[$field]) !== strtolower($expected)) throw new MercatoTaxProviderException('Quaderno calculation ' . $field . ' does not match the request.', false, 502);
        $country = strtoupper((string) ($context['destination']['country'] ?? '')); if (!empty($result['country']) && strtoupper((string) $result['country']) !== $country) throw new MercatoTaxProviderException('Quaderno calculation country does not match the destination.', false, 502);
    }

    private function assertTransactionTotal(array $items, float $expected, string $currency): void {
        $actual = array_sum(array_map(static fn(array $item): float => (float) ($item['amount'] ?? 0) * max(1, (float) ($item['quantity'] ?? 1)), $items));
        $tolerance = 1 / (10 ** MercatoCurrency::decimalPlaces($currency));
        if (abs($actual - $expected) > $tolerance + 1e-9) throw new MercatoTaxProviderException('Quaderno document total does not match the charged order total.', false, 422);
    }
}
