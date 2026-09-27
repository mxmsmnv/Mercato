<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) {
    echo "Mercato discount matrix integration test skipped (set MERCATO_TEST_SITE).\n";
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
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser'));
$wire->wire('page', $wire->pages->get('/'));
/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
$service = $commerce->discountService();

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new \RuntimeException($message);
};
$amount = static function (float $expected, float $actual, string $message) use ($expect): void {
    $expect(abs($expected - $actual) < 0.001, "$message Expected $expected; received $actual.");
};
$rule = static function (array $values = []): MercatoDiscountRule {
    return new MercatoDiscountRule(...array_merge([
        'pageId' => 1, 'title' => 'Matrix', 'code' => 'MATRIX',
        'type' => MercatoDiscountType::PERCENTAGE, 'amount' => 0.0, 'percent' => 10.0,
        'active' => true, 'startsAt' => null, 'endsAt' => null, 'usageLimit' => 0,
        'perCustomerLimit' => 0, 'minimumOrderTotal' => 0.0, 'productIds' => [],
        'collectionIds' => [], 'customerTargets' => [], 'usedCount' => 0, 'notes' => '',
    ], $values));
};
$item = static function (int $id, float $price, float $quantity, float $shipping, array $collections = []): array {
    return [
        'id' => $id, 'product_id' => $id, 'uid' => 'discount-item-' . $id,
        'title' => 'Discount item ' . $id, 'template' => 'mrc-product',
        'price' => $price, 'quantity' => $quantity, 'tax_rate' => 0.0, 'tax_code' => '',
        'shipping_price' => $shipping, 'shipping_dimensions' => [],
        'collection_ids' => $collections, 'product_type' => 'physical',
    ];
};

// Deterministic activation boundaries are inclusive at start/end and reject
// disabled, future, and expired rules.
$now = 2_000_000_000;
foreach ([
    ['disabled', ['active' => false], false],
    ['unbounded', [], true],
    ['start boundary', ['startsAt' => $now], true],
    ['future', ['startsAt' => $now + 1], false],
    ['end boundary', ['endsAt' => $now], true],
    ['expired', ['endsAt' => $now - 1], false],
] as [$label, $values, $expected]) {
    $expect($rule($values)->isCurrentlyActive($now) === $expected, "Discount activation case '$label' failed.");
}

// Type and amount matrix, including provider-facing numeric boundaries.
foreach ([
    ['percentage', ['type' => MercatoDiscountType::PERCENTAGE, 'percent' => 25.0], 100.0, 10.0, 25.0],
    ['percentage capped', ['type' => MercatoDiscountType::PERCENTAGE, 'percent' => 250.0], 100.0, 10.0, 100.0],
    ['percentage negative', ['type' => MercatoDiscountType::PERCENTAGE, 'percent' => -10.0], 100.0, 10.0, 0.0],
    ['fixed', ['type' => MercatoDiscountType::FIXED, 'amount' => 30.0], 100.0, 10.0, 30.0],
    ['fixed capped', ['type' => MercatoDiscountType::FIXED, 'amount' => 500.0], 100.0, 10.0, 110.0],
    ['fixed negative', ['type' => MercatoDiscountType::FIXED, 'amount' => -1.0], 100.0, 10.0, 0.0],
    ['free shipping', ['type' => MercatoDiscountType::FREE_SHIPPING], 100.0, 7.5, 7.5],
    ['unknown type', ['type' => 'unknown'], 100.0, 10.0, 0.0],
    ['non-finite percent', ['type' => MercatoDiscountType::PERCENTAGE, 'percent' => NAN], 100.0, 10.0, 0.0],
    ['non-finite fixed', ['type' => MercatoDiscountType::FIXED, 'amount' => INF], 100.0, 10.0, 0.0],
    ['non-finite subtotal', ['type' => MercatoDiscountType::PERCENTAGE, 'percent' => 25.0], INF, 10.0, 0.0],
    ['non-finite shipping', ['type' => MercatoDiscountType::FREE_SHIPPING], 100.0, NAN, 0.0],
] as [$label, $values, $subtotal, $shipping, $expected]) {
    $actual = $service->calculatePreview($rule($values), $subtotal, $shipping);
    $expect(is_finite($actual), "Discount calculation '$label' returned a non-finite value.");
    $amount($expected, $actual, "Discount calculation '$label' failed.");
}

