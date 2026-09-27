<?php
declare(strict_types=1);

/*
 * Test-only inventory for Mercato-owned visual surfaces.  A state marked
 * "evidenced" has an existing automated profile assertion; "fixture_ready"
 * is deterministically reachable with the presentation fixture but is not yet
 * captured on that surface; "planned" describes the fixture key a future
 * evidence profile must provide.  Nothing in this file is runtime config.
 */

$requiredStates = ['empty', 'loading', 'normal', 'error', 'long_content', 'translated', 'large_data', 'responsive'];
$planned = static function (string $prefix, array $overrides = []) use ($requiredStates): array {
    $states = [];
    foreach ($requiredStates as $state) {
        $states[$state] = ['status' => 'planned', 'fixture_key' => $prefix . '.' . $state];
    }
    foreach ($overrides as $state => $definition) $states[$state] = $definition;
    return $states;
};
$na = static fn(string $reason): array => ['status' => 'not_applicable', 'reason' => $reason];
$evidenced = static fn(string $evidence): array => ['status' => 'evidenced', 'evidence' => $evidence];
$ready = static fn(string $fixture): array => ['status' => 'fixture_ready', 'fixture_key' => $fixture];
$publicEvidence = static fn(string $claim): array => ['status' => 'evidenced', 'evidence' => 'tests/e2e/public-visual-states.spec.js#' . $claim];
$adminEvidence = static fn(string $claim): array => ['status' => 'evidenced', 'evidence' => 'tests/e2e/admin-visual-breadth.spec.js#' . $claim];
$sync = static function (string $prefix, array $overrides = []) use ($planned, $na): array {
    return $planned($prefix, array_replace([
        'loading' => $na('This route is rendered synchronously and has no Mercato-owned asynchronous loading presentation.'),
    ], $overrides));
};

$presentation = 'tests/e2e/presentation-states.spec.js';
$assistive = 'tests/e2e/assistive-visual-evidence.spec.js';

