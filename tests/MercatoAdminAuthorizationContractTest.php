<?php

$root = dirname(__DIR__);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$methodBody = static function (string $source, string $method): string {
    $offset = strpos($source, 'function ' . $method . '(');
    if ($offset === false) {
        throw new RuntimeException(sprintf('Admin method %s was not found.', $method));
    }
    $open = strpos($source, '{', $offset);
    if ($open === false) {
        throw new RuntimeException(sprintf('Admin method %s has no body.', $method));
    }
    $depth = 0;
    $length = strlen($source);
    for ($i = $open; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $open + 1, $i - $open - 1);
            }
        }
    }
    throw new RuntimeException(sprintf('Admin method %s has an unterminated body.', $method));
};

$actionFiles = [
    $root . '/src/Admin/ProcessMercatoProductActions.php',
    $root . '/src/Admin/ProcessMercatoOrderFulfilmentActions.php',
    $root . '/src/Admin/ProcessMercatoPaymentRecoveryActions.php',
    $root . '/src/Admin/ProcessMercatoQuotePanels.php',
];

$actionPermissions = [
    'handleProductVariants' => 'PERMISSION_MANAGE_PRODUCTS',
    'handleProductImport' => 'PERMISSION_MANAGE_PRODUCTS',
    'handleProductBulkAction' => 'PERMISSION_MANAGE_PRODUCTS',
    'handleProductQuickUpdate' => 'PERMISSION_MANAGE_PRODUCTS',
    'handleProductDuplicate' => 'PERMISSION_MANAGE_PRODUCTS',
    'handlePrivacyRetention' => 'PERMISSION_MANAGE_PRIVACY',
    'handleShippingProviderAction' => 'PERMISSION_FULFIL_ORDERS',
    'handleReservationCleanup' => 'PERMISSION_MANAGE_INVENTORY',
    'handleDemoOrderCreation' => 'PERMISSION_LAUNCH_TOOLS',
    'handleDemoStorefrontSetup' => 'PERMISSION_LAUNCH_TOOLS',
    'handleDemoDiscountSetup' => 'PERMISSION_MANAGE_DISCOUNTS',
    'handleManualOrderCreation' => 'PERMISSION_MANUAL_ORDERS',
    'handleStockAdjustment' => 'PERMISSION_MANAGE_INVENTORY',
    'handleFulfilmentUpdate' => 'PERMISSION_FULFIL_ORDERS',
    'handlePaymentAuditAction' => 'PERMISSION_MANAGE_WEBHOOKS',
    'handlePaymentReconciliation' => 'PERMISSION_EDIT_ORDERS',
    'handleUnpaidOrderCancellation' => 'PERMISSION_EDIT_ORDERS',
    'handleRecoveryOrderCancellation' => 'PERMISSION_MANAGE_RECOVERY',
    'handleRecoveryBulkOrderCancellation' => 'PERMISSION_MANAGE_RECOVERY',
    'handleWebhookSimulation' => 'PERMISSION_MANAGE_WEBHOOKS',
    'handleRefund' => 'PERMISSION_REFUND_ORDERS',
    'handleShippingNotification' => 'PERMISSION_FULFIL_ORDERS',
    'handleNotificationRetry' => 'PERMISSION_FULFIL_ORDERS',
    'handleOrderConfirmation' => 'PERMISSION_EDIT_ORDERS',
    'handleOrderStatusLinkRegeneration' => 'PERMISSION_EDIT_ORDERS',
    'handlePaymentLinkEmail' => 'PERMISSION_EDIT_ORDERS',
    'handleRecoveryPaymentLinkEmail' => 'PERMISSION_MANAGE_RECOVERY',
    'handleRecoverySuppressEmail' => 'PERMISSION_MANAGE_RECOVERY',
    'handleRecoveryUnsuppressEmail' => 'PERMISSION_MANAGE_RECOVERY',
    'handleRecoveryAutomationPreview' => 'PERMISSION_MANAGE_RECOVERY',
    'handleOrderNote' => 'PERMISSION_EDIT_ORDERS',
    'handleCustomerNote' => 'PERMISSION_MANAGE_CUSTOMERS',
    'handleCustomerPrivacyAction' => 'PERMISSION_MANAGE_PRIVACY',
    'handleUnpaidOrderTotalsUpdate' => 'PERMISSION_EDIT_ORDERS',
    'handleQuoteUpdate' => 'PERMISSION_MANAGE_QUOTES',
];

