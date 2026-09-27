<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') {
    echo "Mercato fulfilment/product-type integration test skipped (set MERCATO_TEST_SITE).\n";
    exit(0);
}

$_SERVER['HTTP_HOST'] = 'mercato.test';
$_SERVER['SERVER_NAME'] = 'mercato.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for the fulfilment integration profile.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Fulfilment fixtures are forbidden in production mode.');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$money = static fn(float $value): float => round($value, 2);
$runId = 'fulfilment-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
$createdOrderIds = [];
$createdProductIds = [];
$createdInvoices = [];
$logsRoot = rtrim((string) $wire->config->paths->logs, '/') . '/';
$originalCart = $commerce->cart()->toArray();
$configKeys = [
    'enabled_payment_methods', 'enabled_fulfilment_methods', 'default_fulfilment_method',
    'carrier_delivery_label', 'free_shipping_threshold', 'shipping_dimensions_enabled',
    'shipping_provider', 'shipping_provider_include_manual_rates', 'allowed_delivery_countries',
    'delivery_regions', 'delivery_windows', 'store_pickup_label', 'store_pickup_address',
    'store_pickup_instructions', 'store_pickup_locations', 'local_delivery_label',
    'local_delivery_fee', 'local_delivery_minimum_order', 'local_delivery_postcodes',
    'local_delivery_instructions', 'tax_provider', 'tax_display_mode', 'tax_rounding_mode',
    'tax_shipping', 'shipping_tax_rate', 'enabled_notification_events', 'analytics_enabled',
    'markets_json',
];
$originalConfig = [];
foreach ($configKeys as $key) $originalConfig[$key] = $commerce->get($key);
$sessionKeys = ['mrc_cart_items', 'mrc_cart_updated_at', 'mrc_discount_code', 'mrc_pending_order', 'mrc_checkout_started_nonce', 'mrc_checkout_redirect'];
$originalSession = [];
foreach ($sessionKeys as $key) $originalSession[$key] = $wire->session->get($key);
$cleaned = false;

$removeRunLogLines = static function () use ($logsRoot, $runId, &$createdOrderIds, &$createdInvoices): void {
    $orderIds = array_fill_keys(array_map('intval', $createdOrderIds), true);
    $invoices = array_fill_keys(array_map('strval', $createdInvoices), true);
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) {
        $lines = file($path);
        if ($lines === false) continue;
        $kept = [];
        foreach ($lines as $line) {
            $owned = str_contains($line, $runId);
            $json = strstr($line, '{');
            $payload = $json === false ? null : json_decode($json, true);
            if (is_array($payload)) {
                $orderId = (int) ($payload['order_id'] ?? $payload['mrc_order_id'] ?? 0);
                $invoice = (string) ($payload['invoice'] ?? $payload['order'] ?? '');
                $owned = $owned || isset($orderIds[$orderId]) || ($invoice !== '' && isset($invoices[$invoice]));
            }
            if (!$owned) $kept[] = $line;
        }
        if (count($kept) === count($lines)) continue;
        if ($kept === []) @unlink($path); else file_put_contents($path, implode('', $kept), LOCK_EX);
    }
};

$cleanup = static function () use (
    $wire, $commerce, &$createdOrderIds, &$createdProductIds, $runId, $originalConfig,
    $originalSession, $sessionKeys, $originalCart, $removeRunLogLines, &$cleaned
): void {
    if ($cleaned) return;
    $cleaned = true;
    foreach ($originalConfig as $key => $value) $commerce->set($key, $value);
    foreach ($sessionKeys as $key) {
        $value = $originalSession[$key] ?? null;
        if ($value === null) $wire->session->remove($key); else $wire->session->set($key, $value);
    }
    $commerce->cart($originalCart);
    foreach (array_reverse(array_unique($createdOrderIds)) as $id) {
        $order = $wire->pages->get((int) $id);
        if (!$order || !$order->id) continue;
        $email = strtolower((string) ($order->mrc_email ?: $order->mrc_customer_email));
        if (!str_contains($email, $runId)) throw new WireException("Refusing to delete unexpected order {$order->id}.");
        $commerce->orderRepository()->releaseStockReservation($order, 'fulfilment_fixture_cleanup');
        $wire->pages->delete($order, true);
    }
    foreach (array_reverse(array_unique($createdProductIds)) as $id) {
        $product = $wire->pages->get((int) $id);
        if (!$product || !$product->id) continue;
        if (!str_starts_with((string) $product->name, 'e2e-' . $runId . '-')) throw new WireException("Refusing to delete unexpected product {$product->id}.");
        $wire->pages->delete($product, true);
    }
    $removeRunLogLines();
};
register_shutdown_function($cleanup);