$screens = [
    // mrc-home.php requires mrc-products.php, so it inherits the catalog's
    // asynchronous filter/loading behavior instead of the synchronous default.
    'storefront.home' => ['surface' => 'storefront', 'source' => 'templates/mrc-home.php', 'route' => '/', 'states' => $planned('storefront.home', [
        'empty'=>$publicEvidence('public_visual.storefront-home-empty'), 'loading'=>$publicEvidence('public_visual.storefront-home-loading'),
        'normal'=>$publicEvidence('public_visual.storefront-home-normal'), 'error'=>$publicEvidence('public_visual.storefront-home-error'),
        'long_content'=>$publicEvidence('public_visual.storefront-home-long'), 'translated'=>$publicEvidence('public_visual.storefront-home-translated'),
        'large_data'=>$publicEvidence('public_visual.storefront-home-large'), 'responsive'=>$publicEvidence('public_visual.storefront-home-normal-responsive'),
    ])],
    'storefront.catalog' => ['surface' => 'storefront', 'source' => 'templates/mrc-products.php', 'route' => '/products/', 'states' => $planned('storefront.catalog', [
        'empty' => $evidenced($presentation . '#empty-catalog'), 'loading' => $evidenced($presentation . '#filter-loading'),
        'normal' => $publicEvidence('public_visual.storefront-catalog-large'), 'error' => $publicEvidence('public_visual.storefront-catalog-error'),
        'long_content' => $publicEvidence('public_visual.storefront-catalog-long'), 'translated' => $publicEvidence('public_visual.storefront-catalog-translated'),
        'large_data' => $publicEvidence('public_visual.storefront-catalog-large'),
        'responsive' => $evidenced($assistive . '#390px-empty-catalog'),
    ])],
    'storefront.product' => ['surface' => 'storefront', 'source' => 'templates/mrc-product.php', 'route' => '/products/{product}/', 'states' => $sync('storefront.product', [
        'empty' => $na('A valid product-detail route always has one product; a missing product is handled as a route error, not an empty state.'),
        'normal' => $publicEvidence('public_visual.storefront-product-long-translated'),
        'error' => $publicEvidence('public_visual.storefront-product-error'),
        'long_content' => $publicEvidence('public_visual.storefront-product-long-translated'),
        'translated' => $publicEvidence('public_visual.storefront-product-long-translated'),
        'large_data' => $publicEvidence('public_visual.storefront-product-large'),
        'responsive' => $publicEvidence('public_visual.storefront-product-long-translated-responsive'),
    ])],
    'storefront.collections' => ['surface' => 'storefront', 'source' => 'templates/mrc-collections.php', 'route' => '/collections/', 'states' => $sync('storefront.collections', [
        'empty'=>$publicEvidence('public_visual.storefront-collections-empty'), 'normal'=>$publicEvidence('public_visual.storefront-collections-normal'),
        'error'=>$na('The collection index normalizes filters and renders read-only cards; missing routes are handled by ProcessWire and there is no Mercato-owned error presentation.'),
        'long_content'=>$publicEvidence('public_visual.storefront-collections-long'), 'translated'=>$publicEvidence('public_visual.storefront-collections-normal'),
        'large_data'=>$publicEvidence('public_visual.storefront-collections-large'), 'responsive'=>$publicEvidence('public_visual.storefront-collections-normal-responsive'),
    ])],
    'storefront.collection' => ['surface' => 'storefront', 'source' => 'templates/mrc-collection.php', 'route' => '/collections/{collection}/', 'states' => $sync('storefront.collection', [
        'empty'=>$publicEvidence('public_visual.storefront-collection-empty'),'normal'=>$publicEvidence('public_visual.storefront-collection-large'),
        'error'=>$publicEvidence('public_visual.storefront-collection-error'),'long_content'=>$publicEvidence('public_visual.storefront-collection-long'),
        'translated'=>$publicEvidence('public_visual.storefront-collection-large'),'large_data'=>$publicEvidence('public_visual.storefront-collection-large'),
        'responsive'=>$publicEvidence('public_visual.storefront-collection-large-responsive'),
    ])],
    'storefront.content' => ['surface' => 'storefront', 'source' => 'templates/mrc-page.php', 'route' => '/{content-page}/', 'states' => $sync('storefront.content', [
        'empty'=>$na('Every valid Mercato content page renders the built-in story layout even when its optional body is empty.'),
        'normal'=>$publicEvidence('public_visual.storefront-content-long-translated'),
        'error'=>$na('The content template has no Mercato-owned action or error presentation; missing pages are handled by the host routing layer.'),
        'long_content'=>$publicEvidence('public_visual.storefront-content-long-translated'),'translated'=>$publicEvidence('public_visual.storefront-content-long-translated'),
        'large_data'=>$na('This route renders one content document rather than a variable-size data collection; oversized copy belongs to long_content.'),
        'responsive'=>$publicEvidence('public_visual.storefront-content-long-translated-responsive'),
    ])],
    'storefront.checkout' => ['surface' => 'storefront', 'source' => 'templates/mrc-checkout.php', 'route' => '/checkout/', 'states' => $sync('storefront.checkout', [
        'empty' => $publicEvidence('public_visual.storefront-checkout-empty'), 'normal' => $publicEvidence('public_visual.storefront-checkout-normal'),
        'error' => $publicEvidence('public_visual.storefront-checkout-error'),
        'long_content' => $publicEvidence('public_visual.storefront-checkout-long'), 'translated' => $publicEvidence('public_visual.storefront-checkout-translated'),
        'large_data' => $publicEvidence('public_visual.storefront-checkout-large'), 'responsive' => $publicEvidence('public_visual.storefront-checkout-normal-responsive'),
    ])],
    'storefront.success' => ['surface' => 'storefront', 'source' => 'templates/mrc-success.php', 'route' => '/success/', 'states' => $sync('storefront.success', [
        'empty' => $na('A valid success route renders one completed order; an absent order is an error or redirect rather than an empty collection.'),
        'normal' => $publicEvidence('public_visual.storefront-success-normal'),
        'error' => $publicEvidence('public_visual.storefront-success-error'), 'long_content' => $publicEvidence('public_visual.storefront-success-long'),
        'translated' => $publicEvidence('public_visual.storefront-success-translated'), 'large_data' => $publicEvidence('public_visual.storefront-success-large'),
        'responsive' => $publicEvidence('public_visual.storefront-success-normal-responsive'),
    ])],
    'storefront.account' => ['surface' => 'storefront', 'source' => 'templates/mrc-account.php', 'route' => '/account/', 'states' => $sync('storefront.account', [
        'empty' => $publicEvidence('public_visual.storefront-account-empty'), 'normal' => $publicEvidence('public_visual.storefront-account-large-translated'),
        'long_content' => $publicEvidence('public_visual.storefront-account-long'), 'translated'=>$publicEvidence('public_visual.storefront-account-large-translated'),
        'error' => $evidenced($assistive . '#invalid-login-alert'), 'large_data' => $evidenced($presentation . '#paginated-account-history'),
        'responsive' => $publicEvidence('public_visual.storefront-account-large-translated-responsive'),
    ])],
    'storefront.my_quotes' => ['surface' => 'storefront', 'source' => 'templates/mrc-my-quotes.php', 'route' => '/my-quotes/', 'states' => $sync('storefront.my_quotes', [
        'empty'=>$publicEvidence('public_visual.storefront-my-quotes-empty'),'normal'=>$publicEvidence('public_visual.storefront-my-quotes-normal'),
        'error'=>$na('The read-only quote list defines signed-out, empty, and populated states but no Mercato-owned error presentation.'),
        'long_content'=>$publicEvidence('public_visual.storefront-my-quotes-long'),'translated'=>$publicEvidence('public_visual.storefront-my-quotes-translated'),
        'large_data'=>$publicEvidence('public_visual.storefront-my-quotes-large'),'responsive'=>$publicEvidence('public_visual.storefront-my-quotes-normal-responsive'),
    ])],

    'public.order_lookup' => ['surface' => 'storefront', 'source' => 'src/MercatoPublicEndpoints.php', 'route' => '/api/mercato/order-lookup', 'states' => $sync('public.order_lookup', [
        'empty' => $na('This endpoint always renders its bounded lookup form; it has no data-collection empty presentation.'),
        'translated' => $na('The standalone lookup document is currently hard-coded in English and exposes no locale-selection contract.'),
        'large_data' => $na('The endpoint renders one bounded lookup form and never renders a result collection.'),
        'normal'=>$publicEvidence('public_visual.public-order-lookup-empty'),'error'=>$publicEvidence('public_visual.public-order-lookup-error'),
        'long_content'=>$publicEvidence('public_visual.public-order-lookup-long'),'responsive'=>$publicEvidence('public_visual.public-order-lookup-empty-responsive'),
    ])],
    'public.order_status' => ['surface' => 'storefront', 'source' => 'src/MercatoPublicEndpoints.php', 'route' => '/order/status/{code}/', 'states' => $sync('public.order_status', [
        'empty' => $na('A valid signed status route always resolves one order; an invalid capability renders the private error document.'),
        'normal' => $publicEvidence('public_visual.public-order-status-normal'),
        'error' => $publicEvidence('public_visual.public-order-status-error'),
        'long_content' => $publicEvidence('public_visual.public-order-status-large-long'),
        'translated' => $na('The standalone signed-status document is currently hard-coded in English and exposes no locale contract.'),
        'large_data' => $publicEvidence('public_visual.public-order-status-large-long'),
        'responsive' => $publicEvidence('public_visual.public-order-status-normal-responsive'),
    ])],
    'public.receipt' => ['surface' => 'storefront', 'source' => 'src/MercatoPublicEndpoints.php', 'route' => '/order/receipt/{code}/', 'states' => $sync('public.receipt', [
        'empty' => $na('A valid signed receipt route always resolves one order; an invalid capability renders the private error document.'),
        'normal' => $publicEvidence('public_visual.public-receipt-normal'),
        'error' => $publicEvidence('public_visual.public-receipt-error'),
        'long_content' => $publicEvidence('public_visual.public-receipt-large-long'),
        'translated' => $na('The standalone signed-receipt document is currently hard-coded in English and exposes no locale contract.'),
        'large_data' => $publicEvidence('public_visual.public-receipt-large-long'),
        'responsive' => $publicEvidence('public_visual.public-receipt-normal-responsive'),
    ])],
    'public.access_recovery' => ['surface' => 'storefront', 'source' => 'src/MercatoAccessRecovery.php', 'route' => '/access/recovery/{code}/', 'states' => $sync('public.access_recovery', [
        'empty' => $na('A valid recovery route resolves one signed order action; unavailable capabilities render an explicit error state.'),
        'normal' => $publicEvidence('public_visual.public-access-recovery-normal'),
        'error' => $publicEvidence('public_visual.public-access-recovery-error'),
        'long_content' => $na('The built-in recovery document contains only fixed bounded copy; hook-provided project content is outside the base UI contract.'),
        'translated' => $na('The standalone recovery document is currently English-only and exposes no locale-selection contract.'),
        'large_data' => $na('The recovery document renders one bounded action/result card and no data collection.'),
        'responsive' => $publicEvidence('public_visual.public-access-recovery-normal-responsive'),
    ])],
    'public.recovery_unsubscribe' => ['surface' => 'storefront', 'source' => 'src/MercatoPublicEndpoints.php', 'route' => '/api/mercato/recovery-unsubscribe', 'states' => $sync('public.recovery_unsubscribe', [
        'empty' => $na('The signed action has success and invalid states, not an empty collection.'),
        'normal' => $publicEvidence('public_visual.public-recovery-unsubscribe-normal'), 'error' => $publicEvidence('public_visual.public-recovery-unsubscribe-error'),
        'long_content' => $na('The document contains only fixed bounded confirmation copy and accepts no display content.'),
        'translated' => $na('The standalone unsubscribe document is hard-coded in English and exposes no locale contract.'),
        'large_data' => $na('The response renders one bounded confirmation and no collection.'),
        'responsive'=>$publicEvidence('public_visual.public-recovery-unsubscribe-normal-responsive'),
    ])],
];

