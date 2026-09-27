<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') {
    echo "Mercato order-to-cash inventory integration test skipped (set MERCATO_TEST_SITE).\n";
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
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for the inventory integration profile.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Order-to-cash inventory fixtures are forbidden in production mode.');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$runId = 'otc-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
$createdOrderIds = [];
$createdProductIds = [];
$logsRoot = rtrim((string) $wire->config->paths->logs, '/') . '/';
$inventoryLog = $logsRoot . 'mercato-inventory.txt';
$originalNotifications = $commerce->enabled_notification_events;
$originalAnalytics = $commerce->analytics_enabled;
$cleaned = false;

$removeRunLogLines = static function () use ($logsRoot, $runId): void {
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) {
        $lines = file($path);
        if ($lines === false) continue;
        $kept = array_values(array_filter($lines, static fn(string $line): bool => !str_contains($line, $runId)));
        if ($kept === $lines) continue;
        if ($kept === []) {
            @unlink($path);
            continue;
        }
        file_put_contents($path, implode('', $kept), LOCK_EX);
    }
};

$cleanup = static function () use (
    $wire,
    $commerce,
    &$createdOrderIds,
    &$createdProductIds,
    $runId,
    $removeRunLogLines,
    $originalNotifications,
    $originalAnalytics,
    &$cleaned
): void {
    if ($cleaned) return;
    $cleaned = true;
    $commerce->set('enabled_notification_events', $originalNotifications);
    $commerce->set('analytics_enabled', $originalAnalytics);
    foreach (array_reverse(array_unique($createdOrderIds)) as $id) {
        $order = $wire->pages->get((int) $id);
        if (!$order || !$order->id) continue;
        $email = strtolower((string) ($order->mrc_email ?: $order->mrc_customer_email));
        if (!str_contains($email, $runId)) throw new WireException("Refusing to delete unexpected order {$order->id}.");
        $wire->pages->delete($order, true);
    }
    foreach (array_reverse(array_unique($createdProductIds)) as $id) {
        $product = $wire->pages->get((int) $id);
        if (!$product || !$product->id) continue;
        if (!str_starts_with((string) $product->name, 'e2e-' . $runId . '-')) {
            throw new WireException("Refusing to delete unexpected product {$product->id}.");
        }
        $wire->pages->delete($product, true);
    }
    $removeRunLogLines();
};
register_shutdown_function($cleanup);

$commerce->set('enabled_notification_events', []);
$commerce->set('analytics_enabled', false);
$repository = $commerce->orderRepository();
$reconciliation = new MercatoPaymentReconciliationService($commerce);
$reconciliation->setWire($wire);

$productsParent = $wire->pages->get('template=mrc-products, include=all');
if (!$productsParent || !$productsParent->id) $productsParent = $wire->pages->get('/products/');
if (!$productsParent || !$productsParent->id) throw new WireException('Mercato products parent is missing.');

$createProduct = static function (string $suffix, int $stock, string $policy = 'deny') use (
    $wire,
    $productsParent,
    $runId,
    &$createdProductIds
): Page {
    $product = new Page();
    $product->template = 'mrc-product';
    $product->parent = $productsParent;
    $product->name = 'e2e-' . $runId . '-' . $suffix;
    $product->of(false);
    $product->title = 'E2E ' . $runId . ' ' . $suffix;
    $product->mrc_sku = strtoupper(substr(hash('sha256', $runId . '|' . $suffix), 0, 16));
    $product->mrc_price = 10;
    $product->mrc_tax_rate = 0;
    $product->mrc_product_type = 'physical';
    $product->mrc_product_status = 'active';
    $product->mrc_stock = $stock;
    $product->mrc_stock_policy = $policy;
    $wire->pages->save($product);
    $createdProductIds[] = (int) $product->id;
    return $product;
};