$discovered = [];
foreach ($actionFiles as $file) {
    $source = (string) file_get_contents($file);
    preg_match_all('/protected\s+function\s+(handle[A-Za-z0-9_]+)\s*\(/', $source, $matches);
    foreach ($matches[1] as $method) {
        $discovered[$method] = $source;
    }
}

$expect(
    array_keys($discovered) === array_keys($actionPermissions),
    'The admin mutation inventory changed. Classify every new or removed handler in the authorization contract.'
);

foreach ($actionPermissions as $method => $permission) {
    $body = $methodBody($discovered[$method], $method);
    $permissionNeedle = 'hasCommercePermission(self::' . $permission . ')';
    $permissionOffset = strpos($body, $permissionNeedle);
    $csrfOffset = strpos($body, 'validateCsrf()');
    $expect($permissionOffset !== false, sprintf('%s does not enforce %s.', $method, $permission));
    $expect($csrfOffset !== false, sprintf('%s does not enforce a CSRF token.', $method));
    $expect($permissionOffset < $csrfOffset, sprintf('%s must reject unauthorized users before processing CSRF state.', $method));
}

$processSource = (string) file_get_contents($root . '/ProcessMercato.module.php');
$notificationSource = (string) file_get_contents($root . '/src/Admin/ProcessMercatoNotificationTemplates.php');
$routePermissions = [
    '___executeProducts' => 'PERMISSION_MANAGE_PRODUCTS',
    '___executeProductDetail' => 'PERMISSION_MANAGE_PRODUCTS',
    '___executeOrders' => 'PERMISSION_VIEW_ORDERS',
    '___executeQuotes' => 'PERMISSION_VIEW_QUOTES',
    '___executeQuoteDetail' => 'PERMISSION_VIEW_QUOTES',
    '___executeManualOrder' => 'PERMISSION_MANUAL_ORDERS',
    '___executeFulfilment' => 'PERMISSION_FULFIL_ORDERS',
    '___executeOrderTimeline' => 'PERMISSION_VIEW_ORDERS',
    '___executeOrderDetail' => 'PERMISSION_VIEW_ORDERS',
    '___executeCustomers' => 'PERMISSION_VIEW_CUSTOMERS',
    '___executeRecovery' => 'PERMISSION_MANAGE_RECOVERY',
    '___executeCustomerDetail' => 'PERMISSION_VIEW_CUSTOMERS',
    '___executeReports' => 'PERMISSION_VIEW_REPORTS',
    '___executeDiscounts' => 'PERMISSION_MANAGE_DISCOUNTS',
    '___executeWebhooks' => 'PERMISSION_MANAGE_WEBHOOKS',
    '___executePaymentAttempts' => 'PERMISSION_MANAGE_WEBHOOKS',
    '___executeRefunds' => 'PERMISSION_MANAGE_WEBHOOKS',
    '___executeInventory' => 'PERMISSION_MANAGE_INVENTORY',
    '___executeLaunch' => 'PERMISSION_LAUNCH_TOOLS',
];

foreach ($routePermissions as $method => $permission) {
    $body = $methodBody($processSource, $method);
    $expect(
        str_contains($body, 'hasCommercePermission(self::' . $permission . ')'),
        sprintf('%s does not enforce %s.', $method, $permission)
    );
}

$notificationBody = $methodBody($notificationSource, '___executeNotifications');
$expect(str_contains($notificationBody, 'hasCommercePermission(self::PERMISSION_MANAGE_NOTIFICATIONS)'), 'Notification designer access is not protected by its dedicated permission.');
$expect(str_contains($notificationBody, 'validateCsrf()'), 'Notification designer mutations are not CSRF guarded.');

echo sprintf(
    "Mercato admin authorization contract tests passed (%d mutations, %d privileged routes).\n",
    count($actionPermissions) + 1,
    count($routePermissions) + 1
);
