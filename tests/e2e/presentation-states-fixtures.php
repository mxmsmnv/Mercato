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
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php presentation-states-fixtures.php setup|verify|cleanup\n");
    exit(2);
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config); $commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Presentation-state fixtures are forbidden in production mode.');
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for presentation-state fixture management.');
$wire->users->setCurrentUser($superuser); $wire->set('page', $wire->pages->get('/'));

$writeState = static function (array $state) use ($stateFile): void {
    if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) throw new WireException('Could not persist presentation-state fixture state.');
};
$loadState = static function () use ($stateFile): array {
    if (!is_file($stateFile)) throw new WireException('Presentation-state fixture state is missing.');
    return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
};
$cleanup = static function (array $state) use ($wire): array {
    $runId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? '')));
    if ($runId === '') throw new WireException('Refusing presentation cleanup without a valid run id.');
    $deleted = ['orders' => 0, 'products' => 0, 'users' => 0, 'roles' => 0];
    foreach ((array) ($state['order_ids'] ?? []) as $id) {
        $order = $wire->pages->get((int) $id); if (!$order || !$order->id) continue;
        if (!str_contains(strtolower((string) $order->mrc_email), $runId)) throw new WireException("Refusing to delete unexpected presentation order {$order->id}.");
        $order->of(false); $wire->pages->delete($order, true); $deleted['orders']++;
    }
    foreach ((array) ($state['product_ids'] ?? []) as $id) {
        $product = $wire->pages->get((int) $id); if (!$product || !$product->id) continue;
        if (!str_starts_with((string) $product->name, 'e2e-presentation-' . $runId . '-')) throw new WireException("Refusing to delete unexpected presentation product {$product->id}.");
        $product->of(false); $wire->pages->delete($product, true); $deleted['products']++;
    }
    foreach ((array) ($state['users'] ?? []) as $row) {
        $user = $wire->users->get((int) ($row['id'] ?? 0)); if (!$user || !$user->id) continue;
        if ((string) $user->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $user->name, 'e2e-presentation-')) throw new WireException("Refusing to delete unexpected presentation user {$user->id}.");
        $wire->users->delete($user); $deleted['users']++;
    }
    foreach ((array) ($state['roles'] ?? []) as $row) {
        $role = $wire->roles->get((int) ($row['id'] ?? 0)); if (!$role || !$role->id) continue;
        if ((string) $role->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $role->name, 'e2e-presentation-')) throw new WireException("Refusing to delete unexpected presentation role {$role->id}.");
        $wire->roles->delete($role); $deleted['roles']++;
    }
    $stored = (array) $wire->modules->getConfig('Mercato');
    foreach ((array) ($state['original_config'] ?? []) as $key => $snapshot) {
        if (!empty($snapshot['exists'])) $stored[$key] = $snapshot['value']; else unset($stored[$key]);
    }
    $wire->modules->saveConfig('Mercato', $stored);
    $markers = [$runId, 'e2e-presentation-'];
    $deleted['log_lines'] = 0;
    foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) {
        $contents = file_get_contents($path); if ($contents === false) continue;
        $lines = preg_split('/(?<=\n)/', $contents) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $owned = false;
            foreach ($markers as $marker) if ($marker !== '' && str_contains($line, $marker)) { $owned = true; break; }
            if ($owned) { $deleted['log_lines']++; continue; }
            $kept[] = $line;
        }
        if (count($kept) !== count($lines) && file_put_contents($path, implode('', $kept), LOCK_EX) === false) throw new WireException('Could not remove run-owned presentation log records.');
    }
    return $deleted;
};
$setupComplete = $action !== 'setup';
register_shutdown_function(static function () use (&$setupComplete, $stateFile, $loadState, $cleanup): void {
    if ($setupComplete || !is_file($stateFile) || filesize($stateFile) === 0) return;
    try { $cleanup($loadState()); } catch (Throwable $error) { fwrite(STDERR, "Presentation fixture emergency cleanup failed: {$error->getMessage()}\n"); }
});