$createOrder = static function (Page $product, array $item, string $suffix) use (
    $commerce,
    $runId,
    &$createdOrderIds
): Page {
    $quantity = max(1, (int) ($item['quantity'] ?? 1));
    $total = round((float) ($item['price'] ?? 10) * $quantity, 2);
    $order = $commerce->orderRepository()->savePendingOrder([
        'first_name' => 'Inventory',
        'last_name' => 'Fixture',
        'email' => $runId . '-' . $suffix . '@example.test',
        'payment_method' => 'demo',
        'payment_status' => MercatoPaymentStatus::PENDING,
        'payment_complete' => 0,
        'mrc_currency' => 'USD',
        'mrc_items' => json_encode([$item], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'mrc_subtotal_amount' => $total,
        'mrc_total_amount' => $total,
        'checkout_nonce' => hash('sha256', $runId . '|' . $suffix),
    ]);
    $createdOrderIds[] = (int) $order->id;
    return $order;
};

$fresh = static fn(int $id): Page => $wire->pages->getById($id, ['cache' => false])->first();
$definition = static fn(Page $product): array => $commerce->variantService()->getDefinition($fresh((int) $product->id));
$assertPaidAndAdjusted = static function (Page $order) use ($fresh, $expect): Page {
    $order = $fresh((int) $order->id);
    $expect((string) $order->mrc_payment_status === MercatoPaymentStatus::PAID, "Order {$order->id} is not paid.");
    $expect((int) $order->mrc_payment_complete === 1, "Order {$order->id} is not payment-complete.");
    $expect((int) $order->mrc_inventory_adjusted === 1, "Order {$order->id} was not inventory-adjusted.");
    $expect((int) $order->mrc_inventory_reserved === 0, "Paid order {$order->id} retained a reservation.");
    return $order;
};

try {
    $base = $createProduct('base-deny', 1, 'deny');
    $baseItem = $commerce->variantService()->hydrateItem($base, ['quantity' => 1]);
    $baseCart = $commerce->productList([$baseItem]);
    $firstAttempt = $createOrder($base, $baseItem, 'base-first');
    $secondAttempt = $createOrder($base, $baseItem, 'base-second');

    $repository->assertStockAvailable($baseCart, (int) $firstAttempt->id);
    $repository->reserveStock($firstAttempt, 5);
    $expect($repository->getReservedQuantityForProduct((int) $base->id) === 1, 'The first base-stock reservation was not counted.');
    $secondDenied = false;
    try {
        $repository->assertStockAvailable($baseCart, (int) $secondAttempt->id);
    } catch (WireException $error) {
        $secondDenied = $error->getCode() === 409 || str_contains(strtolower($error->getMessage()), 'available');
    }
    $expect($secondDenied, 'A competing reservation attempt was allowed to oversell deny-policy stock.');

    $firstAttempt = $fresh((int) $firstAttempt->id);
    $firstAttempt->of(false);
    $firstAttempt->mrc_inventory_reserved_until = date('Y-m-d H:i:s', time() - 60);
    $wire->pages->save($firstAttempt);
    $expect($repository->countExpiredReservations() >= 1, 'The expired run-owned reservation was not counted.');
    $expect($repository->cleanupExpiredReservations() >= 1, 'The expired run-owned reservation was not released.');
    $expect((int) $fresh((int) $firstAttempt->id)->mrc_inventory_reserved === 0, 'TTL cleanup left the first reservation active.');

    $repository->assertStockAvailable($baseCart, (int) $secondAttempt->id);
    $repository->reserveStock($secondAttempt, 5);
    $secondAttempt = $fresh((int) $secondAttempt->id);
    $secondAttempt->of(false);
    $secondAttempt->mrc_inventory_reserved_until = date('Y-m-d H:i:s', time() - 60);
    $wire->pages->save($secondAttempt);
    $paidBase = $reconciliation->reconcile($secondAttempt, MercatoPaymentStatus::PAID, 'Run-owned paid-before-expiry fixture.', $runId)['order'];
    $paidBase = $assertPaidAndAdjusted($paidBase);
    $expect((int) $fresh((int) $base->id)->mrc_stock === 0, 'Base stock was not decremented exactly once.');
    $expect($repository->cleanupExpiredReservations() === 0, 'Expiry cleanup treated a paid order as an active reservation.');
    $repeatBase = $repository->decrementStockOnce($paidBase);
    $expect(empty($repeatBase['adjusted']) && (int) $fresh((int) $base->id)->mrc_stock === 0, 'Paid base stock was decremented twice.');
    $baseRefund = $commerce->refundService()->refund($paidBase, 10.0, 'Full run-owned base refund.', $runId);
    $expect(($baseRefund['status'] ?? '') === MercatoPaymentStatus::REFUNDED, 'Base refund did not reach refunded state.');
    $expect((int) $fresh((int) $base->id)->mrc_stock === 1, 'Base stock was not restored after full refund.');
    $repeatBaseRestore = $repository->restoreStockAfterFullRefundOnce($fresh((int) $paidBase->id));
    $expect(empty($repeatBaseRestore['restored']) && (int) $fresh((int) $base->id)->mrc_stock === 1, 'Base stock was restored twice.');

    $variantProduct = $createProduct('variants', 50, 'deny');
    $variantResult = $commerce->variantService()->saveDefinition($variantProduct, [[
        'id' => 'mode',
        'label' => 'Mode',
        'values' => [
            ['id' => 'deny', 'label' => 'Deny'],
            ['id' => 'backorder', 'label' => 'Backorder'],
            ['id' => 'preorder', 'label' => 'Preorder'],
        ],
    ]], [
        ['id' => 'exact-deny', 'options' => ['mode' => 'deny'], 'sku' => 'DENY-' . strtoupper(substr($runId, -8)), 'price' => 11, 'stock' => 1, 'stock_policy' => 'deny', 'status' => 'active'],
        ['id' => 'exact-backorder', 'options' => ['mode' => 'backorder'], 'sku' => 'BACK-' . strtoupper(substr($runId, -8)), 'price' => 12, 'stock' => 0, 'stock_policy' => 'backorder', 'status' => 'active'],
        ['id' => 'exact-preorder', 'options' => ['mode' => 'preorder'], 'sku' => 'PRE-' . strtoupper(substr($runId, -8)), 'price' => 13, 'stock' => 0, 'stock_policy' => 'preorder', 'status' => 'active'],
    ]);
    $expect(!empty($variantResult['valid']) && count($variantResult['variants']) === 3, 'Exact variant fixture did not persist.');

    $variantOrders = [];
    foreach (['exact-deny' => ['deny', 1], 'exact-backorder' => ['backorder', 0], 'exact-preorder' => ['preorder', 0]] as $variantId => [$policy, $initialStock]) {
        $product = $fresh((int) $variantProduct->id);
        $item = $commerce->variantService()->hydrateItem($product, ['quantity' => 1, 'variant_id' => $variantId]);
        $evaluation = $commerce->getProductPurchasability($product, 1, 0, 0, $variantId);
        $expect(!empty($evaluation['ok']), "{$policy} variant was not purchasable under its declared policy.");
        $expect(($evaluation['stock_policy'] ?? '') === $policy, "{$policy} variant lost its stock policy.");
        $order = $createOrder($product, $item, 'variant-' . $policy);
        $repository->assertStockAvailable($commerce->productList([$item]), (int) $order->id);
        $repository->reserveStock($order, 5);
        $reserved = $repository->getReservedQuantityForVariant((int) $product->id, $variantId);
        $expect($reserved === ($policy === 'deny' ? 1 : 0), "{$policy} variant reservation semantics are incorrect.");
        $paid = $reconciliation->reconcile($fresh((int) $order->id), MercatoPaymentStatus::PAID, "Run-owned {$policy} payment fixture.", $runId)['order'];
        $paid = $assertPaidAndAdjusted($paid);
        $afterSale = $definition($product);
        $soldVariant = array_values(array_filter($afterSale['variants'], static fn(array $row): bool => $row['id'] === $variantId))[0] ?? null;
        $expect(is_array($soldVariant) && (int) $soldVariant['stock'] === $initialStock - 1, "{$policy} variant stock was not decremented exactly once.");
        $repeat = $repository->decrementStockOnce($paid);
        $expect(empty($repeat['adjusted']), "{$policy} variant accepted a duplicate decrement.");
        $refund = $commerce->refundService()->refund($paid, (float) $item['price'], "Full run-owned {$policy} refund.", $runId);
        $expect(($refund['status'] ?? '') === MercatoPaymentStatus::REFUNDED, "{$policy} variant refund did not complete.");
        $afterRefund = $definition($product);
        $restoredVariant = array_values(array_filter($afterRefund['variants'], static fn(array $row): bool => $row['id'] === $variantId))[0] ?? null;
        $expect(is_array($restoredVariant) && (int) $restoredVariant['stock'] === $initialStock, "{$policy} variant stock was not restored exactly once.");
        $duplicateRestore = $repository->restoreStockAfterFullRefundOnce($fresh((int) $paid->id));
        $expect(empty($duplicateRestore['restored']), "{$policy} variant accepted a duplicate restoration.");
        $variantOrders[$policy] = (int) $paid->id;
    }

    $cancelItem = $commerce->variantService()->hydrateItem($fresh((int) $base->id), ['quantity' => 1]);
    $cancelOrder = $createOrder($base, $cancelItem, 'unpaid-cancel');
    $repository->assertStockAvailable($commerce->productList([$cancelItem]), (int) $cancelOrder->id);
    $repository->reserveStock($cancelOrder, 5);
    $expect($repository->getReservedQuantityForProduct((int) $base->id) === 1, 'Cancelable unpaid order did not reserve base stock.');
    $cancelResult = $reconciliation->reconcile($fresh((int) $cancelOrder->id), MercatoPaymentStatus::CANCELED, 'Run-owned unpaid cancellation.', $runId);
    $canceled = $fresh((int) $cancelOrder->id);
    $expect(($cancelResult['to'] ?? '') === MercatoPaymentStatus::CANCELED && (string) $canceled->mrc_payment_status === MercatoPaymentStatus::CANCELED, 'Unpaid cancellation did not persist.');
    $expect((int) $canceled->mrc_inventory_reserved === 0 && $repository->getReservedQuantityForProduct((int) $base->id) === 0, 'Unpaid cancellation did not release its reservation.');
    $cancelReplayDenied = false;
    try {
        $reconciliation->reconcile($canceled, MercatoPaymentStatus::CANCELED, 'Run-owned unpaid cancellation replay.', $runId);
    } catch (WireException $error) {
        $cancelReplayDenied = str_contains(strtolower($error->getMessage()), 'already');
    }
    $expect($cancelReplayDenied, 'Unpaid cancellation replay was accepted.');

    $events = [];
    foreach (is_file($inventoryLog) ? (file($inventoryLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        if (!str_contains($line, $runId)) continue;
        $json = strstr($line, '{');
        $payload = $json === false ? null : json_decode($json, true);
        if (is_array($payload)) $events[] = $payload;
    }
    $eventCount = static function (int $orderId, string $event) use ($events): int {
        return count(array_filter($events, static fn(array $row): bool => (int) ($row['order_id'] ?? 0) === $orderId && (string) ($row['event'] ?? '') === $event));
    };
    $expect($eventCount((int) $firstAttempt->id, 'reserved') === 1 && $eventCount((int) $firstAttempt->id, 'expired') === 1, 'TTL reservation movement ledger is not exact.');
    $expect($eventCount((int) $paidBase->id, 'reserved') === 1 && $eventCount((int) $paidBase->id, 'sold') === 1 && $eventCount((int) $paidBase->id, 'refund_restored') === 1, 'Base order movement ledger is not exact-once.');
    $expect($eventCount($variantOrders['deny'], 'reserved') === 1, 'Deny variant reservation movement is missing or duplicated.');
    foreach ($variantOrders as $policy => $orderId) {
        $expect($eventCount($orderId, 'sold') === 1 && $eventCount($orderId, 'refund_restored') === 1, "{$policy} variant movement ledger is not exact-once.");
        if ($policy !== 'deny') $expect($eventCount($orderId, 'reserved') === 0, "{$policy} variant incorrectly emitted a reservation movement.");
    }
    $expect($eventCount((int) $cancelOrder->id, 'reserved') === 1 && $eventCount((int) $cancelOrder->id, 'manual_canceled') === 1, 'Cancellation reservation/release movements are not exact.');
    foreach ($events as $event) {
        $expect((int) ($event['product_id'] ?? 0) > 0 && (int) ($event['quantity'] ?? 0) === 1, 'Inventory movement lost its product or quantity invariant.');
    }

    $readRunEvents = static function (string $name) use ($logsRoot, $runId): array {
        $rows = [];
        $path = $logsRoot . basename($name) . '.txt';
        foreach (is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            if (!str_contains($line, $runId)) continue;
            $json = strstr($line, '{');
            $payload = $json === false ? null : json_decode($json, true);
            if (is_array($payload)) $rows[] = $payload;
        }
        return $rows;
    };
    $paymentEvents = $readRunEvents('mercato-payments');
    $refundEvents = $readRunEvents('mercato-refunds');
    $expect(count($paymentEvents) === 5, 'Expected four paid and one canceled manual reconciliation events.');
    $expect(count(array_filter($paymentEvents, static fn(array $row): bool => ($row['event'] ?? '') === 'manual_reconciliation' && ($row['to'] ?? '') === MercatoPaymentStatus::PAID)) === 4, 'Paid reconciliation event cardinality is incorrect.');
    $expect(count(array_filter($paymentEvents, static fn(array $row): bool => ($row['event'] ?? '') === 'manual_reconciliation' && ($row['to'] ?? '') === MercatoPaymentStatus::CANCELED)) === 1, 'Cancellation reconciliation event cardinality is incorrect.');
    $expect(count($refundEvents) === 4, 'Expected one exact refund event for the base order and each variant order.');
    foreach ($refundEvents as $event) {
        $expect(($event['event'] ?? '') === 'refund_issued' && ($event['payment_status'] ?? '') === MercatoPaymentStatus::REFUNDED, 'Refund event lost its final state invariant.');
        $expect(($event['gateway'] ?? '') === 'demo' && ($event['gateway_status'] ?? '') === 'succeeded', 'Refund event lost its Demo gateway outcome.');
    }

    $orderCount = count($createdOrderIds);
    $productCount = count($createdProductIds);
    $movementCount = count($events);
    $domainEventCount = count($paymentEvents) + count($refundEvents);
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
    echo "Mercato order-to-cash inventory integration tests passed: {$orderCount} orders, {$productCount} products, {$movementCount} exact movements, {$domainEventCount} payment/refund events; cleanup complete.\n";
} finally {
    $cleanup();
}