// Page-backed private storage templates deliberately do not render customer
// screens. Keeping them in the inventory prevents them being mistaken for gaps.
foreach ([
    'mrc-order.php' => 'individual order storage', 'mrc-orders.php' => 'order parent storage',
    'mrc-quote.php' => 'individual quote storage', 'mrc-quotes.php' => 'quote parent storage',
    'mrc-storefront.php' => 'shared rendering helper',
] as $file => $purpose) {
    $screens['nonvisual.' . basename($file, '.php')] = [
        'surface' => 'non_visual', 'source' => 'templates/' . $file, 'route' => null, 'purpose' => $purpose,
    ];
}

$adminRoutes = [
    'dashboard' => ['ProcessMercato.module.php', '___execute'],
    'products' => ['ProcessMercato.module.php', '___executeProducts'],
    'product-detail' => ['ProcessMercato.module.php', '___executeProductDetail'],
    'orders' => ['ProcessMercato.module.php', '___executeOrders'],
    'quotes' => ['ProcessMercato.module.php', '___executeQuotes'],
    'quote-detail' => ['ProcessMercato.module.php', '___executeQuoteDetail'],
    'manual-order' => ['ProcessMercato.module.php', '___executeManualOrder'],
    'fulfilment' => ['ProcessMercato.module.php', '___executeFulfilment'],
    'order-timeline' => ['ProcessMercato.module.php', '___executeOrderTimeline'],
    'order-detail' => ['ProcessMercato.module.php', '___executeOrderDetail'],
    'customers' => ['ProcessMercato.module.php', '___executeCustomers'],
    'recovery' => ['ProcessMercato.module.php', '___executeRecovery'],
    'customer-detail' => ['ProcessMercato.module.php', '___executeCustomerDetail'],
    'search' => ['ProcessMercato.module.php', '___executeSearch'],
    'reports' => ['ProcessMercato.module.php', '___executeReports'],
    'discounts' => ['ProcessMercato.module.php', '___executeDiscounts'],
    'webhooks' => ['ProcessMercato.module.php', '___executeWebhooks'],
    'payment-attempts' => ['ProcessMercato.module.php', '___executePaymentAttempts'],
    'refunds' => ['ProcessMercato.module.php', '___executeRefunds'],
    'inventory' => ['ProcessMercato.module.php', '___executeInventory'],
    'launch' => ['ProcessMercato.module.php', '___executeLaunch'],
    'notifications' => ['src/Admin/ProcessMercatoNotificationTemplates.php', '___executeNotifications'],
];
foreach ($adminRoutes as $route => [$source, $method]) {
    $overrides = [
        'empty' => $adminEvidence('admin_visual.empty.routes.' . $route),
        'loading' => $na('This ProcessWire admin route is rendered synchronously and has no Mercato-owned asynchronous loading presentation.'),
        'normal' => $adminEvidence('admin_visual.normal.routes.' . $route),
        'error' => $adminEvidence('admin_visual.denied_routes.' . $route),
        'long_content' => $adminEvidence('admin_visual.normal.long_content'),
        'translated' => $adminEvidence('admin_visual.normal.translated'),
        'large_data' => $adminEvidence('admin_visual.normal.large_data'),
        'responsive' => $adminEvidence('admin_visual.normal.responsive_390'),
    ];
    if (in_array($route, ['product-detail', 'quote-detail', 'order-timeline', 'order-detail', 'customer-detail'], true)) {
        $overrides['empty'] = $na('This detail route resolves one entity and renders an explicit not-found/error panel instead of an empty collection.');
    }
    if ($route === 'dashboard') {
        $overrides['normal'] = $evidenced('tests/e2e/admin.spec.js#admin-dashboard-login');
        $overrides['error'] = $na('The dashboard is the base mercato-admin route; narrower permission denials are exercised on its destination routes.');
    }
    if ($route === 'search') $overrides['error'] = $na('Search filters result domains by permission and has no separate Mercato access-denied screen.');
    if ($route === 'notifications') $overrides['empty'] = $na('Built-in notification definitions always populate the editor; absence of stored overrides is the normal state rather than an empty screen.');
    if ($route === 'products') $overrides = [
        ...$overrides,
        'empty' => $adminEvidence('admin_visual.empty.routes.products'),
        'normal' => $evidenced($presentation . '#admin-products-large'), 'large_data' => $evidenced($presentation . '#admin-products-large'),
        'responsive' => $evidenced($assistive . '#1440px-admin-products'),
    ];
    if ($route === 'orders') $overrides = [
        ...$overrides,
        'empty' => $adminEvidence('admin_visual.empty.routes.orders'),
        'normal' => $evidenced($presentation . '#admin-orders-large'), 'large_data' => $evidenced($presentation . '#admin-orders-large'),
        'responsive' => $evidenced($presentation . '#admin-orders-large'),
    ];
    if ($route === 'fulfilment') $overrides = [...$overrides,
        'normal' => $evidenced('tests/e2e/admin.spec.js#manager-fulfilment-queue'),
        'error' => $evidenced('tests/e2e/admin.spec.js#staff-fulfilment-denial'),
        'responsive' => $evidenced('tests/e2e/admin.spec.js#manager-fulfilment-queue'),
    ];
    if ($route === 'order-detail') $overrides = [...$overrides,
        'normal' => $evidenced('tests/e2e/admin.spec.js#manager-order-detail'),
        'error' => $evidenced('tests/e2e/ownership-boundaries.spec.js#unauthorized-staff-order-detail'),
        'responsive' => $evidenced('tests/e2e/admin.spec.js#manager-order-detail'),
    ];
    if ($route === 'customer-detail') $overrides['normal'] = $evidenced('tests/e2e/admin.spec.js#staff-customer-detail');
    $screens['admin.' . str_replace('-', '_', $route)] = [
        'surface' => 'admin', 'source' => $source, 'method' => $method,
        'route' => '/admin/setup/mercato/' . ($route === 'dashboard' ? '' : $route . '/'),
        'states' => $planned('admin.' . $route, $overrides),
    ];
}
$screens['admin.module_settings'] = [
    'surface' => 'admin', 'source' => 'src/MercatoConfigInputfields.php',
    'route' => '/admin/module/edit?name=Mercato',
    'states' => $planned('admin.module-settings', [
        'loading' => $na('ProcessWire renders the module configuration synchronously; Mercato owns no async loading state here.'),
        'empty' => $na('The settings form always has the module default values and cannot be an empty collection.'),
        'normal' => $adminEvidence('admin_visual.normal.settings'),
        'error' => $adminEvidence('admin_visual.settings.readiness-errors'),
        'long_content' => $adminEvidence('admin_visual.settings.long-translated'),
        'translated' => $adminEvidence('admin_visual.settings.long-translated'),
        'large_data' => $na('The settings form has fixed schema cardinality; oversized values belong to long_content rather than a variable-size data collection.'),
        'responsive' => $adminEvidence('admin_visual.normal.settings.responsive_390'),
    ]),
];
$screens['admin.export'] = [
    'surface' => 'non_visual', 'source' => 'ProcessMercato.module.php', 'method' => '___executeExport',
    'route' => '/admin/setup/mercato/export/', 'purpose' => 'CSV download response, not an HTML screen',
];

return [
    'schema_version' => 1,
    'required_states' => $requiredStates,
    'screens' => $screens,
    'dark_mode' => [
        'status' => 'not_applicable',
        'declared_supported' => false,
        'reason' => 'Mercato declares no store-wide dark theme or configuration contract. Isolated OS-adaptive checkout-card and notification-editor rules do not constitute a supported dark-mode surface.',
        'incidental_adaptive_sources' => ['templates/mrc-checkout.php', 'assets/admin-notifications.js'],
    ],
    'assistive_evidence' => [
        'automatable_via_dom_ax' => [
            'accessible names and descriptions', 'landmark/heading/table structure', 'roles and aria state',
            'hidden versus exposed content', 'live-region presence and priority', 'native invalid-control focus and validation message',
            'focus order/traps/visible focus', 'automated contrast', 'document reflow and overflow',
        ],
        'manual_voiceover_open' => [
            'actual spoken announcement timing and duplicate suppression', 'Safari VoiceOver rotor grouping/order',
            'speech naturalness and verbosity', 'VoiceOver cursor/context retention after dynamic updates',
            'screen-reader-specific keyboard and gesture interaction',
        ],
        'status' => 'manual_open_system_setting_unchanged',
    ],
];
