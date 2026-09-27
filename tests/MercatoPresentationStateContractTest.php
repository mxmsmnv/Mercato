<?php
$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if ($source === false) throw new RuntimeException("Could not read {$relative}.");
    return $source;
};
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$sources = [
    'catalog' => $read('templates/mrc-products.php'),
    'product' => $read('templates/mrc-product.php'),
    'account' => $read('templates/mrc-account.php'),
    'checkout' => $read('templates/mrc-checkout.php'),
    'admin_products' => $read('src/Admin/ProcessMercatoProductPanels.php'),
    'admin_orders' => $read('src/Admin/ProcessMercatoOrderDetailPanels.php'),
    'admin_order_panels' => $read('src/Admin/ProcessMercatoOrderPanels.php'),
    'admin_helpers' => $read('src/Admin/ProcessMercatoAdminHelpers.php'),
];

$states = [
    'catalog.empty' => [$sources['catalog'], '<p role="status">No products are available yet.</p>'],
    'catalog.loading' => [$sources['catalog'], "status.textContent = 'Loading products…'"],
    'catalog.large' => [$sources['catalog'], 'foreach ($products as $product)'],
    'product.long_translated' => [$sources['product'], 'overflow-wrap: anywhere'],
    'account.empty' => [$sources['account'], 'No orders are attached to this account.'],
    'account.large_paginated' => [$sources['account'], '?page=<?= $pageNum + 1 ?>'],
    'checkout.readiness_disabled' => [$sources['checkout'], "disabled aria-disabled=\"true\""],
    'admin.products.empty' => [$sources['admin_products'], 'No products yet. Run the installer or add your first product.'],
    'admin.products.large' => [$sources['admin_products'], 'mrc-products-table'],
    'admin.orders.empty' => [$sources['admin_orders'], 'No orders yet.'],
    'admin.orders.large' => [$sources['admin_orders'], 'foreach ($orders as $order)'],
];
foreach ($states as $name => [$source, $needle]) $expect(str_contains($source, $needle), "Presentation state contract missing: {$name}.");

$controls = [
    'catalog.filters' => [$sources['catalog'], 'data-mrc-product-filters'],
    'catalog.add_to_cart' => [$sources['catalog'], 'catalog_add_to_cart'],
    'catalog.clear_cart' => [$sources['catalog'], 'catalog_clear_cart'],
    'product.quantity' => [$sources['product'], 'name="quantity"'],
    'account.login' => [$sources['account'], 'value="login"'],
    'account.profile' => [$sources['account'], 'value="profile"'],
    'account.logout' => [$sources['account'], 'value="logout"'],
    'checkout.payment' => [$sources['checkout'], 'value="checkout"'],
    'admin.product_filters' => [$sources['admin_products'], 'renderProductFilters'],
    'admin.product_bulk' => [$sources['admin_products'], 'renderProductBulkActions'],
];
foreach ($controls as $name => [$source, $needle]) $expect(str_contains($source, $needle), "Presentation control inventory missing: {$name}.");

$expect(str_contains($sources['catalog'], 'aria-busy="false"') && str_contains($sources['catalog'], "setAttribute('aria-busy', 'true')"), 'Catalog busy state does not transition explicitly.');
$expect(str_contains($sources['admin_helpers'], 'mrc-skeleton-row" aria-hidden="true"'), 'Decorative admin loading placeholders are exposed to accessibility APIs.');
$expect(substr_count($sources['admin_products'], 'mrc-admin-table-wrap" tabindex="0"') >= 3, 'Product administration scroll regions are not keyboard reachable.');
$expect(substr_count($sources['admin_orders'], 'mrc-admin-table-wrap" tabindex="0"') >= 5, 'Order administration scroll regions are not keyboard reachable.');
$expect(!str_contains($sources['checkout'], '<p class="mrc-checkout-actions">'), 'Checkout maintenance state contains invalid nested paragraph markup.');
$expect(!str_contains($sources['admin_order_panels'], '$this->csrfInput()'), 'Order notification retry controls call a nonexistent CSRF renderer.');
$expect(str_contains($sources['admin_order_panels'], '$this->renderCsrfInput()'), 'Order notification retry controls do not render the canonical CSRF input.');

echo 'Mercato presentation-state contract tests passed: ' . count($states) . ' screen/state contracts and ' . count($controls) . " control groups.\n";