if ($action === 'cleanup') {
    if (!is_file($stateFile) || filesize($stateFile) === 0) { echo "No presentation-state fixture; nothing to clean.\n"; exit(0); }
    echo json_encode(['cleaned' => true] + $cleanup($loadState()), JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

if ($action === 'verify') {
    $state = $loadState(); $runId = (string) $state['run_id'];
    $productCount = 0; foreach ((array) $state['product_ids'] as $id) if ($wire->pages->get((int) $id)->id) $productCount++;
    $orderCount = 0; foreach ((array) $state['order_ids'] as $id) {
        $order = $wire->pages->getById((int) $id, ['cache' => false])->first();
        if (!$order || !$order->id || (string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_customer_user_id !== (int) $state['customer_user_id']) throw new WireException('Presentation order state changed unexpectedly.');
        $orderCount++;
    }
    if ($productCount !== (int) $state['product_count'] || $orderCount !== (int) $state['order_count']) throw new WireException('Presentation fixture cardinality changed.');
    $customer = $wire->users->get((int) $state['customer_user_id']);
    if (!$customer->id || (int) $customer->mrc_customer_revision !== 0) throw new WireException('Presentation customer state changed unexpectedly.');
    $longProduct = $wire->pages->getById((int) $state['long_product_id'], ['cache' => false])->first();
    if (!$longProduct || !$longProduct->id || !str_contains((string) $longProduct->title, '日本語') || !str_contains((string) $longProduct->title, 'العربية') || !str_contains((string) $longProduct->mrc_description, '日本語の説明')) throw new WireException('Long translated product content did not persist as UTF-8.');
    if ($commerce->operationalService()->isCheckoutAvailable()) throw new WireException('Non-production checkout maintenance setting did not take effect.');
    if ($commerce->operationalService()->checkoutMessage() !== (string) $state['maintenance_message']) throw new WireException('Checkout maintenance message did not persist.');
    $suspicious = [];
    foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) {
        $path = rtrim((string) $wire->config->paths->logs, '/') . '/' . basename((string) $name);
        if (!is_file($path) || filesize($path) <= (int) $offset) continue;
        $handle = fopen($path, 'rb'); if (!$handle) continue; fseek($handle, (int) $offset); $appended = (string) stream_get_contents($handle); fclose($handle);
        foreach (preg_split('/\R/', $appended) ?: [] as $line) if (str_contains($line, $runId) && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspicious[] = basename($path) . ': ' . substr($line, 0, 500);
    }
    if ($suspicious) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspicious));
    echo json_encode(['verified' => true, 'products' => $productCount, 'orders' => $orderCount, 'checkout_available' => false, 'suspicious_logs' => 0], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)); $password = 'E2E-Presentation-42!';
$stored = (array) $wire->modules->getConfig('Mercato');
$configKeys = ['customer_accounts_mode', 'account_orders_per_page', 'checkout_enabled', 'checkout_maintenance_message', 'enabled_notification_events', 'notification_sender_email'];
$original = []; foreach ($configKeys as $key) $original[$key] = ['exists' => array_key_exists($key, $stored), 'value' => $stored[$key] ?? null];
$maintenance = 'Presentation readiness maintenance — ' . $runId;
$state = ['schema_version' => 1, 'run_id' => $runId, 'created_at' => gmdate(DATE_ATOM), 'password' => $password, 'product_ids' => [], 'order_ids' => [], 'users' => [], 'roles' => [], 'product_count' => 24, 'order_count' => 14, 'original_config' => $original, 'maintenance_message' => $maintenance];
$writeState($state);
$stored['customer_accounts_mode'] = 'optional'; $stored['account_orders_per_page'] = 5; $stored['checkout_enabled'] = false; $stored['checkout_maintenance_message'] = $maintenance; $stored['enabled_notification_events'] = []; $stored['notification_sender_email'] = '';
$wire->modules->saveConfig('Mercato', $stored);
foreach (['customer_accounts_mode'=>'optional', 'account_orders_per_page'=>5, 'checkout_enabled'=>false, 'checkout_maintenance_message'=>$maintenance, 'enabled_notification_events'=>[], 'notification_sender_email'=>''] as $key=>$value) $commerce->set($key, $value);
if ($commerce->operationalService()->isCheckoutAvailable() || $commerce->operationalService()->checkoutMessage() !== $maintenance) throw new WireException('Checkout readiness fixture did not take effect.');

$roleName = 'e2e-presentation-manager-' . substr(hash('sha256', $runId), 0, 10); $role = $wire->roles->add($roleName); $role->of(false);
foreach (['page-edit', 'mercato-admin', 'mercato-view-orders', 'mercato-manage-products'] as $permissionName) {
    $permission = $wire->permissions->get($permissionName); if (!$permission->id) throw new WireException("Required permission {$permissionName} is missing."); $role->addPermission($permission);
}
$wire->roles->save($role); $state['roles'][] = ['id'=>(int)$role->id, 'name'=>(string)$role->name]; $writeState($state);
$manager = new User(); $manager->of(false); $manager->name = 'e2e-presentation-manager-' . substr(hash('sha256', $runId . '-manager'), 0, 12); $manager->email = "e2e-{$runId}-manager@example.test"; $manager->pass = $password; $manager->addRole($role); $wire->users->save($manager);
$state['users'][] = ['id'=>(int)$manager->id, 'name'=>(string)$manager->name]; $state['manager_username'] = (string)$manager->name; $writeState($state);
$customerRole = $wire->roles->get('mercato-customer'); if (!$customerRole->id) throw new WireException('The Mercato customer role is missing.');
$customer = new User(); $customer->of(false); $customer->name = 'e2e-presentation-customer-' . substr(hash('sha256', $runId . '-customer'), 0, 12); $customer->email = "e2e-{$runId}-customer@example.test"; $customer->pass = $password; $customer->addRole($customerRole); $customer->mrc_customer_verified = 1; $customer->mrc_first_name = 'Présentation 日本語'; $customer->mrc_last_name = 'عميل'; $customer->mrc_customer_revision = 0; $wire->users->save($customer);
$state['users'][] = ['id'=>(int)$customer->id, 'name'=>(string)$customer->name]; $state['customer_user_id']=(int)$customer->id; $state['customer_email']=(string)$customer->email; $writeState($state);

$productsParent = $wire->pages->get('/products/'); if (!$productsParent->id) throw new WireException('Install the Mercato demo storefront first.');
$longToken = 'Langinhalt' . str_repeat('Ungebrochen日本語العربية', 11);
for ($index = 1; $index <= (int) $state['product_count']; $index++) {
    $product = new Page(); $product->template = 'mrc-product'; $product->parent = $productsParent; $product->name = 'e2e-presentation-' . $runId . '-' . $index; $product->of(false);
    $product->title = $index === 1 ? "Présentation 日本語 العربية {$longToken}" : sprintf('Presentation state %02d · Español 日本語', $index);
    $product->mrc_description = $index === 1 ? '<p>Mehrsprachiger Inhalt — 日本語の説明 — وصف عربي.</p><p>' . $longToken . '</p>' : '<p>Deterministic presentation fixture.</p>';
    $product->mrc_price = 10 + $index; $product->mrc_tax_rate = 0; $product->mrc_sku = 'PRES-' . strtoupper(substr(hash('sha256', $runId . '-' . $index), 0, 10)); $product->mrc_product_type = $index % 5 === 0 ? 'digital' : 'physical'; $product->mrc_product_status = 'active'; $product->mrc_stock = $index % 6; $product->mrc_stock_policy = $index % 7 === 0 ? 'preorder' : 'deny';
    $wire->pages->save($product); $state['product_ids'][] = (int)$product->id;
    if ($index === 1) { $state['long_product_id']=(int)$product->id; $state['long_product_title']=(string)$product->title; $state['long_product_url']=(string)$product->url; }
    $writeState($state);
}

$longItemTitle = 'Order line · Présentation 日本語 العربية · ' . str_repeat('Unbroken', 18);
$invoices = [];
for ($index = 1; $index <= (int) $state['order_count']; $index++) {
    $items = [['id'=>'presentation-' . $index, 'uid'=>'presentation-' . $index, 'product_id'=>(int)$state['long_product_id'], 'title'=>$longItemTitle, 'sku'=>'PRES-LINE-' . $index, 'price'=>12 + $index, 'quantity'=>1, 'tax_rate'=>0, 'product_type'=>'service', 'stock_policy'=>'allow']];
    $order = $commerce->orderRepository()->savePendingOrder(['first_name'=>'Présentation', 'last_name'=>'عميل', 'email'=>(string)$customer->email, 'payment_method'=>'demo', 'payment_status'=>'paid', 'payment_complete'=>1, 'mrc_items'=>json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'mrc_subtotal_amount'=>12+$index, 'mrc_total_amount'=>12+$index, 'mrc_currency'=>'USD', 'mrc_fulfilment_status'=>'unfulfilled', 'mrc_customer_user_id'=>(int)$customer->id, 'mrc_notes'=>'presentation-' . $runId . '-' . $index]);
    $state['order_ids'][]=(int)$order->id; $invoices[]=(string)$order->mrc_invoice_number; $writeState($state);
}
$state['invoices']=$invoices;
$state['products_url']=(string)$productsParent->url;
$state['empty_catalog_url']=rtrim((string)$productsParent->url, '/') . '/?min_price=99999999';
$state['large_catalog_url']=rtrim((string)$productsParent->url, '/') . '/?sort=newest';
$state['account_url']='/account/'; $state['checkout_url']='/checkout/';
$processUrl=(string)$wire->pages->get('process=ProcessMercato, include=all')->url; $state['process_url']=$processUrl; $state['admin_products_url']=rtrim($processUrl, '/') . '/products/?q=' . rawurlencode('Presentation state'); $state['admin_orders_url']=rtrim($processUrl, '/') . '/orders/';
$state['log_offsets']=[]; foreach (glob(rtrim((string)$wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) $state['log_offsets'][basename($path)]=(int)filesize($path);
$writeState($state);
$setupComplete = true;
echo json_encode(['ready'=>true, 'run_id'=>$runId, 'products'=>count($state['product_ids']), 'orders'=>count($state['order_ids']), 'checkout_available'=>false], JSON_UNESCAPED_SLASHES) . "\n";
