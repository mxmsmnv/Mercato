<?php
declare(strict_types=1);

use ProcessWire\Page;
use ProcessWire\ProcessWire;
use ProcessWire\WireException;

$action = $argv[1] ?? '';
$site = rtrim((string) getenv('MERCATO_E2E_SITE'), '/');
$stateFile = (string) getenv('MERCATO_E2E_STATE');
if (!in_array($action, ['setup', 'verify', 'verify-recovery', 'verify-security', 'capture-cross-browser', 'verify-cross-browser', 'cleanup'], true) || $site === '' || $stateFile === '') {
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php fixtures.php setup|verify|verify-recovery|verify-security|capture-cross-browser|verify-cross-browser|cleanup\n");
    exit(2);
}
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
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
    $deleted = ['orders' => 0, 'pages' => 0, 'users' => 0];
    $ownedOrderIds = []; $ownedInvoices = [];
    $prefix = 'e2e-' . preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? ''))) . '-';
    if ($prefix !== 'e2e--') {
        foreach ($wire->pages->find('template=mrc-order, include=all, limit=1000') as $order) {
            $details = json_decode((string) $order->mrc_customer_details, true);
            $email = strtolower((string) ($details['email'] ?? $order->mrc_email ?? $order->mrc_customer_email ?? ''));
            if (!str_starts_with($email, $prefix)) continue;
            $ownedOrderIds[(int) $order->id] = true; $ownedInvoices[(string) $order->mrc_invoice_number] = true;
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
    $fixtureEmails = array_merge(
        [(string) ($state['csrf_probe_email'] ?? '')],
        (array) ($state['customer_fixture_emails'] ?? [])
    );
    foreach (array_unique(array_map('strtolower', $fixtureEmails)) as $fixtureEmail) {
        if ($fixtureEmail !== '' && str_starts_with($fixtureEmail, $prefix)) {
            $fixtureUser = $wire->users->get('email=' . $wire->sanitizer->selectorValue($fixtureEmail));
            if ($fixtureUser && $fixtureUser->id && $fixtureUser->hasRole('mercato-customer')) {
                $wire->users->delete($fixtureUser); $deleted['users']++;
            }
        }
    }
    $logsRoot = rtrim((string) $wire->config->paths->logs, '/') . '/'; $runId = (string) ($state['run_id'] ?? '');
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) {
        $lines = file($path); if ($lines === false) continue; $kept = [];
        foreach ($lines as $line) {
            $owned = $runId !== '' && str_contains($line, $runId); $json = strstr($line, '{'); $payload = $json === false ? null : json_decode($json, true);
            if (is_array($payload)) { $orderId=(int)($payload['order_id']??$payload['mrc_order_id']??0); $invoice=(string)($payload['invoice']??$payload['order']??''); $owned=$owned||isset($ownedOrderIds[$orderId])||($invoice!==''&&isset($ownedInvoices[$invoice])); }
            if (!$owned) $kept[] = $line;
        }
        if (count($kept)!==count($lines)) { if ($kept===[]) @unlink($path); else file_put_contents($path,implode('',$kept),LOCK_EX); }
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

if (in_array($action, ['verify', 'verify-recovery', 'verify-security', 'capture-cross-browser', 'verify-cross-browser'], true)) {
    if (!is_file($stateFile)) throw new WireException('Fixture state is missing; persisted acceptance state cannot be verified.');
    $state = json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
    $runId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($state['run_id'] ?? '')));
    if ($runId === '') throw new WireException('Fixture run ID is missing.');
    $prefix = 'e2e-' . $runId . '-';
    $probeEmail = strtolower((string) ($state['csrf_probe_email'] ?? ''));
    if ($probeEmail === '') throw new WireException('The CSRF probe identity is missing from fixture state.');
    $probeUser = $wire->users->get('email=' . $wire->sanitizer->selectorValue($probeEmail));
    if ($probeUser && $probeUser->id) throw new WireException('The browser CSRF rejection probe unexpectedly created a customer account.');
    if ($action === 'verify-security') {
        echo json_encode(['verified' => true, 'csrf_probe_persisted' => false], JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    if ($action === 'capture-cross-browser') {
        $project = strtolower(trim((string) ($argv[2] ?? '')));
        if (!in_array($project, ['chromium', 'firefox', 'webkit'], true)) throw new WireException('Cross-browser project must be chromium, firefox, or webkit.');
        $row = (array) ($state['cross_browser'][$project] ?? []); $email = (string) ($row['checkout_email'] ?? '');
        $safe = $wire->sanitizer->selectorValue($email); $orders = $wire->pages->find('template=' . $wire->sanitizer->selectorValue((string) $commerce->order_template) . ",include=all,mrc_email={$safe},limit=2");
        if ($email === '' || $orders->count() !== 1) throw new WireException("Expected exactly one {$project} cross-browser checkout order; found {$orders->count()}.");
        $order = $orders->first(); $relative = static function (string $url): string { $path=(string)(parse_url($url,PHP_URL_PATH)?:'/'); $query=(string)(parse_url($url,PHP_URL_QUERY)?:''); return $path.($query!==''?'?'.$query:''); };
        $state['cross_browser'][$project]['checkout_order'] = ['id'=>(int)$order->id,'invoice'=>(string)$order->mrc_invoice_number,'status_url'=>$relative($commerce->getOrderStatusUrl($order)),'receipt_url'=>$relative($commerce->getOrderReceiptUrl($order)),'pdf_url'=>$relative($commerce->getOrderReceiptPdfUrl($order))];
        if (file_put_contents($stateFile,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false) throw new WireException('Could not persist cross-browser checkout capture.');
        echo json_encode(['captured'=>true,'project'=>$project,'order_id'=>(int)$order->id,'invoice'=>(string)$order->mrc_invoice_number],JSON_UNESCAPED_SLASHES)."\n"; exit(0);
    }
    if ($action === 'verify-cross-browser') {
        $orderIds=[]; $units=0;
        foreach (['chromium','firefox','webkit'] as $project) {
            $row=(array)($state['cross_browser'][$project]??[]); $checkout=(array)($row['checkout_order']??[]); $order=$wire->pages->get((int)($checkout['id']??0));
            if (!$order->id || (string)$order->mrc_payment_status!=='paid' || (int)$order->mrc_payment_complete!==1 || (int)$order->mrc_inventory_adjusted!==1) throw new WireException("{$project} checkout is not durably paid and adjusted.");
            $recovery=(array)($row['recovery']??[]); $recoveryOrder=$wire->pages->get((int)($recovery['order_id']??0));
            if (!$recoveryOrder->id || (string)$recoveryOrder->mrc_payment_status!=='paid' || (int)$recoveryOrder->mrc_payment_complete!==1 || (int)$recoveryOrder->mrc_inventory_adjusted!==1) throw new WireException("{$project} recovery is not durably paid and adjusted.");
            $query=[]; parse_str((string)parse_url((string)($recovery['payment_url']??''),PHP_URL_QUERY),$query); if ($commerce->verifyPaymentLinkToken($recoveryOrder,(string)($query['mrc_token']??''))) throw new WireException("{$project} recovery link remained payable.");
            foreach ([$order,$recoveryOrder] as $paid) { foreach ((array)json_decode((string)$paid->mrc_items,true) as $item) if ((int)($item['product_id']??0)===(int)$state['product_id']) $units+=max(1,(int)($item['quantity']??1)); $orderIds[]=(int)$paid->id; }
        }
        if (count(array_unique($orderIds))!==6 || $units!==6) throw new WireException('Cross-browser matrix did not persist six unique paid single-unit orders.');
        $product=$wire->pages->getById((int)$state['product_id'],['cache'=>false])->first(); $expected=(int)$state['initial_stock']-6; if ((int)$product->mrc_stock!==$expected) throw new WireException("Cross-browser inventory expected {$expected}; found {$product->mrc_stock}.");
        $expired=$wire->pages->get((int)($state['recovery']['expired']['order_id']??0)); if (!$expired->id || (string)$expired->mrc_payment_status!=='failed' || (int)$expired->mrc_payment_complete!==0) throw new WireException('Expired recovery boundary mutated.');
        $suspicious=[]; $rawLogs=''; $markers=array_merge([(string)$state['run_id']],array_map('strval',$orderIds)); $logsRoot=rtrim((string)$wire->config->paths->logs,'/').'/';
        foreach ((array)($state['log_offsets']??[]) as $name=>$offset) { $path=$logsRoot.basename((string)$name); if(!is_file($path)||filesize($path)<=(int)$offset) continue; $handle=fopen($path,'rb'); if(!$handle) continue; fseek($handle,(int)$offset); $appended=(string)stream_get_contents($handle); fclose($handle); $rawLogs.=$appended; foreach(preg_split('/\R/',$appended)?:[] as $line){$owned=false;foreach($markers as $marker)if($marker!==''&&str_contains($line,$marker)){$owned=true;break;}if($owned&&preg_match('/\b(fatal|uncaught|exception|error)\b/i',$line))$suspicious[]=basename($path).': '.substr($line,0,500);}}
        if($suspicious) throw new WireException('Cross-browser run-owned log errors: '.implode(' | ',$suspicious));
        foreach((array)$state['cross_browser'] as $row){foreach([(string)($row['checkout_email']??''),(string)($row['recovery']['email']??'')] as $email)if($email!==''&&str_contains($rawLogs,$email))throw new WireException('Cross-browser logs exposed a raw customer email.');}
        echo json_encode(['verified'=>true,'engines'=>3,'paid_orders'=>6,'ordered_units'=>$units,'stock'=>(int)$product->mrc_stock,'suspicious_logs'=>0],JSON_UNESCAPED_SLASHES)."\n"; exit(0);
    }
    $product = $wire->pages->get((int) ($state['product_id'] ?? 0));
    if (!$product || !$product->id || !in_array((string) $product->name, (array) ($state['created_page_names'] ?? []), true)) {
        throw new WireException('The recorded acceptance product is missing or no longer matches the fixture state.');
    }

    if ($action === 'verify-recovery') {
        $recoveryState = (array) ($state['recovery'] ?? []); $recoveryOrders = [];
        foreach (['desktop', 'mobile'] as $key) {
            $row = (array) ($recoveryState[$key] ?? []); $order = $wire->pages->get((int) ($row['order_id'] ?? 0));
            if (!$order->id || (string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1 || (int) ($order->mrc_inventory_adjusted ?? 0) !== 1) {
                throw new WireException("Recovery order {$key} is not durably paid or adjusted exactly once.");
            }
            $query = []; parse_str((string) parse_url((string) ($row['payment_url'] ?? ''), PHP_URL_QUERY), $query);
            if ($commerce->verifyPaymentLinkToken($order, (string) ($query['mrc_token'] ?? ''))) throw new WireException("The consumed {$key} recovery link remained payable.");
            $recoveryOrders[] = (int) $order->id;
        }
        $expired = $wire->pages->get((int) ($recoveryState['expired']['order_id'] ?? 0));
        if (!$expired->id || (string) $expired->mrc_payment_status !== 'failed' || (int) $expired->mrc_payment_complete !== 0 || (int) ($expired->mrc_inventory_adjusted ?? 0) !== 0) {
            throw new WireException('The expired recovery order changed payment or inventory state.');
        }
        $expectedStock = (int) ($state['initial_stock'] ?? 0) - 2;
        if ((int) $product->mrc_stock !== $expectedStock) throw new WireException("Recovery inventory transition is incorrect; expected {$expectedStock}, found {$product->mrc_stock}.");
        echo json_encode(['verified' => true, 'recovery_orders' => $recoveryOrders, 'stock' => (int) $product->mrc_stock], JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    $orders = [];
    $recoveryOrders = [];
    $orderedUnits = 0;
    $recoveryUnits = 0;
    $recoveryState = (array) ($state['recovery'] ?? []);
    $recoveryIds = array_map(static fn(array $row): int => (int) ($row['order_id'] ?? 0), array_filter([
        (array) ($recoveryState['desktop'] ?? []),
        (array) ($recoveryState['mobile'] ?? []),
    ]));
    $crossRecoveryIds = array_map(static fn(array $row): int => (int) ($row['recovery']['order_id'] ?? 0), (array) ($state['cross_browser'] ?? []));
    $expiredId = (int) ($recoveryState['expired']['order_id'] ?? 0);
    foreach ($wire->pages->find('template=mrc-order, include=all, limit=1000') as $order) {
        $details = json_decode((string) $order->mrc_customer_details, true);
        $email = strtolower((string) ($details['email'] ?? $order->mrc_email ?? $order->mrc_customer_email ?? ''));
        if (!str_starts_with($email, $prefix)) continue;
        if ((int) $order->id === (int) ($state['customer_order_id'] ?? 0)) continue;
        if ((int) $order->id === $expiredId) {
            if ((string) $order->mrc_payment_status !== 'failed' || (int) $order->mrc_payment_complete !== 0 || (int) ($order->mrc_inventory_adjusted ?? 0) !== 0) {
                throw new WireException('The expired recovery order changed payment or inventory state.');
            }
            continue;
        }
        if (in_array((int) $order->id, $crossRecoveryIds, true)) {
            if ((string) $order->mrc_payment_status !== 'failed' || (int) $order->mrc_payment_complete !== 0 || (int) ($order->mrc_inventory_adjusted ?? 0) !== 0) throw new WireException("Unused cross-browser recovery order {$order->id} changed state.");
            continue;
        }
        if (in_array((int) $order->id, $recoveryIds, true)) {
            if ((string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1 || (int) ($order->mrc_inventory_adjusted ?? 0) !== 1) {
                throw new WireException("Recovery order {$order->id} is not durably paid or adjusted exactly once.");
            }
            foreach ((array) json_decode((string) $order->mrc_items, true) as $item) {
                if ((int) ($item['product_id'] ?? $item['id'] ?? 0) === (int) $product->id) {
                    $recoveryUnits += max(1, (int) ($item['quantity'] ?? 1));
                }
            }
            $recoveryOrders[] = (int) $order->id;
            continue;
        }
        if ((string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1) {
            throw new WireException("Acceptance order {$order->id} is not durably paid.");
        }
        if ((string) $order->mrc_discount_code !== (string) ($state['coupon'] ?? '') || (float) $order->mrc_discount_total <= 0) {
            throw new WireException("Acceptance order {$order->id} did not persist the expected coupon discount.");
        }
        foreach ((array) json_decode((string) $order->mrc_items, true) as $item) {
            if ((int) ($item['product_id'] ?? $item['id'] ?? 0) === (int) $product->id) {
                $orderedUnits += max(1, (int) ($item['quantity'] ?? 1));
            }
        }
        $orders[] = (int) $order->id;
    }
    if (count($orders) !== 2) {
        throw new WireException('Expected exactly two run-owned paid orders (browser and native API); found ' . count($orders) . '.');
    }
    if ($orderedUnits !== 2) throw new WireException("Expected two purchased fixture units; found {$orderedUnits}.");
    if (count($recoveryOrders) !== 2 || $recoveryUnits !== 2) {
        throw new WireException('Expected two recovered paid orders and two recovered units; found ' . count($recoveryOrders) . " orders and {$recoveryUnits} units.");
    }
    $expectedStock = (int) ($state['initial_stock'] ?? 0) - $orderedUnits - $recoveryUnits;
    if ((int) $product->mrc_stock !== $expectedStock) {
        throw new WireException("Fixture inventory transition is incorrect; expected {$expectedStock}, found {$product->mrc_stock}.");
    }
    $customer = $wire->users->get((int) ($state['customer_user_id'] ?? 0));
    $otherCustomer = $wire->users->get((int) ($state['other_customer_user_id'] ?? 0));
    $accountOrder = $wire->pages->get((int) ($state['customer_order_id'] ?? 0));
    if (!$customer->id || !$otherCustomer->id || !$accountOrder->id) throw new WireException('Authenticated customer fixture state is missing.');
    if ((int) $customer->mrc_customer_verified !== 1 || (string) $customer->mrc_phone !== '+1 555 0199') {
        throw new WireException('The browser customer profile update was not persisted.');
    }
    $addresses = json_decode((string) $customer->mrc_customer_addresses, true);
    if (!is_array($addresses) || ($addresses[0]['address'] ?? '') !== 'Updated Browser Street') {
        throw new WireException('The browser customer address update was not persisted.');
    }
    if ((string) $accountOrder->mrc_payment_status !== 'paid' || (int) $accountOrder->mrc_customer_user_id !== (int) $customer->id || (int) $accountOrder->mrc_customer_user_id === (int) $otherCustomer->id) {
        throw new WireException('Customer order ownership isolation is incorrect.');
    }
    foreach (['desktop' => $customer, 'mobile' => $otherCustomer] as $key => $owner) {
        $row = (array) ($recoveryState[$key] ?? []); $order = $wire->pages->get((int) ($row['order_id'] ?? 0));
        $query = []; parse_str((string) parse_url((string) ($row['payment_url'] ?? ''), PHP_URL_QUERY), $query);
        if (!$order->id || $commerce->verifyPaymentLinkToken($order, (string) ($query['mrc_token'] ?? ''))) {
            throw new WireException("The consumed {$key} recovery link remained payable after completion.");
        }
        if (!$commerce->customerAccountService()->ownsOrder($owner, $order)) throw new WireException("The {$key} recovery order lost its owner.");
        $wrongOwner = $key === 'desktop' ? $otherCustomer : $customer;
        if ($commerce->customerAccountService()->ownsOrder($wrongOwner, $order)) throw new WireException("The {$key} recovery order crossed customer ownership.");
    }
    $suspiciousLogs = [];
    $logsRoot = rtrim((string) $wire->config->paths->logs, '/') . '/';
    $markers = array_filter([$runId, $probeEmail, (string) $expiredId, ...array_map('strval', $recoveryIds)]);
    foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) {
        $safeName = basename((string) $name); $path = $logsRoot . $safeName;
        if (!is_file($path) || filesize($path) <= (int) $offset) continue;
        $handle = fopen($path, 'rb'); if (!$handle) continue; fseek($handle, (int) $offset); $appended = (string) stream_get_contents($handle); fclose($handle);
        foreach (preg_split('/\R/', $appended) ?: [] as $line) {
            $owned = false; foreach ($markers as $marker) if ($marker !== '' && str_contains($line, $marker)) { $owned = true; break; }
            if ($owned && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspiciousLogs[] = $safeName . ': ' . substr($line, 0, 500);
        }
    }
    if ($suspiciousLogs) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspiciousLogs));
    echo json_encode(['verified' => true, 'orders' => $orders, 'recovery_orders' => $recoveryOrders, 'ordered_units' => $orderedUnits + $recoveryUnits, 'stock' => (int) $product->mrc_stock, 'suspicious_logs' => 0], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$productName = 'e2e-product-' . $runId;
$discountName = 'e2e-discount-' . $runId;
$keys = ['enabled_payment_methods', 'checkout_enabled', 'analytics_enabled', 'analytics_adapters', 'analytics_default_consent', 'customer_accounts_mode', 'signed_link_retention_days', 'enabled_notification_events', 'notification_sender_email'];
$stored = (array) $wire->modules->getConfig('Mercato');
$original = [];
foreach ($keys as $key) $original[$key] = ['exists' => array_key_exists($key, $stored), 'value' => $stored[$key] ?? null];
$stored['enabled_payment_methods'] = ['demo'];
$stored['checkout_enabled'] = true;
$stored['analytics_enabled'] = true;
$stored['analytics_adapters'] = ['data_layer', 'first_party'];
$stored['analytics_default_consent'] = 'granted';
$stored['customer_accounts_mode'] = 'optional';
$stored['signed_link_retention_days'] = 1;
$stored['enabled_notification_events'] = [];
$stored['notification_sender_email'] = '';
$wire->modules->saveConfig('Mercato', $stored);
$commerce->set('signed_link_retention_days', 1);
$commerce->set('enabled_notification_events', []);
$commerce->set('notification_sender_email', '');

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

$customerPassword = 'E2E-Customer-42!';
$customerEmail = 'e2e-' . $runId . '-customer@example.test';
$otherCustomerEmail = 'e2e-' . $runId . '-other@example.test';
$state = [
    'schema_version' => 1, 'run_id' => $runId, 'created_at' => gmdate(DATE_ATOM),
    'product_id' => (int) $product->id, 'product_url' => (string) $product->url,
    'admin_url' => (string) $wire->config->urls->admin,
    'process_mercato_url' => (string) $wire->pages->get('process=ProcessMercato, include=all')->url,
    'csrf_probe_email' => 'e2e-' . $runId . '-csrf@example.test',
    'customer_email' => $customerEmail, 'customer_password' => $customerPassword,
    'other_customer_email' => $otherCustomerEmail, 'other_customer_password' => $customerPassword,
    'customer_fixture_emails' => [$customerEmail, $otherCustomerEmail],
    'initial_stock' => 50, 'coupon' => (string) $discount->mrc_discount_code,
    'created_page_ids' => [(int) $product->id, (int) $discount->id],
    'created_page_names' => [$productName, $discountName], 'original_config' => $original,
];
if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) throw new WireException('Could not write fixture state.');

$customerRole = $wire->roles->get('mercato-customer');
if (!$customerRole || !$customerRole->id) throw new WireException('The Mercato customer role is missing.');
$makeCustomer = static function (string $email, string $password, string $suffix) use ($wire, $customerRole, $runId): \ProcessWire\User {
    $customer = new \ProcessWire\User(); $customer->of(false);
    $customer->name = 'e2e-customer-' . substr(hash('sha256', $runId . '-' . $suffix), 0, 18);
    $customer->email = $email; $customer->pass = $password; $customer->addRole($customerRole);
    $customer->mrc_first_name = $suffix === 'owner' ? 'Browser' : 'Other';
    $customer->mrc_last_name = 'Customer'; $customer->mrc_customer_verified = 1;
    $wire->users->save($customer); return $customer;
};
$customer = $makeCustomer($customerEmail, $customerPassword, 'owner');
$otherCustomer = $makeCustomer($otherCustomerEmail, $customerPassword, 'other');
$accountItems = [['id'=>'account-browser-fixture','product_id'=>999991,'title'=>'Browser account fixture','sku'=>'ACCOUNT-E2E','price'=>12,'quantity'=>1,'tax_rate'=>0,'product_type'=>'service','uid'=>'account-browser-fixture']];
$accountOrder = $commerce->orderRepository()->savePendingOrder([
    'first_name'=>'Browser', 'last_name'=>'Customer', 'email'=>$customerEmail,
    'payment_status'=>'paid', 'payment_complete'=>1, 'mrc_items'=>json_encode($accountItems),
    'mrc_total_amount'=>12, 'mrc_currency'=>'USD', 'mrc_fulfilment_status'=>'delivered',
    'mrc_customer_user_id'=>(int) $customer->id,
]);
$state['customer_user_id'] = (int) $customer->id;
$state['other_customer_user_id'] = (int) $otherCustomer->id;
$state['customer_order_id'] = (int) $accountOrder->id;
$state['customer_invoice'] = (string) $accountOrder->mrc_invoice_number;

$recoveryItem = [
    'id' => 'recovery-' . (int) $product->id,
    'product_id' => (int) $product->id,
    'title' => (string) $product->title,
    'sku' => (string) $product->mrc_sku,
    'price' => (float) $product->mrc_price,
    'quantity' => 1,
    'tax_rate' => (float) $product->mrc_tax_rate,
    'product_type' => (string) $product->mrc_product_type,
    'stock_policy' => (string) $product->mrc_stock_policy,
    'uid' => 'recovery-' . (int) $product->id,
];
$makeFailedOrder = static function (string $email, int $customerId, string $label) use ($commerce, $recoveryItem): Page {
    return $commerce->orderRepository()->savePendingOrder([
        'first_name' => $label, 'last_name' => 'Recovery', 'email' => $email,
        'address' => 'Recovery Street', 'city' => 'Test City', 'zip' => '10001', 'country' => 'US',
        'payment_method' => 'demo', 'payment_status' => 'failed', 'payment_complete' => 0,
        'mrc_items' => json_encode([$recoveryItem], JSON_UNESCAPED_SLASHES),
        'mrc_subtotal_amount' => 24.50, 'mrc_shipping_amount' => 4.50,
        'mrc_discount_total' => 0, 'mrc_total_amount' => 29.00, 'mrc_currency' => 'USD',
        'mrc_fulfilment_method' => 'carrier_delivery', 'mrc_fulfilment_label' => 'Delivery',
        'mrc_fulfilment_details' => json_encode(['type' => 'carrier_delivery', 'amount' => 4.50], JSON_UNESCAPED_SLASHES),
        'mrc_customer_user_id' => $customerId,
    ]);
};
$desktopRecoveryOrder = $makeFailedOrder($customerEmail, (int) $customer->id, 'Desktop');
$mobileRecoveryOrder = $makeFailedOrder($otherCustomerEmail, (int) $otherCustomer->id, 'Mobile');
$desktopRecoveryOrder = $wire->pages->getById((int) $desktopRecoveryOrder->id, ['cache' => false])->first();
$mobileRecoveryOrder = $wire->pages->getById((int) $mobileRecoveryOrder->id, ['cache' => false])->first();
$expiredEmail = 'e2e-' . $runId . '-expired@example.test';
$expiredOrder = $makeFailedOrder($expiredEmail, 0, 'Expired');
$expiredOrder->of(false); $expiredOrder->created = time() - (3 * 86400); $wire->pages->save($expiredOrder, ['quiet' => true]);
$expiredOrder = $wire->pages->getById((int) $expiredOrder->id, ['cache' => false])->first();
if (!$commerce->areOrderSignedLinksExpired($expiredOrder)) throw new WireException('The expired payment-link fixture did not cross the configured retention boundary.');
foreach ([$desktopRecoveryOrder, $mobileRecoveryOrder] as $payableOrder) {
    if (!$commerce->verifyPaymentLinkToken($payableOrder, $commerce->getPaymentLinkToken($payableOrder))) {
        throw new WireException("The current recovery order {$payableOrder->id} is not payable before browser testing.");
    }
}
$relativePaymentUrl = static function (Page $order) use ($commerce): string {
    $url = (string) $commerce->getPaymentLinkUrl($order);
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/checkout/');
    $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
    return $path . ($query !== '' ? '?' . $query : '');
};

$state['recovery'] = [
    'desktop' => [
        'order_id' => (int) $desktopRecoveryOrder->id,
        'invoice' => (string) $desktopRecoveryOrder->mrc_invoice_number,
        'payment_url' => $relativePaymentUrl($desktopRecoveryOrder),
        'email' => $customerEmail,
        'password' => $customerPassword,
    ],
    'mobile' => [
        'order_id' => (int) $mobileRecoveryOrder->id,
        'invoice' => (string) $mobileRecoveryOrder->mrc_invoice_number,
        'payment_url' => $relativePaymentUrl($mobileRecoveryOrder),
        'email' => $otherCustomerEmail,
        'password' => $customerPassword,
    ],
    'expired' => [
        'order_id' => (int) $expiredOrder->id,
        'invoice' => (string) $expiredOrder->mrc_invoice_number,
        'payment_url' => $relativePaymentUrl($expiredOrder),
    ],
];
$state['cross_browser'] = [];
if ((string) getenv('MERCATO_E2E_PROFILE') === 'cross-browser-checkout') {
    foreach (['chromium', 'firefox', 'webkit'] as $engine) {
        $checkoutEmail = 'e2e-' . $runId . '-cross-' . $engine . '-checkout@example.test';
        $recoveryEmail = 'e2e-' . $runId . '-cross-' . $engine . '-recovery@example.test';
        $recoveryOrder = $makeFailedOrder($recoveryEmail, 0, ucfirst($engine));
        $recoveryOrder = $wire->pages->getById((int) $recoveryOrder->id, ['cache' => false])->first();
        if (!$commerce->verifyPaymentLinkToken($recoveryOrder, $commerce->getPaymentLinkToken($recoveryOrder))) throw new WireException("{$engine} cross-browser recovery order is not payable before testing.");
        $state['cross_browser'][$engine] = [
            'checkout_email' => $checkoutEmail,
            'recovery' => [
                'order_id' => (int) $recoveryOrder->id,
                'invoice' => (string) $recoveryOrder->mrc_invoice_number,
                'email' => $recoveryEmail,
                'payment_url' => $relativePaymentUrl($recoveryOrder),
            ],
        ];
    }
}
$state['log_offsets'] = [];
foreach (glob(rtrim((string) $wire->config->paths->logs, '/') . '/*.txt') ?: [] as $logPath) {
    $state['log_offsets'][basename($logPath)] = (int) filesize($logPath);
}
if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) throw new WireException('Could not update customer fixture state.');
echo json_encode(['ready' => true, 'run_id' => $runId, 'product_id' => $product->id], JSON_UNESCAPED_SLASHES) . "\n";
