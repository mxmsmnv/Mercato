<?php
declare(strict_types=1);

use ProcessWire\Page;
use ProcessWire\ProcessWire;
use ProcessWire\User;
use ProcessWire\WireException;

$action = $argv[1] ?? '';
$site = rtrim((string) getenv('MERCATO_E2E_SITE'), '/');
$stateFile = (string) getenv('MERCATO_E2E_STATE');
$dataset = strtolower((string) (getenv('MERCATO_ADMIN_VISUAL_DATASET') ?: 'normal'));
if (!in_array($action, ['setup', 'verify', 'cleanup'], true) || $site === '' || $stateFile === '' || !in_array($dataset, ['empty', 'normal'], true)) {
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json MERCATO_ADMIN_VISUAL_DATASET=empty|normal php admin-visual-breadth-fixtures.php setup|verify|cleanup\n");
    exit(2);
}
$realSite = null; $siteMatch = [];
if ($dataset === 'empty') {
    $confirmation = 'I_UNDERSTAND_THIS_CREATES_AND_DROPS_A_DISPOSABLE_DATABASE';
    if (getenv('MERCATO_ADMIN_VISUAL_EMPTY_ISOLATED') !== '1' || getenv('MERCATO_FRESH_CONFIRM') !== $confirmation) {
        throw new RuntimeException('The empty admin dataset requires both the isolated opt-in and the disposable-database confirmation.');
    }
    $realSite = realpath($site);
    if (!$realSite || !preg_match('#/(?:private/)?tmp/mercato-fresh-install-([a-f0-9]{12})$#D', $realSite, $siteMatch)) {
        throw new RuntimeException('The empty admin dataset site path failed the disposable ownership guard.');
    }
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1';
$disposable = null;
if ($dataset === 'empty') {
    $databaseName = (string) $config->dbName;
    if (!preg_match('/^mercato_codex_fresh_([a-f0-9]{12})$/D', $databaseName, $databaseMatch) || !hash_equals($siteMatch[1], $databaseMatch[1])) {
        throw new WireException('The empty admin dataset database/site identity failed the disposable ownership guard.');
    }
    $sharedSite = realpath((string) (getenv('MERCATO_ADMIN_VISUAL_SHARED_SITE') ?: '/Users/mas/Sites/mercato.dev'));
    if ($sharedSite && $sharedSite === $realSite) throw new WireException('The disposable site resolves to the shared development site.');
    if ($sharedSite && is_file($sharedSite . '/site/config.php') && is_file($sharedSite . '/wire/core/ProcessWire.php')) {
        $sharedConfig = ProcessWire::buildConfig($sharedSite);
        if ((string) $sharedConfig->dbName === $databaseName) throw new WireException('The disposable site resolves to the shared development database.');
    }
}
$wire = new ProcessWire($config); $commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Admin visual fixtures are forbidden in production mode.');
if ($dataset === 'empty') {
    $guardToken = trim((string) getenv('MERCATO_FRESH_GUARD_TOKEN'));
    if (!preg_match('/^[a-f0-9]{32}$/D', $guardToken)) throw new WireException('The empty admin dataset guard token is missing or invalid.');
    try { $storedGuard = (string) $wire->database->query('SELECT token FROM mercato_fresh_install_guard LIMIT 1')->fetchColumn(); }
    catch (Throwable $error) { throw new WireException('The disposable database ownership table is unavailable.', 0, $error); }
    if (!hash_equals($guardToken, $storedGuard)) throw new WireException('The disposable database ownership token does not match.');
    $disposable = ['site' => $realSite, 'database' => $databaseName, 'suffix' => $siteMatch[1], 'guard_sha256' => hash('sha256', $guardToken)];
}
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for fixture management.');
$wire->users->setCurrentUser($superuser); $wire->set('page', $wire->pages->get('/'));

$writeState = static function (array $state) use ($stateFile): void {
    if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) throw new WireException('Could not persist admin visual fixture state.');
};
$loadState = static function () use ($stateFile): array {
    if (!is_file($stateFile) || filesize($stateFile) === 0) throw new WireException('Admin visual fixture state is missing.');
    return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
};
$cleanup = static function (array $state) use ($wire): array {
    $runId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? '')));
    if ($runId === '') throw new WireException('Refusing admin visual cleanup without a valid run id.');
    $deleted = ['orders' => 0, 'quotes' => 0, 'products' => 0, 'discounts' => 0, 'users' => 0, 'roles' => 0, 'log_lines' => 0];
    foreach ((array) ($state['order_ids'] ?? []) as $id) {
        $page = $wire->pages->getById((int) $id, ['cache' => false])->first(); if (!$page || !$page->id) continue;
        if (!str_contains(strtolower((string) $page->mrc_email), $runId)) throw new WireException("Refusing to delete unexpected admin visual order {$page->id}.");
        $page->of(false); $wire->pages->delete($page, true); $deleted['orders']++;
    }
    foreach ((array) ($state['quote_ids'] ?? []) as $id) {
        $page = $wire->pages->getById((int) $id, ['cache' => false])->first(); if (!$page || !$page->id) continue;
        if (!str_contains(strtolower((string) $page->mrc_email), $runId)) throw new WireException("Refusing to delete unexpected admin visual quote {$page->id}.");
        $page->of(false); $wire->pages->delete($page, true); $deleted['quotes']++;
    }
    foreach ((array) ($state['product_ids'] ?? []) as $id) {
        $page = $wire->pages->getById((int) $id, ['cache' => false])->first(); if (!$page || !$page->id) continue;
        if (!str_starts_with((string) $page->name, 'e2e-admin-visual-' . $runId . '-')) throw new WireException("Refusing to delete unexpected admin visual product {$page->id}.");
        $page->of(false); $wire->pages->delete($page, true); $deleted['products']++;
    }
    foreach ((array) ($state['discount_ids'] ?? []) as $id) {
        $page = $wire->pages->getById((int) $id, ['cache' => false])->first(); if (!$page || !$page->id) continue;
        if (!str_contains(strtolower((string) $page->mrc_discount_code), substr($runId, -6))) throw new WireException("Refusing to delete unexpected admin visual discount {$page->id}.");
        $page->of(false); $wire->pages->delete($page, true); $deleted['discounts']++;
    }
    foreach ((array) ($state['users'] ?? []) as $row) {
        $user = $wire->users->get((int) ($row['id'] ?? 0)); if (!$user || !$user->id) continue;
        if ((string) $user->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $user->name, 'e2e-admin-visual-')) throw new WireException("Refusing to delete unexpected admin visual user {$user->id}.");
        $wire->users->delete($user); $deleted['users']++;
    }
    foreach ((array) ($state['roles'] ?? []) as $row) {
        $role = $wire->roles->get((int) ($row['id'] ?? 0)); if (!$role || !$role->id) continue;
        if ((string) $role->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $role->name, 'e2e-admin-visual-')) throw new WireException("Refusing to delete unexpected admin visual role {$role->id}.");
        $wire->roles->delete($role); $deleted['roles']++;
    }
    foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) {
        $contents = file_get_contents($path); if ($contents === false) continue;
        $lines = preg_split('/(?<=\n)/', $contents) ?: []; $kept = [];
        foreach ($lines as $line) { if (str_contains(strtolower($line), $runId)) { $deleted['log_lines']++; continue; } $kept[] = $line; }
        if (count($kept) !== count($lines) && file_put_contents($path, implode('', $kept), LOCK_EX) === false) throw new WireException('Could not remove run-owned admin visual log records.');
    }
    return $deleted;
};

