<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) {
    echo "Mercato config/readiness integration test skipped (set MERCATO_TEST_SITE).\n";
    exit(0);
}

$_SERVER['HTTP_HOST'] = 'mercato.test';
$_SERVER['SERVER_NAME'] = 'mercato.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser'));
/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new \RuntimeException($message);
};

$defaults = Mercato::getDefaultConfig();
$expect(count($defaults) === 181, 'Default configuration inventory changed; update the explicit matrix.');
$inputfields = Mercato::getModuleConfigInputfields($defaults);
$names = [];
foreach ($inputfields->getAll() as $inputfield) {
    $name = (string) $inputfield->name;
    if ($name !== '') $names[$name] = true;
}
$transientFields = [
    'mrc_overwrite_template_files', 'mrc_run_installer', 'mrc_send_test_email',
    'mrc_test_email_event', 'mrc_test_email_recipient', 'production_activation_confirmed',
];
$storageOnlyFields = [
    'backup_evidence', 'cart_template', 'checkout_template', 'installed_schema_version',
    'order_template', 'orders_template', 'product_template', 'quote_template', 'quotes_template',
];
$unsafeMutationFields = array_merge($storageOnlyFields, ['production']);
$safeFields = array_values(array_diff(array_keys($defaults), $unsafeMutationFields));
$expect(array_values(array_diff(array_keys($names), array_keys($defaults), $transientFields)) === [], 'Config UI contains a field without a default.');
$expect(array_values(array_diff(array_keys($defaults), array_keys($names), $storageOnlyFields)) === [], 'A user-configurable default is missing from the config UI.');
$expect(count($names) === 178, 'Config input inventory changed; update the explicit matrix.');
$expect(count($safeFields) === 171, 'Safe config mutation inventory changed; update the explicit matrix.');

$modules = $wire->modules;
$originalStored = (array) $modules->getConfig('Mercato');
$originalRuntime = $commerce->getArray();
$baseline = array_merge($defaults, $originalStored);
$settingsLog = rtrim((string) $wire->config->paths->logs, '/') . '/mercato-settings.txt';
$settingsLogExisted = is_file($settingsLog);
$settingsLogContents = $settingsLogExisted ? file_get_contents($settingsLog) : false;
$originalHttpRoot = (string) $wire->config->urls->httpRoot;
$restored = false;
$restore = static function () use ($modules, $commerce, $wire, $originalStored, $originalRuntime, $originalHttpRoot, $settingsLog, $settingsLogExisted, $settingsLogContents, &$restored): void {
    if ($restored) return;
    $modules->saveConfig('Mercato', $originalStored);
    $commerce->setArray($originalRuntime);
    $wire->config->urls->httpRoot = $originalHttpRoot;
    if ($settingsLogExisted) {
        file_put_contents($settingsLog, $settingsLogContents === false ? '' : $settingsLogContents);
    } elseif (is_file($settingsLog)) {
        unlink($settingsLog);
    }
    $restored = true;
};
register_shutdown_function($restore);

$persist = static function () use ($modules, $commerce, $defaults, $expect): array {
    $normalized = [];
    foreach ($defaults as $key => $_default) $normalized[$key] = $commerce->get($key);
    $expect($modules->saveConfig('Mercato', $normalized), 'ProcessWire rejected normalized module configuration.');
    $stored = (array) $modules->getConfig('Mercato');
    $expect(array_keys($stored) === array_keys($normalized), 'Persisted configuration key inventory/order changed.');
    foreach ($normalized as $key => $value) {
        $expect(array_key_exists($key, $stored) && $stored[$key] === $value, "Persisted/read value changed for $key.");
    }
    return $stored;
};

