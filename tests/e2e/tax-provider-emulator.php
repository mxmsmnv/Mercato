<?php
declare(strict_types=1);

$logFile = (string) getenv('MERCATO_TAX_EMULATOR_LOG');
if ($logFile === '') { http_response_code(500); echo '{"error":"missing emulator log"}'; return; }
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$headers = function_exists('getallheaders') ? getallheaders() : [];
$body = (string) file_get_contents('php://input');
$payload = $method === 'GET' ? $_GET : [];
if ($method !== 'GET') {
    $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
    if (str_contains($contentType, 'json')) $payload = json_decode($body, true) ?: [];
    else parse_str($body, $payload);
}
$event = ['at' => gmdate(DATE_ATOM), 'method' => $method, 'path' => $path, 'idempotency_key' => (string) ($headers['Idempotency-Key'] ?? $headers['idempotency-key'] ?? $headers['X-Mercato-Idempotency-Key'] ?? $headers['x-mercato-idempotency-key'] ?? ''), 'payload' => $payload];
$handle = fopen($logFile, 'ab');
if (!$handle) { http_response_code(500); echo '{"error":"cannot write emulator log"}'; return; }
flock($handle, LOCK_EX); fwrite($handle, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n"); fflush($handle); flock($handle, LOCK_UN); fclose($handle);
header('Content-Type: application/json');

if ($path === '/health') { echo '{"ok":true}'; return; }
if ($path === '/v1/tax/calculations' && $method === 'POST') {
    $lines = []; $tax = 0; $taxable = 0;
    foreach ((array) ($payload['line_items'] ?? []) as $line) {
        $amount = max(0, (int) ($line['amount'] ?? 0)); $lineTax = (int) round($amount * 0.10); $tax += $lineTax; $taxable += $amount;
        $lines[] = ['reference' => (string) ($line['reference'] ?? ''), 'tax_code' => (string) ($line['tax_code'] ?? ''), 'amount' => $amount, 'amount_tax' => $lineTax, 'tax_behavior' => (string) ($line['tax_behavior'] ?? 'exclusive'), 'tax_breakdown' => [['amount' => $lineTax, 'taxability_reason' => 'standard_rated']]];
    }
    $shipping = (array) ($payload['shipping_cost'] ?? []); $shippingAmount = max(0, (int) ($shipping['amount'] ?? 0)); $shippingTax = (int) round($shippingAmount * 0.10); $tax += $shippingTax; $taxable += $shippingAmount;
    echo json_encode(['id' => 'taxcalc_e2e_' . substr(hash('sha256', $event['idempotency_key']), 0, 12), 'object' => 'tax.calculation', 'livemode' => false, 'currency' => (string) ($payload['currency'] ?? 'usd'), 'tax_amount_exclusive' => $tax, 'tax_amount_inclusive' => 0, 'line_items' => ['data' => $lines], 'shipping_cost' => ['amount' => $shippingAmount, 'amount_tax' => $shippingTax, 'tax_behavior' => (string) ($shipping['tax_behavior'] ?? 'exclusive')], 'tax_breakdown' => [['amount' => $tax, 'taxability_reason' => 'standard_rated', 'tax_rate_details' => ['country' => 'US', 'state' => 'NY', 'display_name' => 'E2E Sales Tax', 'tax_type' => 'sales_tax', 'percentage_decimal' => '10.0']]], 'expires_at' => time() + 3600], JSON_UNESCAPED_SLASHES); return;
}
if ($path === '/v1/tax/transactions/create_from_calculation' && $method === 'POST') {
    echo json_encode(['id' => 'tax_e2e_' . substr(hash('sha256', $event['idempotency_key']), 0, 12), 'object' => 'tax.transaction', 'type' => 'transaction', 'livemode' => false]); return;
}
if ($path === '/v1/tax/transactions/create_reversal' && $method === 'POST') {
    echo json_encode(['id' => 'tax_e2e_reversal_' . substr(hash('sha256', $event['idempotency_key']), 0, 12), 'object' => 'tax.transaction', 'type' => 'reversal', 'livemode' => false, 'reversal' => ['original_transaction' => (string) ($payload['original_transaction'] ?? '')]]); return;
}
if ($path === '/api/tax_rates/calculate' && $method === 'GET') {
    $amount = max(0.0, (float) ($payload['amount'] ?? 0)); $tax = round($amount * 0.10, 2);
    echo json_encode(['status' => 'taxable', 'country' => strtoupper((string) ($payload['to_country'] ?? 'US')), 'region' => 'NY', 'name' => 'E2E Sales Tax', 'tax_code' => (string) ($payload['tax_code'] ?? 'standard'), 'tax_behavior' => (string) ($payload['tax_behavior'] ?? 'excluded'), 'currency' => strtoupper((string) ($payload['currency'] ?? 'USD')), 'rate' => 10, 'taxable_part' => 100, 'subtotal' => $amount, 'tax_amount' => $tax, 'additional_tax_amount' => 0, 'total_amount' => round($amount + $tax, 2)], JSON_UNESCAPED_SLASHES); return;
}
if ($path === '/api/transactions' && $method === 'POST') {
    $refund = (string) ($payload['type'] ?? '') === 'refund';
    echo json_encode(['id' => ($refund ? 'cr_e2e_' : 'in_e2e_') . substr(hash('sha256', $event['idempotency_key']), 0, 12), 'type' => $refund ? 'credit' : 'invoice', 'number' => 'E2E-' . substr(hash('sha256', $event['idempotency_key']), 0, 8)]); return;
}
if (preg_match('#^/api/(?:invoices|receipts)/[^/]+/void$#', $path) && $method === 'PUT') { echo json_encode(['id' => basename(dirname($path)), 'type' => str_contains($path, '/receipts/') ? 'receipt' : 'invoice']); return; }

http_response_code(404); echo json_encode(['error' => 'unhandled emulator route', 'path' => $path, 'method' => $method]);