$setupComplete = $action !== 'setup';
register_shutdown_function(static function () use (&$setupComplete, $stateFile, $loadState, $cleanup): void {
    if ($setupComplete || !is_file($stateFile) || filesize($stateFile) === 0) return;
    try { $cleanup($loadState()); @unlink($stateFile); } catch (Throwable $error) { fwrite(STDERR, "Admin visual emergency cleanup failed: {$error->getMessage()}\n"); }
});

if ($action === 'cleanup') {
    if (!is_file($stateFile) || filesize($stateFile) === 0) { echo "No admin visual fixture; nothing to clean.\n"; exit(0); }
    $result = ['cleaned' => true] + $cleanup($loadState());
    if (!unlink($stateFile) && is_file($stateFile)) throw new WireException('Could not remove the admin visual fixture state file.');
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}
if ($action === 'verify') {
    $state = $loadState(); $runId = (string) $state['run_id'];
    foreach ((array) $state['users'] as $row) if (!$wire->users->get((int) $row['id'])->id) throw new WireException('An admin visual fixture user is missing.');
    foreach ((array) $state['product_ids'] as $id) if (!$wire->pages->getById((int) $id, ['cache' => false])->first()->id) throw new WireException('An admin visual fixture product is missing.');
    foreach ((array) $state['order_ids'] as $id) if (!$wire->pages->getById((int) $id, ['cache' => false])->first()->id) throw new WireException('An admin visual fixture order is missing.');
    foreach ((array) $state['quote_ids'] as $id) if (!$wire->pages->getById((int) $id, ['cache' => false])->first()->id) throw new WireException('An admin visual fixture quote is missing.');
    foreach ((array) $state['discount_ids'] as $id) if (!$wire->pages->getById((int) $id, ['cache' => false])->first()->id) throw new WireException('An admin visual fixture discount is missing.');
    $suspicious = [];
    foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) {
        $path = rtrim((string) $wire->config->paths->logs, '/') . '/' . basename((string) $name); if (!is_file($path) || filesize($path) <= (int) $offset) continue;
        $handle = fopen($path, 'rb'); if (!$handle) continue; fseek($handle, (int) $offset); $tail = (string) stream_get_contents($handle); fclose($handle);
        foreach (preg_split('/\R/', $tail) ?: [] as $line) if (str_contains($line, $runId) && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspicious[] = basename($path) . ': ' . substr($line, 0, 500);
    }
    if ($suspicious) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspicious));
    echo json_encode(['verified' => true, 'dataset' => $state['dataset'], 'products' => count($state['product_ids']), 'orders' => count($state['order_ids']), 'quotes' => count($state['quote_ids']), 'suspicious_logs' => 0], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)); $password = 'E2E-Admin-Visual-42!';
