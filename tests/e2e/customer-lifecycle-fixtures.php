<?php
declare(strict_types=1);

use ProcessWire\Page;
use ProcessWire\ProcessWire;
use ProcessWire\User;
use ProcessWire\WireException;

$action = $argv[1] ?? '';
$site = rtrim((string) getenv('MERCATO_E2E_SITE'), '/');
$stateFile = (string) getenv('MERCATO_E2E_STATE');
$actions = ['setup', 'prepare-registration', 'prepare-reset', 'prepare-claim', 'verify', 'cleanup'];
if (!in_array($action, $actions, true) || $site === '' || $stateFile === '') {
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php customer-lifecycle-fixtures.php " . implode('|', $actions) . "\n");
    exit(2);
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Customer lifecycle fixtures are forbidden in production mode.');
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for fixture management.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

$writeState = static function (array $state) use ($stateFile): void {
    if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        throw new WireException('Could not persist customer lifecycle fixture state.');
    }
};
$loadState = static function () use ($stateFile): array {
    if (!is_file($stateFile)) throw new WireException('Customer lifecycle fixture state is missing.');
    return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
};
$relativeUrl = static function (string $url): string {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
    return $path . ($query !== '' ? '?' . $query : '');
};
$tamperUrl = static function (string $url): string {
    $parts = parse_url($url);
    $path = (string) ($parts['path'] ?? '/');
    $trimmed = rtrim($path, '/');
    $last = substr($trimmed, -1);
    $path = substr($trimmed, 0, -1) . ($last === 'a' ? 'b' : 'a') . '/';
    return $path . (!empty($parts['query']) ? '?' . $parts['query'] : '');
};

$cleanup = static function (array $state) use ($wire): array {
    $deleted = ['orders' => 0, 'users' => 0, 'products' => 0];
    $runId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? '')));
    foreach ((array) ($state['order_ids'] ?? []) as $id) {
        $order = $wire->pages->get((int) $id);
        if (!$order || !$order->id) continue;
        if ($runId === '' || !str_contains(strtolower((string) $order->mrc_email), $runId)) throw new WireException("Refusing to delete unexpected lifecycle order {$order->id}.");
        $order->of(false); $wire->pages->delete($order, true); $deleted['orders']++;
    }
    foreach ((array) ($state['users'] ?? []) as $row) {
        $user = $wire->users->get((int) ($row['id'] ?? 0));
        if (!$user || !$user->id) continue;
        if ((string) $user->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $user->email, 'e2e-' . $runId . '-')) {
            throw new WireException("Refusing to delete unexpected lifecycle user {$user->id}.");
        }
        $wire->users->delete($user); $deleted['users']++;
    }
    foreach ((array) ($state['product_ids'] ?? []) as $id) {
        $product = $wire->pages->get((int) $id);
        if (!$product || !$product->id) continue;
        if ((string) $product->name !== 'e2e-lifecycle-' . $runId) throw new WireException("Refusing to delete unexpected lifecycle product {$product->id}.");
        $product->of(false); $wire->pages->delete($product, true); $deleted['products']++;
    }
    $stored = (array) $wire->modules->getConfig('Mercato');
    foreach ((array) ($state['original_config'] ?? []) as $key => $snapshot) {
        if (!empty($snapshot['exists'])) $stored[$key] = $snapshot['value']; else unset($stored[$key]);
    }
    $wire->modules->saveConfig('Mercato', $stored);
    return $deleted;
};

