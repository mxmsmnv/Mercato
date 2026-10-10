<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) {
    echo "Mercato tax integration test skipped (set MERCATO_TEST_SITE).\n";
    exit(0);
}

$_SERVER['HTTP_HOST'] = 'mercato.test';
$_SERVER['SERVER_NAME'] = 'mercato.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser'));
$wire->set('page', $wire->pages->get('/'));
/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
$originalTaxConfig = [
    'tax_provider' => $commerce->tax_provider,
    'tax_provider_retries' => $commerce->tax_provider_retries,
    'tax_provider_timeout_seconds' => $commerce->tax_provider_timeout_seconds,
    'tax_provider_failure_policy' => $commerce->tax_provider_failure_policy,
    'tax_display_mode' => $commerce->tax_display_mode,
    'tax_price_behavior' => $commerce->tax_price_behavior,
    'tax_rounding_mode' => $commerce->tax_rounding_mode,
];
$order = null;
$voidOrder = null;
$ambiguousOrder = null;
$taxLogPath = rtrim((string) $wire->config->paths->logs, '/') . '/mercato-tax.txt';
$taxLogExisted = is_file($taxLogPath);
$taxLogBefore = $taxLogExisted ? (string) file_get_contents($taxLogPath) : '';
register_shutdown_function(static function () use ($wire, $commerce, $originalTaxConfig, &$order, &$voidOrder, &$ambiguousOrder, $taxLogPath, $taxLogExisted, $taxLogBefore): void {
    foreach ([$order, $voidOrder, $ambiguousOrder] as $fixture) {
        if (!$fixture instanceof Page || !$fixture->id) continue;
        $fresh = $wire->pages->get((int) $fixture->id);
        if ($fresh->id) $wire->pages->delete($fresh, true);
    }
    if ($taxLogExisted) file_put_contents($taxLogPath, $taxLogBefore, LOCK_EX);
    elseif (is_file($taxLogPath)) unlink($taxLogPath);
    $commerce->setArray($originalTaxConfig);
});

final class MercatoIntegrationTaxProvider implements MercatoTaxProviderInterface {
    public int $estimateCalls = 0;
    public int $commitCalls = 0;
    public int $refundCalls = 0;
    public int $voidCalls = 0;
    public bool $failFirst = false;
    public bool $slow = false;
    public bool $programmerError = false;
    public bool $ambiguousCommit = false;
    public array $lastContext = [];
    public function getTaxProviderKey(): string { return 'integration-fixture'; }
    public function estimate(array $context): array {
        $this->estimateCalls++;
        $this->lastContext = $context;
        if ($this->programmerError) throw new \TypeError('Fixture tax contract defect.');
        if ($this->failFirst && $this->estimateCalls === 1) throw new WireException('Fixture transient failure.');
        if ($this->slow) usleep(1100000);
        $base = max(0, (float) $context['items'][0]['line_total'] - (float) $context['discount']['amount']);
        $tax = round(($base + (float) $context['shipping']['amount']) * 0.1, 2);
        return ['currency' => $context['currency'], 'display_mode' => 'excluded', 'total_tax' => $tax, 'taxable_amount' => $base + (float) $context['shipping']['amount'], 'lines' => [['line_id' => $context['items'][0]['line_id'], 'tax_code' => $context['items'][0]['tax_code'], 'taxable_amount' => $base, 'tax' => round($base * 0.1, 2), 'rate' => 10, 'jurisdiction' => 'US-NY']], 'shipping' => ['taxable_amount' => (float) $context['shipping']['amount'], 'tax' => round((float) $context['shipping']['amount'] * 0.1, 2), 'rate' => 10, 'jurisdiction' => 'US-NY'], 'jurisdictions' => [['country' => 'US', 'region' => 'NY', 'name' => 'New York fixture', 'type' => 'state', 'rate' => 10, 'tax' => $tax]], 'provider_reference' => 'fixture-estimate', 'idempotency_key' => $context['idempotency_key']];
    }
    public function commit(array $context): array { $this->commitCalls++; if ($this->ambiguousCommit) throw new MercatoTaxProviderException('Fixture response lost after commit.', true, 502, null, true); return ['status' => 'committed', 'provider_reference' => 'fixture-commit']; }
    public function refund(array $context): array { $this->refundCalls++; return ['status' => 'refunded', 'provider_reference' => 'fixture-refund', 'amount' => $context['amount']]; }
    public function void(array $context): array { $this->voidCalls++; return ['status' => 'voided', 'provider_reference' => 'fixture-void']; }
}

