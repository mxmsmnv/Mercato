<?php
declare(strict_types=1);

use ProcessWire\ProcessWire;
use ProcessWire\User;
use ProcessWire\WireException;

$action = $argv[1] ?? '';
$site = rtrim((string) getenv('MERCATO_E2E_SITE'), '/');
$stateFile = (string) getenv('MERCATO_E2E_STATE');
if (!in_array($action, ['setup', 'verify', 'cleanup'], true) || $site === '' || $stateFile === '') {
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php ownership-boundaries-fixtures.php setup|verify|cleanup\n");
    exit(2);
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Ownership-boundary fixtures are forbidden in production mode.');
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for ownership-boundary fixture management.');
$wire->users->setCurrentUser($superuser); $wire->set('page', $wire->pages->get('/'));

$writeState = static function (array $state) use ($stateFile): void {
    if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) throw new WireException('Could not persist ownership-boundary fixture state.');
};
$loadState = static function () use ($stateFile): array {
    if (!is_file($stateFile)) throw new WireException('Ownership-boundary fixture state is missing.');
    return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
};
$relativeUrl = static function (string $url): string {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/'); $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
    return $path . ($query !== '' ? '?' . $query : '');
};
$cleanup = static function (array $state) use ($wire): array {
    $deleted = ['orders' => 0, 'users' => 0, 'roles' => 0];
    $runId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? '')));
    foreach ((array) ($state['order_ids'] ?? []) as $id) {
        $order = $wire->pages->get((int) $id); if (!$order || !$order->id) continue;
        if ($runId === '' || !str_contains(strtolower((string) $order->mrc_email), $runId)) throw new WireException("Refusing to delete unexpected ownership order {$order->id}.");
        $order->of(false); $wire->pages->delete($order, true); $deleted['orders']++;
    }
    foreach ((array) ($state['users'] ?? []) as $row) {
        $user = $wire->users->get((int) ($row['id'] ?? 0)); if (!$user || !$user->id) continue;
        if ((string) $user->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $user->name, 'e2e-boundary-')) throw new WireException("Refusing to delete unexpected ownership user {$user->id}.");
        $wire->users->delete($user); $deleted['users']++;
    }
    foreach ((array) ($state['roles'] ?? []) as $row) {
        $role = $wire->roles->get((int) ($row['id'] ?? 0)); if (!$role || !$role->id) continue;
        if ((string) $role->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $role->name, 'e2e-boundary-')) throw new WireException("Refusing to delete unexpected ownership role {$role->id}.");
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
    if (!is_file($stateFile) || filesize($stateFile) === 0) { echo "No ownership-boundary fixture state; nothing to clean.\n"; exit(0); }
    echo json_encode(['cleaned' => true] + $cleanup($loadState()), JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

if ($action === 'verify') {
    $state = $loadState(); $orders = [];
    foreach ((array) $state['order_ids'] as $id) {
        $order = $wire->pages->getById((int) $id, ['cache' => false])->first();
        if (!$order || !$order->id) throw new WireException("Ownership order {$id} is missing.");
        $orders[(int) $id] = $order;
        if ((string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1 || (float) ($order->mrc_refunded_amount ?? 0) !== 0.0) throw new WireException("Boundary probes mutated payment state for order {$id}.");
    }
    $ownerOrder = $orders[(int) $state['owner_order_id']]; $secondOrder = $orders[(int) $state['second_order_id']]; $expiredOrder = $orders[(int) $state['expired_order_id']];
    if ((int) $ownerOrder->mrc_customer_user_id !== (int) $state['owner_user_id'] || (int) $expiredOrder->mrc_customer_user_id !== (int) $state['owner_user_id'] || (int) $secondOrder->mrc_customer_user_id !== (int) $state['second_user_id']) throw new WireException('A cross-account probe changed order ownership.');
    $owner = $wire->users->get((int) $state['owner_user_id']); $second = $wire->users->get((int) $state['second_user_id']);
    if (!$owner->id || !$second->id) throw new WireException('Ownership customers are missing.');
    if ((string) $owner->mrc_first_name !== (string) $state['owner_first_name'] || (int) $owner->mrc_customer_revision !== (int) $state['owner_revision']) throw new WireException('The victim account was changed by a cross-account probe.');
    if ((string) $second->mrc_first_name !== (string) $state['second_expected_first_name'] || (int) $second->mrc_customer_revision !== (int) $state['second_initial_revision'] + 1) throw new WireException('The acting customer profile did not persist exactly one self-owned update.');
    if (!$commerce->areOrderSignedLinksExpired($expiredOrder)) throw new WireException('The expired signed-route order no longer crosses the retention boundary.');
    $staff = $wire->users->get((int) $state['staff_user_id']);
    if (!$staff->id || $staff->hasPermission('mercato-view-orders') || $staff->hasPermission('mercato-refund-orders')) throw new WireException('Unauthorized staff permissions changed.');
    $suspicious = [];
    foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) {
        $path = rtrim((string) $wire->config->paths->logs, '/') . '/' . basename((string) $name);
        if (!is_file($path) || filesize($path) <= (int) $offset) continue;
        $handle = fopen($path, 'rb'); if (!$handle) continue; fseek($handle, (int) $offset); $appended = (string) stream_get_contents($handle); fclose($handle);
        foreach (preg_split('/\R/', $appended) ?: [] as $line) if (str_contains($line, (string) $state['run_id']) && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspicious[] = basename($path) . ': ' . substr($line, 0, 500);
    }
    if ($suspicious) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspicious));
    echo json_encode(['verified' => true, 'owner_order_id' => (int) $ownerOrder->id, 'second_order_id' => (int) $secondOrder->id, 'expired_order_id' => (int) $expiredOrder->id, 'ownership' => 'unchanged', 'owner_profile' => 'unchanged', 'second_profile_revision' => (int) $second->mrc_customer_revision, 'payment_states' => 'paid', 'suspicious_logs' => 0], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)); $password = 'E2E-Boundary-42!';
