<?php
require_once __DIR__ . '/../src/MercatoConfigSupport.php';

use ProcessWire\MercatoConfigSupport;

final class MercatoConfigNormalizationHarness {
    use MercatoConfigSupport;

    public static function normalize(string $method, mixed ...$arguments): mixed {
        $reflection = new ReflectionMethod(self::class, $method);
        return $reflection->invoke(null, ...$arguments);
    }
}

$expect = static function (mixed $actual, mixed $expected, string $label): void {
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$cases = [
    ['normalizePagePathConfig', ['/checkout/success/', 'fallback'], 'checkout/success', 'page path trims slashes'],
    ['normalizePagePathConfig', [['-1', 'orders'], 'fallback'], 'orders', 'page path rejects ProcessWire removal marker'],
    ['normalizePagePathListConfig', [' /terms/ ,privacy,terms '], ['terms', 'privacy'], 'page list trims and deduplicates'],
    ['normalizeEnabledPaymentMethods', ['stripe-card, paypal,invalid'], ['stripe-card', 'paypal'], 'payment methods trim CSV values'],
    ['normalizeEnabledPaymentMethods', [[]], ['stripe-card'], 'payment methods retain safe fallback'],
    ['normalizeEnabledFulfilmentMethods', ['store_pickup, local_delivery,invalid'], ['store_pickup', 'local_delivery'], 'fulfilment methods trim CSV values'],
    ['normalizeDefaultFulfilmentMethod', ['invalid', ['store_pickup', 'carrier_delivery']], 'store_pickup', 'default fulfilment remains enabled'],
    ['normalizeFrontendFramework', [' UIKIT '], 'uikit', 'frontend framework is case-normalized'],
    ['normalizeFrontendFramework', ['unknown'], 'vanilla', 'unknown frontend framework falls back'],
    ['normalizeReservationTtlMinutes', [0], 30, 'reservation TTL invalid low uses default'],
    ['normalizeReservationTtlMinutes', [1441], 1440, 'reservation TTL caps high'],
    ['normalizeReservationCleanupSchedule', ['everyHour'], 'everyHour', 'known schedule retained'],
    ['normalizeReservationCleanupSchedule', ['weekly'], 'every30Minutes', 'unknown schedule falls back'],
    ['normalizeReservationCleanupSchedule', ['weekly', 'everyDay'], 'everyDay', 'privacy schedule uses its policy fallback'],
    ['normalizeReservationCleanupSchedule', ['weekly', 'disabled'], 'disabled', 'recovery schedule fails closed'],
    ['normalizeReservationCleanupSchedule', ['weekly', 'not-a-schedule'], 'every30Minutes', 'invalid fallback uses cleanup default'],
    ['normalizeRetentionDays', [-1, 30, 1, 3650], 30, 'retention invalid low uses policy default'],
    ['normalizeRetentionDays', [9000, 30, 1, 3650], 3650, 'retention caps high'],
    ['normalizeInvoicePrefix', [' inv / 2026! '], 'INV2026', 'invoice prefix strips unsafe characters'],
    ['normalizeLowStockThreshold', [-1], 0, 'stock threshold floors'],
    ['normalizeLowStockThreshold', [1000001], 1000000, 'stock threshold caps'],
    ['normalizeMoneyAmount', [-0.01], 0.0, 'money floors negative values'],
    ['normalizeMoneyAmount', [100000001], 100000000.0, 'money caps large values'],
    ['normalizeMoneyAmount', [NAN], 0.0, 'money rejects NaN'],
    ['normalizeMoneyAmount', [INF], 0.0, 'money rejects infinity'],
    ['normalizeFiniteRange', [NAN, 5.0, 1.0, 30.0], 5.0, 'finite range rejects NaN'],
    ['normalizeFiniteRange', [999, 5.0, 1.0, 30.0], 30.0, 'finite range caps high'],
    ['normalizeShippingDimensionsField', [' Product Dimensions! '], 'product_dimensions', 'dimension field is sanitized'],
    ['normalizeShippingCalculationMode', ['MAX_WEIGHT'], 'max_weight', 'shipping calculation mode is normalized'],
    ['normalizeShippingCalculationMode', ['invalid'], 'flat', 'shipping calculation mode falls back'],
    ['normalizeMissingMeasurementsPolicy', ['unavailable'], 'unavailable', 'measurement policy retains valid value'],
    ['normalizeTaxRate', [-5], 0.0, 'tax rate floors'],
    ['normalizeTaxRate', [125], 100.0, 'tax rate caps'],
    ['normalizeTaxRate', [NAN], 0.0, 'tax rate rejects NaN'],
    ['normalizeTaxLabel', [' <b> Sales   tax </b> '], 'Sales tax', 'tax label strips markup and collapses spaces'],
    ['normalizeTaxRoundingMode', ['bad'], 'line', 'tax rounding falls back'],
    ['normalizeTaxDisplayMode', ['excluded'], 'excluded', 'tax display retains known value'],
    ['normalizeFrontendAssetUrl', ['javascript:alert(1)', 'tailwind'], 'https://cdn.tailwindcss.com', 'asset URL rejects unsafe scheme'],
    ['normalizeFrontendAssetUrl', ['https://assets.example/app.css', 'bootstrap'], 'https://assets.example/app.css', 'asset URL accepts HTTPS'],
    ['normalizeReceiptPdfUrlTemplate', ['javascript:alert(1)'], '', 'receipt URL rejects unsafe scheme'],
    ['normalizeReceiptPdfUrlTemplate', ['/order/{code}/pdf/'], '/order/{code}/pdf/', 'receipt URL accepts local template'],
    ['normalizeReceiptTemplateFile', ['../secret.php'], '', 'template file rejects traversal'],
    ['normalizeReceiptTemplateFile', ['mercato/receipt.php'], 'mercato/receipt.php', 'template file accepts relative PHP'],
    ['normalizeCountryCodes', ['us, GB;de,usa'], "DE\nGB\nUS", 'country codes accept lowercase, reject non-ISO length, and sort'],
    ['normalizeDeliveryRegions', ["us:ny=New York\nGB|ENG|England\ninvalid"], "GB:ENG:England\nUS:NY:New York", 'delivery regions normalize and sort'],
    ['normalizeDeliveryWindows', [[" 9-12 ", '', "9-12", "13-17\0"]], "9-12\n13-17", 'delivery windows sanitize and deduplicate'],
    ['normalizePickupLocations', ["Shop B|B Street\nShop A|A Street|Desk|9-5\nShop A|Replacement"], "Shop A | Replacement\nShop B | B Street", 'pickup locations normalize by label'],
    ['normalizeRecoveryEmailCooldownMinutes', [-1], 0, 'recovery cooldown floors'],
    ['normalizeRecoveryEmailCooldownMinutes', [20000], 10080, 'recovery cooldown caps'],
    ['normalizeRecoveryAutomationMinAgeMinutes', [1], 15, 'recovery minimum age floors'],
    ['normalizeRecoveryAutomationBatchLimit', [101], 100, 'recovery batch caps'],
    ['normalizeRecoveryDiscountCode', [' save 10%! '], 'SAVE10', 'recovery discount code sanitizes'],
    ['normalizeHeadlessAllowedOrigins', [" HTTPS://Shop.Example/\nhttps://api.example:8443\nhttp://bad.example\nhttps://*.example\nhttps://user@example.com\nhttps://shop.example/path\nhttps://shop.example"], "https://api.example:8443\nhttps://shop.example", 'headless origins retain unique exact HTTPS origins only'],
];

foreach ($cases as [$method, $arguments, $expected, $label]) {
    $expect(MercatoConfigNormalizationHarness::normalize($method, ...$arguments), $expected, $label);
}

echo 'Mercato config normalization tests passed: ' . count($cases) . " parameterized cases.\n";