$cart = $commerce->productList([
    $item(900001, 40.0, 2.0, 5.0, [700001]),
    $item(900002, 20.0, 1.0, 3.0, [700002]),
]);
$amount(10.0, $service->calculateCartPreview($rule(['percent' => 10.0]), $cart), 'Untargeted cart percentage failed.');
$amount(8.0, $service->calculateCartPreview($rule(['percent' => 10.0, 'productIds' => [900001]]), $cart), 'Product-targeted subtotal failed.');
$amount(2.0, $service->calculateCartPreview($rule(['percent' => 10.0, 'collectionIds' => [700002]]), $cart), 'Collection-targeted subtotal failed.');
$amount(0.0, $service->calculateCartPreview($rule(['productIds' => [999999]]), $cart), 'Non-matching product target was accepted.');
$amount(0.0, $service->calculateCartPreview($rule(['minimumOrderTotal' => 100.01]), $cart), 'Below-minimum cart was accepted.');
$amount(10.0, $service->calculateCartPreview($rule(['minimumOrderTotal' => 100.0]), $cart), 'Exact minimum-order boundary was rejected.');

$rebased = $service->applyFinalShippingAmount(['valid' => true, 'type' => MercatoDiscountType::FREE_SHIPPING, 'amount' => 1], 12.345);
$amount(12.35, (float) $rebased['amount'], 'Final free-shipping amount was not rounded.');
$rebased = $service->applyFinalShippingAmount(['valid' => true, 'type' => MercatoDiscountType::FREE_SHIPPING, 'amount' => 1], INF);
$amount(0.0, (float) $rebased['amount'], 'Non-finite final shipping amount leaked into discount data.');
$unchanged = ['valid' => false, 'type' => MercatoDiscountType::FREE_SHIPPING, 'amount' => 4.0];
$expect($service->applyFinalShippingAmount($unchanged, 12.0) === $unchanged, 'Invalid free-shipping discount was unexpectedly rebased.');

// Non-default markets accept only percentage discounts without a fixed minimum.
$market = ['id' => 'us', 'currency' => 'USD', 'is_default' => false];
$serviceMarket = $commerce->marketService();
$serviceMarket->assertDiscountSupported(['valid' => true, 'type' => MercatoDiscountType::PERCENTAGE, 'minimum_order_total' => 0], $market);
foreach ([
    ['type' => MercatoDiscountType::FIXED, 'minimum_order_total' => 0],
    ['type' => MercatoDiscountType::FREE_SHIPPING, 'minimum_order_total' => 0],
    ['type' => MercatoDiscountType::PERCENTAGE, 'minimum_order_total' => 1],
] as $unsupported) {
    $rejected = false;
    try {
        $serviceMarket->assertDiscountSupported(['valid' => true] + $unsupported, $market);
    } catch (WireException $exception) {
        $rejected = $exception->getCode() === 422;
    }
    $expect($rejected, 'Unsupported non-default-market discount was accepted.');
}
$serviceMarket->assertDiscountSupported(['valid' => false, 'type' => MercatoDiscountType::FIXED, 'minimum_order_total' => 100], $market);