// Mutate every safe setting. Generic values cover unconstrained inputs; the
// overrides provide valid representatives for every constrained family.
$normal = $baseline;
foreach ($safeFields as $key) {
    $default = $defaults[$key];
    $normal[$key] = match (gettype($default)) {
        'boolean' => !$default,
        'integer' => $default + 1,
        'double' => $default + 1.25,
        'array' => ['matrix'],
        default => 'matrix-' . $key,
    };
}
$normal = array_merge($normal, [
    'orders_parent' => '/matrix/orders/', 'quotes_parent' => '/matrix/quotes/',
    'success_page' => '/matrix/success/', 'cancel_page' => '/matrix/cancel/',
    'policy_pages' => ['/matrix/terms/', 'matrix/privacy'],
    'currency' => 'USD', 'currency_symbol' => '$', 'currency_symbol_position' => 'after',
    'markets_json' => '[{"code":"US","currency":"USD"}]', 'invoice_prefix' => 'MATRIX-',
    'production' => false, 'frontend_framework' => 'bootstrap',
    'frontend_tailwind_cdn_url' => 'https://assets.example.test/tailwind.js',
    'frontend_bootstrap_cdn_url' => 'https://assets.example.test/bootstrap.css',
    'frontend_uikit_cdn_url' => 'https://assets.example.test/uikit.css',
    'reservation_cleanup_schedule' => 'everyHour', 'privacy_retention_schedule' => 'every2Hours',
    'privacy_policy_version' => '2026.09-matrix', 'customer_accounts_mode' => 'optional',
    'headless_api_allowed_origins' => "https://shop.example.test/\nhttps://api.example.test:8443",
    'push_transport' => 'apns', 'apns_environment' => 'production',
    'analytics_adapters' => ['data_layer', 'matrix_adapter', 'data_layer'],
    'analytics_default_consent' => 'granted', 'analytics_order_identifier' => 'hash',
    'analytics_account_identifier' => 'hash', 'notification_sender_email' => 'matrix@example.test',
    'notification_reply_to' => 'reply@example.test', 'notification_transport' => 'fixture_transport',
    'notification_locale' => 'fr-FR', 'notification_brand_color' => '#123abc',
    'notification_logo_url' => 'https://assets.example.test/logo.png',
    'notification_templates_json' => '{"refund":{"subject":"Matrix refund","text":"Matrix text","html":"<p>Matrix HTML</p>"}}',
    'notification_header_html' => '<div>Matrix header</div>', 'notification_footer_html' => '<div>Matrix footer</div>',
    'enabled_notification_events' => ['refund', 'shipment_tracking'], 'seo_default_robots' => 'index,follow',
    'seo_social_image_url' => 'https://assets.example.test/social.png',
    'seo_organization_logo_url' => 'https://assets.example.test/org.png',
    'receipt_template_file' => 'mercato/matrix-receipt.php',
    'order_status_template_file' => 'mercato/matrix-status.php',
    'receipt_pdf_url_template' => '/matrix/order/{code}/pdf/', 'quote_inventory_policy' => 'on_acceptance',
    'recovery_automation_schedule' => 'everyDay', 'recovery_discount_code' => 'MATRIX10',
    'recovery_suppressed_emails' => 'suppressed@example.test',
    'enabled_fulfilment_methods' => ['store_pickup', 'local_delivery'],
    'default_fulfilment_method' => 'local_delivery', 'shipping_dimensions_field' => 'matrix_dimensions',
    'shipping_calculation_mode' => 'dimensional_weight', 'shipping_missing_measurements' => 'unavailable',
    'shipping_rate_table' => '[]', 'shipping_provider' => 'matrix_provider',
    'shipping_provider_failure_policy' => 'fail_closed', 'shipping_provider_service_map' => '{}',
    'shipping_provider_allowed_regions' => 'US:NY', 'shipping_provider_package_mode' => 'per_item',
    'tax_display_mode' => 'excluded', 'tax_label' => 'Sales tax', 'tax_rounding_mode' => 'total',
    'tax_provider' => 'matrix_provider', 'tax_provider_failure_policy' => 'zero_tax',
    'tax_registrations' => '[]', 'tax_nexus_regions' => 'US-NY',
    'allowed_delivery_countries' => 'us, ca', 'delivery_regions' => 'ca:on=Ontario',
    'delivery_windows' => "09:00-12:00\n13:00-17:00",
    'store_pickup_locations' => 'Matrix Shop | 1 Test Street | Desk | 09:00-17:00',
    'enabled_payment_methods' => ['demo', 'bank-transfer'], 'signed_link_retention_days' => 3649,
]);
$expectedNormal = $normal;
$expectedNormal['orders_parent'] = 'matrix/orders'; $expectedNormal['quotes_parent'] = 'matrix/quotes';
$expectedNormal['success_page'] = 'matrix/success'; $expectedNormal['cancel_page'] = 'matrix/cancel';
$expectedNormal['policy_pages'] = ['matrix/terms', 'matrix/privacy'];
$expectedNormal['headless_api_allowed_origins'] = "https://api.example.test:8443\nhttps://shop.example.test";
$expectedNormal['analytics_adapters'] = ['data_layer', 'matrix_adapter'];
$expectedNormal['notification_locale'] = 'fr_fr';
$expectedNormal['notification_templates_json'] = '{"refund":{"subject":"Matrix refund","text":"Matrix text","html":"<p>Matrix HTML</p>","updated_at":""}}';
$expectedNormal['allowed_delivery_countries'] = "CA\nUS";
$expectedNormal['delivery_regions'] = 'CA:ON:Ontario';