$state = ['schema_version' => 1, 'run_id' => $runId, 'dataset' => $dataset, 'created_at' => gmdate(DATE_ATOM), 'password' => $password, 'product_ids' => [], 'order_ids' => [], 'quote_ids' => [], 'discount_ids' => [], 'users' => [], 'roles' => [], 'disposable' => $disposable];
$writeState($state);

if ($dataset === 'empty') {
    $state['empty_pruned'] = [];
    foreach (['mrc-order', 'mrc-quote', 'mrc-product', 'mrc-discount'] as $templateName) {
        $ownedPages = $wire->pages->find('template=' . $wire->sanitizer->selectorValue($templateName) . ', include=all, limit=10000');
        $state['empty_pruned'][$templateName] = $ownedPages->count();
        foreach ($ownedPages as $ownedPage) { $ownedPage->of(false); $wire->pages->delete($ownedPage, true); }
        if ($wire->pages->count('template=' . $wire->sanitizer->selectorValue($templateName) . ', include=all') !== 0) throw new WireException("Could not establish isolated empty state for {$templateName}.");
    }
    $writeState($state);
}

$mercatoPermissions = [
    'mercato-admin', 'mercato-view-orders', 'mercato-edit-orders', 'mercato-refund-orders', 'mercato-create-manual-orders',
    'mercato-view-quotes', 'mercato-manage-quotes', 'mercato-manage-products', 'mercato-manage-inventory', 'mercato-fulfil-orders',
    'mercato-manage-notifications', 'mercato-view-customers', 'mercato-manage-customers', 'mercato-manage-privacy',
    'mercato-manage-recovery', 'mercato-view-reports', 'mercato-manage-discounts', 'mercato-manage-webhooks', 'mercato-launch-tools',
];
$roleDefinitions = ['viewer' => ['page-edit', 'mercato-admin'], 'operator' => array_merge(['page-edit'], $mercatoPermissions)];
if ($wire->permissions->get('module-admin')->id) $roleDefinitions['operator'][] = 'module-admin';
$roles = [];
foreach ($roleDefinitions as $kind => $permissions) {
    $name = 'e2e-admin-visual-' . $kind . '-' . substr(hash('sha256', $runId), 0, 10); $role = $wire->roles->add($name); $role->of(false);
    foreach ($permissions as $permission) { if (!$wire->permissions->get($permission)->id) throw new WireException("Required permission {$permission} is missing."); $role->addPermission($permission); }
    $wire->roles->save($role); $roles[$kind] = $wire->roles->get((int) $role->id); $state['roles'][] = ['id' => (int) $role->id, 'name' => $name]; $writeState($state);
}
$makeUser = static function (string $kind, $role) use ($wire, $runId, $password, &$state, $writeState): User {
    $user = new User(); $user->of(false); $user->name = 'e2e-admin-visual-' . $kind . '-' . substr(hash('sha256', $runId . $kind), 0, 12); $user->email = "e2e-{$runId}-{$kind}@example.test"; $user->pass = $password; $user->addRole($role); $wire->users->save($user);
    $state['users'][] = ['id' => (int) $user->id, 'name' => (string) $user->name]; $writeState($state); return $user;
};
$viewer = $makeUser('viewer', $roles['viewer']); $operator = $makeUser('operator', $roles['operator']);
$settingsAdmin = $makeUser('settings-admin', $wire->roles->get('superuser'));
$customerRole = $wire->roles->get('mercato-customer'); if (!$customerRole->id) throw new WireException('The Mercato customer role is missing.');
$customer = $makeUser('customer', $customerRole); $customer->of(false); $customer->email = "e2e-{$runId}-customer@example.test"; $customer->mrc_first_name = 'Présentation'; $customer->mrc_last_name = '顧客 العربية'; $customer->mrc_customer_verified = 1; $wire->users->save($customer);