$stored = (array) $wire->modules->getConfig('Mercato'); $keys = ['customer_accounts_mode', 'account_claim_guest_orders', 'enabled_notification_events', 'notification_sender_email', 'signed_link_retention_days']; $original = [];
foreach ($keys as $key) $original[$key] = ['exists' => array_key_exists($key, $stored), 'value' => $stored[$key] ?? null];
$state = ['schema_version' => 1, 'run_id' => $runId, 'created_at' => gmdate(DATE_ATOM), 'password' => $password, 'order_ids' => [], 'users' => [], 'roles' => [], 'original_config' => $original, 'owner_first_name' => 'Boundary Owner', 'second_expected_first_name' => 'Boundary Second Updated']; $writeState($state);
$stored['customer_accounts_mode'] = 'optional'; $stored['account_claim_guest_orders'] = true; $stored['enabled_notification_events'] = []; $stored['notification_sender_email'] = ''; $stored['signed_link_retention_days'] = 1; $wire->modules->saveConfig('Mercato', $stored);
foreach (['customer_accounts_mode' => 'optional', 'account_claim_guest_orders' => true, 'enabled_notification_events' => [], 'notification_sender_email' => '', 'signed_link_retention_days' => 1] as $key => $value) $commerce->set($key, $value);

$roleName = 'e2e-boundary-staff-' . substr(hash('sha256', $runId), 0, 10); $role = $wire->roles->add($roleName);
if (!$role || !$role->id) throw new WireException("Could not create ownership-boundary role {$roleName}.");
$role->of(false);
foreach (['page-edit', 'mercato-admin'] as $permissionName) { if (!$wire->permissions->get($permissionName)->id) throw new WireException("Required permission {$permissionName} is missing."); $role->addPermission($permissionName); }
$wire->roles->save($role); $role = $wire->roles->get((int) $role->id);
foreach (['page-edit', 'mercato-admin'] as $permissionName) if (!$role->hasPermission($permissionName)) throw new WireException("Ownership-boundary role did not persist {$permissionName}.");
foreach (['mercato-view-orders', 'mercato-view-customers', 'mercato-refund-orders'] as $permissionName) if ($role->hasPermission($permissionName)) throw new WireException("Ownership-boundary role unexpectedly inherited {$permissionName}.");
$state['roles'][] = ['id' => (int) $role->id, 'name' => (string) $role->name]; $writeState($state);
$staff = new User(); $staff->of(false); $staff->name = 'e2e-boundary-staff-' . substr(hash('sha256', $runId . '-staff'), 0, 12); $staff->email = "e2e-{$runId}-staff@example.test"; $staff->pass = $password; $staff->addRole($role); $wire->users->save($staff);
$state['users'][] = ['id' => (int) $staff->id, 'name' => (string) $staff->name]; $state['staff_user_id'] = (int) $staff->id; $state['staff_username'] = (string) $staff->name; $writeState($state);