if ($action === 'cleanup') {
    if (!is_file($stateFile) || filesize($stateFile) === 0) { echo "No customer lifecycle fixture state; nothing to clean.\n"; exit(0); }
    echo json_encode(['cleaned' => true] + $cleanup($loadState()), JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($action !== 'setup') {
    $state = $loadState();
    $email = (string) $state['email'];
    $safeEmail = $wire->sanitizer->selectorValue($email);
    $user = $wire->users->get("email=$safeEmail, roles=mercato-customer, include=all");

    if ($action === 'prepare-registration') {
        if (!$user || !$user->id || (int) $user->mrc_customer_verified !== 0) throw new WireException('Browser registration did not create the expected unverified customer.');
        $token = hash('sha256', 'verify|' . $state['run_id']);
        $user->of(false); $user->mrc_customer_verification = json_encode(['hash' => hash('sha256', $token), 'expires' => time() + 3600]); $wire->users->save($user);
        $state['registered_user_id'] = (int) $user->id;
        $state['users'][] = ['id' => (int) $user->id, 'name' => (string) $user->name];
        $state['verification_url'] = '/account/?' . http_build_query(['action' => 'verify', 'user' => (int) $user->id, 'token' => $token]);
        $state['invalid_verification_url'] = '/account/?' . http_build_query(['action' => 'verify', 'user' => (int) $user->id, 'token' => 'invalid-' . $token]);
        $writeState($state); echo json_encode(['prepared' => 'registration', 'user_id' => (int) $user->id]) . "\n"; exit(0);
    }

    if (!$user || !$user->id || (int) $user->mrc_customer_verified !== 1) throw new WireException('Expected verified lifecycle customer is missing.');
    if ($action === 'prepare-reset') {
        $issued = json_decode((string) $user->mrc_customer_password_reset, true);
        if (!is_array($issued) || empty($issued['hash']) || (int) ($issued['expires'] ?? 0) <= time()) throw new WireException('Browser reset request did not persist a current reset token.');
        $token = hash('sha256', 'reset|' . $state['run_id']);
        $user->of(false); $user->mrc_customer_password_reset = json_encode(['hash' => hash('sha256', $token), 'expires' => time() + 3600]); $wire->users->save($user);
        $state['reset_url'] = '/account/?' . http_build_query(['action' => 'reset', 'user' => (int) $user->id, 'token' => $token]);
        $state['invalid_reset_url'] = '/account/?' . http_build_query(['action' => 'reset', 'user' => (int) $user->id, 'token' => 'invalid-' . $token]);
        $writeState($state); echo json_encode(['prepared' => 'reset', 'user_id' => (int) $user->id]) . "\n"; exit(0);
    }
    if ($action === 'prepare-claim') {
        $order = $wire->pages->get((int) $state['order_id']);
        $details = $order && $order->id ? json_decode((string) $order->mrc_customer_claim_details, true) : null;
        if (!is_array($details) || (int) ($details['user_id'] ?? 0) !== (int) $user->id || empty($details['token_hash'])) throw new WireException('Browser claim request did not persist owner-bound proof.');
        $token = hash('sha256', 'claim|' . $state['run_id']);
        $details['token_hash'] = hash('sha256', $token); $details['expires'] = time() + 3600;
        $order->of(false); $order->mrc_customer_claim_details = json_encode($details); $wire->pages->save($order);
        $state['claim_url'] = '/account/?' . http_build_query(['action' => 'claim', 'order' => (int) $order->id, 'token' => $token]);
        $state['invalid_claim_url'] = '/account/?' . http_build_query(['action' => 'claim', 'order' => (int) $order->id, 'token' => 'invalid-' . $token]);
        $writeState($state); echo json_encode(['prepared' => 'claim', 'order_id' => (int) $order->id]) . "\n"; exit(0);
    }
    if ($action === 'verify') {
        $order = $wire->pages->get((int) $state['order_id']);
        if (!$order || !$order->id || (int) $order->mrc_customer_user_id !== (int) $user->id || trim((string) $order->mrc_customer_claim_details) !== '') throw new WireException('Guest order claim did not persist exactly once.');
        if ((string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1) throw new WireException('Customer lifecycle changed paid state.');
        if (trim((string) $user->mrc_customer_verification) !== '' || trim((string) $user->mrc_customer_password_reset) !== '') throw new WireException('Consumed customer tokens were not cleared.');
        $downloads = json_decode((string) $order->mrc_download_details, true);
        $events = is_array($downloads) ? (array) ($downloads['events'] ?? []) : [];
        if (count($events) !== 1 || (int) ($events[0]['product_id'] ?? 0) !== (int) $state['product_id']) throw new WireException('Digital download was not recorded exactly once.');
        foreach ((array) $state['nonexistent_emails'] as $missingEmail) {
            $safe = $wire->sanitizer->selectorValue((string) $missingEmail);
            if ($wire->users->get("email=$safe, include=all")->id) throw new WireException('Enumeration probe unexpectedly created an account.');
        }
        $suspicious = [];
        foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) {
            $path = rtrim((string) $wire->config->paths->logs, '/') . '/' . basename((string) $name);
            if (!is_file($path) || filesize($path) <= (int) $offset) continue;
            $handle = fopen($path, 'rb'); if (!$handle) continue; fseek($handle, (int) $offset); $appended = (string) stream_get_contents($handle); fclose($handle);
            foreach (preg_split('/\R/', $appended) ?: [] as $line) if (str_contains($line, (string) $state['run_id']) && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspicious[] = basename($path) . ': ' . substr($line, 0, 500);
        }
        if ($suspicious) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspicious));
        echo json_encode(['verified' => true, 'user_id' => (int) $user->id, 'order_id' => (int) $order->id, 'claimed' => true, 'download_events' => 1, 'suspicious_logs' => 0], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
    }
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$email = 'e2e-' . $runId . '-lifecycle@example.test';
$otherEmail = 'e2e-' . $runId . '-other@example.test';
$password = 'E2E-Lifecycle-42!';
$newPassword = 'E2E-Lifecycle-84!';
$stored = (array) $wire->modules->getConfig('Mercato');
$keys = ['customer_accounts_mode', 'account_claim_guest_orders', 'account_login_attempts', 'account_login_window_seconds', 'enabled_notification_events', 'notification_sender_email'];
$original = [];
foreach ($keys as $key) $original[$key] = ['exists' => array_key_exists($key, $stored), 'value' => $stored[$key] ?? null];
$state = ['schema_version' => 1, 'run_id' => $runId, 'created_at' => gmdate(DATE_ATOM), 'email' => $email, 'other_email' => $otherEmail, 'password' => $password, 'new_password' => $newPassword, 'users' => [], 'order_ids' => [], 'product_ids' => [], 'original_config' => $original, 'nonexistent_emails' => ['e2e-' . $runId . '-missing@example.test']];
$writeState($state);
$stored['customer_accounts_mode'] = 'optional'; $stored['account_claim_guest_orders'] = true; $stored['account_login_attempts'] = 2; $stored['account_login_window_seconds'] = 900; $stored['enabled_notification_events'] = []; $stored['notification_sender_email'] = '';
$wire->modules->saveConfig('Mercato', $stored);
foreach (['customer_accounts_mode' => 'optional', 'account_claim_guest_orders' => true, 'account_login_attempts' => 2, 'account_login_window_seconds' => 900, 'enabled_notification_events' => [], 'notification_sender_email' => ''] as $key => $value) $commerce->set($key, $value);

$products = $wire->pages->get('/products/');
if (!$products->id) throw new WireException('Install the Mercato demo storefront before running lifecycle acceptance.');
$product = new Page(); $product->template = 'mrc-product'; $product->parent = $products; $product->name = 'e2e-lifecycle-' . $runId; $product->of(false);
$product->title = 'Lifecycle digital guide'; $product->mrc_price = 14; $product->mrc_tax_rate = 0; $product->mrc_sku = 'LIFE-' . strtoupper(substr(hash('sha256', $runId), 0, 10));
$product->mrc_product_type = 'digital'; $product->mrc_product_status = 'active'; $product->mrc_stock_policy = 'allow'; $product->mrc_download_limit = 1; $product->mrc_download_expiry_days = 1;
$wire->pages->save($product); $state['product_ids'][] = (int) $product->id; $state['product_id'] = (int) $product->id; $writeState($state);
$temporaryFile = tempnam(sys_get_temp_dir(), 'mercato-lifecycle-');
if ($temporaryFile === false || file_put_contents($temporaryFile, "Mercato lifecycle fixture {$runId}\n") === false) throw new WireException('Could not create lifecycle digital file.');
$downloadName = 'mercato-lifecycle-' . substr(hash('sha256', $runId), 0, 10) . '.txt';
$namedTemporaryFile = dirname($temporaryFile) . '/' . $downloadName;
if (!rename($temporaryFile, $namedTemporaryFile)) throw new WireException('Could not name lifecycle digital file.');
$product->of(false); $product->mrc_digital_files->add($namedTemporaryFile); $wire->pages->save($product); @unlink($namedTemporaryFile);

$customerRole = $wire->roles->get('mercato-customer');
if (!$customerRole || !$customerRole->id) throw new WireException('The Mercato customer role is missing.');
$other = new User(); $other->of(false); $other->name = 'e2e-lifecycle-other-' . substr(hash('sha256', $runId), 0, 12); $other->email = $otherEmail; $other->pass = $password; $other->addRole($customerRole); $other->mrc_customer_verified = 1; $wire->users->save($other);
$state['users'][] = ['id' => (int) $other->id, 'name' => (string) $other->name]; $state['other_user_id'] = (int) $other->id; $writeState($state);

$items = [['id' => (string) $product->id, 'product_id' => (int) $product->id, 'title' => (string) $product->title, 'sku' => (string) $product->mrc_sku, 'price' => 14, 'quantity' => 1, 'tax_rate' => 0, 'product_type' => 'digital', 'stock_policy' => 'allow', 'uid' => 'lifecycle-' . $product->id]];
$order = $commerce->orderRepository()->savePendingOrder(['first_name' => 'Lifecycle', 'last_name' => 'Customer', 'email' => $email, 'payment_method' => 'demo', 'payment_status' => 'paid', 'payment_complete' => 1, 'mrc_items' => json_encode($items, JSON_UNESCAPED_SLASHES), 'mrc_subtotal_amount' => 14, 'mrc_total_amount' => 14, 'mrc_currency' => 'USD', 'mrc_fulfilment_status' => 'delivered', 'mrc_fulfilment_method' => 'digital', 'mrc_fulfilment_label' => 'Digital delivery', 'mrc_customer_user_id' => 0]);
$order = $wire->pages->getById((int) $order->id, ['cache' => false])->first();
$state['order_ids'][] = (int) $order->id; $state['order_id'] = (int) $order->id; $state['invoice'] = (string) $order->mrc_invoice_number;
$state['status_url'] = $relativeUrl($commerce->getOrderStatusUrl($order)); $state['receipt_url'] = $relativeUrl($commerce->getOrderReceiptUrl($order)); $state['receipt_pdf_url'] = $relativeUrl($commerce->getOrderReceiptPdfUrl($order));
$downloads = $commerce->getOrderDigitalDownloads($order);
if (count($downloads) !== 1) throw new WireException('Lifecycle order did not expose its single digital download.');
$state['download_url'] = $relativeUrl((string) $downloads[0]['url']); $state['download_filename'] = (string) $downloads[0]['filename']; $state['download_contents'] = "Mercato lifecycle fixture {$runId}\n";
$state['invalid_status_url'] = $tamperUrl($state['status_url']); $state['invalid_receipt_url'] = $tamperUrl($state['receipt_url']);
$state['log_offsets'] = []; foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) $state['log_offsets'][basename($path)] = (int) filesize($path);
$writeState($state);
echo json_encode(['ready' => true, 'run_id' => $runId, 'order_id' => (int) $order->id, 'invoice' => $state['invoice'], 'product_id' => (int) $product->id], JSON_UNESCAPED_SLASHES) . "\n";