try {
    $commerce->setConfigData($normal);
    foreach ($safeFields as $key) $expect($commerce->get($key) === $expectedNormal[$key], "Normal config normalization changed $key unexpectedly.");
    foreach ($storageOnlyFields as $key) $expect($commerce->get($key) === $baseline[$key], "Normal profile changed storage-only/internal field $key.");
    $expect(empty($commerce->production), 'Safe config profile enabled production.');
    foreach ($transientFields as $field) $expect($commerce->get($field) === null, "Transient config action $field leaked into module data.");
    $storedNormal = $persist();
    foreach ($safeFields as $key) $expect($storedNormal[$key] === $expectedNormal[$key], "Normal persisted round-trip changed $key.");

    $expect($commerce->getEnabledPaymentMethods() === ['demo', 'bank-transfer'], 'Payment-method effect did not reflect persisted config.');
    $expect($commerce->getEnabledFulfilmentMethods() === ['store_pickup', 'local_delivery'], 'Fulfilment-method effect did not reflect persisted config.');
    $expect($commerce->getDefaultFulfilmentMethod() === 'local_delivery', 'Default fulfilment effect did not reflect persisted config.');
    $expect($commerce->getFrontendFramework() === 'bootstrap', 'Frontend framework effect did not reflect persisted config.');
    $expect($commerce->getFrontendAssetUrl() === 'https://assets.example.test/bootstrap.css', 'Frontend asset effect did not reflect persisted config.');
    $expect(str_ends_with($commerce->formatPrice(12.5), "\u{00A0}$"), 'Currency display effect did not reflect persisted config.');
    $expect($commerce->getDefaultTaxRate() === $expectedNormal['default_tax_rate'], 'Tax-rate effect did not reflect persisted config.');
    $expect($commerce->getTaxDisplayMode() === 'excluded', 'Tax-display effect did not reflect persisted config.');

    // Invalid, empty, boundary, and conflicting values for every normalizer family.
    $invalid = array_merge($baseline, [
        'production' => false, 'currency' => 'NOT-ISO', 'currency_symbol' => '', 'currency_symbol_position' => 'sideways',
        'invoice_prefix' => ' inv / 2026! ', 'orders_parent' => ['-1', '/safe/orders/'], 'quotes_parent' => '',
        'success_page' => '', 'cancel_page' => ['-1'], 'policy_pages' => ['-1', '/terms/', 'terms', ''],
        'quote_expiry_days' => 999, 'quote_inventory_policy' => 'reserve-now', 'frontend_framework' => 'unknown',
        'frontend_tailwind_cdn_url' => 'javascript:alert(1)', 'frontend_bootstrap_cdn_url' => '',
        'frontend_uikit_cdn_url' => 'ftp://example.test/uikit.css', 'reservation_ttl_minutes' => 9999,
        'reservation_cleanup_schedule' => 'weekly', 'cart_retention_days' => -1, 'draft_order_retention_days' => 9000,
        'webhook_payload_retention_days' => 0, 'gateway_timeout_seconds' => -5, 'gateway_retries' => 99,
        'customer_data_retention_days' => -1, 'privacy_retention_schedule' => 'weekly',
        'privacy_retention_batch_limit' => 0, 'privacy_policy_version' => ' *** ', 'customer_accounts_mode' => 'mandatory-ish',
        'account_token_ttl_minutes' => 0, 'account_login_attempts' => 999, 'account_login_window_seconds' => 0,
        'account_orders_per_page' => 999, 'checkout_maintenance_message' => str_repeat('x', 600),
        'headless_api_token_ttl_minutes' => -10, 'headless_api_rate_limit_per_minute' => 5001,
        'headless_api_max_body_bytes' => 12,
        'headless_api_allowed_origins' => "https://Shop.Example/\nhttps://api.example:8443\nhttps://*.example\nhttp://bad.example",
        'push_transport' => '', 'apns_environment' => 'invalid', 'backup_max_age_hours' => 99999,
        'health_storage_min_bytes' => 1, 'health_cron_max_age_seconds' => 1,
        'analytics_adapters' => ['Data Layer!', '', 'data-layer'], 'analytics_default_consent' => 'maybe',
        'analytics_order_identifier' => 'raw-id', 'analytics_account_identifier' => 'raw-id', 'low_stock_threshold' => -1,
        'notification_transport' => '../wiremail', 'notification_locale' => '../EN', 'notification_brand_color' => 'red',
        'notification_logo_url' => 'http://example.test/logo.png', 'notification_templates_json' => '{bad json',
        'notification_header_html' => '<script>alert(1)</script><div>safe</div>', 'notification_retries' => 99,
        'enabled_notification_events' => ['refund', 'unknown', 'refund'], 'seo_site_name' => str_repeat('s', 100),
        'seo_default_description' => str_repeat('d', 200), 'seo_social_image_url' => 'javascript:alert(1)',
        'receipt_template_file' => '../secret.php', 'order_status_template_file' => '/absolute/not-php.txt',
        'receipt_pdf_url_template' => 'javascript:alert(1)', 'enabled_payment_methods' => ['invalid'],
        'enabled_fulfilment_methods' => ['local_delivery', 'invalid'], 'default_fulfilment_method' => 'carrier_delivery',
        'free_shipping_threshold' => NAN, 'shipping_dimensions_field' => ' Product Dimensions! ',
        'shipping_calculation_mode' => 'invalid', 'shipping_dimensional_divisor' => NAN,
        'shipping_missing_measurements' => 'invalid', 'shipping_provider' => '',
        'shipping_provider_failure_policy' => 'continue', 'shipping_provider_timeout_seconds' => 0,
        'shipping_provider_retries' => 99, 'shipping_provider_quote_ttl_seconds' => 0,
        'shipping_provider_handling_fixed' => INF, 'shipping_provider_handling_percent' => NAN,
        'shipping_provider_package_mode' => 'parcel', 'default_tax_rate' => NAN, 'tax_display_mode' => 'gross-ish',
        'tax_label' => '<b> Sales   tax </b>', 'tax_rounding_mode' => 'bankers', 'shipping_tax_rate' => INF,
        'tax_provider' => '', 'tax_provider_failure_policy' => 'continue', 'tax_provider_timeout_seconds' => 0,
        'tax_provider_retries' => 99, 'allowed_delivery_countries' => 'us, GB;de,usa',
        'delivery_regions' => "us:ny=New York\ninvalid", 'delivery_windows' => [" 9-12 ", '', '9-12'],
        'store_pickup_locations' => "Shop B|B Street\nShop A|A Street|Desk|9-5\nShop A|Replacement",
        'local_delivery_fee' => -20, 'local_delivery_minimum_order' => INF,
        'recovery_email_cooldown_minutes' => 20000, 'recovery_automation_schedule' => 'weekly',
        'recovery_automation_min_age_minutes' => 1, 'recovery_automation_batch_limit' => 101,
        'recovery_discount_code' => ' save 10%! ', 'recovery_suppressed_emails' => 'VALID@EXAMPLE.TEST invalid valid@example.test',
    ]);
    $commerce->setConfigData($invalid);
    $invalidExpected = [
        'currency' => 'GBP', 'currency_symbol' => '£', 'currency_symbol_position' => 'before',
        'invoice_prefix' => 'INV2026', 'orders_parent' => 'safe/orders', 'quotes_parent' => 'quotes',
        'success_page' => 'checkout/success', 'cancel_page' => 'checkout', 'policy_pages' => ['terms'],
        'quote_expiry_days' => 365, 'quote_inventory_policy' => 'none', 'frontend_framework' => 'vanilla',
        'frontend_tailwind_cdn_url' => 'https://cdn.tailwindcss.com',
        'frontend_bootstrap_cdn_url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        'frontend_uikit_cdn_url' => 'https://cdn.jsdelivr.net/npm/uikit@3.21.7/dist/css/uikit.min.css',
        'reservation_ttl_minutes' => 1440, 'reservation_cleanup_schedule' => 'every30Minutes',
        'cart_retention_days' => 30, 'draft_order_retention_days' => 3650, 'webhook_payload_retention_days' => 90,
        'gateway_timeout_seconds' => 3, 'gateway_retries' => 3, 'customer_data_retention_days' => 0,
        'privacy_retention_schedule' => 'everyDay', 'privacy_retention_batch_limit' => 1, 'privacy_policy_version' => '1.0',
        'customer_accounts_mode' => 'disabled', 'account_token_ttl_minutes' => 5, 'account_login_attempts' => 20,
        'account_login_window_seconds' => 60, 'account_orders_per_page' => 100,
        'headless_api_token_ttl_minutes' => 5, 'headless_api_rate_limit_per_minute' => 1000,
        'headless_api_max_body_bytes' => 1024, 'headless_api_allowed_origins' => "https://api.example:8443\nhttps://shop.example",
        'push_transport' => 'apns', 'apns_environment' => 'sandbox', 'backup_max_age_hours' => 8760,
        'health_storage_min_bytes' => 1048576, 'health_cron_max_age_seconds' => 3600,
        'analytics_adapters' => ['datalayer', 'data-layer'], 'analytics_default_consent' => 'denied',
        'analytics_order_identifier' => 'invoice', 'analytics_account_identifier' => 'omit', 'low_stock_threshold' => 0,
        'notification_transport' => 'wiremail', 'notification_locale' => 'en', 'notification_brand_color' => '#6b4f3a',
        'notification_logo_url' => '', 'notification_templates_json' => '{}', 'notification_header_html' => '<div>safe</div>',
        'notification_retries' => 5, 'enabled_notification_events' => ['refund'],
        'seo_social_image_url' => '', 'receipt_template_file' => '', 'order_status_template_file' => '', 'receipt_pdf_url_template' => '',
        'enabled_payment_methods' => ['stripe-card'], 'enabled_fulfilment_methods' => ['local_delivery'],
        'default_fulfilment_method' => 'local_delivery', 'free_shipping_threshold' => 0.0,
        'shipping_dimensions_field' => 'product_dimensions', 'shipping_calculation_mode' => 'flat',
        'shipping_dimensional_divisor' => 5000.0, 'shipping_missing_measurements' => 'flat',
        'shipping_provider' => 'manual', 'shipping_provider_failure_policy' => 'manual_fallback',
        'shipping_provider_timeout_seconds' => 1, 'shipping_provider_retries' => 3,
        'shipping_provider_quote_ttl_seconds' => 60, 'shipping_provider_handling_fixed' => 0.0,
        'shipping_provider_handling_percent' => 0.0, 'shipping_provider_package_mode' => 'combined',
        'default_tax_rate' => 0.0, 'tax_display_mode' => 'included', 'tax_label' => 'Sales tax',
        'tax_rounding_mode' => 'line', 'shipping_tax_rate' => 0.0, 'tax_provider' => 'manual',
        'tax_provider_failure_policy' => 'fail_closed', 'tax_provider_timeout_seconds' => 1, 'tax_provider_retries' => 3,
        'allowed_delivery_countries' => "DE\nGB\nUS", 'delivery_regions' => 'US:NY:New York',
        'delivery_windows' => '9-12', 'store_pickup_locations' => "Shop A | Replacement\nShop B | B Street",
        'local_delivery_fee' => 0.0, 'local_delivery_minimum_order' => 0.0,
        'recovery_email_cooldown_minutes' => 10080, 'recovery_automation_schedule' => 'disabled',
        'recovery_automation_min_age_minutes' => 15, 'recovery_automation_batch_limit' => 100,
        'recovery_discount_code' => 'SAVE10', 'recovery_suppressed_emails' => 'valid@example.test',
    ];
    foreach ($invalidExpected as $key => $value) $expect($commerce->get($key) === $value, "Invalid/boundary normalization failed for $key.");
    $expect(strlen((string) $commerce->checkout_maintenance_message) === 500, 'Maintenance message boundary was not capped.');
    $expect(mb_strlen((string) $commerce->seo_site_name) === 80, 'SEO site-name boundary was not capped.');
    $expect(mb_strlen((string) $commerce->seo_default_description) === 160, 'SEO description boundary was not capped.');
    $storedInvalid = $persist();
    foreach ($invalidExpected as $key => $value) $expect($storedInvalid[$key] === $value, "Persisted invalid/boundary normalization changed $key.");

    $beforeRejectedMarkets = (array) $modules->getConfig('Mercato');
    $rejectedMarkets = false;
    try {
        $commerce->setConfigData(array_merge($baseline, ['production' => false, 'markets_json' => '{not-json']));
    } catch (WireException $exception) {
        $rejectedMarkets = str_contains($exception->getMessage(), 'valid JSON array');
    }
    $expect($rejectedMarkets, 'Malformed market configuration was not rejected.');
    $expect((array) $modules->getConfig('Mercato') === $beforeRejectedMarkets, 'Rejected market config changed persisted data.');

    // Readiness covers disabled mode and every live provider without enabling
    // production in storage or constructing an external request.
    $wire->config->urls->httpRoot = 'https://mercato.test/';
    $readiness = new \ReflectionMethod(Mercato::class, 'renderProductionReadinessNotice');
    $renderReadiness = static fn(array $data): string => (string) $readiness->invoke(null, $data);
    $devHtml = $renderReadiness(array_merge($baseline, [
        'production' => false, 'enabled_payment_methods' => ['demo'], 'enabled_notification_events' => [],
        'notification_sender_email' => '', 'merchant_legal_details' => '', 'policy_pages' => [],
    ]));
    $expect(str_contains($devHtml, 'Production mode is disabled.'), 'Readiness did not report disabled production mode.');
    $expect(str_contains($devHtml, 'Critical live payment settings are present.'), 'Demo development readiness invented a critical provider requirement.');
    $expect(str_contains($devHtml, 'recommended before launch'), 'Readiness omitted recommended warnings.');

    $providers = [
        'stripe' => [
            ['enabled_payment_methods' => ['stripe-card'], 'stripe_live_pk' => '', 'stripe_live_sk' => '', 'stripe_webhook_secret' => ''],
            ['enabled_payment_methods' => ['stripe-card'], 'stripe_live_pk' => 'pk_live_matrix', 'stripe_live_sk' => 'sk_live_matrix', 'stripe_test_pk' => 'pk_test_matrix', 'stripe_test_sk' => 'sk_test_matrix', 'stripe_webhook_secret' => 'whsec_matrix'],
        ],
        'mollie' => [
            ['enabled_payment_methods' => ['mollie'], 'mollie_live_key' => ''],
            ['enabled_payment_methods' => ['mollie'], 'mollie_live_key' => 'live_matrix', 'mollie_test_key' => 'test_matrix'],
        ],
        'paypal' => [
            ['enabled_payment_methods' => ['paypal'], 'paypal_live_client_id' => '', 'paypal_live_secret' => '', 'paypal_live_webhook_id' => ''],
            ['enabled_payment_methods' => ['paypal'], 'paypal_live_client_id' => 'live_client_matrix', 'paypal_live_secret' => 'live_secret_matrix', 'paypal_live_webhook_id' => 'live_webhook_matrix', 'paypal_test_client_id' => 'test_client_matrix'],
        ],
    ];
    foreach ($providers as $provider => [$missing, $complete]) {
        $common = array_merge($baseline, [
            'production' => true, 'enabled_notification_events' => ['order_confirmation'],
            'notification_sender_name' => 'Matrix Store', 'notification_sender_email' => 'matrix@example.test',
            'notification_transport' => 'wiremail', 'merchant_legal_details' => 'Matrix merchant', 'policy_pages' => ['terms'],
        ]);
        $missingHtml = $renderReadiness(array_merge($common, $missing));
        $completeHtml = $renderReadiness(array_merge($common, $complete));
        $expect(str_contains($missingHtml, 'required before taking live payments'), ucfirst($provider) . ' missing readiness was not critical.');
        $expect(str_contains($completeHtml, 'Critical live payment settings are present.'), ucfirst($provider) . ' complete readiness was not accepted: ' . trim(strip_tags($completeHtml)));
        $expect(!str_contains($completeHtml, 'required before taking live payments'), ucfirst($provider) . ' complete readiness retained a critical error.');
    }
    $conflictHtml = $renderReadiness(array_merge($baseline, [
        'production' => true, 'enabled_payment_methods' => ['stripe-card'],
        'stripe_live_pk' => 'same', 'stripe_test_pk' => 'same', 'stripe_live_sk' => 'live-secret',
        'stripe_test_sk' => 'test-secret', 'stripe_webhook_secret' => 'whsec_matrix',
        'enabled_notification_events' => [],
    ]));
    $expect(str_contains($conflictHtml, 'cannot match'), 'Readiness did not surface conflicting live/test credentials.');
} finally {
    $restore();
}

$expect((array) $modules->getConfig('Mercato') === $originalStored, 'Exact persisted configuration restore failed.');
foreach ($originalRuntime as $key => $value) $expect($commerce->get($key) === $value, "Exact in-memory restore failed for $key.");
$expect(is_file($settingsLog) === $settingsLogExisted, 'Settings audit log existence was not restored.');
if ($settingsLogExisted) $expect(file_get_contents($settingsLog) === $settingsLogContents, 'Settings audit log contents were not restored exactly.');

echo "Mercato config/readiness integration matrix passed: $checks assertions, 181 defaults, 178 inputs, 171 safely mutated settings, exact config/log restore.\n";
