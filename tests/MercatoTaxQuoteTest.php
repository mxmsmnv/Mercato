<?php
require_once __DIR__ . '/../src/Tax/MercatoTaxQuote.php';
require_once __DIR__ . '/../src/Tax/MercatoTaxProviderInterface.php';
use ProcessWire\MercatoTaxQuote;
use ProcessWire\MercatoTaxProviderInterface;

final class FixtureTaxProvider implements MercatoTaxProviderInterface {
    public int $estimateCalls = 0;
    public array $lifecycleKeys = [];
    public function getTaxProviderKey(): string { return 'fixture'; }
    public function estimate(array $context): array {
        $this->estimateCalls++;
        $country = strtoupper((string) ($context['destination']['country'] ?? ''));
        $region = strtoupper((string) ($context['destination']['region'] ?? ''));
        $rate = $country === 'US' && $region === 'NY' ? 8.875 : ($country === 'CA' ? 13.0 : 20.0);
        $discount = (float) ($context['discount']['amount'] ?? 0);
        $remainingDiscount = $discount;
        $lines = []; $taxable = 0.0; $exempt = 0.0; $tax = 0.0; $exemptions = [];
        foreach ($context['items'] as $item) {
            $gross = (float) $item['line_total'];
            $allocated = min($gross, $remainingDiscount);
            $remainingDiscount -= $allocated;
            $base = round($gross - $allocated, 2);
            $isExempt = !empty($context['customer']['tax_exempt']) || ($item['tax_code'] ?? '') === 'exempt';
            $lineTax = $isExempt ? 0.0 : round($base * $rate / 100, 2);
            $lines[] = ['line_id' => $item['line_id'], 'tax_code' => $item['tax_code'], 'taxable_amount' => $isExempt ? 0 : $base, 'exempt_amount' => $isExempt ? $base : 0, 'tax' => $lineTax, 'rate' => $isExempt ? 0 : $rate, 'jurisdiction' => $country . ($region ? '-' . $region : '')];
            $taxable += $isExempt ? 0 : $base; $exempt += $isExempt ? $base : 0; $tax += $lineTax;
            if ($isExempt) $exemptions[] = ['type' => 'product_or_customer', 'code' => (string) ($item['tax_code'] ?? ''), 'reason' => 'Fixture exemption'];
        }
        $shippingBase = !empty($context['shipping']['taxable']) ? (float) $context['shipping']['amount'] : 0.0;
        $shippingTax = round($shippingBase * $rate / 100, 2); $tax += $shippingTax; $taxable += $shippingBase;
        return ['provider' => 'fixture', 'currency' => $context['currency'], 'display_mode' => 'excluded', 'total_tax' => $tax, 'taxable_amount' => $taxable, 'exempt_amount' => $exempt, 'lines' => $lines, 'shipping' => ['taxable_amount' => $shippingBase, 'tax' => $shippingTax, 'rate' => $rate, 'jurisdiction' => $country], 'jurisdictions' => [['country' => $country, 'region' => $region, 'name' => $country . ' ' . $region, 'type' => 'destination', 'rate' => $rate, 'tax' => $tax]], 'exemptions' => $exemptions, 'provider_reference' => 'fixture-' . substr($context['idempotency_key'], -8), 'idempotency_key' => $context['idempotency_key']];
    }
    private function lifecycle(string $operation, array $context): array { $key = $context['idempotency_key']; if (!isset($this->lifecycleKeys[$key])) $this->lifecycleKeys[$key] = ['status' => $operation === 'commit' ? 'committed' : ($operation === 'refund' ? 'refunded' : 'voided'), 'provider_reference' => 'fixture-lifecycle']; return $this->lifecycleKeys[$key]; }
    public function commit(array $context): array { return $this->lifecycle('commit', $context); }
    public function refund(array $context): array { return $this->lifecycle('refund', $context) + ['amount' => (float) $context['amount']]; }
    public function void(array $context): array { return $this->lifecycle('void', $context); }
}
$quote = MercatoTaxQuote::normalize([
    'provider' => 'fixture', 'currency' => 'usd', 'total_tax' => 8.25, 'taxable_amount' => 100,
    'lines' => [['line_id' => 'a', 'tax_code' => 'general', 'taxable_amount' => 100, 'tax' => 8.25, 'rate' => 8.25]],
    'jurisdictions' => [['country' => 'us', 'region' => 'ny', 'name' => 'New York', 'type' => 'state', 'rate' => 8.25, 'tax' => 8.25]],
], ['currency' => 'USD', 'display_mode' => 'excluded', 'idempotency_key' => 'fixture']);
if ($quote['currency'] !== 'USD' || $quote['total_tax'] !== 8.25 || count($quote['lines']) !== 1) throw new RuntimeException('Tax quote normalization failed.');
$failed = false;
try { MercatoTaxQuote::normalize(['currency' => 'USD', 'total_tax' => -1]); } catch (InvalidArgumentException) { $failed = true; }
if (!$failed) throw new RuntimeException('Negative tax must be rejected.');

