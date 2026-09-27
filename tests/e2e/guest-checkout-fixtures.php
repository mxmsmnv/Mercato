<?php
declare(strict_types=1);

use ProcessWire\Page;
use ProcessWire\ProcessWire;
use ProcessWire\WireException;

$action = $argv[1] ?? '';
$site = rtrim((string) getenv('MERCATO_E2E_SITE'), '/');
$stateFile = (string) getenv('MERCATO_E2E_STATE');
$actions = ['setup', 'capture', 'expire', 'verify', 'cleanup'];
if (!in_array($action, $actions, true) || $site === '' || $stateFile === '') {
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php guest-checkout-fixtures.php " . implode('|', $actions) . "\n"); exit(2);
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1'; $wire = new ProcessWire($config);
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Guest checkout fixtures are forbidden in production mode.');
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for guest checkout fixture management.');
$wire->users->setCurrentUser($superuser); $wire->set('page', $wire->pages->get('/'));

$writeState = static function (array $state) use ($stateFile): void { if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) throw new WireException('Could not persist guest checkout fixture state.'); };
$loadState = static function () use ($stateFile): array { if (!is_file($stateFile)) throw new WireException('Guest checkout fixture state is missing.'); return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR); };
$relativeUrl = static function (string $url): string { $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/'); $query = (string) (parse_url($url, PHP_URL_QUERY) ?: ''); return $path . ($query !== '' ? '?' . $query : ''); };
$cleanup = static function (array $state) use ($wire): array {
    $deleted = ['orders' => 0, 'products' => 0]; $runId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? '')));
    foreach ((array) ($state['order_ids'] ?? []) as $id) { $order = $wire->pages->get((int) $id); if (!$order || !$order->id) continue; if ($runId === '' || !str_contains(strtolower((string) $order->mrc_email), $runId)) throw new WireException("Refusing to delete unexpected guest order {$order->id}."); $order->of(false); $wire->pages->delete($order, true); $deleted['orders']++; }
    foreach ((array) ($state['products'] ?? []) as $row) { $product = $wire->pages->get((int) ($row['id'] ?? 0)); if (!$product || !$product->id) continue; if ((string) $product->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $product->name, 'e2e-guest-')) throw new WireException("Refusing to delete unexpected guest product {$product->id}."); $product->of(false); $wire->pages->delete($product, true); $deleted['products']++; }
    $stored = (array) $wire->modules->getConfig('Mercato'); foreach ((array) ($state['original_config'] ?? []) as $key => $snapshot) { if (!empty($snapshot['exists'])) $stored[$key] = $snapshot['value']; else unset($stored[$key]); } $wire->modules->saveConfig('Mercato', $stored); return $deleted;
};

if ($action === 'cleanup') { if (!is_file($stateFile) || filesize($stateFile) === 0) { echo "No guest checkout fixture state; nothing to clean.\n"; exit(0); } echo json_encode(['cleaned' => true] + $cleanup($loadState()), JSON_UNESCAPED_SLASHES) . "\n"; exit(0); }