$productsParent = $wire->pages->get('/products/'); if (!$productsParent->id) throw new WireException('The demo products parent is required.');
$longToken = 'AdminVisual' . str_repeat('Unbroken日本語العربية', 12);
if ($dataset === 'normal') {
    for ($index = 1; $index <= 18; $index++) {
        $product = new Page(); $product->template = 'mrc-product'; $product->parent = $productsParent; $product->name = "e2e-admin-visual-{$runId}-{$index}"; $product->of(false);
        $product->title = $index === 1 ? "Présentation 日本語 العربية {$longToken}" : sprintf('Admin visual %02d · Español 日本語', $index);
        $product->mrc_description = '<p>Deterministic admin visual fixture — 日本語 — وصف عربي.</p>'; $product->mrc_price = 20 + $index; $product->mrc_tax_rate = 0; $product->mrc_sku = 'AV-' . strtoupper(substr(hash('sha256', $runId . $index), 0, 10)); $product->mrc_product_type = $index % 4 === 0 ? 'digital' : 'physical'; $product->mrc_product_status = 'active'; $product->mrc_stock = $index; $product->mrc_stock_policy = 'deny'; $wire->pages->save($product);
        $state['product_ids'][] = (int) $product->id; if ($index === 1) $state['product_id'] = (int) $product->id; $writeState($state);
    }
    $longItem = 'Admin visual Présentation 日本語 العربية ' . str_repeat('LongItem', 20);
    for ($index = 1; $index <= 12; $index++) {
        $paid = $index !== 12; $items = [['id' => "av-{$index}", 'uid' => "av-{$index}", 'product_id' => (int) $state['product_id'], 'title' => $longItem, 'sku' => "AV-LINE-{$index}", 'price' => 30 + $index, 'quantity' => 1, 'tax_rate' => 0, 'product_type' => 'service', 'stock_policy' => 'allow']];
        $order = $commerce->orderRepository()->savePendingOrder(['first_name' => 'Présentation', 'last_name' => '顧客 العربية', 'email' => (string) $customer->email, 'payment_method' => 'demo', 'payment_status' => $paid ? 'paid' : 'pending', 'payment_complete' => $paid ? 1 : 0, 'mrc_items' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'mrc_subtotal_amount' => 30 + $index, 'mrc_total_amount' => 30 + $index, 'mrc_currency' => 'USD', 'mrc_fulfilment_method' => 'digital', 'mrc_fulfilment_label' => 'Digital', 'mrc_fulfilment_status' => 'unfulfilled', 'mrc_customer_user_id' => (int) $customer->id, 'mrc_notes' => "admin-visual-{$runId}-{$index}"]);
        $state['order_ids'][] = (int) $order->id; if ($index === 1) $state['order_id'] = (int) $order->id; $writeState($state);
    }
    $quotesParent = $wire->pages->get('/quotes/'); if (!$quotesParent->id) throw new WireException('The quote parent is required.');
    $quote = new Page(); $quote->template = 'mrc-quote'; $quote->parent = $quotesParent; $quote->name = 'e2e-admin-visual-' . $runId; $quote->of(false); $quote->title = 'Quote ' . $runId; $quote->mrc_quote_number = 'Q-' . strtoupper(substr(hash('sha256', $runId), 0, 10)); $quote->mrc_quote_status = 'requested'; $quote->mrc_first_name = 'Présentation'; $quote->mrc_last_name = '顧客 العربية'; $quote->mrc_email = (string) $customer->email; $quote->mrc_notes = $longToken; $quote->mrc_items = json_encode([['title' => $longToken, 'quantity' => 1, 'sum' => 99]], JSON_UNESCAPED_UNICODE); $quote->mrc_currency = 'USD'; $quote->mrc_total_amount = 99; $wire->pages->save($quote); $state['quote_ids'][] = (int) $quote->id; $state['quote_id'] = (int) $quote->id; $writeState($state);
    $discount = new Page(); $discount->template = 'mrc-discount'; $discount->parent = $wire->pages->get('/'); $discount->name = 'e2e-admin-visual-discount-' . $runId; $discount->of(false); $discount->title = 'Admin visual discount'; $discount->mrc_discount_code = 'AV' . strtoupper(substr($runId, -6)); $discount->mrc_discount_active = 1; $discount->mrc_discount_type = 'percent'; $discount->mrc_discount_percent = 10; $discount->mrc_discount_notes = $longToken; $wire->pages->save($discount); $state['discount_ids'][] = (int) $discount->id; $writeState($state);
}