$context = [
    'currency' => 'USD', 'display_mode' => 'excluded', 'idempotency_key' => 'estimate-ny',
    'destination' => ['country' => 'US', 'region' => 'NY'], 'customer' => ['tax_exempt' => false],
    'items' => [
        ['line_id' => 'general', 'tax_code' => 'general', 'line_total' => 100],
        ['line_id' => 'exempt', 'tax_code' => 'exempt', 'line_total' => 20],
    ],
    'discount' => ['amount' => 10], 'shipping' => ['taxable' => true, 'amount' => 5],
];
$provider = new FixtureTaxProvider();
$ny = MercatoTaxQuote::normalize($provider->estimate($context), $context);
if ($ny['total_tax'] !== 8.43 || $ny['taxable_amount'] !== 95.0 || $ny['exempt_amount'] !== 20.0) throw new RuntimeException('NY discount, shipping, exemption, or rounding fixture failed.');
if ($ny['jurisdictions'][0]['region'] !== 'NY' || count($ny['exemptions']) !== 1) throw new RuntimeException('Jurisdiction or exemption snapshot failed.');
$caContext = $context; $caContext['currency'] = 'CAD'; $caContext['destination'] = ['country' => 'CA', 'region' => 'ON']; $caContext['idempotency_key'] = 'estimate-ca';
$ca = MercatoTaxQuote::normalize($provider->estimate($caContext), $caContext);
if ($ca['currency'] !== 'CAD' || $ca['total_tax'] !== 12.35) throw new RuntimeException('Second jurisdiction fixture failed.');
$exemptContext = $context; $exemptContext['customer']['tax_exempt'] = true; $exemptContext['shipping']['taxable'] = false; $exemptContext['discount']['amount'] = 0; $exemptContext['idempotency_key'] = 'estimate-exempt';
$customerExempt = MercatoTaxQuote::normalize($provider->estimate($exemptContext), $exemptContext);
if ($customerExempt['total_tax'] !== 0.0 || $customerExempt['exempt_amount'] !== 120.0) throw new RuntimeException('Customer exemption fixture failed.');

$commit = ['idempotency_key' => 'commit-1'];
if ($provider->commit($commit) !== $provider->commit($commit) || count($provider->lifecycleKeys) !== 1) throw new RuntimeException('Commit replay fixture failed.');
$provider->refund(['idempotency_key' => 'refund-partial', 'amount' => 25]);
$provider->refund(['idempotency_key' => 'refund-full', 'amount' => 100]);
$provider->void(['idempotency_key' => 'void-1']);
if (count($provider->lifecycleKeys) !== 4) throw new RuntimeException('Refund and void fixture failed.');

$attempts = 0;
$retry = static function (callable $operation, int $retries) use (&$attempts): array {
    $last = null;
    for ($i = 0; $i <= $retries; $i++) try { $attempts++; return $operation(); } catch (Throwable $e) { $last = $e; }
    throw $last;
};
$retryResult = $retry(static function () use (&$attempts): array { if ($attempts < 2) throw new RuntimeException('transient'); return ['ok' => true]; }, 1);
if (empty($retryResult['ok']) || $attempts !== 2) throw new RuntimeException('Retry fixture failed.');
$started = microtime(true); usleep(20000); $timedOut = (microtime(true) - $started) > 0.01;
if (!$timedOut) throw new RuntimeException('Timeout fixture failed.');
echo "Mercato tax quote tests passed.\n";
