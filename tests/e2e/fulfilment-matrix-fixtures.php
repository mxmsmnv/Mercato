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
    fwrite(STDERR, "Usage: MERCATO_E2E_SITE=/site MERCATO_E2E_STATE=/tmp/state.json php fulfilment-matrix-fixtures.php setup|capture|verify|cleanup\n");
    exit(2);
}

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1'; $wire = new ProcessWire($config);
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Fulfilment matrix fixtures are forbidden in production mode.');
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for fulfilment matrix fixtures.');
$wire->users->setCurrentUser($superuser); $wire->set('page', $wire->pages->get('/'));

$writeState = static function (array $state) use ($stateFile): void {
    if (file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) throw new WireException('Could not persist fulfilment matrix state.');
};
$loadState = static function () use ($stateFile): array {
    if (!is_file($stateFile)) throw new WireException('Fulfilment matrix state is missing.');
    return json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
};
$removeOwnedLogLines = static function (array $state) use ($wire): void {
    $runId = (string) ($state['run_id'] ?? '');
    $ids = array_fill_keys(array_map('intval', (array) ($state['order_ids'] ?? [])), true);
    $invoices = array_fill_keys(array_map('strval', array_column((array) ($state['orders'] ?? []), 'invoice')), true);
    $root = rtrim((string) $wire->config->paths->logs, '/') . '/';
    $paths = array_merge(glob($root . 'mercato-*.txt') ?: [], [$root . 'errors.txt']);
    foreach (array_unique($paths) as $path) {
        if (!is_file($path) || ($lines = file($path)) === false) continue;
        $kept = [];
        foreach ($lines as $line) {
            $owned = $runId !== '' && str_contains($line, $runId);
            if ($path === $root . 'errors.txt' && str_contains($line, basename(__FILE__))) $owned = true;
            $json = strstr($line, '{'); $payload = $json === false ? null : json_decode($json, true);
            if (is_array($payload)) {
                $orderId = (int) ($payload['order_id'] ?? $payload['mrc_order_id'] ?? 0);
                $invoice = (string) ($payload['invoice'] ?? $payload['order'] ?? '');
                $owned = $owned || isset($ids[$orderId]) || ($invoice !== '' && isset($invoices[$invoice]));
            }
            if (!$owned) $kept[] = $line;
        }
        if (count($kept) !== count($lines)) { if ($kept === []) @unlink($path); else file_put_contents($path, implode('', $kept), LOCK_EX); }
    }
};
$cleanup = static function (array $state) use ($wire, $commerce, $removeOwnedLogLines): array {
    $runId = (string) ($state['run_id'] ?? ''); $deleted = ['orders'=>0, 'users'=>0, 'products'=>0];
    $orderIds = array_map('intval', (array) ($state['order_ids'] ?? []));
    foreach ((array) ($state['scenarios'] ?? []) as $scenario) {
        $email = (string) ($scenario['email'] ?? ''); if ($email === '') continue;
        $safe = $wire->sanitizer->selectorValue($email);
        foreach ($wire->pages->find('template=' . $wire->sanitizer->selectorValue((string) $commerce->order_template) . ",include=all,mrc_email={$safe}") as $order) {
            $orderIds[] = (int) $order->id; $state['orders'][] = ['id'=>(int)$order->id, 'invoice'=>(string)$order->mrc_invoice_number];
        }
    }
    $state['order_ids'] = array_values(array_unique($orderIds));
    foreach (array_reverse(array_unique($orderIds)) as $id) {
        $order = $wire->pages->get((int) $id); if (!$order || !$order->id) continue;
        if ($runId === '' || !str_contains(strtolower((string) $order->mrc_email), strtolower($runId))) throw new WireException("Refusing to delete unexpected fulfilment order {$order->id}.");
        $wire->pages->delete($order, true); $deleted['orders']++;
    }
    foreach (array_reverse((array) ($state['users'] ?? [])) as $row) {
        $user = $wire->users->get((int) ($row['id'] ?? 0)); if (!$user || !$user->id) continue;
        if ((string) $user->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $user->name, 'e2e-fulfilment-')) throw new WireException("Refusing to delete unexpected fulfilment user {$user->id}.");
        $wire->users->delete($user); $deleted['users']++;
    }
    foreach (array_reverse((array) ($state['products'] ?? [])) as $row) {
        $product = $wire->pages->get((int) ($row['id'] ?? 0)); if (!$product || !$product->id) continue;
        if ((string) $product->name !== (string) ($row['name'] ?? '') || !str_starts_with((string) $product->name, 'e2e-fulfilment-')) throw new WireException("Refusing to delete unexpected fulfilment product {$product->id}.");
        $wire->pages->delete($product, true); $deleted['products']++;
    }
    $stored = (array) $wire->modules->getConfig('Mercato');
    foreach ((array) ($state['original_config'] ?? []) as $key => $snapshot) { if (!empty($snapshot['exists'])) $stored[$key] = $snapshot['value']; else unset($stored[$key]); }
    $wire->modules->saveConfig('Mercato', $stored); $removeOwnedLogLines($state);
    return $deleted;
};