$expect = static function (bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); };
$provider = new MercatoIntegrationTaxProvider();
$commerce->addHookAfter('taxProviders', static function (HookEvent $event) use ($provider): void {
    $providers = is_array($event->return) ? $event->return : [];
    $providers[$provider->getTaxProviderKey()] = $provider;
    $event->return = $providers;
});
$commerce->tax_provider = $provider->getTaxProviderKey();
$commerce->tax_provider_retries = 1;
$commerce->tax_provider_timeout_seconds = 1;
$commerce->tax_provider_failure_policy = 'fail_closed';
$commerce->tax_display_mode = 'excluded';
$commerce->tax_price_behavior = 'excluded';
$cart = $commerce->productList([['id' => 'tax-fixture', 'product_id' => 999999, 'title' => 'Tax fixture', 'price' => 100, 'quantity' => 1, 'tax_rate' => 20, 'tax_code' => 'general', 'shipping_price' => 0, 'template' => 'fixture', 'uid' => 'tax-fixture']]);
$customer = ['email' => 'tax-fixture@example.test', 'address' => '1 Test Street', 'city' => 'New York', 'zip' => '10001', 'country' => 'US', 'region' => 'NY'];
$shipping = ['type' => 'carrier_delivery', 'amount' => 5];
$provider->failFirst = true;
$quote = $commerce->taxService()->estimate($cart, $customer, $shipping, ['code' => 'TEN', 'amount' => 10]);
$expect($provider->estimateCalls === 2 && $quote['total_tax'] === 9.5, 'Provider retry or final-address estimate failed.');
$expect($quote['input_snapshot']['destination']['region'] === 'NY', 'Final destination snapshot was not stored.');
$commerce->taxService()->estimate($cart, $customer + ['tax_exempt' => true, 'tax_number' => 'DE1234', 'tax_number_type' => 'eu_vat', 'tax_number_validated' => true], $shipping);
$expect(empty($provider->lastContext['customer']['tax_exempt']) && empty($provider->lastContext['customer']['tax_number_validated']), 'Untrusted checkout input forged a tax exemption or validated Tax ID.');

$pending = ['first_name' => 'Tax', 'last_name' => 'Fixture', 'email' => 'tax-fixture@example.test', 'mrc_currency' => 'USD', 'mrc_items' => json_encode($cart->toArray()), 'mrc_subtotal_amount' => 100, 'mrc_shipping_amount' => 5, 'mrc_tax_amount' => 9.5, 'mrc_tax_details' => json_encode(['quote' => $quote]), 'mrc_tax_provider_reference' => $quote['provider_reference'], 'mrc_tax_committed' => 0, 'mrc_total_amount' => 104.5, 'payment_status' => Mercato::PAYMENT_STATUS_PENDING, 'payment_complete' => 0];
$order = $commerce->orderRepository()->savePendingOrder($pending);
$commerce->taxService()->commit($order);
$commerce->taxService()->commit($order);
$expect($provider->commitCalls === 1, 'Tax commit replay was not idempotent.');
$commerce->taxService()->refund($order, 25, 'partial-1');
$commerce->taxService()->refund($order, 25, 'partial-1');
$commerce->taxService()->refund($order, 79.5, 'full-1');
$expect($provider->refundCalls === 2, 'Partial/full refund replay was not idempotent.');

$voidOrder = $commerce->orderRepository()->savePendingOrder($pending + ['checkout_nonce' => bin2hex(random_bytes(8))]);
$commerce->taxService()->void($voidOrder, 'fixture failure');
$commerce->taxService()->void($voidOrder, 'fixture failure');
$expect($provider->voidCalls === 1, 'Tax void replay was not idempotent.');