$customerRole = $wire->roles->get('mercato-customer'); if (!$customerRole || !$customerRole->id) throw new WireException('The Mercato customer role is missing.');
$makeCustomer = static function (string $kind, string $firstName) use ($wire, $customerRole, $password, $runId, &$state, $writeState): User {
    $user = new User(); $user->of(false); $user->name = 'e2e-boundary-' . $kind . '-' . substr(hash('sha256', $runId . '-' . $kind), 0, 12); $user->email = "e2e-{$runId}-{$kind}@example.test"; $user->pass = $password; $user->addRole($customerRole); $user->mrc_customer_verified = 1; $user->mrc_first_name = $firstName; $user->mrc_last_name = 'Customer'; $user->mrc_customer_revision = 0; $wire->users->save($user);
    $state['users'][] = ['id' => (int) $user->id, 'name' => (string) $user->name]; $writeState($state); return $user;
};
$owner = $makeCustomer('owner', (string) $state['owner_first_name']); $second = $makeCustomer('second', 'Boundary Second');
$state['owner_user_id'] = (int) $owner->id; $state['owner_email'] = (string) $owner->email; $state['owner_revision'] = (int) $owner->mrc_customer_revision;
$state['second_user_id'] = (int) $second->id; $state['second_email'] = (string) $second->email; $state['second_initial_revision'] = (int) $second->mrc_customer_revision; $writeState($state);

$items = [['id' => 'ownership-boundary', 'product_id' => 0, 'title' => 'Ownership boundary fixture', 'sku' => 'BOUNDARY-E2E', 'price' => 18, 'quantity' => 1, 'tax_rate' => 0, 'product_type' => 'service', 'stock_policy' => 'deny', 'uid' => 'ownership-boundary']];
$makeOrder = static function (User $customer, string $label) use ($commerce, $items, $wire, &$state, $writeState) {
    $order = $commerce->orderRepository()->savePendingOrder(['first_name' => (string) $customer->mrc_first_name, 'last_name' => 'Customer', 'email' => (string) $customer->email, 'payment_method' => 'demo', 'payment_status' => 'paid', 'payment_complete' => 1, 'mrc_items' => json_encode($items, JSON_UNESCAPED_SLASHES), 'mrc_subtotal_amount' => 18, 'mrc_total_amount' => 18, 'mrc_currency' => 'USD', 'mrc_fulfilment_status' => 'unfulfilled', 'mrc_customer_user_id' => (int) $customer->id, 'mrc_notes' => $label]);
    $order = $wire->pages->getById((int) $order->id, ['cache' => false])->first(); $state['order_ids'][] = (int) $order->id; $writeState($state); return $order;
};
$ownerOrder = $makeOrder($owner, 'owner-' . $runId); $secondOrder = $makeOrder($second, 'second-' . $runId); $expiredOrder = $makeOrder($owner, 'expired-' . $runId);
$expiredOrder->of(false); $expiredOrder->created = time() - (3 * 86400); $wire->pages->save($expiredOrder, ['quiet' => true]); $expiredOrder = $wire->pages->getById((int) $expiredOrder->id, ['cache' => false])->first();
if (!$commerce->areOrderSignedLinksExpired($expiredOrder)) throw new WireException('The signed-route expiry fixture did not cross the retention boundary.');
$state['owner_order_id'] = (int) $ownerOrder->id; $state['owner_invoice'] = (string) $ownerOrder->mrc_invoice_number; $state['second_order_id'] = (int) $secondOrder->id; $state['second_invoice'] = (string) $secondOrder->mrc_invoice_number; $state['expired_order_id'] = (int) $expiredOrder->id; $state['expired_invoice'] = (string) $expiredOrder->mrc_invoice_number;
foreach (['owner' => $ownerOrder, 'expired' => $expiredOrder] as $prefix => $order) { $state[$prefix . '_status_url'] = $relativeUrl($commerce->getOrderStatusUrl($order)); $state[$prefix . '_receipt_url'] = $relativeUrl($commerce->getOrderReceiptUrl($order)); $state[$prefix . '_pdf_url'] = $relativeUrl($commerce->getOrderReceiptPdfUrl($order)); }
$processUrl = (string) $wire->pages->get('process=ProcessMercato, include=all')->url; $state['process_url'] = $processUrl; $state['order_detail_url'] = rtrim($processUrl, '/') . '/order-detail/?id=' . (int) $ownerOrder->id;
$state['log_offsets'] = []; foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) $state['log_offsets'][basename($path)] = (int) filesize($path);
$writeState($state); echo json_encode(['ready' => true, 'run_id' => $runId, 'owner_order_id' => (int) $ownerOrder->id, 'second_order_id' => (int) $secondOrder->id, 'expired_order_id' => (int) $expiredOrder->id], JSON_UNESCAPED_SLASHES) . "\n";
