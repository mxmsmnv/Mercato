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
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php admin-fixtures.php setup|verify|cleanup\n");
    exit(2);
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Admin acceptance fixtures are forbidden in production mode.');
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for fixture management.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

$writeState = static function (array $state) use ($stateFile): void {
    if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        throw new WireException('Could not persist admin fixture state.');
    }
};

$loadState = static function () use ($stateFile): array {
    if (!is_file($stateFile)) throw new WireException('Admin fixture state is missing.');
    return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
};

$cleanup = static function (array $state) use ($wire): array {
    $deleted = ['orders' => 0, 'users' => 0, 'roles' => 0];
    $runId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? '')));
    $prefix = 'e2e-' . $runId . '-';
    foreach ((array) ($state['order_ids'] ?? []) as $id) {
        $order = $wire->pages->get((int) $id);
        if (!$order || !$order->id) continue;
        $email = strtolower((string) ($order->mrc_email ?? ''));
        if ($runId === '' || !str_starts_with($email, $prefix)) throw new WireException("Refusing to delete unexpected admin fixture order {$order->id}.");
        $order->of(false); $wire->pages->delete($order, true); $deleted['orders']++;
    }
    foreach ((array) ($state['users'] ?? []) as $row) {
        $user = $wire->users->get((int) ($row['id'] ?? 0));
        if (!$user || !$user->id) continue;
        if ((string) $user->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $user->name, 'e2e-admin-')) {
            throw new WireException("Refusing to delete unexpected admin fixture user {$user->id}.");
        }
        $wire->users->delete($user); $deleted['users']++;
    }
    foreach ((array) ($state['roles'] ?? []) as $row) {
        $role = $wire->roles->get((int) ($row['id'] ?? 0));
        if (!$role || !$role->id) continue;
        if ((string) $role->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $role->name, 'e2e-admin-')) {
            throw new WireException("Refusing to delete unexpected admin fixture role {$role->id}.");
        }
        $wire->roles->delete($role); $deleted['roles']++;
    }
    $stored = (array) $wire->modules->getConfig('Mercato');
    foreach ((array) ($state['original_config'] ?? []) as $key => $snapshot) {
        if (!empty($snapshot['exists'])) $stored[$key] = $snapshot['value']; else unset($stored[$key]);
    }
    $wire->modules->saveConfig('Mercato', $stored);
    return $deleted;
};

