<?php
declare(strict_types=1);

use ProcessWire\Page;
use ProcessWire\ProcessWire;
use ProcessWire\User;
use ProcessWire\WireException;

$action = $argv[1] ?? '';
$site = rtrim((string) getenv('MERCATO_E2E_SITE'), '/');
$stateFile = (string) getenv('MERCATO_E2E_STATE');
if (!in_array($action, ['setup', 'verify', 'cleanup'], true) || $site === '' || $stateFile === '') {
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php refund-fixtures.php setup|verify|cleanup\n");
    exit(2);
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Refund fixtures are forbidden in production mode.');
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for refund fixture management.');
$wire->users->setCurrentUser($superuser); $wire->set('page', $wire->pages->get('/'));

$writeState = static function (array $state) use ($stateFile): void {
    if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) throw new WireException('Could not persist refund fixture state.');
};
$loadState = static function () use ($stateFile): array {
    if (!is_file($stateFile)) throw new WireException('Refund fixture state is missing.');
    return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
};
$relativeUrl = static function (string $url): string {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/'); $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
    return $path . ($query !== '' ? '?' . $query : '');
};
$cleanup = static function (array $state) use ($wire): array {
    $deleted = ['orders' => 0, 'users' => 0, 'roles' => 0, 'products' => 0];
    $runId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? '')));
    foreach ((array) ($state['order_ids'] ?? []) as $id) {
        $order = $wire->pages->get((int) $id); if (!$order || !$order->id) continue;
        if ($runId === '' || !str_contains(strtolower((string) $order->mrc_email), $runId)) throw new WireException("Refusing to delete unexpected refund order {$order->id}.");
        $order->of(false); $wire->pages->delete($order, true); $deleted['orders']++;
    }
    foreach ((array) ($state['users'] ?? []) as $row) {
        $user = $wire->users->get((int) ($row['id'] ?? 0)); if (!$user || !$user->id) continue;
        if ((string) $user->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $user->name, 'e2e-refund-')) throw new WireException("Refusing to delete unexpected refund user {$user->id}.");
        $wire->users->delete($user); $deleted['users']++;
    }
    foreach ((array) ($state['roles'] ?? []) as $row) {
        $role = $wire->roles->get((int) ($row['id'] ?? 0)); if (!$role || !$role->id) continue;
        if ((string) $role->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $role->name, 'e2e-refund-')) throw new WireException("Refusing to delete unexpected refund role {$role->id}.");
        $wire->roles->delete($role); $deleted['roles']++;
    }
    foreach ((array) ($state['product_ids'] ?? []) as $id) {
        $product = $wire->pages->get((int) $id); if (!$product || !$product->id) continue;
        if ((string) $product->name !== 'e2e-refund-' . $runId) throw new WireException("Refusing to delete unexpected refund product {$product->id}.");
        $product->of(false); $wire->pages->delete($product, true); $deleted['products']++;
    }
    $stored = (array) $wire->modules->getConfig('Mercato');
    foreach ((array) ($state['original_config'] ?? []) as $key => $snapshot) {
        if (!empty($snapshot['exists'])) $stored[$key] = $snapshot['value']; else unset($stored[$key]);
    }
    $wire->modules->saveConfig('Mercato', $stored); return $deleted;
};