if ($action === 'cleanup') {
    if (!is_file($stateFile) || filesize($stateFile) === 0) { echo "No fulfilment matrix state; nothing to clean.\n"; exit(0); }
    echo json_encode(['cleaned'=>true] + $cleanup($loadState()), JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

if ($action !== 'setup') {
    $state = $loadState(); $orders = []; $orderIds = [];
    foreach ((array) $state['scenarios'] as $key => $scenario) {
        $safe = $wire->sanitizer->selectorValue((string) $scenario['email']);
        $found = $wire->pages->find('template=' . $wire->sanitizer->selectorValue((string) $commerce->order_template) . ",include=all,mrc_email={$safe},limit=2");
        if ($found->count() !== 1) throw new WireException("Expected one {$key} order; found {$found->count()}.");
        $order = $found->first(); $orderIds[] = (int) $order->id;
        $orders[$key] = ['id'=>(int)$order->id, 'invoice'=>(string)$order->mrc_invoice_number];
    }
    if ($action === 'capture') {
        $state['orders'] = $orders; $state['order_ids'] = $orderIds; $writeState($state);
        echo json_encode(['captured'=>true, 'orders'=>$orders], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
    }
    foreach ($orders as $key => $captured) {
        $order = $wire->pages->getById((int) $captured['id'], ['cache'=>false])->first(); $scenario = $state['scenarios'][$key];
        if ((string) $order->mrc_payment_status !== 'paid' || (int) $order->mrc_payment_complete !== 1) throw new WireException("{$key} order is not durably paid.");
        $expectedOwner = (int) ($scenario['user_id'] ?? 0); if ((int) $order->mrc_customer_user_id !== $expectedOwner) throw new WireException("{$key} order owner is incorrect.");
        if ((string) $order->mrc_fulfilment_method !== (string) $scenario['method']) throw new WireException("{$key} fulfilment method was not persisted.");
        if (round((float) $order->mrc_shipping_amount, 2) !== round((float) $scenario['shipping'], 2)) throw new WireException("{$key} shipping amount is incorrect.");
        if (round((float) $order->mrc_tax_amount, 2) !== 0.0) throw new WireException("{$key} tax snapshot is incorrect.");
        if ((int) $order->mrc_inventory_adjusted !== 1) throw new WireException("{$key} inventory was not finalized exactly once.");
        $items = json_decode((string) $order->mrc_items, true, 512, JSON_THROW_ON_ERROR);
        if (count($items) !== count((array) $scenario['products'])) throw new WireException("{$key} line-item cardinality is incorrect.");
        $actualProducts = array_map(static fn(array $item): int => (int) ($item['product_id'] ?? 0), $items); sort($actualProducts);
        $expectedProducts = array_map('intval', (array) $scenario['products']); sort($expectedProducts);
        if ($actualProducts !== $expectedProducts) throw new WireException("{$key} lost its product snapshots.");
        $details = json_decode((string) $order->mrc_fulfilment_details, true, 512, JSON_THROW_ON_ERROR);
        $shipping = json_decode((string) $order->mrc_shipping_address, true, 512, JSON_THROW_ON_ERROR);
        if ((string) ($details['type'] ?? '') !== (string) $scenario['method'] || (bool) ($details['requires_shipping'] ?? true) !== (bool) $scenario['requires_shipping']) throw new WireException("{$key} fulfilment snapshot is incorrect.");
        if ((string) ($shipping['type'] ?? '') !== (string) $scenario['address_type']) throw new WireException("{$key} address snapshot type is incorrect.");
        if (!$scenario['requires_shipping'] && trim((string) ($shipping['address'] ?? '')) !== '') throw new WireException("{$key} no-shipping snapshot retained an address.");
    }
    foreach ((array) $state['products'] as $row) {
        $product = $wire->pages->getById((int) $row['id'], ['cache'=>false])->first();
        if (!$product->id || (int) $product->mrc_stock !== (int) $row['expected_stock']) throw new WireException("{$row['type']} stock does not reflect the exact matrix purchases.");
    }
    $suspicious = []; $rawLogs = '';
    foreach ((array) ($state['log_offsets'] ?? []) as $name => $offset) {
        $path = rtrim((string) $wire->config->paths->logs, '/') . '/' . basename((string) $name); if (!is_file($path) || filesize($path) <= (int) $offset) continue;
        $handle = fopen($path, 'rb'); if (!$handle) continue; fseek($handle, (int) $offset); $appended = (string) stream_get_contents($handle); fclose($handle); $rawLogs .= $appended;
        foreach (preg_split('/\R/', $appended) ?: [] as $line) if (str_contains($line, (string) $state['run_id']) && preg_match('/\b(fatal|uncaught|exception|error)\b/i', $line)) $suspicious[] = basename($path) . ': ' . substr($line, 0, 500);
    }
    if ($suspicious) throw new WireException('Run-owned ProcessWire log errors: ' . implode(' | ', $suspicious));
    foreach ((array) $state['scenarios'] as $scenario) if (str_contains($rawLogs, (string) $scenario['email'])) throw new WireException('Fulfilment logs exposed a raw checkout email.');
    echo json_encode(['verified'=>true, 'orders'=>count($orders), 'paid'=>6, 'guest'=>3, 'customer'=>3, 'methods'=>['carrier_delivery','store_pickup','local_delivery','digital-no-shipping','service-no-shipping','mixed'], 'stocks'=>array_column($state['products'], 'expected_stock', 'type'), 'suspicious_logs'=>0], JSON_UNESCAPED_SLASHES) . "\n"; exit(0);
}

$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)); $password = 'E2E-Fulfilment-42!';
$stored = (array) $wire->modules->getConfig('Mercato');
$configValues = [
    'enabled_payment_methods'=>['demo'], 'checkout_enabled'=>true, 'customer_accounts_mode'=>'optional',
    'enabled_notification_events'=>[], 'notification_sender_email'=>'', 'analytics_enabled'=>false,
    'enabled_fulfilment_methods'=>['carrier_delivery','store_pickup','local_delivery'], 'default_fulfilment_method'=>'carrier_delivery',
    'carrier_delivery_label'=>'Matrix carrier', 'free_shipping_threshold'=>0, 'shipping_dimensions_enabled'=>false,
    'shipping_provider'=>'manual', 'shipping_provider_include_manual_rates'=>true, 'allowed_delivery_countries'=>'US',
    'delivery_regions'=>'', 'delivery_windows'=>'', 'store_pickup_label'=>'Matrix pickup',
    'store_pickup_address'=>'1 Matrix Plaza, New York', 'store_pickup_instructions'=>'Bring the matrix code.',
    'store_pickup_locations'=>"Matrix counter A | 1 Matrix Plaza, New York | Bring the matrix code. | 09:00-17:00\nMatrix counter B | 2 Matrix Plaza, New York | Side entrance. | 10:00-18:00",
    'local_delivery_label'=>'Matrix local delivery', 'local_delivery_fee'=>6.5, 'local_delivery_minimum_order'=>20,
    'local_delivery_postcodes'=>"100\n112", 'local_delivery_instructions'=>'Matrix courier window.',
    'tax_provider'=>'manual', 'tax_display_mode'=>'included', 'tax_rounding_mode'=>'line', 'tax_shipping'=>true, 'shipping_tax_rate'=>0, 'markets_json'=>'',
];
$original = []; foreach ($configValues as $key => $value) { $original[$key] = ['exists'=>array_key_exists($key, $stored), 'value'=>$stored[$key] ?? null]; $stored[$key] = $value; }
$wire->modules->saveConfig('Mercato', $stored); foreach ($configValues as $key => $value) $commerce->set($key, $value);
$state = ['schema_version'=>1, 'run_id'=>$runId, 'created_at'=>gmdate(DATE_ATOM), 'password'=>$password, 'products'=>[], 'users'=>[], 'scenarios'=>[], 'orders'=>[], 'order_ids'=>[], 'original_config'=>$original, 'log_offsets'=>[]];
foreach (glob(rtrim((string)$wire->config->paths->logs,'/').'/*.txt') ?: [] as $path) $state['log_offsets'][basename($path)]=(int)filesize($path);
$writeState($state);
$productsParent = $wire->pages->get('/products/'); if (!$productsParent->id) throw new WireException('Install the Mercato demo storefront before fulfilment matrix acceptance.');
$makeProduct = static function (string $type, float $price, float $shipping, int $expectedSales) use ($wire, $productsParent, $runId, &$state, $writeState): Page {
    $page = new Page(); $page->template='mrc-product'; $page->parent=$productsParent; $page->name="e2e-fulfilment-{$type}-{$runId}"; $page->of(false);
    $page->title='Fulfilment Matrix ' . ucfirst($type); $page->mrc_sku='FM-' . strtoupper(substr(hash('sha256', $runId . $type), 0, 10)); $page->mrc_price=$price; $page->mrc_shipping_price=$shipping; $page->mrc_tax_rate=0; $page->mrc_product_type=$type; $page->mrc_product_status='active'; $page->mrc_stock=20; $page->mrc_stock_policy='deny'; $wire->pages->save($page);
    $state['products'][]=['id'=>(int)$page->id,'name'=>(string)$page->name,'url'=>(string)$page->url,'type'=>$type,'initial_stock'=>20,'expected_stock'=>20-$expectedSales]; $writeState($state); return $page;
};
$physical=$makeProduct('physical',120,7.25,4); $digital=$makeProduct('digital',24,91,2); $service=$makeProduct('service',60,83,2);
$role=$wire->roles->get('mercato-customer'); if(!$role->id) throw new WireException('Mercato customer role is missing.');
$makeUser=static function(string $key) use($wire,$role,$runId,$password,&$state,$writeState): User { $user=new User(); $user->of(false); $user->name='e2e-fulfilment-'.$key.'-'.substr(hash('sha256',$runId.$key),0,10); $user->email="e2e-{$runId}-{$key}@example.test"; $user->pass=$password; $user->addRole($role); $user->mrc_customer_verified=1; $user->mrc_first_name=ucfirst($key); $user->mrc_last_name='Matrix'; $wire->users->save($user); $state['users'][]=['id'=>(int)$user->id,'name'=>(string)$user->name,'email'=>(string)$user->email]; $writeState($state); return $user; };
$pickupUser=$makeUser('pickup'); $digitalUser=$makeUser('digital'); $mixedUser=$makeUser('mixed');
$state['scenarios'] = [
    'carrier'=>['identity'=>'guest','email'=>"e2e-{$runId}-carrier@example.test",'method'=>'carrier_delivery','products'=>[(int)$physical->id],'product_urls'=>[(string)$physical->url],'shipping'=>7.25,'requires_shipping'=>true,'address_type'=>'carrier_delivery','viewport'=>'desktop'],
    'pickup'=>['identity'=>'customer','email'=>(string)$pickupUser->email,'user_id'=>(int)$pickupUser->id,'method'=>'store_pickup','products'=>[(int)$physical->id],'product_urls'=>[(string)$physical->url],'shipping'=>0,'requires_shipping'=>true,'address_type'=>'pickup','viewport'=>'mobile'],
    'local'=>['identity'=>'guest','email'=>"e2e-{$runId}-local@example.test",'method'=>'local_delivery','products'=>[(int)$physical->id],'product_urls'=>[(string)$physical->url],'shipping'=>6.5,'requires_shipping'=>true,'address_type'=>'local_delivery','viewport'=>'mobile'],
    'digital'=>['identity'=>'customer','email'=>(string)$digitalUser->email,'user_id'=>(int)$digitalUser->id,'method'=>'carrier_delivery','products'=>[(int)$digital->id],'product_urls'=>[(string)$digital->url],'shipping'=>0,'requires_shipping'=>false,'address_type'=>'no_shipping','viewport'=>'desktop'],
    'service'=>['identity'=>'guest','email'=>"e2e-{$runId}-service@example.test",'method'=>'carrier_delivery','products'=>[(int)$service->id],'product_urls'=>[(string)$service->url],'shipping'=>0,'requires_shipping'=>false,'address_type'=>'no_shipping','viewport'=>'mobile'],
    'mixed'=>['identity'=>'customer','email'=>(string)$mixedUser->email,'user_id'=>(int)$mixedUser->id,'method'=>'carrier_delivery','products'=>[(int)$physical->id,(int)$digital->id,(int)$service->id],'product_urls'=>[(string)$physical->url,(string)$digital->url,(string)$service->url],'shipping'=>7.25,'requires_shipping'=>true,'address_type'=>'carrier_delivery','viewport'=>'desktop'],
];
$writeState($state); echo json_encode(['ready'=>true,'run_id'=>$runId,'products'=>3,'users'=>3,'scenarios'=>array_keys($state['scenarios'])],JSON_UNESCAPED_SLASHES)."\n";
