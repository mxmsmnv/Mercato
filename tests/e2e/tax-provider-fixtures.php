<?php
declare(strict_types=1);

use ProcessWire\Page;
use ProcessWire\ProcessWire;
use ProcessWire\WireException;

$action = $argv[1] ?? '';
$site = rtrim((string) getenv('MERCATO_E2E_SITE'), '/');
$stateFile = (string) getenv('MERCATO_E2E_STATE');
$baseUrl = rtrim((string) getenv('MERCATO_TAX_EMULATOR_URL'), '/');
$logFile = (string) getenv('MERCATO_TAX_EMULATOR_LOG');
$actions = ['setup', 'activate-stripe', 'capture-stripe', 'activate-quaderno', 'capture-quaderno', 'refund-stripe', 'refund-quaderno', 'verify', 'cleanup'];
if (!in_array($action, $actions, true) || $site === '' || $stateFile === '') { fwrite(STDERR, 'Invalid tax-provider fixture invocation.' . PHP_EOL); exit(2); }

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1'; $wire = new ProcessWire($config);
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Tax-provider E2E fixtures are forbidden in production mode.');
$superuser = $wire->users->get('template=user, roles.name=superuser'); if (!$superuser || !$superuser->id) throw new WireException('A superuser is required.');
$wire->users->setCurrentUser($superuser); $wire->set('page', $wire->pages->get('/'));
foreach (['MercatoStripeTax', 'MercatoQuadernoTax'] as $moduleName) if (!$wire->modules->isInstalled($moduleName)) throw new WireException("{$moduleName} must be installed for E2E.");

$write = static function(array $state) use ($stateFile): void { if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new WireException('Cannot write tax E2E state.'); };
$load = static function() use ($stateFile): array { if (!is_file($stateFile)) throw new WireException('Tax E2E state is missing.'); return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR); };
$restoreConfig = static function(string $module, array $snapshot) use ($wire): void { $wire->modules->saveConfig($module, $snapshot); };
$cleanup = static function(array $state) use ($wire, $restoreConfig): array {
    $deleted = 0; $run = (string) ($state['run_id'] ?? '');
    foreach ((array) ($state['order_ids'] ?? []) as $id) { $page = $wire->pages->get((int) $id); if (!$page->id) continue; if (!str_contains((string) $page->mrc_email, $run)) throw new WireException('Refusing to delete an unrelated tax E2E order.'); $page->of(false); $wire->pages->delete($page, true); $deleted++; }
    foreach ((array) ($state['products'] ?? []) as $row) { $page = $wire->pages->get((int) ($row['id'] ?? 0)); if (!$page->id) continue; if ((string) $page->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $page->name, 'e2e-tax-')) throw new WireException('Refusing to delete an unrelated tax E2E product.'); $page->of(false); $wire->pages->delete($page, true); }
    foreach ((array) ($state['original_module_config'] ?? []) as $module => $snapshot) $restoreConfig((string) $module, (array) $snapshot);
    return ['orders' => $deleted, 'products' => count((array) ($state['products'] ?? []))];
};

