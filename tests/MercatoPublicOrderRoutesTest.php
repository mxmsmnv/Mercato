<?php
$trait = (string) file_get_contents(__DIR__ . '/../src/MercatoPublicOrderRoutes.php');
$module = (string) file_get_contents(__DIR__ . '/../Mercato.module.php');
$api = (string) file_get_contents(__DIR__ . '/../src/MercatoOrderApi.php');
$experience = (string) file_get_contents(__DIR__ . '/../src/MercatoOrderExperience.php');
$endpoints = (string) file_get_contents(__DIR__ . '/../src/MercatoPublicEndpoints.php');
$seo = (string) file_get_contents(__DIR__ . '/../src/Seo/MercatoSeoRules.php');

foreach ([
    'getOrderPublicRouteCode',
    'resolveOrderPublicRouteCode',
    'mercato-order-public-route|',
    "in_array(\$purpose, ['status', 'receipt'], true)",
    'hash_equals',
] as $needle) {
    if (!str_contains($trait, $needle)) throw new RuntimeException('Missing signed public-order route protection: ' . $needle);
}

foreach ([
    "'/order/status/{code}/?'",
    "'/order/receipt/{code}/?'",
    "'/order/receipt/{code}/pdf/?'",
] as $needle) {
    if (!str_contains($module, $needle)) throw new RuntimeException('Missing clean public-order route: ' . $needle);
}

if (!str_contains($api, "'/order/status/'") || !str_contains($api, "'/order/receipt/'")) {
    throw new RuntimeException('Order link generators must prefer clean public routes.');
}
if (!str_contains($experience, "'/order/receipt/'") || !str_contains($experience, "'/pdf/'")) {
    throw new RuntimeException('Receipt PDF links must use the clean public route.');
}
if (substr_count($endpoints, "resolveOrderPublicRouteCode(\$code") < 3) {
    throw new RuntimeException('Status, receipt, and receipt PDF handlers must resolve opaque route codes.');
}
foreach (['getOrderStatusUrl($order)', 'getOrderReceiptUrl($order)', 'getOrderReceiptPdfUrl($order)'] as $redirect) {
    if (!str_contains($endpoints, "header('Location: ' . \$this->{$redirect}")) {
        throw new RuntimeException('Legacy public-order links must redirect to clean routes: ' . $redirect);
    }
}
if (!str_contains($seo, "'/order/status/'") || !str_contains($seo, "'/order/receipt/'")) {
    throw new RuntimeException('Clean private order routes must remain excluded from SEO.');
}

echo "Mercato public order routes test passed.\n";
