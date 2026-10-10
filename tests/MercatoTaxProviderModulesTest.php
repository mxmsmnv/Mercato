<?php
declare(strict_types=1);

namespace ProcessWire {
    if (!class_exists(WireException::class)) { class WireException extends \RuntimeException {} }
    require_once __DIR__ . '/../src/Pricing/MercatoCurrency.php';
    require_once __DIR__ . '/../src/Tax/MercatoTaxProviderInterface.php';
    require_once __DIR__ . '/../src/Tax/MercatoTaxProviderException.php';
    require_once __DIR__ . '/../src/Tax/Provider/MercatoStripeTaxProvider.php';
    require_once __DIR__ . '/../src/Tax/Provider/MercatoQuadernoTaxProvider.php';

    $checks = 0;
    $expect = static function(bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) throw new \RuntimeException($message); };

    foreach (['MercatoStripeTax.module.php', 'MercatoQuadernoTax.module.php'] as $moduleFile) {
        $source = (string) file_get_contents(__DIR__ . '/../' . $moduleFile);
        $expect(strpos($source, 'MercatoTaxProviderInterface.php') < strpos($source, '/Provider/'), $moduleFile . ' does not bootstrap the provider interface before its adapter.');
        $expect(str_contains($source, "'test_api_base_url' => ''"), $moduleFile . ' does not default its test transport to disabled.');
        $expect(str_contains($source, '127\\.0\\.0\\.1') && str_contains($source, '\\[::1\\]') && str_contains($source, 'localhost'), $moduleFile . ' test transport is not constrained to loopback hosts.');
        $expect(str_contains($source, 'loopback transport is forbidden in production mode'), $moduleFile . ' does not fail closed when a test transport reaches production mode.');
    }

    $base = [
        'currency' => 'USD', 'tax_behavior' => 'excluded', 'display_mode' => 'excluded', 'tax_date' => '2026-10-10', 'idempotency_key' => 'tax_est_fixture',
        'destination' => ['address' => '1 Test Street', 'city' => 'New York', 'region' => 'NY', 'postal_code' => '10001', 'country' => 'US'],
        'ship_from' => ['country' => 'US', 'postal_code' => '90049'], 'customer' => ['tax_exempt' => false],
        'items' => [
            ['line_id' => 'a', 'line_total' => 60.0, 'quantity' => 1, 'tax_code' => 'general', 'product_type' => 'physical'],
            ['line_id' => 'b', 'line_total' => 40.0, 'quantity' => 1, 'tax_code' => 'digital', 'product_type' => 'digital'],
        ],
        'discount' => ['amount' => 10.0, 'allocations' => ['a' => 6.0, 'b' => 4.0], 'shipping_discount' => 0.0],
        'shipping' => ['amount' => 10.0, 'taxable' => true],
    ];

    $stripeCalls = [];
    $stripeTransport = static function(string $operation, array $payload, string $key) use (&$stripeCalls): array {
        $stripeCalls[] = compact('operation', 'payload', 'key');
        if ($operation === 'calculate') return [
            'id' => 'taxcalc_fixture', 'currency' => 'usd', 'tax_amount_exclusive' => 800, 'tax_amount_inclusive' => 0,
            'line_items' => ['data' => [
                ['reference' => 'a', 'tax_code' => 'txcd_99999999', 'amount' => 5400, 'amount_tax' => 500, 'tax_behavior' => 'exclusive'],
                ['reference' => 'b', 'amount' => 3600, 'amount_tax' => 300, 'tax_behavior' => 'exclusive'],
            ]],
            'tax_breakdown' => [
                ['amount' => 500, 'taxable_amount' => 9000, 'tax_rate_details' => ['country' => 'US', 'state' => 'NY', 'tax_type' => 'sales_tax', 'percentage_decimal' => '5.555556']],
                ['amount' => 300, 'taxable_amount' => 9000, 'tax_rate_details' => ['country' => 'US', 'state' => 'NY', 'tax_type' => 'sales_tax', 'percentage_decimal' => '3.333333']],
            ],
            'shipping_cost' => ['amount' => 1000, 'amount_tax' => 0, 'tax_behavior' => 'exclusive'],
        ];
        if ($operation === 'commit') return ['id' => 'tax_fixture'];
        return ['id' => 'tax_reversal_fixture', 'object' => 'tax.transaction', 'type' => 'reversal', 'reversal' => ['original_transaction' => (string) ($payload['original_transaction'] ?? '')]];
    };
    $stripe = new MercatoStripeTaxProvider($stripeTransport, ['tax_code_map' => ['general' => 'txcd_99999999'], 'shipping_tax_code' => 'txcd_92010001']);
    $stripeQuote = $stripe->estimate($base);
    $expect($stripeCalls[0]['payload']['line_items'][0]['amount'] === 5400 && $stripeCalls[0]['payload']['line_items'][1]['amount'] === 3600, 'Stripe line discount allocation/minor units failed.');
    $expect($stripeCalls[0]['payload']['line_items'][0]['tax_code'] === 'txcd_99999999' && !isset($stripeCalls[0]['payload']['line_items'][1]['tax_code']), 'Stripe tax-code mapping/default omission failed.');
    $expect($stripeCalls[0]['payload']['shipping_cost']['amount'] === 1000 && $stripeQuote['total_tax'] === 8.0 && $stripeQuote['taxable_amount'] === 100.0, 'Stripe shipping, multi-jurisdiction taxable base, or response amount normalization failed.');
    $expect($stripeQuote['jurisdictions'][0]['country'] === 'US' && abs($stripeQuote['lines'][0]['rate'] - 9.259259) < 0.000001, 'Stripe real-shape jurisdiction or effective line rate mapping failed.');
    $commit = $stripe->commit(['quote' => $stripeQuote, 'order' => ['id' => 7, 'invoice' => 'INV-7'], 'idempotency_key' => 'commit-7']);
    $expect($commit['provider_reference'] === 'tax_fixture', 'Stripe commit mapping failed.');
    $stripe->refund(['transaction' => $commit, 'quote' => $stripeQuote, 'order' => ['currency' => 'USD', 'total' => 100], 'amount' => 25, 'refund_id' => 're_1', 'idempotency_key' => 'refund-1']);
    $expect(end($stripeCalls)['payload']['mode'] === 'partial' && end($stripeCalls)['payload']['flat_amount'] === -2500, 'Stripe partial reversal mapping failed.');
    $beforeVoid = count($stripeCalls); $stripe->void(['transaction' => [], 'idempotency_key' => 'void-1']);
    $expect(count($stripeCalls) === $beforeVoid, 'Uncommitted Stripe calculation void performed a remote mutation.');
    $blockedVoid = false; try { $stripe->void(['transaction' => $commit, 'idempotency_key' => 'void-committed']); } catch (MercatoTaxProviderException $e) { $blockedVoid = $e->getCode() === 409; }
    $expect($blockedVoid, 'Committed Stripe Tax transaction was voided without a confirmed payment refund.');
    $exemptContext = $base; $exemptContext['customer'] = ['tax_exempt' => true]; $stripe->estimate($exemptContext);
    $expect(end($stripeCalls)['payload']['customer_details']['taxability_override'] === 'customer_exempt', 'Stripe customer exemption enum is invalid.');
    $reverseChargeContext = $base; $reverseChargeContext['customer'] = ['taxability_override' => 'reverse_charge', 'tax_number' => 'EU123', 'tax_number_type' => 'eu_vat', 'tax_number_validated' => true]; $stripe->estimate($reverseChargeContext);
    $expect(end($stripeCalls)['payload']['customer_details']['taxability_override'] === 'reverse_charge', 'Stripe reverse-charge enum was collapsed into customer exemption.');
    $nonTaxShippingContext = $base; $nonTaxShippingContext['shipping']['taxable'] = false; $stripe->estimate($nonTaxShippingContext);
    $expect(end($stripeCalls)['payload']['shipping_cost']['amount'] === 1000, 'Stripe did not receive paid shipping for provider-side taxability determination.');

    $quadernoCalls = [];
    $quadernoTransport = static function(string $operation, array $payload, string $key) use (&$quadernoCalls): array {
        $quadernoCalls[] = compact('operation', 'payload', 'key');
        if ($operation === 'calculate') return ['status' => 'taxable', 'country' => 'US', 'region' => 'NY', 'name' => 'Sales tax', 'tax_code' => (string) $payload['tax_code'], 'rate' => 10, 'taxable_part' => 100, 'subtotal' => (float) $payload['amount'], 'total_amount' => round((float) $payload['amount'] * 1.1, 2), 'tax_amount' => round((float) $payload['amount'] * 0.1, 2), 'additional_tax_amount' => 0];
        if ($operation === 'transaction') return ['id' => str_starts_with((string) ($payload['type'] ?? ''), 'refund') ? 'cr_1' : 'in_1', 'type' => ($payload['type'] ?? '') === 'refund' ? 'credit' : 'invoice', 'number' => 'Q-1'];
        return ['id' => 'in_1'];
    };
    $quaderno = new MercatoQuadernoTaxProvider($quadernoTransport, ['tax_code_map' => ['general' => 'standard', 'digital' => 'eservice'], 'shipping_tax_code' => 'standard']);
    $quadernoQuote = $quaderno->estimate($base);
    $expect(count($quadernoCalls) === 3 && $quadernoCalls[0]['payload']['amount'] === '54.00' && $quadernoCalls[1]['payload']['product_type'] === 'service', 'Quaderno line classification/allocation failed.');
    $expect(abs($quadernoQuote['total_tax'] - 10.0) < 0.001, 'Quaderno total mapping failed.');
    $qCommit = $quaderno->commit(['quote' => $quadernoQuote, 'order' => ['id' => 8, 'invoice' => 'INV-8', 'currency' => 'USD', 'total' => 110, 'created_at' => '2026-10-10T12:00:00Z', 'customer' => [], 'shipping_address' => [], 'payment' => ['method' => 'stripe-card', 'processor_id' => 'pi_test']], 'idempotency_key' => 'commit-8']);
    $commitCall = end($quadernoCalls);
    $expect($qCommit['provider_reference'] === 'in_1' && $qCommit['document_type'] === 'invoice' && count($commitCall['payload']['items']) === 3 && $commitCall['payload']['payment']['method'] === 'credit_card', 'Quaderno commit, shipping, or payment mapping failed.');
    $blocked = false; try { $quaderno->refund(['transaction' => $qCommit, 'order' => ['currency' => 'USD', 'total' => 110], 'amount' => 25, 'refund_id' => 'partial']); } catch (MercatoTaxProviderException $e) { $blocked = $e->getCode() === 422; }
    $expect($blocked, 'Unsafe Quaderno partial refund was not blocked.');
    $qRefund = $quaderno->refund(['transaction' => $qCommit, 'quote' => $quadernoQuote, 'order' => ['currency' => 'USD', 'total' => 110, 'customer' => [], 'shipping_address' => [], 'payment' => ['method' => 'stripe-card', 'processor_id' => 'pi_test']], 'amount' => 110, 'refund_id' => 'full', 'idempotency_key' => 'refund-full']);
    $refundCall = end($quadernoCalls);
    $expect($qRefund['provider_reference'] === 'cr_1' && $refundCall['payload']['processor_id'] === $qCommit['processor_id'] && count($refundCall['payload']['items']) === 3, 'Quaderno full refund did not reuse the original processor identity and lines.');

    $nonTaxShippingQuote = $quadernoQuote;
    $nonTaxShippingQuote['shipping'] = ['taxable_amount' => 0.0, 'exempt_amount' => 10.0, 'tax' => 0.0, 'rate' => 0.0];
    $nonTaxShippingQuote['total_tax'] = 9.0;
    $nonTaxShipping = $quaderno->commit(['quote' => $nonTaxShippingQuote, 'order' => ['id' => 9, 'store_namespace' => 'shop-a', 'invoice' => 'INV-9', 'currency' => 'USD', 'total' => 109, 'created_at' => '2026-10-10T12:00:00Z', 'customer' => [], 'shipping_address' => [], 'payment' => ['method' => 'stripe-card', 'processor_id' => 'pi_test']], 'idempotency_key' => 'commit-9']);
    $nonTaxShippingCall = end($quadernoCalls);
    $expect($nonTaxShipping['provider_reference'] === 'in_1' && count($nonTaxShippingCall['payload']['items']) === 3 && $nonTaxShippingCall['payload']['processor_id'] === 'mercato-shopa-order-9' && !isset($nonTaxShippingCall['payload']['shipping_address']), 'Quaderno omitted exempt shipping, leaked an empty address, or used a non-namespaced processor ID.');

    echo "Mercato bundled tax provider contract tests passed: {$checks} assertions.\n";
}