if ($action === 'cleanup') { if (!is_file($stateFile)) { echo "No tax E2E state; nothing to clean.\n"; exit(0); } echo json_encode(['cleaned' => true] + $cleanup($load()), JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(0); }

if ($action === 'setup') {
    if ($baseUrl === '' || !preg_match('#^http://127\.0\.0\.1:[1-9][0-9]{0,4}$#', $baseUrl)) throw new WireException('A loopback emulator URL is required.');
    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $state = ['schema_version' => 1, 'run_id' => $run, 'order_ids' => [], 'products' => [], 'original_module_config' => []];
    foreach (['Mercato', 'MercatoStripeTax', 'MercatoQuadernoTax'] as $module) $state['original_module_config'][$module] = (array) $wire->modules->getConfig($module);
    $write($state);
    $stored = $state['original_module_config']['Mercato'];
    $stored['enabled_payment_methods'] = ['demo']; $stored['checkout_enabled'] = true; $stored['customer_accounts_mode'] = 'optional'; $stored['enabled_notification_events'] = []; $stored['notification_sender_email'] = ''; $stored['analytics_enabled'] = false;
    $stored['tax_price_behavior'] = 'excluded'; $stored['tax_display_mode'] = 'excluded'; $stored['tax_provider_failure_policy'] = 'fail_closed'; $stored['tax_provider_timeout_seconds'] = 3; $stored['tax_provider_retries'] = 0; $stored['tax_shipping'] = false;
    $wire->modules->saveConfig('Mercato', $stored);
    $wire->modules->saveConfig('MercatoStripeTax', ['enabled' => false, 'secret_key' => 'sk_test_mercato_e2e_only', 'tax_code_map_json' => '{"general":"txcd_99999999"}', 'shipping_tax_code' => 'txcd_92010001', 'test_api_base_url' => $baseUrl]);
    $wire->modules->saveConfig('MercatoQuadernoTax', ['enabled' => false, 'environment' => 'sandbox', 'account_slug' => 'mercato-e2e', 'sandbox_api_key' => 'sandbox-e2e-only', 'live_api_key' => '', 'tax_code_map_json' => '{"general":"standard"}', 'product_type_map_json' => '{"physical":"good","digital":"service","service":"service","recurring":"service"}', 'shipping_tax_code' => 'standard', 'test_api_base_url' => $baseUrl]);
    $products = $wire->pages->get('/products/'); if (!$products->id) throw new WireException('Mercato demo storefront is required.');
    foreach (['stripe' => 20.0, 'quaderno' => 30.0] as $provider => $price) { $product = new Page(); $product->template = 'mrc-product'; $product->parent = $products; $product->name = "e2e-tax-{$provider}-{$run}"; $product->of(false); $product->title = ucfirst($provider) . ' Tax E2E Product'; $product->mrc_price = $price; $product->mrc_tax_rate = 0; $product->mrc_tax_code = 'general'; $product->mrc_sku = 'TAX-' . strtoupper($provider) . '-' . substr(hash('sha256', $run), 0, 8); $product->mrc_product_type = 'physical'; $product->mrc_product_status = 'active'; $product->mrc_stock = 5; $product->mrc_stock_policy = 'deny'; $product->mrc_shipping_price = 0; $wire->pages->save($product); $state['products'][] = ['id' => (int) $product->id, 'name' => (string) $product->name]; $state[$provider . '_product_url'] = (string) $product->url; $state[$provider . '_product_id'] = (int) $product->id; $state[$provider . '_email'] = "e2e-tax-{$run}-{$provider}@example.test"; $write($state); }
    $state['emulator_log'] = $logFile; $write($state); echo json_encode(['ready' => true, 'run_id' => $run], JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(0);
}

$state = $load();
if (str_starts_with($action, 'activate-')) {
    $provider = substr($action, strlen('activate-')); $module = $provider === 'stripe' ? 'MercatoStripeTax' : 'MercatoQuadernoTax'; $other = $provider === 'stripe' ? 'MercatoQuadernoTax' : 'MercatoStripeTax';
    $active = (array) $wire->modules->getConfig($module); $active['enabled'] = true; $wire->modules->saveConfig($module, $active); $inactive = (array) $wire->modules->getConfig($other); $inactive['enabled'] = false; $wire->modules->saveConfig($other, $inactive);
    $mercato = (array) $wire->modules->getConfig('Mercato'); $mercato['tax_provider'] = $provider === 'stripe' ? 'stripe_tax' : 'quaderno_tax'; $wire->modules->saveConfig('Mercato', $mercato);
    $state['active_provider'] = $provider; $write($state); echo json_encode(['active' => $provider]) . PHP_EOL; exit(0);
}

if (str_starts_with($action, 'capture-')) {
    $provider = substr($action, strlen('capture-')); $email = (string) $state[$provider . '_email']; $safe = $wire->sanitizer->selectorValue($email); $orders = $wire->pages->find('template=' . $wire->sanitizer->selectorValue((string) $commerce->order_template) . ", include=all, mrc_email={$safe}, limit=2");
    if ($orders->count() !== 1) throw new WireException("Expected one {$provider} browser order, found {$orders->count()}."); $order = $orders->first(); $details = json_decode((string) $order->mrc_tax_details, true);
    $expectedProvider = $provider === 'stripe' ? 'stripe_tax' : 'quaderno_tax';
    $product = $wire->pages->getById((int) $state[$provider . '_product_id'], ['cache' => false])->first();
    if ((string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1 || (int) $order->mrc_inventory_adjusted !== 1 || (int) $product->mrc_stock !== 4 || (string) ($details['quote']['provider'] ?? '') !== $expectedProvider || (string) ($details['commit']['status'] ?? '') !== 'committed' || (float) $order->mrc_tax_amount <= 0) throw new WireException("{$provider} tax lifecycle or inventory adjustment was not persisted.");
    $statusUrl = $commerce->getOrderStatusUrl($order); $query = (string) parse_url($statusUrl, PHP_URL_QUERY);
    $state['order_ids'][] = (int) $order->id; $state[$provider . '_order_id'] = (int) $order->id; $state[$provider . '_invoice'] = (string) $order->mrc_invoice_number; $state[$provider . '_total'] = (float) $order->mrc_total_amount; $state[$provider . '_tax'] = (float) $order->mrc_tax_amount; $state[$provider . '_status_url'] = (string) parse_url($statusUrl, PHP_URL_PATH) . ($query !== '' ? '?' . $query : ''); $write($state);
    echo json_encode(['captured' => $provider, 'order_id' => (int) $order->id, 'tax' => (float) $order->mrc_tax_amount, 'total' => (float) $order->mrc_total_amount]) . PHP_EOL; exit(0);
}

if (str_starts_with($action, 'refund-')) {
    $provider = substr($action, strlen('refund-')); $order = $wire->pages->get((int) $state[$provider . '_order_id']); $commerce->refundPayment($order, (float) $order->mrc_total_amount, 'Tax provider E2E full refund', 'tax-e2e');
    $state[$provider . '_refunded_at'] = gmdate(DATE_ATOM); $write($state); echo json_encode(['refunded' => $provider]) . PHP_EOL; exit(0);
}

if ($action === 'verify') {
    if (empty($state['stripe_refunded_at']) || empty($state['quaderno_refunded_at'])) throw new WireException('Tax E2E refunds were not executed.');
    foreach (['stripe', 'quaderno'] as $provider) { $order = $wire->pages->getById((int) $state[$provider . '_order_id'], ['cache' => false])->first(); $product = $wire->pages->getById((int) $state[$provider . '_product_id'], ['cache' => false])->first(); $details = json_decode((string) $order->mrc_tax_details, true); if ((string) $order->mrc_payment_status !== 'refunded' || (int) $order->mrc_payment_complete !== 0 || (int) $product->mrc_stock !== 5 || count((array) ($details['refunds'] ?? [])) !== 1 || (string) ($details['refunds'][0]['status'] ?? '') !== 'refunded') throw new WireException("{$provider} refund lifecycle or inventory restoration is incomplete."); }
    $events = []; foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) $events[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $paths = array_count_values(array_column($events, 'path')); foreach (['/v1/tax/calculations', '/v1/tax/transactions/create_from_calculation', '/v1/tax/transactions/create_reversal', '/api/tax_rates/calculate', '/api/transactions'] as $path) if (empty($paths[$path])) throw new WireException("Missing emulator request {$path}.");
    foreach ($events as $event) if ($event['path'] !== '/health' && trim((string) ($event['idempotency_key'] ?? '')) === '') throw new WireException('Provider request omitted its idempotency key.');
    echo json_encode(['verified' => true, 'orders' => $state['order_ids'], 'emulator_events' => count($events), 'paths' => $paths], JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(0);
}

throw new WireException('Unhandled tax-provider fixture action.');
