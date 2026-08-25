<?php
declare(strict_types=1);

use ProcessWire\Page;
use ProcessWire\ProcessWire;
use ProcessWire\WireException;

$action = $argv[1] ?? '';
$site = rtrim((string) getenv('MERCATO_E2E_SITE'), '/');
$stateFile = (string) getenv('MERCATO_E2E_STATE');
if (!in_array($action, ['setup', 'cleanup'], true) || $site === '' || $stateFile === '') {
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php fixtures.php setup|cleanup\n");
    exit(2);
}
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = 'localhost';
$wire = new ProcessWire($config);
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) {
    throw new WireException('Acceptance fixtures are forbidden when Mercato production mode is enabled.');
}
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for fixture management.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

function e2eDeleteFixtures(ProcessWire $wire, array $state): array {
    $deleted = ['orders' => 0, 'pages' => 0];
    $prefix = 'e2e-' . preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? ''))) . '-';
    if ($prefix !== 'e2e--') {
        foreach ($wire->pages->find('template=mrc-order, include=all, limit=1000') as $order) {
            $details = json_decode((string) $order->mrc_customer_details, true);
            $email = strtolower((string) ($details['email'] ?? $order->mrc_email ?? $order->mrc_customer_email ?? ''));
            if (!str_starts_with($email, $prefix)) continue;
            $order->of(false); $wire->pages->delete($order, true); $deleted['orders']++;
        }
    }
    foreach (array_reverse((array) ($state['created_page_ids'] ?? [])) as $id) {
        $page = $wire->pages->get((int) $id);
        if (!$page || !$page->id) continue;
        $expected = (array) ($state['created_page_names'] ?? []);
        if (!in_array((string) $page->name, $expected, true)) {
            throw new WireException("Refusing to delete unexpected fixture page {$page->id}.");
        }
        $page->of(false); $wire->pages->delete($page, true); $deleted['pages']++;
    }
    return $deleted;
}

if ($action === 'cleanup') {
    if (!is_file($stateFile)) { echo "No fixture state; nothing to clean.\n"; exit(0); }
    $state = json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
    $deleted = e2eDeleteFixtures($wire, $state);
    $stored = (array) $wire->modules->getConfig('Mercato');
    foreach ((array) ($state['original_config'] ?? []) as $key => $snapshot) {
        if (!empty($snapshot['exists'])) $stored[$key] = $snapshot['value']; else unset($stored[$key]);
    }
    $wire->modules->saveConfig('Mercato', $stored);
    echo json_encode(['cleaned' => true] + $deleted, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$productName = 'e2e-product-' . $runId;
$discountName = 'e2e-discount-' . $runId;
$keys = ['enabled_payment_methods', 'checkout_enabled', 'analytics_enabled', 'analytics_adapters', 'analytics_default_consent', 'customer_accounts_mode'];
$stored = (array) $wire->modules->getConfig('Mercato');
$original = [];
foreach ($keys as $key) $original[$key] = ['exists' => array_key_exists($key, $stored), 'value' => $stored[$key] ?? null];
$stored['enabled_payment_methods'] = ['demo'];
$stored['checkout_enabled'] = true;
$stored['analytics_enabled'] = true;
$stored['analytics_adapters'] = ['data_layer', 'first_party'];
$stored['analytics_default_consent'] = 'granted';
$stored['customer_accounts_mode'] = 'optional';
$wire->modules->saveConfig('Mercato', $stored);

$products = $wire->pages->get('/products/'); $discounts = $wire->pages->get('/discounts/');
if (!$products->id || !$discounts->id) throw new WireException('Install the Mercato demo storefront before running acceptance tests.');

$product = new Page(); $product->template = 'mrc-product'; $product->parent = $products; $product->name = $productName; $product->of(false);
$product->title = 'Acceptance Test Cup'; $product->mrc_price = 24.50; $product->mrc_tax_rate = 20; $product->mrc_tax_code = 'standard';
$product->mrc_shipping_price = 4.50; $product->mrc_sku = 'E2E-' . strtoupper(substr(hash('sha256', $runId), 0, 10));
$product->mrc_product_type = 'physical'; $product->mrc_product_status = 'active'; $product->mrc_stock = 50;
$product->mrc_low_stock_threshold = 5; $product->mrc_stock_policy = 'deny'; $product->mrc_description = 'Deterministic, disposable acceptance-test product.';
$wire->pages->save($product);

$discount = new Page(); $discount->template = 'mrc-discount'; $discount->parent = $discounts; $discount->name = $discountName; $discount->of(false);
$discount->title = 'Acceptance 10%'; $discount->mrc_discount_code = 'E2E' . strtoupper(substr(hash('sha256', $runId), 0, 8));
$discount->mrc_discount_active = 1; $discount->mrc_discount_type = 'percentage'; $discount->mrc_discount_percent = 10;
$discount->mrc_discount_amount = 0; $discount->mrc_discount_usage_limit = 0; $discount->mrc_discount_customer_limit = 0;
$discount->mrc_discount_minimum_order = 0; $discount->mrc_discount_notes = 'Disposable acceptance-test coupon.';
$discount->mrc_discount_products->add($product); $wire->pages->save($discount);

$state = [
    'schema_version' => 1, 'run_id' => $runId, 'created_at' => gmdate(DATE_ATOM),
    'product_id' => (int) $product->id, 'product_url' => (string) $product->url,
    'initial_stock' => 50, 'coupon' => (string) $discount->mrc_discount_code,
    'created_page_ids' => [(int) $product->id, (int) $discount->id],
    'created_page_names' => [$productName, $discountName], 'original_config' => $original,
];
if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) throw new WireException('Could not write fixture state.');
echo json_encode(['ready' => true, 'run_id' => $runId, 'product_id' => $product->id], JSON_UNESCAPED_SLASHES) . "\n";
