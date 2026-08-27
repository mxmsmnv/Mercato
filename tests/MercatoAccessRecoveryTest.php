<?php
$module = (string) file_get_contents(__DIR__ . '/../Mercato.module.php');
$trait = (string) file_get_contents(__DIR__ . '/../src/MercatoAccessRecovery.php');
$endpoint = (string) file_get_contents(__DIR__ . '/../src/MercatoPublicEndpoints.php');
$delivery = (string) file_get_contents(__DIR__ . '/../src/Notification/MercatoOrderConfirmationService.php');

foreach ([
    "'/access/recovery/{code}/?'",
    "'/api/mercato/access-recovery'",
    "'access_recovery_enabled' => false",
    'use MercatoAccessRecovery',
] as $needle) {
    if (!str_contains($module, $needle)) throw new RuntimeException('Mercato access recovery registration is missing: ' . $needle);
}
foreach ([
    'getOrderAccessRecoveryUrl',
    'getOrderAccessRecoveryCode',
    'resolveOrderAccessRecoveryCode',
    'verifyOrderAccessRecoveryToken',
    '___orderAccessRecoveryState',
    '___replaceOrderAccessCredential',
    "['http', 'https']",
] as $needle) {
    if (!str_contains($trait, $needle)) throw new RuntimeException('Mercato access recovery contract is missing: ' . $needle);
}
foreach (['hasValidToken', '303', 'no-store', 'no-referrer', 'mrc_access_recovery_once'] as $needle) {
    if (!str_contains($endpoint, $needle)) throw new RuntimeException('Mercato access recovery endpoint safeguard is missing: ' . $needle);
}
if (!str_contains($delivery, "'{access_recovery_link}' => \$this->commerce->getOrderAccessRecoveryUrl(\$order)")) {
    throw new RuntimeException('Order confirmation does not resolve the signed access recovery link.');
}

echo "Mercato access recovery tests passed.\n";