if ($action !== 'setup') {
    $state = $loadState();
    $findOrder = static function (string $email) use ($wire, $commerce): Page { $safe = $wire->sanitizer->selectorValue($email); $orders = $wire->pages->find('template=' . $wire->sanitizer->selectorValue((string) $commerce->order_template) . ", include=all, mrc_email={$safe}, limit=2"); if ($orders->count() !== 1) throw new WireException("Expected exactly one guest order for {$email}; found {$orders->count()}."); return $orders->first(); };
    if ($action === 'capture') {
        foreach (['physical', 'digital'] as $kind) {
            $order = $findOrder((string) $state[$kind . '_email']);
            if ((string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1 || (int) ($order->mrc_customer_user_id ?? 0) !== 0) throw new WireException("{$kind} guest checkout did not persist a paid ownerless order.");
            $state['order_ids'][] = (int) $order->id; $state[$kind . '_order_id'] = (int) $order->id; $state[$kind . '_invoice'] = (string) $order->mrc_invoice_number;
            $state[$kind . '_status_url'] = $relativeUrl($commerce->getOrderStatusUrl($order)); $state[$kind . '_receipt_url'] = $relativeUrl($commerce->getOrderReceiptUrl($order)); $state[$kind . '_pdf_url'] = $relativeUrl($commerce->getOrderReceiptPdfUrl($order)); $state[$kind . '_payment_url'] = $relativeUrl($commerce->getPaymentLinkUrl($order)); $state[$kind . '_access_url'] = $relativeUrl($commerce->getOrderAccessRecoveryUrl($order));
            if ($state[$kind . '_access_url'] === '') throw new WireException("{$kind} access recovery URL was not generated.");
        }
        $digitalOrder = $wire->pages->get((int) $state['digital_order_id']); $downloads = $commerce->getOrderDigitalDownloads($digitalOrder);
        if (count($downloads) !== 1) throw new WireException('Digital guest order did not expose one download.');
        $state['download_url'] = $relativeUrl((string) $downloads[0]['url']); $state['download_filename'] = (string) $downloads[0]['filename'];
        $state['order_ids'] = array_values(array_unique(array_map('intval', $state['order_ids']))); $writeState($state);
        echo json_encode(['captured' => true, 'physical_order_id' => $state['physical_order_id'], 'digital_order_id' => $state['digital_order_id']], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
    }
    if ($action === 'expire') {
        foreach (['physical', 'digital'] as $kind) { $order = $wire->pages->get((int) ($state[$kind . '_order_id'] ?? 0)); if (!$order->id) throw new WireException("{$kind} order is missing before expiry."); $order->of(false); $order->created = time() - (3 * 86400); $wire->pages->save($order, ['quiet' => true]); if (!$commerce->areOrderSignedLinksExpired($wire->pages->getById((int) $order->id, ['cache' => false])->first())) throw new WireException("{$kind} order did not cross the signed-link expiry boundary."); }
        $state['expired_at'] = gmdate(DATE_ATOM); $writeState($state); echo json_encode(['expired' => true, 'orders' => $state['order_ids']], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
    }
    if ($action === 'verify') {
        if (empty($state['expired_at']) || count((array) $state['order_ids']) !== 2) throw new WireException('Guest route lifecycle did not reach capture and expiry.');
        $physical = $wire->pages->getById((int) $state['physical_order_id'], ['cache' => false])->first(); $digital = $wire->pages->getById((int) $state['digital_order_id'], ['cache' => false])->first(); $product = $wire->pages->getById((int) $state['physical_product_id'], ['cache' => false])->first();
        foreach (['physical' => $physical, 'digital' => $digital] as $kind => $order) if (!$order->id || (string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1 || (int) ($order->mrc_customer_user_id ?? 0) !== 0 || !$commerce->areOrderSignedLinksExpired($order)) throw new WireException("{$kind} guest order state regressed.");
        if ((int) $physical->mrc_inventory_adjusted !== 1 || (int) $product->mrc_stock !== (int) $state['initial_physical_stock'] - 1) throw new WireException('Physical guest inventory was not decremented exactly once.');
        $details = json_decode((string) $digital->mrc_download_details, true); $events = is_array($details) ? (array) ($details['events'] ?? []) : [];
        if (count($events) !== 1 || (int) ($events[0]['product_id'] ?? 0) !== (int) $state['digital_product_id']) throw new WireException('Digital guest download was not recorded exactly once.');
        $suspicious = []; foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) { $path = rtrim((string) $wire->config->paths->logs, '/') . '/' . basename((string) $name); if (!is_file($path) || filesize($path) <= (int) $offset) continue; $h = fopen($path, 'rb'); if (!$h) continue; fseek($h, (int) $offset); $appended = (string) stream_get_contents($h); fclose($h); foreach (preg_split('/\R/', $appended) ?: [] as $line) if (str_contains($line, (string) $state['run_id']) && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspicious[] = basename($path) . ': ' . substr($line, 0, 500); }
        if ($suspicious) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspicious));
        echo json_encode(['verified' => true, 'orders' => [(int) $physical->id, (int) $digital->id], 'payment' => 'paid', 'guest_owner_ids' => [0, 0], 'physical_stock' => (int) $product->mrc_stock, 'download_events' => 1, 'signed_links_expired' => true, 'suspicious_logs' => 0], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
    }
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)); $stored = (array) $wire->modules->getConfig('Mercato');
$keys = ['enabled_payment_methods', 'checkout_enabled', 'customer_accounts_mode', 'enabled_notification_events', 'notification_sender_email', 'signed_link_retention_days', 'access_recovery_enabled', 'analytics_enabled']; $original = [];
foreach ($keys as $key) $original[$key] = ['exists' => array_key_exists($key, $stored), 'value' => $stored[$key] ?? null];
$state = ['schema_version' => 1, 'run_id' => $runId, 'created_at' => gmdate(DATE_ATOM), 'physical_email' => "e2e-{$runId}-physical@example.test", 'digital_email' => "e2e-{$runId}-digital@example.test", 'initial_physical_stock' => 5, 'products' => [], 'order_ids' => [], 'original_config' => $original]; $writeState($state);
$stored['enabled_payment_methods'] = ['demo']; $stored['checkout_enabled'] = true; $stored['customer_accounts_mode'] = 'optional'; $stored['enabled_notification_events'] = []; $stored['notification_sender_email'] = ''; $stored['signed_link_retention_days'] = 1; $stored['access_recovery_enabled'] = true; $stored['analytics_enabled'] = false; $wire->modules->saveConfig('Mercato', $stored);
foreach (['enabled_payment_methods' => ['demo'], 'checkout_enabled' => true, 'customer_accounts_mode' => 'optional', 'enabled_notification_events' => [], 'notification_sender_email' => '', 'signed_link_retention_days' => 1, 'access_recovery_enabled' => true, 'analytics_enabled' => false] as $key => $value) $commerce->set($key, $value);
$products = $wire->pages->get('/products/'); if (!$products->id) throw new WireException('Install the Mercato demo storefront before guest checkout acceptance.');
$makeProduct = static function (string $kind, float $price) use ($wire, $products, $runId, &$state, $writeState): Page { $product = new Page(); $product->template = 'mrc-product'; $product->parent = $products; $product->name = "e2e-guest-{$kind}-{$runId}"; $product->of(false); $product->title = 'Guest ' . ucfirst($kind) . ' Fixture'; $product->mrc_price = $price; $product->mrc_tax_rate = 0; $product->mrc_sku = 'GUEST-' . strtoupper(substr(hash('sha256', $runId . $kind), 0, 10)); $product->mrc_product_type = $kind; $product->mrc_product_status = 'active'; $product->mrc_stock_policy = 'deny'; if ($kind === 'physical') { $product->mrc_stock = 5; $product->mrc_shipping_price = 3; } else { $product->mrc_stock = 1; $product->mrc_download_limit = 1; $product->mrc_download_expiry_days = 1; } $wire->pages->save($product); $state['products'][] = ['id' => (int) $product->id, 'name' => (string) $product->name]; $state[$kind . '_product_id'] = (int) $product->id; $state[$kind . '_product_url'] = (string) $product->url; $writeState($state); return $product; };
$makeProduct('physical', 21); $digital = $makeProduct('digital', 9);
$temporary = tempnam(sys_get_temp_dir(), 'mercato-guest-download-'); if ($temporary === false || file_put_contents($temporary, "Mercato guest download {$runId}\n") === false) throw new WireException('Could not create guest digital file.');
$filename = 'mercato-guest-' . substr(hash('sha256', $runId), 0, 10) . '.txt'; $named = dirname($temporary) . '/' . $filename; if (!rename($temporary, $named)) throw new WireException('Could not name guest digital file.'); $digital->of(false); $digital->mrc_digital_files->add($named); $wire->pages->save($digital); @unlink($named);
$state['download_filename'] = $filename; $state['download_contents'] = "Mercato guest download {$runId}\n"; $state['log_offsets'] = []; foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $path) $state['log_offsets'][basename($path)] = (int) filesize($path); $writeState($state);
echo json_encode(['ready' => true, 'run_id' => $runId, 'physical_product_id' => $state['physical_product_id'], 'digital_product_id' => $state['digital_product_id']], JSON_UNESCAPED_SLASHES) . "\n";
