<?php
declare(strict_types=1);

use ProcessWire\Page;
use ProcessWire\ProcessWire;
use ProcessWire\User;
use ProcessWire\WireException;

$action = $argv[1] ?? '';
$site = rtrim((string) getenv('MERCATO_E2E_SITE'), '/');
$stateFile = (string) getenv('MERCATO_E2E_STATE');
if (!in_array($action, ['setup', 'capture', 'verify', 'cleanup'], true) || $site === '' || $stateFile === '') {
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php authenticated-variant-fixtures.php setup|capture|verify|cleanup\n");
    exit(2);
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1'; $wire = new ProcessWire($config);
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Authenticated variant fixtures are forbidden in production mode.');
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for fixture management.');
$wire->users->setCurrentUser($superuser); $wire->set('page', $wire->pages->get('/'));

$writeState = static function (array $state) use ($stateFile): void {
    if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) throw new WireException('Could not persist authenticated variant fixture state.');
};
$loadState = static function () use ($stateFile): array {
    if (!is_file($stateFile)) throw new WireException('Authenticated variant fixture state is missing.');
    return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
};
$cleanup = static function (array $state) use ($wire): array {
    $deleted = ['orders' => 0, 'users' => 0, 'products' => 0];
    $runId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? '')));
    foreach ((array) ($state['order_ids'] ?? []) as $id) {
        $order = $wire->pages->get((int) $id); if (!$order || !$order->id) continue;
        if ($runId === '' || !str_contains(strtolower((string) $order->mrc_email), $runId)) throw new WireException("Refusing to delete unexpected authenticated variant order {$order->id}.");
        $order->of(false); $wire->pages->delete($order, true); $deleted['orders']++;
    }
    $user = $wire->users->get((int) ($state['user_id'] ?? 0));
    if ($user && $user->id) {
        if ((string) $user->name !== (string) ($state['username'] ?? '') || !str_starts_with((string) $user->email, 'e2e-' . $runId . '-')) throw new WireException("Refusing to delete unexpected authenticated variant user {$user->id}.");
        $wire->users->delete($user); $deleted['users']++;
    }
    $product = $wire->pages->get((int) ($state['product_id'] ?? 0));
    if ($product && $product->id) {
        if ((string) $product->name !== (string) ($state['product_name'] ?? '') || !str_starts_with((string) $product->name, 'e2e-auth-variant-')) throw new WireException("Refusing to delete unexpected authenticated variant product {$product->id}.");
        $product->of(false); $wire->pages->delete($product, true); $deleted['products']++;
    }
    $stored = (array) $wire->modules->getConfig('Mercato');
    foreach ((array) ($state['original_config'] ?? []) as $key => $snapshot) { if (!empty($snapshot['exists'])) $stored[$key] = $snapshot['value']; else unset($stored[$key]); }
    $wire->modules->saveConfig('Mercato', $stored);
    return $deleted;
};