$ambiguousOrder = $commerce->orderRepository()->savePendingOrder($pending + ['checkout_nonce' => bin2hex(random_bytes(8))]);
$provider->ambiguousCommit = true;
$ambiguousFailed = false;
try { $commerce->taxService()->commit($ambiguousOrder); } catch (MercatoTaxProviderException $e) { $ambiguousFailed = $e->ambiguous; }
$storedAmbiguous = json_decode((string) $wire->pages->get((int) $ambiguousOrder->id)->mrc_tax_details, true);
$expect($ambiguousFailed && ($storedAmbiguous['commit']['status'] ?? '') === 'ambiguous', 'Ambiguous tax commit was not persisted for recovery.');
$provider->ambiguousCommit = false;
$recoveredCommit = $commerce->taxService()->commit($ambiguousOrder);
$expect(($recoveredCommit['status'] ?? '') === 'committed', 'Ambiguous tax commit could not be safely replayed with its stable idempotency key.');

// An unavailable external provider must preserve excluded-tax economics when
// the configured policy falls back to the manual provider.
$commerce->tax_provider = 'unavailable-provider';
$commerce->tax_provider_failure_policy = 'manual_fallback';
$fallbackQuote = $commerce->taxService()->estimate($cart, $customer, $shipping);
$expect($fallbackQuote['provider'] === 'manual', 'Manual fallback did not report its effective provider.');
$expect($fallbackQuote['requested_provider'] === 'unavailable-provider' && !empty($fallbackQuote['fallback']), 'Manual fallback audit metadata was not preserved.');
$expect($fallbackQuote['tax_behavior'] === 'excluded' && !empty($fallbackQuote['tax_added_to_total']), 'Manual fallback lost excluded-tax economics.');
$expect((float) $fallbackQuote['total_tax'] > 0, 'Manual fallback silently returned zero tax.');

// Aggregate rounding must distribute minor units without ever making a line
// negative, even when every raw line tax is just over half a cent.
$commerce->tax_rounding_mode = 'total';
$roundingContext = $provider->lastContext;
$roundingContext['items'] = [];
for ($i = 0; $i < 5; $i++) $roundingContext['items'][] = ['line_id' => 'fraction-' . $i, 'line_total' => 0.051, 'tax_rate' => 10, 'tax_code' => 'general'];
$roundingContext['shipping'] = ['amount' => 0, 'taxable' => false, 'tax_rate' => 0];
$roundingContext['discount'] = ['allocations' => [], 'shipping_discount' => 0];
$roundingContext['customer'] = ['taxability_override' => 'none'];
$roundingContext['idempotency_key'] = 'rounding-fixture';
$roundingQuote = (new MercatoManualTaxProvider($commerce))->estimate($roundingContext);
$expect(abs((float) $roundingQuote['total_tax'] - 0.03) < 0.000001 && min(array_column($roundingQuote['lines'], 'tax')) >= 0, 'Aggregate tax rounding produced a negative line or wrong total.');

$roundingContext['customer']['taxability_override'] = 'reverse_charge';
$exemptQuote = (new MercatoManualTaxProvider($commerce))->estimate($roundingContext);
$expect((float) $exemptQuote['total_tax'] === 0.0 && (float) $exemptQuote['taxable_amount'] === 0.0 && (float) $exemptQuote['exempt_amount'] > 0, 'Manual provider ignored trusted reverse-charge exemption.');

$commerce->tax_provider = $provider->getTaxProviderKey();
$commerce->tax_provider_failure_policy = 'fail_closed';

$provider->slow = true; $provider->failFirst = false;
$timedOut = false;
try { $commerce->taxService()->estimate($cart, $customer, $shipping); } catch (WireException $e) { $timedOut = str_contains($e->getMessage(), 'timed out'); }
$expect($timedOut, 'Configured timeout outcome was not enforced.');

$provider->slow = false;
$provider->programmerError = true;
$beforeProgrammerError = $provider->estimateCalls;
$programmerFailure = false;
try { $commerce->taxService()->estimate($cart, $customer, $shipping); } catch (WireException $e) { $programmerFailure = str_contains($e->getMessage(), 'contract defect'); }
$expect($programmerFailure && $provider->estimateCalls === $beforeProgrammerError + 1, 'Deterministic tax contract defect was retried.');

echo 'Mercato tax integration tests passed; fixture orders ' . $order->id . ' and ' . $voidOrder->id . ".\n";
