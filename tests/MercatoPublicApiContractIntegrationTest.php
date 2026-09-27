<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) {
    echo "Mercato public API contract integration test skipped (set MERCATO_TEST_SITE).\n";
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
/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};

// Every Mercato method explicitly documented under "Public Calls Agents May Use".
$documentedMethods = [
    'cart', 'productList', 'formatPrice', 'completePayment', 'clearPendingCheckoutSession',
    'orderRepository', 'fulfilmentService', 'getProductPurchasability', 'variantService',
    'getOrderAnalyticsEvent', 'getFrontendFramework', 'renderFrontendFrameworkAssets',
    'getFrontendUiClasses', 'getStorefrontTemplateOverridePath', 'privacyService',
    'areOrderSignedLinksExpired', 'customerAccountService', 'getRuntimeCompatibilityReport',
    'operationalService', 'getBackgroundJobs', 'runBackgroundJobs', 'analyticsService',
    'setAnalyticsConsent', 'setMessage', 'getMessage', 'notificationDeliveryService',
    'pushNotificationService', 'notificationTemplates', 'notificationTemplate',
    'getOrderAccessRecoveryUrl', 'saveNotificationTemplate', 'resetNotificationTemplate',
    'saveNotificationMailLayout', 'seoService', 'seoOwner', 'usesBuiltInSeo',
    'submitQuoteRequest', 'updateQuoteStatus', 'quoteService', 'taxService',
    'shippingProviderService', 'paymentReconciliationAuditService', 'headlessApiService',
    'mcpProviderInfo', 'mcpTools', 'ensureMcpOperationsSchema', 'getGateway',
];

$reflection = new \ReflectionClass($commerce);
foreach ($documentedMethods as $methodName) {
    $expect($reflection->hasMethod($methodName), "Documented Mercato method $methodName does not exist.");
    $expect($reflection->getMethod($methodName)->isPublic(), "Documented Mercato method $methodName is not public.");
}
$expect(count($documentedMethods) === 47, 'Documented public API inventory changed; update the explicit contract ledger.');

$serviceContracts = [
    'orderRepository' => MercatoOrderRepository::class,
    'fulfilmentService' => MercatoFulfilmentService::class,
    'variantService' => MercatoVariantService::class,
    'privacyService' => MercatoPrivacyService::class,
    'customerAccountService' => MercatoCustomerAccountService::class,
    'operationalService' => MercatoOperationalService::class,
    'analyticsService' => MercatoAnalyticsService::class,
    'notificationDeliveryService' => MercatoEmailDeliveryService::class,
    'pushNotificationService' => MercatoPushNotificationService::class,
    'seoService' => MercatoSeoService::class,
    'quoteService' => MercatoQuoteService::class,
    'taxService' => MercatoTaxService::class,
    'shippingProviderService' => MercatoShippingProviderService::class,
    'paymentReconciliationAuditService' => MercatoPaymentReconciliationAuditService::class,
    'headlessApiService' => MercatoHeadlessApiService::class,
];
foreach ($serviceContracts as $methodName => $expectedClass) {
    $service = $commerce->{$methodName}();
    $expect($service instanceof $expectedClass, "$methodName() returned " . get_debug_type($service) . " instead of $expectedClass.");
}

$expect($commerce->cart([])->count() === 0, 'Empty cart public API is not deterministic.');
$expect($commerce->productList([])->count() === 0, 'Empty product-list public API is not deterministic.');
$expect(is_string($commerce->formatPrice(0.0)), 'Price formatter did not return a string for the zero boundary.');
$expect(in_array($commerce->getFrontendFramework(), ['tailwind', 'vanilla'], true), 'Frontend framework public API returned an unsupported value.');
$expect(is_array($commerce->getFrontendUiClasses()), 'Frontend UI class public API did not return an array.');
$expect(is_array($commerce->getRuntimeCompatibilityReport()), 'Runtime compatibility public API did not return a report.');
$expect(is_array($commerce->getBackgroundJobs()), 'Background jobs public API did not return an inventory.');
$expect(is_array($commerce->notificationTemplates()), 'Notification templates public API did not return an inventory.');
$expect(is_array($commerce->mcpProviderInfo()) && is_array($commerce->mcpTools()), 'MCP provider public API did not return array contracts.');

$stripe = $commerce->getGateway('stripe');
$stripeReflection = new \ReflectionClass($stripe);
foreach (['getCustomerBillingDetails', 'getStripeOrderData'] as $methodName) {
    $expect($stripeReflection->hasMethod($methodName) && $stripeReflection->getMethod($methodName)->isPublic(), "Documented Stripe method $methodName is missing or not public.");
}

$unknownGatewayRejected = false;
try {
    $commerce->getGateway('definitely-not-a-gateway');
} catch (WireException) {
    $unknownGatewayRejected = true;
}
$expect($unknownGatewayRejected, 'Unknown payment gateway did not fail closed.');

$unknownTemplateRejected = false;
try {
    $commerce->notificationTemplate('definitely-not-an-event');
} catch (WireException) {
    $unknownTemplateRejected = true;
}
$expect($unknownTemplateRejected, 'Unknown notification template did not fail closed.');

echo 'Mercato public API contract integration tests passed: 47 module methods, 15 service accessors, 2 Stripe methods.' . "\n";