if ($action === 'cleanup') {
    if (!is_file($stateFile) || filesize($stateFile) === 0) { echo "No authenticated variant fixture state; nothing to clean.\n"; exit(0); }
    echo json_encode(['cleaned' => true] + $cleanup($loadState()), JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

if ($action !== 'setup') {
    $state = $loadState(); $safeEmail = $wire->sanitizer->selectorValue((string) $state['email']);
    $orders = $wire->pages->find('template=' . $wire->sanitizer->selectorValue((string) $commerce->order_template) . ", include=all, mrc_email={$safeEmail}, limit=2");
    if ($orders->count() !== 1) throw new WireException('Expected exactly one authenticated variant order; found ' . $orders->count() . '.');
    $order = $orders->first();
    if ($action === 'capture') {
        $state['order_ids'] = [(int) $order->id]; $state['order_id'] = (int) $order->id; $state['invoice'] = (string) $order->mrc_invoice_number; $writeState($state);
        echo json_encode(['captured' => true, 'order_id' => (int) $order->id, 'invoice' => $state['invoice']], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
    }
    if ((string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1) throw new WireException('Authenticated variant order is not paid.');
    if ((int) $order->mrc_customer_user_id !== (int) $state['user_id']) throw new WireException('Authenticated checkout did not bind the order to its signed-in customer.');
    if ((int) $order->mrc_inventory_adjusted !== 1) throw new WireException('Inventory adjustment was not finalized exactly once.');
    $items = json_decode((string) $order->mrc_items, true, 512, JSON_THROW_ON_ERROR);
    if (count($items) !== 1) throw new WireException('Authenticated variant order did not preserve exactly one line.');
    $item = $items[0];
    if ((int) ($item['product_id'] ?? 0) !== (int) $state['product_id'] || (string) ($item['variant_id'] ?? '') !== (string) $state['variant_id']) throw new WireException('Order line lost its exact product or variant identity.');
    if ((string) ($item['sku'] ?? '') !== (string) $state['variant_sku'] || (float) ($item['price'] ?? 0) !== (float) $state['variant_price'] || (int) ($item['quantity'] ?? 0) !== 2) throw new WireException('Order line lost the selected variant SKU, price, or quantity snapshot.');
    $itemOptions = (array) ($item['variant_options'] ?? []); $expectedOptions = (array) $state['variant_options']; ksort($itemOptions); ksort($expectedOptions);
    if ($itemOptions !== $expectedOptions) throw new WireException('Order line lost its exact variant option snapshot.');
    $product = $wire->pages->getById((int) $state['product_id'], ['cache' => false])->first(); $variant = $commerce->variantService()->resolve($product, (string) $state['variant_id']);
    if (!$variant || (int) $variant['stock'] !== (int) $state['initial_variant_stock'] - 2) throw new WireException('Selected variant stock was not decremented exactly once by the purchased quantity.');
    $user = $wire->users->get((int) $state['user_id']); if (!$user->id || (int) $user->mrc_customer_verified !== 1) throw new WireException('Verified customer account regressed.');
    $suspicious = [];
    foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) { $path = rtrim((string) $wire->config->paths->logs, '/') . '/' . basename((string) $name); if (!is_file($path) || filesize($path) <= (int) $offset) continue; $handle = fopen($path, 'rb'); if (!$handle) continue; fseek($handle, (int) $offset); $appended = (string) stream_get_contents($handle); fclose($handle); foreach (preg_split('/\R/', $appended) ?: [] as $line) if (str_contains($line, (string) $state['run_id']) && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspicious[] = basename($path) . ': ' . substr($line, 0, 500); }
    if ($suspicious) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspicious));
    echo json_encode(['verified' => true, 'order_id' => (int) $order->id, 'user_id' => (int) $user->id, 'variant_id' => $variant['id'], 'variant_stock' => (int) $variant['stock'], 'quantity' => 2, 'suspicious_logs' => 0], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)); $password = 'E2E-Variant-42!'; $email = "e2e-{$runId}-variant@example.test";
$stored = (array) $wire->modules->getConfig('Mercato'); $keys = ['enabled_payment_methods', 'checkout_enabled', 'customer_accounts_mode', 'enabled_notification_events', 'notification_sender_email', 'analytics_enabled']; $original = [];
foreach ($keys as $key) $original[$key] = ['exists' => array_key_exists($key, $stored), 'value' => $stored[$key] ?? null];
$stored['enabled_payment_methods'] = ['demo']; $stored['checkout_enabled'] = true; $stored['customer_accounts_mode'] = 'optional'; $stored['enabled_notification_events'] = []; $stored['notification_sender_email'] = ''; $stored['analytics_enabled'] = false; $wire->modules->saveConfig('Mercato', $stored);
foreach (['enabled_payment_methods' => ['demo'], 'checkout_enabled' => true, 'customer_accounts_mode' => 'optional', 'enabled_notification_events' => [], 'notification_sender_email' => '', 'analytics_enabled' => false] as $key => $value) $commerce->set($key, $value);
$products = $wire->pages->get('/products/'); if (!$products->id) throw new WireException('Install the Mercato demo storefront before authenticated variant acceptance.');
$product = new Page(); $product->template = 'mrc-product'; $product->parent = $products; $product->name = 'e2e-auth-variant-' . $runId; $product->of(false); $product->title = 'Authenticated Variant Fixture'; $product->mrc_price = 20; $product->mrc_tax_rate = 0; $product->mrc_shipping_price = 0; $product->mrc_sku = 'AUTH-' . strtoupper(substr(hash('sha256', $runId), 0, 10)); $product->mrc_product_type = 'physical'; $product->mrc_product_status = 'active'; $product->mrc_stock = 12; $product->mrc_stock_policy = 'deny'; $wire->pages->save($product);
$options = [['id' => 'size', 'label' => 'Size', 'values' => [['id' => 'small', 'label' => 'Small'], ['id' => 'large', 'label' => 'Large']]], ['id' => 'finish', 'label' => 'Finish', 'values' => [['id' => 'natural', 'label' => 'Natural'], ['id' => 'charcoal', 'label' => 'Charcoal']]]];
$variantId = 'large-charcoal'; $variantSku = 'AUTH-VAR-' . strtoupper(substr(hash('sha256', $runId . '-variant'), 0, 10)); $variantPrice = 37.5; $initialStock = 6; $variantOptions = ['size' => 'large', 'finish' => 'charcoal'];
$commerce->variantService()->saveDefinition($product, $options, [['id' => 'small-natural', 'options' => ['size' => 'small', 'finish' => 'natural'], 'sku' => $product->mrc_sku . '-SN', 'price' => 20, 'stock' => 4, 'stock_policy' => 'deny', 'status' => 'active'], ['id' => $variantId, 'options' => $variantOptions, 'sku' => $variantSku, 'price' => $variantPrice, 'stock' => $initialStock, 'stock_policy' => 'deny', 'status' => 'active']]);
$role = $wire->roles->get('mercato-customer'); if (!$role || !$role->id) throw new WireException('The Mercato customer role is missing.');
$username = 'e2e-auth-variant-' . substr(hash('sha256', $runId), 0, 12); $user = new User(); $user->of(false); $user->name = $username; $user->email = $email; $user->pass = $password; $user->addRole($role); $user->mrc_customer_verified = 1; $user->mrc_first_name = 'Variant'; $user->mrc_last_name = 'Customer'; $wire->users->save($user);
$state = ['schema_version' => 1, 'run_id' => $runId, 'created_at' => gmdate(DATE_ATOM), 'email' => $email, 'password' => $password, 'username' => $username, 'user_id' => (int) $user->id, 'product_id' => (int) $product->id, 'product_name' => (string) $product->name, 'product_url' => (string) $product->url, 'variant_id' => $variantId, 'variant_sku' => $variantSku, 'variant_price' => $variantPrice, 'variant_options' => $variantOptions, 'initial_variant_stock' => $initialStock, 'order_ids' => [], 'original_config' => $original, 'log_offsets' => []];
foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) $state['log_offsets'][basename($path)] = (int) filesize($path); $writeState($state);
echo json_encode(['ready' => true, 'run_id' => $runId, 'user_id' => (int) $user->id, 'product_id' => (int) $product->id, 'variant_id' => $variantId], JSON_UNESCAPED_SLASHES) . "\n";