// Resolve against a real discount page to cover code normalization, customer
// targets, usage limits, product targets, audit minimization, and cleanup.
$discountPage = null;
$usageOrder = null;
$logFile = rtrim((string) $wire->config->paths->logs, '/') . '/mercato-discounts.txt';
$runId = strtolower(bin2hex(random_bytes(6)));
$code = 'E2E' . strtoupper($runId);
$customer = 'private-' . $runId . '@vip.example';
$otherCustomer = 'other-' . $runId . '@vip.example';
$source = 'discount_matrix_' . $runId;
try {
    $parent = $wire->pages->get('/discounts/');
    $template = $wire->templates->get('mrc-discount');
    $expect((bool) ($parent && $parent->id && $template && $template->id), 'Discount fixture parent/template is unavailable.');
    $product = $wire->pages->findOne('template=mrc-product, parent=/products/, status<1024');
    $expect((bool) ($product && $product->id), 'Product fixture for discount targeting is unavailable.');

    $discountPage = new Page();
    $discountPage->template = $template;
    $discountPage->parent = $parent;
    $discountPage->name = 'e2e-discount-matrix-' . $runId;
    $discountPage->of(false);
    $discountPage->title = 'E2E discount matrix ' . $runId;
    $discountPage->mrc_discount_code = $code;
    $discountPage->mrc_discount_active = 1;
    $discountPage->mrc_discount_type = MercatoDiscountType::PERCENTAGE;
    $discountPage->mrc_discount_percent = 20;
    $discountPage->mrc_discount_amount = 0;
    $discountPage->mrc_discount_usage_limit = 0;
    $discountPage->mrc_discount_customer_limit = 1;
    $discountPage->mrc_discount_minimum_order = 50;
    $discountPage->mrc_discount_customer_targets = "vip.example\nEXACT@example.test";
    $wire->pages->save($discountPage);
    // Page-reference fields are initialized only after the new Page has an ID.
    $discountPage = $wire->pages->get((int) $discountPage->id);
    $discountPage->of(false);
    $productTargets = new PageArray();
    $productTargets->add($product);
    $discountPage->set('mrc_discount_products', $productTargets);
    $wire->pages->save($discountPage);
    $discountPage = $wire->pages->get((int) $discountPage->id);
    $expect(count($discountPage->mrc_discount_products) === 1 && (int) $discountPage->mrc_discount_products->first()->id === (int) $product->id, 'Product target fixture did not persist.');

    $targetCart = $commerce->productList([$item((int) $product->id, 60.0, 1.0, 4.0)]);
    $wrongCart = $commerce->productList([$item((int) $product->id + 900000, 60.0, 1.0, 4.0)]);
    $resolved = $service->resolveCartDiscount('  ' . strtolower($code) . ' ', $targetCart, strtoupper($customer), true, ['source' => $source, 'email' => $customer]);
    $expect(!empty($resolved['valid']) && $resolved['code'] === $code, 'Valid normalized/customer/product-targeted coupon was rejected.');
    $amount(12.0, (float) $resolved['amount'], 'Resolved percentage coupon amount is incorrect.');
    $expect(empty($service->resolveCartDiscount($code, $targetCart, '', false)['valid']), 'Customer-targeted coupon accepted an empty email.');
    $expect(empty($service->resolveCartDiscount($code, $targetCart, 'person@evilvip.example', false)['valid']), 'Customer domain target matched a suffix-confusion address.');
    $expect(empty($service->resolveCartDiscount($code, $wrongCart, $customer, false)['valid']), 'Product-targeted coupon accepted a non-matching cart.');

    $ordersParent = $wire->pages->get('/' . trim((string) $commerce->orders_parent, '/') . '/');
    $expect((bool) ($ordersParent && $ordersParent->id), 'Orders fixture parent is unavailable.');
    $usageOrder = new Page();
    $usageOrder->template = (string) $commerce->order_template;
    $usageOrder->parent = $ordersParent;
    $usageOrder->name = 'e2e-discount-usage-' . $runId;
    $usageOrder->title = 'E2E discount usage ' . $runId;
    $usageOrder->mrc_discount_code = $code;
    $usageOrder->mrc_email = strtolower($customer);
    $usageOrder->mrc_payment_complete = 1;
    $wire->pages->save($usageOrder);

    $sameCustomer = $service->resolveCartDiscount($code, $targetCart, $customer, false);
    $expect(empty($sameCustomer['valid']) && ($sameCustomer['customer_used_count'] ?? 0) === 1, 'Per-customer usage limit did not reject a paid prior use.');
    $expect(!empty($service->resolveCartDiscount($code, $targetCart, $otherCustomer, false)['valid']), 'Per-customer usage leaked across customers.');

    $discountPage->of(false);
    $discountPage->mrc_discount_usage_limit = 1;
    $wire->pages->save($discountPage);
    $expect(empty($service->resolveCartDiscount($code, $targetCart, $otherCustomer, false)['valid']), 'Global usage limit did not reject a paid prior use.');

    $lines = is_file($logFile) ? (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $auditLine = '';
    foreach (array_reverse($lines) as $line) {
        if (str_contains($line, $source)) { $auditLine = $line; break; }
    }
    $expect($auditLine !== '', 'Discount audit event was not recorded.');
    $expect(!str_contains(strtolower($auditLine), strtolower($customer)), 'Raw customer email leaked into the discount audit log.');
    $expect(str_contains($auditLine, 'p***@vip.example'), 'Discount audit log did not retain a useful masked address.');
    $expect(str_contains($auditLine, hash('sha256', strtolower($customer))), 'Discount audit log omitted the deterministic email hash.');
} finally {
    if ($usageOrder instanceof Page && $usageOrder->id) $wire->pages->delete($usageOrder, true);
    if ($discountPage instanceof Page && $discountPage->id) $wire->pages->delete($discountPage, true);
    // Remove only this run's audit evidence while holding an exclusive lock;
    // unrelated production-style log lines must remain byte-for-byte intact.
    if (is_file($logFile) && ($handle = fopen($logFile, 'c+')) !== false) {
        if (flock($handle, LOCK_EX)) {
            rewind($handle);
            $contents = stream_get_contents($handle);
            $kept = array_filter(explode("\n", (string) $contents), static fn(string $line): bool => !str_contains($line, $source));
            $cleaned = implode("\n", $kept);
            if ($cleaned !== '' && !str_ends_with($cleaned, "\n")) $cleaned .= "\n";
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $cleaned);
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

echo "Mercato discount matrix integration tests passed: $checks assertions across calculation, activation, targeting, usage, market, audit, and cleanup contracts.\n";