$processUrl = rtrim((string) $wire->pages->get('process=ProcessMercato, include=all')->url, '/') . '/';
$route = static fn(string $path = ''): string => $processUrl . ltrim($path, '/');
$state['operator_username'] = (string) $operator->name; $state['viewer_username'] = (string) $viewer->name; $state['settings_username'] = (string) $settingsAdmin->name; $state['customer_email'] = (string) $customer->email; $state['process_url'] = $processUrl;
$state['routes'] = [
    ['id' => 'dashboard', 'url' => $route()], ['id' => 'products', 'url' => $route('products/?q=' . rawurlencode($dataset === 'normal' ? 'Admin visual' : '__empty_' . $runId))],
    ['id' => 'orders', 'url' => $route('orders/')], ['id' => 'quotes', 'url' => $route('quotes/')], ['id' => 'manual-order', 'url' => $route('manual-order/')],
    ['id' => 'fulfilment', 'url' => $route('fulfilment/')], ['id' => 'customers', 'url' => $route('customers/?q=' . rawurlencode($dataset === 'normal' ? $customer->email : '__empty_' . $runId))],
    ['id' => 'recovery', 'url' => $route('recovery/')], ['id' => 'search', 'url' => $route('search/?q=' . rawurlencode($dataset === 'normal' ? $runId : '__empty_' . $runId))],
    ['id' => 'reports', 'url' => $route('reports/')], ['id' => 'discounts', 'url' => $route('discounts/')], ['id' => 'webhooks', 'url' => $route('webhooks/')],
    ['id' => 'payment-attempts', 'url' => $route('payment-attempts/')], ['id' => 'refunds', 'url' => $route('refunds/')], ['id' => 'inventory', 'url' => $route('inventory/')],
    ['id' => 'launch', 'url' => $route('launch/')], ['id' => 'notifications', 'url' => $route('notifications/')],
];
if ($dataset === 'normal') {
    $state['routes'][] = ['id' => 'product-detail', 'url' => $route('product-detail/?id=' . (int) $state['product_id'])];
    $state['routes'][] = ['id' => 'order-timeline', 'url' => $route('order-timeline/?id=' . (int) $state['order_id'])];
    $state['routes'][] = ['id' => 'order-detail', 'url' => $route('order-detail/?id=' . (int) $state['order_id'])];
    $state['routes'][] = ['id' => 'quote-detail', 'url' => $route('quote-detail/?id=' . (int) $state['quote_id'])];
    $state['routes'][] = ['id' => 'customer-detail', 'url' => $route('customer-detail/?key=' . rawurlencode((string) $customer->email))];
}
$state['settings_url'] = rtrim((string) $wire->config->urls->admin, '/') . '/module/edit/?name=Mercato';
$routePermissions = [
    'products' => 'mercato-manage-products', 'product-detail' => 'mercato-manage-products', 'orders' => 'mercato-view-orders',
    'order-timeline' => 'mercato-view-orders', 'order-detail' => 'mercato-view-orders', 'quotes' => 'mercato-view-quotes',
    'quote-detail' => 'mercato-view-quotes', 'manual-order' => 'mercato-create-manual-orders', 'fulfilment' => 'mercato-fulfil-orders',
    'customers' => 'mercato-view-customers', 'customer-detail' => 'mercato-view-customers', 'recovery' => 'mercato-manage-recovery',
    'reports' => 'mercato-view-reports', 'discounts' => 'mercato-manage-discounts', 'webhooks' => 'mercato-manage-webhooks',
    'payment-attempts' => 'mercato-manage-webhooks', 'refunds' => 'mercato-manage-webhooks', 'inventory' => 'mercato-manage-inventory',
    'launch' => 'mercato-launch-tools', 'notifications' => 'mercato-manage-notifications',
];
foreach ($state['routes'] as &$row) if (isset($routePermissions[$row['id']])) $row['permission'] = $routePermissions[$row['id']]; unset($row);
$state['denied_routes'] = array_values(array_filter($state['routes'], static fn(array $row): bool => isset($row['permission'])));
$state['log_offsets'] = []; foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) $state['log_offsets'][basename($path)] = (int) filesize($path);
$writeState($state); $setupComplete = true;
echo json_encode(['ready' => true, 'dataset' => $dataset, 'routes' => count($state['routes']) + 1, 'products' => count($state['product_ids']), 'orders' => count($state['order_ids']), 'quotes' => count($state['quote_ids'])], JSON_UNESCAPED_SLASHES) . "\n";