$productsParent = $wire->pages->get('template=mrc-products,include=all');
if (!$productsParent || !$productsParent->id) $productsParent = $wire->pages->get('/products/');
if (!$productsParent || !$productsParent->id) throw new WireException('Mercato products parent is missing.');

$createProduct = static function (string $suffix, string $type, float $price, float $shipping, float $taxRate) use (
    $wire, $productsParent, $runId, &$createdProductIds
): Page {
    $product = new Page();
    $product->template = 'mrc-product';
    $product->parent = $productsParent;
    $product->name = 'e2e-' . $runId . '-' . $suffix;
    $product->of(false);
    $product->title = 'E2E ' . $runId . ' ' . $suffix;
    $product->mrc_sku = strtoupper(substr(hash('sha256', $runId . '|' . $suffix), 0, 16));
    $product->mrc_price = $price;
    $product->mrc_shipping_price = $shipping;
    $product->mrc_tax_rate = $taxRate;
    $product->mrc_product_type = $type;
    $product->mrc_product_status = 'active';
    $product->mrc_stock = 20;
    $product->mrc_stock_policy = 'deny';
    $wire->pages->save($product);
    $createdProductIds[] = (int) $product->id;
    return $product;
};

$clearCheckoutSession = static function () use ($wire): void {
    foreach (['mrc_pending_order', 'mrc_checkout_started_nonce', 'mrc_checkout_redirect', 'mrc_discount_code'] as $key) $wire->session->remove($key);
};
$fresh = static fn(int $id): Page => $wire->pages->getById($id, ['cache' => false])->first();