if ($action === 'cleanup') {
    if (!is_file($stateFile)) { echo "No admin fixture state; nothing to clean.\n"; exit(0); }
    echo json_encode(['cleaned' => true] + $cleanup($loadState()), JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($action === 'verify') {
    $state = $loadState();
    $order = $wire->pages->get((int) ($state['order_ids'][0] ?? 0));
    if (!$order || !$order->id) throw new WireException('The admin fixture order is missing.');
    if ((string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1) throw new WireException('Admin probes changed the paid state.');
    if ((string) $order->mrc_fulfilment_status !== 'shipped') throw new WireException('The manager fulfilment transition was not persisted.');
    if ((string) $order->mrc_fulfilment_tracking !== (string) $state['expected_tracking']) throw new WireException('The manager tracking value was not persisted.');
    if (!str_contains((string) $order->mrc_fulfilment_notes, (string) $state['expected_note'])) throw new WireException('The manager fulfilment note was not persisted.');
    if ((float) ($order->mrc_refunded_amount ?? 0) !== 0.0 || (float) ($order->mrc_refund_pending_amount ?? 0) !== 0.0) throw new WireException('A denied refund probe mutated refund state.');
    $customer = $wire->users->get((int) ($state['customer_user_id'] ?? 0));
    if (!$customer || !$customer->id || strtolower((string) $customer->email) !== strtolower((string) $state['customer_email'])) throw new WireException('A denied privacy probe mutated the customer.');
    $stored = (array) $wire->modules->getConfig('Mercato');
    if (($stored['notification_templates_json'] ?? '{}') !== ($state['notification_templates_json'] ?? '{}')) throw new WireException('A denied notification probe mutated notification templates.');
    $suspicious = [];
    foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) {
        $path = rtrim((string) $wire->config->paths->logs, '/') . '/' . basename((string) $name);
        if (!is_file($path) || filesize($path) <= (int) $offset) continue;
        $handle = fopen($path, 'rb'); if (!$handle) continue; fseek($handle, (int) $offset); $appended = (string) stream_get_contents($handle); fclose($handle);
        foreach (preg_split('/\R/', $appended) ?: [] as $line) {
            if (str_contains($line, (string) $state['run_id']) && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspicious[] = basename($path) . ': ' . substr($line, 0, 500);
        }
    }
    if ($suspicious) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspicious));
    echo json_encode(['verified' => true, 'order_id' => (int) $order->id, 'fulfilment' => (string) $order->mrc_fulfilment_status, 'tracking' => (string) $order->mrc_fulfilment_tracking, 'refund' => 0, 'privacy' => 'unchanged', 'notifications' => 'unchanged', 'suspicious_logs' => 0], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$prefix = 'e2e-' . $runId . '-';
$password = 'E2E-Admin-42!';
$stored = (array) $wire->modules->getConfig('Mercato');
$originalConfig = [];
foreach (['enabled_notification_events', 'notification_templates_json'] as $key) $originalConfig[$key] = ['exists' => array_key_exists($key, $stored), 'value' => $stored[$key] ?? null];
$state = [
    'schema_version' => 1,
    'run_id' => $runId,
    'created_at' => gmdate(DATE_ATOM),
    'original_config' => $originalConfig,
    'notification_templates_json' => (string) ($stored['notification_templates_json'] ?? '{}'),
    'order_ids' => [], 'users' => [], 'roles' => [],
    'expected_tracking' => 'E2E-' . strtoupper(substr(hash('sha256', $runId), 0, 12)),
    'expected_note' => 'E2E fulfilment verified ' . $runId,
];
$writeState($state);
$stored['enabled_notification_events'] = [];
$wire->modules->saveConfig('Mercato', $stored);
$commerce->set('enabled_notification_events', []);

$permissionRows = [
    'staff' => ['page-edit', 'mercato-admin', 'mercato-view-orders', 'mercato-view-customers'],
    'manager' => ['page-edit', 'mercato-admin', 'mercato-view-orders', 'mercato-fulfil-orders'],
];
$createdRoles = [];
foreach ($permissionRows as $kind => $permissionNames) {
    $roleName = 'e2e-admin-' . $kind . '-' . substr(hash('sha256', $runId), 0, 10);
    $role = $wire->roles->add($roleName);
    if (!$role || !$role->id) throw new WireException("Could not create admin fixture role {$roleName}.");
    $role->of(false);
    foreach ($permissionNames as $permissionName) {
        $permission = $wire->permissions->get($permissionName);
        if (!$permission || !$permission->id) throw new WireException("Required permission {$permissionName} is missing.");
        // Use the public string form used by the installer. Passing a newly
        // loaded Permission Page here can leave ProcessWire's in-request role
        // permission cache out of sync for roles created in the same request.
        $role->addPermission($permissionName);
    }
    $wire->roles->save($role);
    $role = $wire->roles->get((int) $role->id);
    foreach ($permissionNames as $permissionName) {
        if (!$role->hasPermission($permissionName)) throw new WireException("Fixture role {$roleName} did not persist {$permissionName}.");
    }
    foreach (array_diff(array_merge(...array_values($permissionRows)), $permissionNames) as $deniedPermissionName) {
        if ($role->hasPermission($deniedPermissionName)) throw new WireException("Fixture role {$roleName} unexpectedly inherited {$deniedPermissionName}.");
    }
    $createdRoles[$kind] = $role;
    $state['roles'][] = ['id' => (int) $role->id, 'name' => (string) $role->name]; $writeState($state);
}

$makeUser = static function (string $kind, string $email, $role) use ($wire, $password, $runId, &$state, $writeState): User {
    $user = new User(); $user->of(false); $user->name = 'e2e-admin-' . $kind . '-' . substr(hash('sha256', $runId . '-' . $kind), 0, 12);
    $user->email = $email; $user->pass = $password; $user->addRole($role); $wire->users->save($user);
    $state['users'][] = ['id' => (int) $user->id, 'name' => (string) $user->name, 'email' => $email]; $writeState($state);
    return $user;
};
$staffEmail = $prefix . 'staff@example.test';
$managerEmail = $prefix . 'manager@example.test';
$staff = $makeUser('staff', $staffEmail, $createdRoles['staff']);
$manager = $makeUser('manager', $managerEmail, $createdRoles['manager']);
foreach (['staff' => $staff, 'manager' => $manager] as $kind => $user) {
    $wire->pages->uncache($user);
    $user = $wire->users->get((int) $user->id);
    foreach ($permissionRows[$kind] as $permissionName) {
        if (!$user->hasPermission($permissionName)) throw new WireException("Fixture user {$user->name} did not receive {$permissionName}.");
    }
    foreach (array_diff(array_merge(...array_values($permissionRows)), $permissionRows[$kind]) as $deniedPermissionName) {
        if ($user->hasPermission($deniedPermissionName)) throw new WireException("Fixture user {$user->name} unexpectedly received {$deniedPermissionName}.");
    }
}

$customerRole = $wire->roles->get('mercato-customer');
if (!$customerRole || !$customerRole->id) throw new WireException('The Mercato customer role is missing.');
$customerEmail = $prefix . 'customer@example.test';
$customer = new User(); $customer->of(false); $customer->name = 'e2e-admin-customer-' . substr(hash('sha256', $runId . '-customer'), 0, 12);
$customer->email = $customerEmail; $customer->pass = $password; $customer->addRole($customerRole); $customer->mrc_first_name = 'Admin'; $customer->mrc_last_name = 'Journey'; $customer->mrc_customer_verified = 1;
$wire->users->save($customer); $state['users'][] = ['id' => (int) $customer->id, 'name' => (string) $customer->name, 'email' => $customerEmail]; $writeState($state);

$items = [['id' => 'admin-browser-fixture', 'product_id' => 0, 'title' => 'Admin browser fixture', 'sku' => 'ADMIN-E2E', 'price' => 29, 'quantity' => 1, 'tax_rate' => 0, 'product_type' => 'service', 'stock_policy' => 'deny', 'uid' => 'admin-browser-fixture']];
$order = $commerce->orderRepository()->savePendingOrder([
    'first_name' => 'Admin', 'last_name' => 'Journey', 'email' => $customerEmail,
    'address' => 'E2E Admin Street', 'city' => 'Test City', 'zip' => '10001', 'country' => 'US',
    'payment_method' => 'demo', 'payment_status' => 'paid', 'payment_complete' => 1,
    'mrc_items' => json_encode($items, JSON_UNESCAPED_SLASHES),
    'mrc_subtotal_amount' => 29, 'mrc_shipping_amount' => 0, 'mrc_discount_total' => 0, 'mrc_total_amount' => 29, 'mrc_currency' => 'USD',
    'mrc_fulfilment_method' => 'carrier_delivery', 'mrc_fulfilment_label' => 'Delivery',
    'mrc_fulfilment_details' => json_encode(['type' => 'carrier_delivery', 'label' => 'Delivery', 'amount' => 0], JSON_UNESCAPED_SLASHES),
    'mrc_fulfilment_status' => 'unfulfilled', 'mrc_customer_user_id' => (int) $customer->id,
]);
$order = $wire->pages->getById((int) $order->id, ['cache' => false])->first();
$state['order_ids'][] = (int) $order->id;
$state['invoice'] = (string) $order->mrc_invoice_number;
$state['customer_user_id'] = (int) $customer->id;
$state['customer_email'] = $customerEmail;
$state['password'] = $password;
$state['staff'] = ['username' => (string) $staff->name, 'email' => $staffEmail];
$state['manager'] = ['username' => (string) $manager->name, 'email' => $managerEmail];
$state['customer'] = ['email' => $customerEmail];
$processUrl = (string) $wire->pages->get('process=ProcessMercato, include=all')->url;
$state['admin_url'] = (string) $wire->config->urls->admin;
$state['process_url'] = $processUrl;
$state['orders_url'] = rtrim($processUrl, '/') . '/orders/';
$state['order_detail_url'] = rtrim($processUrl, '/') . '/order-detail/?id=' . (int) $order->id;
$state['fulfilment_url'] = rtrim($processUrl, '/') . '/fulfilment/';
$state['notifications_url'] = rtrim($processUrl, '/') . '/notifications/';
$state['customer_detail_url'] = rtrim($processUrl, '/') . '/customer-detail/?key=' . rawurlencode($customerEmail);
$state['log_offsets'] = [];
foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) $state['log_offsets'][basename($path)] = (int) filesize($path);
$writeState($state);
echo json_encode(['ready' => true, 'run_id' => $runId, 'order_id' => (int) $order->id, 'invoice' => $state['invoice']], JSON_UNESCAPED_SLASHES) . "\n";