if ($action === 'cleanup') {
    if (!is_file($stateFile) || filesize($stateFile) === 0) { echo "No refund fixture state; nothing to clean.\n"; exit(0); }
    echo json_encode(['cleaned' => true] + $cleanup($loadState()), JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

if ($action === 'verify') {
    $state = $loadState();
    $order = $wire->pages->getById((int) $state['order_id'], ['cache' => false])->first();
    $product = $wire->pages->getById((int) $state['product_id'], ['cache' => false])->first();
    if (!$order || !$order->id || !$product || !$product->id) throw new WireException('Refund order or product is missing.');
    if ((string) $order->mrc_payment_status !== 'refunded' || (int) $order->mrc_payment_complete !== 0) throw new WireException('Full refund state was not persisted.');
    if (round((float) $order->mrc_refunded_amount, 2) !== (float) $state['total'] || (float) $order->mrc_refund_pending_amount !== 0.0) throw new WireException('Refund totals are incorrect.');
    if ((int) $order->mrc_inventory_adjusted !== 1 || (int) $order->mrc_inventory_refund_restored !== 1 || (int) $product->mrc_stock !== (int) $state['initial_stock']) throw new WireException('Inventory was not restored exactly once.');
    $details = json_decode((string) $order->mrc_refund_details, true);
    if (!is_array($details) || count($details) !== 1 || round((float) ($details[0]['amount'] ?? 0), 2) !== (float) $state['total']) throw new WireException('Refund detail ledger is not exact-once.');
    $issued = 0; $suspicious = [];
    foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) {
        $path = rtrim((string) $wire->config->paths->logs, '/') . '/' . basename((string) $name);
        if (!is_file($path) || filesize($path) <= (int) $offset) continue;
        $handle = fopen($path, 'rb'); if (!$handle) continue; fseek($handle, (int) $offset); $appended = (string) stream_get_contents($handle); fclose($handle);
        foreach (preg_split('/\R/', $appended) ?: [] as $line) {
            if (str_contains($line, '"event":"refund_issued"') && str_contains($line, '"order_id":' . (int) $order->id)) $issued++;
            if (str_contains($line, (string) $state['run_id']) && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspicious[] = basename($path) . ': ' . substr($line, 0, 500);
        }
    }
    if ($issued !== 1) throw new WireException("Expected one refund_issued audit event, found {$issued}.");
    if ($suspicious) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspicious));
    echo json_encode(['verified' => true, 'order_id' => (int) $order->id, 'payment_status' => 'refunded', 'refunded_amount' => (float) $order->mrc_refunded_amount, 'stock' => (int) $product->mrc_stock, 'refund_events' => $issued, 'suspicious_logs' => 0], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$password = 'E2E-Refund-42!'; $email = 'e2e-' . $runId . '-refund@example.test';
$stored = (array) $wire->modules->getConfig('Mercato'); $keys = ['customer_accounts_mode', 'enabled_notification_events', 'notification_sender_email']; $original = [];
foreach ($keys as $key) $original[$key] = ['exists' => array_key_exists($key, $stored), 'value' => $stored[$key] ?? null];
$state = ['schema_version' => 1, 'run_id' => $runId, 'created_at' => gmdate(DATE_ATOM), 'password' => $password, 'email' => $email, 'initial_stock' => 10, 'total' => 20.0, 'reason' => 'Customer return ' . $runId, 'order_ids' => [], 'users' => [], 'roles' => [], 'product_ids' => [], 'original_config' => $original]; $writeState($state);
$stored['customer_accounts_mode'] = 'optional'; $stored['enabled_notification_events'] = []; $stored['notification_sender_email'] = ''; $wire->modules->saveConfig('Mercato', $stored);
foreach (['customer_accounts_mode' => 'optional', 'enabled_notification_events' => [], 'notification_sender_email' => ''] as $key => $value) $commerce->set($key, $value);

$roleName = 'e2e-refund-manager-' . substr(hash('sha256', $runId), 0, 10); $role = $wire->roles->add($roleName); $role->of(false);
foreach (['page-edit', 'mercato-admin', 'mercato-view-orders', 'mercato-refund-orders'] as $permissionName) {
    if (!$wire->permissions->get($permissionName)->id) throw new WireException("Required permission {$permissionName} is missing.");
    $role->addPermission($permissionName);
}
$wire->roles->save($role); foreach (['page-edit', 'mercato-admin', 'mercato-view-orders', 'mercato-refund-orders'] as $permissionName) if (!$role->hasPermission($permissionName)) throw new WireException("Refund role did not persist {$permissionName}.");
$state['roles'][] = ['id' => (int) $role->id, 'name' => (string) $role->name]; $writeState($state);

$manager = new User(); $manager->of(false); $manager->name = 'e2e-refund-manager-' . substr(hash('sha256', $runId . '-manager'), 0, 12); $manager->email = 'e2e-' . $runId . '-manager@example.test'; $manager->pass = $password; $manager->addRole($role); $wire->users->save($manager);
$state['users'][] = ['id' => (int) $manager->id, 'name' => (string) $manager->name]; $state['manager_username'] = (string) $manager->name; $writeState($state);
$customerRole = $wire->roles->get('mercato-customer'); if (!$customerRole || !$customerRole->id) throw new WireException('The Mercato customer role is missing.');
$customer = new User(); $customer->of(false); $customer->name = 'e2e-refund-customer-' . substr(hash('sha256', $runId . '-customer'), 0, 12); $customer->email = $email; $customer->pass = $password; $customer->addRole($customerRole); $customer->mrc_customer_verified = 1; $customer->mrc_first_name = 'Refund'; $customer->mrc_last_name = 'Customer'; $wire->users->save($customer);
$state['users'][] = ['id' => (int) $customer->id, 'name' => (string) $customer->name]; $state['customer_user_id'] = (int) $customer->id; $writeState($state);

$products = $wire->pages->get('/products/'); if (!$products->id) throw new WireException('Install the Mercato demo storefront before refund acceptance.');
$product = new Page(); $product->template = 'mrc-product'; $product->parent = $products; $product->name = 'e2e-refund-' . $runId; $product->of(false); $product->title = 'Refund fixture product'; $product->mrc_price = 20; $product->mrc_tax_rate = 0; $product->mrc_sku = 'REF-' . strtoupper(substr(hash('sha256', $runId), 0, 10)); $product->mrc_product_type = 'physical'; $product->mrc_product_status = 'active'; $product->mrc_stock = 10; $product->mrc_stock_policy = 'deny'; $wire->pages->save($product);
$state['product_ids'][] = (int) $product->id; $state['product_id'] = (int) $product->id; $writeState($state);
$items = [['id' => (string) $product->id, 'product_id' => (int) $product->id, 'title' => (string) $product->title, 'sku' => (string) $product->mrc_sku, 'price' => 20, 'quantity' => 1, 'tax_rate' => 0, 'product_type' => 'physical', 'stock_policy' => 'deny', 'uid' => 'refund-' . $product->id]];
$order = $commerce->orderRepository()->savePendingOrder(['first_name' => 'Refund', 'last_name' => 'Customer', 'email' => $email, 'payment_method' => 'demo', 'payment_status' => 'paid', 'payment_complete' => 1, 'mrc_items' => json_encode($items, JSON_UNESCAPED_SLASHES), 'mrc_subtotal_amount' => 20, 'mrc_total_amount' => 20, 'mrc_currency' => 'USD', 'mrc_fulfilment_status' => 'unfulfilled', 'mrc_fulfilment_method' => 'carrier_delivery', 'mrc_fulfilment_label' => 'Delivery', 'mrc_customer_user_id' => (int) $customer->id]);
$order = $wire->pages->getById((int) $order->id, ['cache' => false])->first(); $inventory = $commerce->orderRepository()->decrementStockOnce($order);
if (empty($inventory['adjusted']) || (int) $wire->pages->get((int) $product->id)->mrc_stock !== 9) throw new WireException('Refund fixture inventory did not reach the paid baseline.');
$state['order_ids'][] = (int) $order->id; $state['order_id'] = (int) $order->id; $state['invoice'] = (string) $order->mrc_invoice_number;
$processUrl = (string) $wire->pages->get('process=ProcessMercato, include=all')->url; $state['process_url'] = $processUrl; $state['order_detail_url'] = rtrim($processUrl, '/') . '/order-detail/?id=' . (int) $order->id;
$state['status_url'] = $relativeUrl($commerce->getOrderStatusUrl($order)); $state['receipt_url'] = $relativeUrl($commerce->getOrderReceiptUrl($order)); $state['receipt_pdf_url'] = $relativeUrl($commerce->getOrderReceiptPdfUrl($order));
$state['log_offsets'] = []; foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) $state['log_offsets'][basename($path)] = (int) filesize($path);
$writeState($state); echo json_encode(['ready' => true, 'run_id' => $runId, 'order_id' => (int) $order->id, 'invoice' => $state['invoice'], 'product_id' => (int) $product->id, 'stock' => 9], JSON_UNESCAPED_SLASHES) . "\n";