try {
    $commerce->set('enabled_payment_methods', ['demo']);
    $commerce->set('enabled_fulfilment_methods', ['carrier_delivery', 'store_pickup', 'local_delivery']);
    $commerce->set('default_fulfilment_method', 'carrier_delivery');
    $commerce->set('carrier_delivery_label', 'Fixture carrier');
    $commerce->set('free_shipping_threshold', 0.0);
    $commerce->set('shipping_dimensions_enabled', false);
    $commerce->set('shipping_provider', 'manual');
    $commerce->set('shipping_provider_include_manual_rates', true);
    $commerce->set('allowed_delivery_countries', 'US');
    $commerce->set('delivery_regions', '');
    $commerce->set('delivery_windows', '');
    $commerce->set('store_pickup_label', 'Fixture pickup');
    $commerce->set('store_pickup_address', '1 Fixture Plaza, New York');
    $commerce->set('store_pickup_instructions', 'Bring the pickup code.');
    $commerce->set('store_pickup_locations', 'Fixture counter | 1 Fixture Plaza, New York | Bring the pickup code. | 09:00-17:00');
    $commerce->set('local_delivery_label', 'Fixture local delivery');
    $commerce->set('local_delivery_fee', 6.50);
    $commerce->set('local_delivery_minimum_order', 20.0);
    $commerce->set('local_delivery_postcodes', "100\n112");
    $commerce->set('local_delivery_instructions', 'Fixture courier window.');
    $commerce->set('tax_provider', 'manual');
    $commerce->set('tax_display_mode', 'included');
    $commerce->set('tax_rounding_mode', 'line');
    $commerce->set('tax_shipping', true);
    $commerce->set('shipping_tax_rate', 20.0);
    $commerce->set('enabled_notification_events', []);
    $commerce->set('analytics_enabled', false);
    $commerce->set('markets_json', '');

    $physical = $createProduct('physical', 'physical', 120.0, 7.25, 20.0);
    $digital = $createProduct('digital', 'digital', 24.0, 91.0, 10.0);
    $service = $createProduct('service', 'service', 60.0, 83.0, 15.0);
    $item = static fn(Page $product, int $quantity = 1): array => $commerce->variantService()->hydrateItem($product, ['quantity' => $quantity]);
    $physicalItem = $item($physical);
    $digitalItem = $item($digital);
    $serviceItem = $item($service);

    $validAddress = ['address' => '10 Fixture Street', 'city' => 'New York', 'zip' => '10001', 'country' => 'US'];
    $identity = ['first_name' => 'Fulfilment', 'last_name' => 'Fixture'];
    $scenarioResults = [];

    $assertRejected = static function (callable $operation, string $fragment, string $message) use ($expect): void {
        $rejected = false;
        try {
            $operation();
        } catch (WireException $error) {
            $rejected = str_contains(strtolower($error->getMessage()), strtolower($fragment));
        }
        $expect($rejected, $message);
    };

    $physicalCart = $commerce->productList([$physicalItem]);
    $assertRejected(
        fn() => $commerce->fulfilmentService()->resolveSelection('carrier_delivery', $physicalCart, []),
        'address',
        'Carrier delivery accepted a physical cart without a delivery address.'
    );
    $assertRejected(
        fn() => $commerce->fulfilmentService()->resolveSelection('carrier_delivery', $physicalCart, array_merge($validAddress, ['country' => 'GB'])),
        'country',
        'Carrier delivery accepted a country outside the configured readiness boundary.'
    );
    $localInvalid = $commerce->fulfilmentService()->getCheckoutMethods($physicalCart, array_merge($validAddress, ['zip' => '99999']));
    $localInvalid = array_values(array_filter($localInvalid, static fn(array $method): bool => ($method['type'] ?? '') === 'local_delivery'))[0] ?? [];
    $expect(isset($localInvalid['available']) && !$localInvalid['available'], 'Invalid local-delivery postcode was advertised as available.');
    $localPendingAddress = $commerce->fulfilmentService()->getCheckoutMethods($physicalCart, []);
    $localPendingAddress = array_values(array_filter($localPendingAddress, static fn(array $method): bool => ($method['type'] ?? '') === 'local_delivery'))[0] ?? [];
    $expect(!empty($localPendingAddress['available']), 'Local delivery was disabled before the shopper could enter a qualifying postcode.');
    $assertRejected(
        fn() => $commerce->fulfilmentService()->resolveSelection('local_delivery', $physicalCart, array_merge($validAddress, ['zip' => '99999'])),
        'postal code',
        'Local delivery accepted an invalid postcode.'
    );

    $createScenario = static function (
        string $name,
        array $items,
        string $method,
        array $address,
        float $expectedShipping,
        bool $replay = false
    ) use (
        $commerce, $wire, $runId, $identity, $money, $expect, $clearCheckoutSession,
        &$createdOrderIds, &$createdInvoices, &$scenarioResults
    ): Page {
        $clearCheckoutSession();
        $email = $runId . '-' . $name . '@example.test';
        $customer = $identity + ['email' => $email] + $address;
        $options = [
            'fulfilment_method' => $method,
            'payment_method' => 'demo',
            'pickup_location' => $method === 'store_pickup' ? 'pickup_1' : '',
            'mrc_policy_accepted' => 1,
            'checkout_nonce' => hash('sha256', $runId . '|' . $name),
        ];
        $quote = $commerce->getHeadlessCheckoutQuote($items, $customer, $options);
        $expect($money((float) $quote['shipping']) === $money($expectedShipping), "{$name} quote shipping was not authoritative: expected {$expectedShipping}, got " . (float) $quote['shipping'] . '.');
        $cart = $commerce->productList($items);
        $expectedTax = $money($cart->getTax() + ($expectedShipping > 0 ? $commerce->calculateTax($expectedShipping, 20.0) : 0.0));
        $expect($money((float) $quote['tax']) === $expectedTax, "{$name} quote tax total is incorrect.");
        $expect($money((float) $quote['total']) === $money($cart->getSubtotal() + $expectedShipping), "{$name} quote grand total is incorrect.");

        $commerce->cart($items);
        $commerce->initializePayment($customer + $options);
        $pending = $wire->session->get('mrc_pending_order');
        $orderId = (int) ($pending['mrc_order_page_id'] ?? 0);
        $expect($orderId > 0, "{$name} checkout did not persist an order.");
        $createdOrderIds[] = $orderId;
        $order = $wire->pages->getById($orderId, ['cache' => false])->first();
        $createdInvoices[] = (string) $order->mrc_invoice_number;
        $fulfilment = json_decode((string) $order->mrc_fulfilment_details, true);
        $tax = json_decode((string) $order->mrc_tax_details, true);
        $shippingAddress = json_decode((string) $order->mrc_shipping_address, true);
        $expect((string) $order->mrc_fulfilment_method === $method, "{$name} persisted the wrong fulfilment method.");
        $expect($money((float) $order->mrc_shipping_amount) === $money($expectedShipping), "{$name} persisted the wrong shipping total.");
        $expect($money((float) $order->mrc_tax_amount) === $expectedTax, "{$name} persisted the wrong tax total.");
        $expect($money((float) $order->mrc_total_amount) === $money((float) $quote['total']), "{$name} persisted total diverged from its quote.");
        $expect(is_array($fulfilment) && (string) ($fulfilment['type'] ?? '') === $method, "{$name} lost its fulfilment quote snapshot.");
        $expect(is_array($tax) && $money((float) ($tax['quote']['total_tax'] ?? -1)) === $expectedTax, "{$name} lost its tax quote snapshot.");
        $expect(is_array($shippingAddress), "{$name} lost its address snapshot.");

        if ($replay) {
            $beforeCount = count($wire->pages->find('template=' . $commerce->order_template . ',include=all,mrc_email=' . $wire->sanitizer->selectorValue($email)));
            $commerce->cart($items);
            $commerce->initializePayment($customer + $options);
            $replayed = $wire->session->get('mrc_pending_order');
            $afterCount = count($wire->pages->find('template=' . $commerce->order_template . ',include=all,mrc_email=' . $wire->sanitizer->selectorValue($email)));
            $expect((int) ($replayed['mrc_order_page_id'] ?? 0) === $orderId && $beforeCount === 1 && $afterCount === 1, "{$name} checkout replay created or selected a different order.");
        }
        $scenarioResults[$name] = ['quote' => $quote, 'fulfilment' => $fulfilment, 'shipping_address' => $shippingAddress];
        return $order;
    };

    $carrierOrder = $createScenario('carrier', [$physicalItem], 'carrier_delivery', $validAddress, 7.25, true);
    $pickupOrder = $createScenario('pickup', [$physicalItem], 'store_pickup', [], 0.0);
    $localOrder = $createScenario('local', [$physicalItem], 'local_delivery', $validAddress, 6.50);
    $digitalOrder = $createScenario('digital', [$digitalItem], 'carrier_delivery', [], 0.0);
    $serviceOrder = $createScenario('service', [$serviceItem], 'carrier_delivery', [], 0.0);
    $mixedOrder = $createScenario('mixed', [$physicalItem, $digitalItem, $serviceItem], 'carrier_delivery', $validAddress, 7.25);

    $expect(($scenarioResults['pickup']['shipping_address']['type'] ?? '') === 'pickup', 'Pickup snapshot was not normalized as pickup.');
    $expect(($scenarioResults['pickup']['shipping_address']['pickup_location_key'] ?? '') === 'pickup_1', 'Pickup snapshot lost the selected location.');
    $expect(($scenarioResults['local']['shipping_address']['type'] ?? '') === 'local_delivery', 'Local-delivery snapshot lost its address type.');
    foreach (['digital', 'service'] as $name) {
        $expect(empty($scenarioResults[$name]['shipping_address']['address']), "{$name} no-shipping snapshot unexpectedly contains a delivery address.");
        $expect(($scenarioResults[$name]['fulfilment']['requires_shipping'] ?? null) === false, "{$name} snapshot was not marked no-shipping.");
    }
    $expect(($scenarioResults['mixed']['fulfilment']['requires_shipping'] ?? null) === true, 'Mixed cart was not marked as requiring physical delivery.');
    $mixedCalculation = (array) ($scenarioResults['mixed']['fulfilment']['shipping_calculation'] ?? []);
    $expect($money((float) ($mixedCalculation['amount'] ?? -1)) === 7.25, 'Mixed cart charged digital/service shipping data.');

    $beforeCancel = [
        'fulfilment' => (string) $carrierOrder->mrc_fulfilment_details,
        'tax' => (string) $carrierOrder->mrc_tax_details,
        'shipping' => (float) $carrierOrder->mrc_shipping_amount,
        'total' => (float) $carrierOrder->mrc_total_amount,
    ];
    $reconciliation = new MercatoPaymentReconciliationService($commerce);
    $reconciliation->setWire($wire);
    $cancelResult = $reconciliation->reconcile($fresh((int) $carrierOrder->id), MercatoPaymentStatus::CANCELED, 'Run-owned fulfilment cancellation.', $runId);
    $canceled = $fresh((int) $carrierOrder->id);
    $expect(($cancelResult['to'] ?? '') === MercatoPaymentStatus::CANCELED && (string) $canceled->mrc_payment_status === MercatoPaymentStatus::CANCELED, 'Unpaid fulfilment cancellation did not persist.');
    $expect((int) $canceled->mrc_inventory_reserved === 0, 'Unpaid fulfilment cancellation retained inventory.');
    $expect((string) $canceled->mrc_fulfilment_details === $beforeCancel['fulfilment'] && (string) $canceled->mrc_tax_details === $beforeCancel['tax'], 'Cancellation mutated immutable quote snapshots.');
    $expect((float) $canceled->mrc_shipping_amount === $beforeCancel['shipping'] && (float) $canceled->mrc_total_amount === $beforeCancel['total'], 'Cancellation mutated immutable totals.');
    $assertRejected(
        fn() => $reconciliation->reconcile($canceled, MercatoPaymentStatus::CANCELED, 'Run-owned fulfilment cancellation replay.', $runId),
        'already',
        'Cancellation replay was accepted.'
    );

    $orderCount = count(array_unique($createdOrderIds));
    $productCount = count(array_unique($createdProductIds));
    $cleanup();
    $pageExists = static function (int $id) use ($wire): bool {
        $statement = $wire->database->prepare('SELECT COUNT(*) FROM pages WHERE id=:id');
        $statement->execute([':id' => $id]);
        return (int) $statement->fetchColumn() > 0;
    };
    foreach ($createdOrderIds as $id) $expect(!$pageExists((int) $id), "Order {$id} remained after cleanup.");
    foreach ($createdProductIds as $id) $expect(!$pageExists((int) $id), "Product {$id} remained after cleanup.");
    $residualLogs = '';
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) $residualLogs .= (string) file_get_contents($path);
    $expect(!str_contains($residualLogs, $runId), 'Run-owned Mercato log rows remained after cleanup.');
    echo "Mercato fulfilment/product-type integration tests passed: {$orderCount} orders, {$productCount} products, six fulfilment/product combinations, exact cleanup.\n";
} finally {
    $cleanup();
}
