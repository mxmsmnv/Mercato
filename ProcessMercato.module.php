<?php
namespace ProcessWire;

foreach ([
    MercatoFulfilmentStatus::class => '/src/Order/MercatoFulfilmentStatus.php',
    MercatoOrderTimeline::class => '/src/Order/MercatoOrderTimeline.php',
    MercatoPaymentStatus::class => '/src/Payment/MercatoPaymentStatus.php',
    MercatoPaymentEventLog::class => '/src/Payment/MercatoPaymentEventLog.php',
    MercatoPaymentReconciliationService::class => '/src/Payment/MercatoPaymentReconciliationService.php',
    MercatoRefundEventLog::class => '/src/Payment/MercatoRefundEventLog.php',
    MercatoRefundService::class => '/src/Payment/MercatoRefundService.php',
    MercatoDiscountType::class => '/src/Discount/MercatoDiscountType.php',
    MercatoFulfilmentEventLog::class => '/src/Fulfilment/MercatoFulfilmentEventLog.php',
    MercatoFulfilmentMethodType::class => '/src/Fulfilment/MercatoFulfilmentMethodType.php',
    MercatoShippingNotificationService::class => '/src/Fulfilment/MercatoShippingNotificationService.php',
    MercatoEventLog::class => '/src/Logging/MercatoEventLog.php',
    MercatoOrderConfirmationService::class => '/src/Notification/MercatoOrderConfirmationService.php',
    MercatoPaymentLinkService::class => '/src/Notification/MercatoPaymentLinkService.php',
    MercatoQuoteStatus::class => '/src/Quote/MercatoQuoteStatus.php',
] as $class => $file) {
    if (!class_exists($class)) {
        require_once __DIR__ . $file;
    }
}

require_once __DIR__ . '/src/Admin/ProcessMercatoPaymentPanels.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoEventReaders.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoLaunchPanels.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoExports.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoLaunchExports.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoProductPanels.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoOrderPanels.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoOrderDetailPanels.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoRecoveryCustomerPanels.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoReportDiscountPanels.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoWebhookInventoryPanels.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoUiHelpers.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoAdminStyles.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoQueries.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoProductActions.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoOrderFulfilmentActions.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoPaymentRecoveryActions.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoImportCustomerReportData.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoAdminHelpers.php';
require_once __DIR__ . '/src/Admin/ProcessMercatoQuotePanels.php';

/**
 * ProcessMercato
 *
 * Admin dashboard for Mercato orders and products.
 */
class ProcessMercato extends Process implements Module {

    use ProcessMercatoPaymentPanels;
    use ProcessMercatoEventReaders;
    use ProcessMercatoLaunchPanels;
    use ProcessMercatoExports;
    use ProcessMercatoLaunchExports;
    use ProcessMercatoProductPanels;
    use ProcessMercatoOrderPanels;
    use ProcessMercatoOrderDetailPanels;
    use ProcessMercatoRecoveryCustomerPanels;
    use ProcessMercatoReportDiscountPanels;
    use ProcessMercatoWebhookInventoryPanels;
    use ProcessMercatoUiHelpers;
    use ProcessMercatoAdminStyles;
    use ProcessMercatoQueries;
    use ProcessMercatoProductActions;
    use ProcessMercatoOrderFulfilmentActions;
    use ProcessMercatoPaymentRecoveryActions;
    use ProcessMercatoImportCustomerReportData;
    use ProcessMercatoAdminHelpers;
    use ProcessMercatoQuotePanels;

    protected const PERMISSION_ADMIN = 'mercato-admin';
    protected const PERMISSION_VIEW_ORDERS = 'mercato-view-orders';
    protected const PERMISSION_EDIT_ORDERS = 'mercato-edit-orders';
    protected const PERMISSION_REFUND_ORDERS = 'mercato-refund-orders';
    protected const PERMISSION_MANUAL_ORDERS = 'mercato-create-manual-orders';
    protected const PERMISSION_VIEW_QUOTES = 'mercato-view-quotes';
    protected const PERMISSION_MANAGE_QUOTES = 'mercato-manage-quotes';
    protected const PERMISSION_MANAGE_PRODUCTS = 'mercato-manage-products';
    protected const PERMISSION_MANAGE_INVENTORY = 'mercato-manage-inventory';
    protected const PERMISSION_FULFIL_ORDERS = 'mercato-fulfil-orders';
    protected const PERMISSION_VIEW_CUSTOMERS = 'mercato-view-customers';
    protected const PERMISSION_MANAGE_CUSTOMERS = 'mercato-manage-customers';
    protected const PERMISSION_MANAGE_PRIVACY = 'mercato-manage-privacy';
    protected const PERMISSION_MANAGE_RECOVERY = 'mercato-manage-recovery';
    protected const PERMISSION_VIEW_REPORTS = 'mercato-view-reports';
    protected const PERMISSION_MANAGE_DISCOUNTS = 'mercato-manage-discounts';
    protected const PERMISSION_MANAGE_WEBHOOKS = 'mercato-manage-webhooks';
    protected const PERMISSION_LAUNCH_TOOLS = 'mercato-launch-tools';
    protected const DASHBOARD_CACHE_TTL_SECONDS = 60;

    public static function getModuleInfo(): array {
        return [
            'title' => 'Mercato Dashboard',
            'summary' => 'Admin dashboard for Mercato orders, products, and revenue.',
            'version' => 140,
            'author' => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'singular' => true,
            'autoload' => false,
            'icon' => 'shopping-cart',
            'requires' => ['Mercato'],
            'permission' => self::PERMISSION_ADMIN,
            'permissions' => [
                self::PERMISSION_ADMIN => 'Access Mercato dashboard',
                self::PERMISSION_VIEW_ORDERS => 'View Mercato orders',
                self::PERMISSION_EDIT_ORDERS => 'Edit Mercato orders and send order emails',
                self::PERMISSION_REFUND_ORDERS => 'Issue and reconcile Mercato refunds',
                self::PERMISSION_MANUAL_ORDERS => 'Create Mercato manual orders',
                self::PERMISSION_VIEW_QUOTES => 'View Mercato quote requests',
                self::PERMISSION_MANAGE_QUOTES => 'Manage Mercato quote requests',
                self::PERMISSION_MANAGE_PRODUCTS => 'Manage Mercato products and product imports',
                self::PERMISSION_MANAGE_INVENTORY => 'Adjust and view Mercato inventory',
                self::PERMISSION_FULFIL_ORDERS => 'Update Mercato fulfilment and send fulfilment emails',
                self::PERMISSION_VIEW_CUSTOMERS => 'View Mercato customers',
                self::PERMISSION_MANAGE_CUSTOMERS => 'Manage Mercato customer notes',
                self::PERMISSION_MANAGE_PRIVACY => 'Review and execute Mercato privacy actions',
                self::PERMISSION_MANAGE_RECOVERY => 'Manage Mercato abandoned checkout recovery',
                self::PERMISSION_VIEW_REPORTS => 'View Mercato reports',
                self::PERMISSION_MANAGE_DISCOUNTS => 'Manage Mercato discounts',
                self::PERMISSION_MANAGE_WEBHOOKS => 'View and simulate Mercato webhooks',
                self::PERMISSION_LAUNCH_TOOLS => 'Use Mercato launch and fixture tools',
            ],
            'page' => [
                'name' => 'mercato',
                'parent' => 'setup',
                'title' => 'Mercato',
            ],
        ];
    }

    public function ___execute(): string {
        $this->headline($this->_('Mercato Dashboard'));

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $orders = $this->getOrders($commerce);
        $products = $this->getProducts($commerce, 3);
        $stats = $this->getCachedDashboardData($commerce, 'stats');

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('dashboard');
        $out .= $this->renderHeader($commerce);
        $out .= $this->renderStats($stats, $commerce);
        $out .= $this->renderOperationsAttention($commerce, $stats);
        $out .= $this->renderRevenueChart($orders, $commerce);
        $out .= $this->renderRecentOrders($orders, $commerce);
        $out .= $this->renderProducts($products, $commerce);
        $out .= '</div>';

        return $out;
    }

    public function ___executeProducts(): string {
        $this->headline($this->_('Mercato Products'));
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_PRODUCTS)) {
            return $this->renderAccessDenied(self::PERMISSION_MANAGE_PRODUCTS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $adjustmentResult = $this->handleStockAdjustment($commerce);
        $bulkResult = $this->handleProductBulkAction($commerce);
        $importResult = $this->handleProductImport($commerce);
        $filters = $this->getRequestedProductFilters();
        $products = $this->getProducts($commerce, 100, $filters);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('products');
        $out .= $this->renderProducts($products, $commerce, false, $importResult, $adjustmentResult, $bulkResult, $filters);
        $out .= '</div>';

        return $out;
    }

    public function ___executeProductDetail(): string {
        $this->headline($this->_('Mercato Product Detail'));
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_PRODUCTS)) {
            return $this->renderAccessDenied(self::PERMISSION_MANAGE_PRODUCTS, 'products');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $adjustmentResult = $this->handleStockAdjustment($commerce);
        $quickUpdateResult = $this->handleProductQuickUpdate($commerce);
        $variantResult = $this->handleProductVariants($commerce);
        $duplicateResult = $this->handleProductDuplicate($commerce);
        $productId = (int) $this->wire('sanitizer')->int($this->wire('input')->get('id'));
        $product = $productId > 0 ? $this->wire('pages')->get($productId) : new NullPage();

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('products');
        if (!$product || !$product->id || !$product->template || $product->template->name !== 'mrc-product') {
            $out .= '<section class="pw-wrap mrc-admin-panel"><h2 class="uk-h3">' . $this->e($this->_('Product not found')) . '</h2>';
            $out .= '<p><a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('products/')) . '">' . $this->e($this->_('Back to products')) . '</a></p></section>';
            $out .= '</div>';
            return $out;
        }

        $canViewOrders = $this->hasCommercePermission(self::PERMISSION_VIEW_ORDERS);
        $orders = $canViewOrders ? $this->getOrdersContainingProduct($commerce, $product, 25) : new PageArray();
        $out .= $this->renderProductDetail($product, $commerce, $orders, $adjustmentResult, $quickUpdateResult, $duplicateResult, $canViewOrders, $variantResult);
        $out .= '</div>';
        return $out;
    }

    public function ___executeOrders(): string {
        $this->headline($this->_('Mercato Orders'));
        if (!$this->hasCommercePermission(self::PERMISSION_VIEW_ORDERS)) {
            return $this->renderAccessDenied(self::PERMISSION_VIEW_ORDERS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $status = $this->getRequestedOrderStatus();
        $orders = $this->getFilteredOrders($commerce, $status, 100);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('orders');
        $out .= $this->renderOrders($orders, $commerce, $status);
        $out .= '</div>';

        return $out;
    }

    public function ___executeQuotes(): string {
        $this->headline($this->_('Mercato Quote Requests'));
        if (!$this->hasCommercePermission(self::PERMISSION_VIEW_QUOTES)) {
            return $this->renderAccessDenied(self::PERMISSION_VIEW_QUOTES, 'dashboard');
        }
        $commerce = $this->wire('modules')->get('Mercato');
        return $this->renderStyles() . '<div class="mrc-admin-dashboard">'
            . $this->renderAdminNav('quotes')
            . $this->renderQuotes($this->getQuotes($commerce), $commerce)
            . '</div>';
    }

    public function ___executeQuoteDetail(): string {
        $this->headline($this->_('Mercato Quote Request'));
        if (!$this->hasCommercePermission(self::PERMISSION_VIEW_QUOTES)) {
            return $this->renderAccessDenied(self::PERMISSION_VIEW_QUOTES, 'quotes');
        }
        $commerce = $this->wire('modules')->get('Mercato');
        $quote = $this->wire('pages')->get((int) $this->wire('input')->get('id'));
        $out = $this->renderStyles() . '<div class="mrc-admin-dashboard">' . $this->renderAdminNav('quotes');
        if (!$quote || !$quote->id || $quote->template->name !== (string) $commerce->quote_template) {
            return $out . '<section class="pw-wrap mrc-admin-panel"><p class="uk-alert uk-alert-danger">' . $this->e($this->_('Quote request not found.')) . '</p></section></div>';
        }
        $result = $this->handleQuoteUpdate($commerce, $quote);
        if ($result && empty($result['errors'])) $quote = $this->wire('pages')->getById((int) $quote->id, ['cache' => false])->first();
        return $out . $this->renderQuoteDetail($quote, $commerce, $result) . '</div>';
    }

    public function ___executeManualOrder(): string {
        $this->headline($this->_('Create Manual Order'));
        if (!$this->hasCommercePermission(self::PERMISSION_MANUAL_ORDERS)) {
            return $this->renderAccessDenied(self::PERMISSION_MANUAL_ORDERS, 'orders');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $result = $this->handleManualOrderCreation($commerce);
        $formValues = (array) ($result['values'] ?? $this->getManualOrderFormValues($commerce));
        if (!$result && trim((string) ($formValues['customer_key'] ?? '')) !== '') {
            $formValues = $this->applyManualOrderCustomer($commerce, $formValues);
        }
        $products = $this->getManualOrderProducts($commerce, $formValues);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('manual-order');
        $out .= $this->renderManualOrder($products, $commerce, $result, $formValues);
        $out .= '</div>';

        return $out;
    }

    public function ___executeFulfilment(): string {
        $this->headline($this->_('Mercato Fulfilment'));
        if (!$this->hasCommercePermission(self::PERMISSION_FULFIL_ORDERS)) {
            return $this->renderAccessDenied(self::PERMISSION_FULFIL_ORDERS, 'orders');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $actionResult = $this->handleFulfilmentUpdate($commerce);
        $notificationResult = $this->handleShippingNotification($commerce);
        $retryResult = $this->handleNotificationRetry($commerce);
        if ($retryResult) $notificationResult = $retryResult;
        $method = $this->getRequestedFulfilmentMethod();
        $queueFilter = $this->getRequestedFulfilmentQueueFilter();
        $notificationFilters = $this->getRequestedNotificationFilters();
        $orders = $this->getFulfilmentOrders($commerce, 100, $method, $queueFilter);
        $events = $this->filterFulfilmentEventsByMethod($this->getFulfilmentEvents(10000), $method, 30);
        $notifications = $this->getNotificationEvents(10000, $notificationFilters, 30);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('fulfilment');
        $out .= $this->renderFulfilment($orders, $commerce, $actionResult, $notificationResult, $method, $queueFilter, $this->getFulfilmentQueueSummary($commerce, $method));
        $out .= $this->renderFulfilmentEvents($events);
        $out .= $this->renderNotificationEvents($notifications, $notificationFilters, $method);
        $out .= '</div>';

        return $out;
    }

    public function ___executeOrderTimeline(): string {
        $this->headline($this->_('Order Timeline'));
        if (!$this->hasCommercePermission(self::PERMISSION_VIEW_ORDERS)) {
            return $this->renderAccessDenied(self::PERMISSION_VIEW_ORDERS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $orderId = (int) $this->wire('sanitizer')->int($this->wire('input')->get('id'));
        $order = $this->wire('pages')->get($orderId);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('orders');
        if (!$order || !$order->id || $order->template->name !== $commerce->order_template) {
            return $out . '<section class="pw-wrap mrc-admin-panel"><p class="uk-alert uk-alert-danger">' . $this->e($this->_('Order not found.')) . '</p></section></div>';
        }

        $events = MercatoOrderTimeline::build(
            $order,
            $this->getWebhookEvents(10000),
            $this->getInventoryEvents(10000),
            $this->getFulfilmentEvents(10000),
            $this->getNotificationEvents(10000),
            $this->getPaymentEvents(10000),
            $this->getRefundEvents(10000),
            $this->getOrderEditEvents(10000),
            $this->getOrderNoteEvents(10000),
            $this->getRecoveryEvents(10000),
            $this->getPaymentAttemptEvents(10000)
        );
        $out .= $this->renderOrderTimeline($order, $events, $commerce);
        return $out . '</div>';
    }

    public function ___executeOrderDetail(): string {
        $this->headline($this->_('Order Detail'));
        if (!$this->hasCommercePermission(self::PERMISSION_VIEW_ORDERS)) {
            return $this->renderAccessDenied(self::PERMISSION_VIEW_ORDERS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $orderId = (int) $this->wire('sanitizer')->int($this->wire('input')->get('id'));
        $order = $this->wire('pages')->get($orderId);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('orders');
        if (!$order || !$order->id || $order->template->name !== $commerce->order_template) {
            return $out . '<section class="pw-wrap mrc-admin-panel"><p class="uk-alert uk-alert-danger">' . $this->e($this->_('Order not found.')) . '</p></section></div>';
        }

        $orderEditResult = $this->handleUnpaidOrderTotalsUpdate($commerce, $order);
        $orderNoteResult = $this->handleOrderNote($commerce, $order);
        $paymentLinkResult = $this->handlePaymentLinkEmail($commerce, $order);
        $cancelResult = $this->handleUnpaidOrderCancellation($commerce, $order);
        $actionResult = $this->handlePaymentReconciliation($commerce, $order);
        $paymentAuditResult = $this->handlePaymentAuditAction($commerce, $order);
        if (!$actionResult && $paymentAuditResult) $actionResult = $paymentAuditResult;
        $shippingActionResult = $this->handleShippingProviderAction($commerce, $order);
        if (!$actionResult && $shippingActionResult) $actionResult = $shippingActionResult;
        $refundResult = $this->handleRefund($commerce, $order);
        $confirmationResult = $this->handleOrderConfirmation($commerce, $order);
        $statusLinkResult = $this->handleOrderStatusLinkRegeneration($commerce, $order);
        if (($orderEditResult && empty($orderEditResult['errors'])) || ($orderNoteResult && empty($orderNoteResult['errors'])) || ($paymentLinkResult && empty($paymentLinkResult['errors'])) || ($cancelResult && empty($cancelResult['errors'])) || ($actionResult && (empty($actionResult['errors']) || !empty($actionResult['warning']))) || ($refundResult && (empty($refundResult['errors']) || !empty($refundResult['warning']))) || ($confirmationResult && empty($confirmationResult['errors'])) || ($statusLinkResult && empty($statusLinkResult['errors']))) {
            $reloaded = $this->wire('pages')->getById((int) $order->id, ['cache' => false])->first();
            if ($reloaded && $reloaded->id) {
                $order = $reloaded;
            }
        }
        $events = MercatoOrderTimeline::build(
            $order,
            $this->getWebhookEvents(10000),
            $this->getInventoryEvents(10000),
            $this->getFulfilmentEvents(10000),
            $this->getNotificationEvents(10000),
            $this->getPaymentEvents(10000),
            $this->getRefundEvents(10000),
            $this->getOrderEditEvents(10000),
            $this->getOrderNoteEvents(10000),
            $this->getRecoveryEvents(10000),
            $this->getPaymentAttemptEvents(10000)
        );
        $out .= $this->renderOrderDetail($order, $events, $commerce, $actionResult, $refundResult, $confirmationResult, $paymentLinkResult, $orderEditResult, $orderNoteResult, $cancelResult, $statusLinkResult);
        return $out . '</div>';
    }

    public function ___executeCustomers(): string {
        $this->headline($this->_('Mercato Customers'));
        if (!$this->hasCommercePermission(self::PERMISSION_VIEW_CUSTOMERS)) {
            return $this->renderAccessDenied(self::PERMISSION_VIEW_CUSTOMERS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $filters = $this->getRequestedCustomerFilters();
        $customers = $this->filterCustomers($this->getCustomersFromOrders($commerce), $filters);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('customers');
        $out .= $this->renderCustomers($customers, $commerce, $filters);
        $out .= '</div>';

        return $out;
    }

    public function ___executeRecovery(): string {
        $this->headline($this->_('Mercato Recovery'));
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_RECOVERY)) {
            return $this->renderAccessDenied(self::PERMISSION_MANAGE_RECOVERY, 'orders');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $result = $this->handleRecoveryPaymentLinkEmail($commerce);
        if (!$result) {
            $result = $this->handleRecoverySuppressEmail($commerce);
        }
        if (!$result) {
            $result = $this->handleRecoveryUnsuppressEmail($commerce);
        }
        if (!$result) {
            $result = $this->handleRecoveryOrderCancellation($commerce);
        }
        if (!$result) {
            $result = $this->handleRecoveryBulkOrderCancellation($commerce);
        }
        if (!$result) {
            $result = $this->handleRecoveryAutomationPreview($commerce);
        }
        $filters = $this->getRequestedRecoveryFilters();
        $rows = $this->getAbandonedCheckouts($commerce, $filters);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('recovery');
        $out .= $this->renderRecovery($rows, $commerce, $filters, $result);
        $out .= '</div>';

        return $out;
    }

    public function ___executeCustomerDetail(): string {
        $this->headline($this->_('Mercato Customer'));
        if (!$this->hasCommercePermission(self::PERMISSION_VIEW_CUSTOMERS)) {
            return $this->renderAccessDenied(self::PERMISSION_VIEW_CUSTOMERS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $key = $this->getRequestedCustomerKey();
        $customer = $this->getCustomerByKey($commerce, $key);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('customers');
        if (!$customer) {
            return $out . '<section class="pw-wrap mrc-admin-panel"><p class="uk-alert uk-alert-danger">' . $this->e($this->_('Customer not found.')) . '</p></section></div>';
        }
        $privacyResult = $this->handleCustomerPrivacyAction($commerce, $customer);
        $noteResult = $privacyResult ? [] : $this->handleCustomerNote($customer);
        if (($privacyResult['status'] ?? '') === 'completed') { $customer = $this->getCustomerByKey($commerce, (string) ($privacyResult['new_customer_key'] ?? '')) ?: $customer; }
        $out .= $this->renderCustomerDetail($customer, $this->getCustomerOrders($commerce, $customer), $commerce, $noteResult, $privacyResult);
        $out .= '</div>';

        return $out;
    }

    public function ___executeSearch(): string {
        $this->headline($this->_('Mercato Search'));

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $query = $this->getRequestedSearchQuery();
        $results = $query !== '' ? $this->getSearchResults($commerce, $query) : [
            'orders' => new PageArray(),
            'products' => new PageArray(),
            'customers' => [],
        ];

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('search');
        $out .= $this->renderSearch($query, $results, $commerce);
        $out .= '</div>';

        return $out;
    }

    public function ___executeReports(): string {
        $this->headline($this->_('Mercato Reports'));
        if (!$this->hasCommercePermission(self::PERMISSION_VIEW_REPORTS)) {
            return $this->renderAccessDenied(self::PERMISSION_VIEW_REPORTS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $reports = $this->getCachedDashboardData($commerce, 'reports');

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('reports');
        $out .= $this->renderReports($reports, $commerce);
        $out .= '</div>';

        return $out;
    }

    public function ___executeDiscounts(): string {
        $this->headline($this->_('Mercato Discounts'));
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_DISCOUNTS)) {
            return $this->renderAccessDenied(self::PERMISSION_MANAGE_DISCOUNTS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $discounts = $commerce->discountService()->listDiscounts(100);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('discounts');
        $out .= $this->renderDiscounts($discounts, $commerce);
        $out .= '</div>';

        return $out;
    }

    public function ___executeWebhooks(): string {
        $this->headline($this->_('Mercato Webhooks'));
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_WEBHOOKS)) {
            return $this->renderAccessDenied(self::PERMISSION_MANAGE_WEBHOOKS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $simulationResult = $this->handleWebhookSimulation($commerce);
        $filters = $this->getRequestedWebhookFilters();
        $allEvents = $this->getWebhookEvents(250);
        $events = $this->filterWebhookEvents($allEvents, $filters);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('webhooks');
        $out .= $this->renderWebhooks($events, $commerce, $simulationResult, $filters, $allEvents);
        $out .= '</div>';

        return $out;
    }

    public function ___executePaymentAttempts(): string {
        $this->headline($this->_('Mercato Payment Attempts'));
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_WEBHOOKS)) {
            return $this->renderAccessDenied(self::PERMISSION_MANAGE_WEBHOOKS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $filters = $this->getRequestedPaymentAttemptFilters();
        $events = $this->filterPaymentAttemptEvents($this->getPaymentAttemptEvents(500), $filters);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('payment-attempts');
        $out .= $this->renderPaymentReconciliationQueue($commerce, $this->getPaymentAttemptEvents(10000));
        $out .= $this->renderPaymentAttempts($events, $commerce, $filters);
        $out .= '</div>';

        return $out;
    }

    public function ___executeRefunds(): string {
        $this->headline($this->_('Mercato Refunds'));
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_WEBHOOKS)) {
            return $this->renderAccessDenied(self::PERMISSION_MANAGE_WEBHOOKS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $filters = $this->getRequestedRefundFilters();
        $events = $this->filterRefundEvents($this->getRefundEvents(500), $filters);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('refunds');
        $out .= $this->renderRefunds($events, $commerce, $filters);
        $out .= '</div>';

        return $out;
    }

    public function ___executeInventory(): string {
        $this->headline($this->_('Mercato Inventory'));
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_INVENTORY)) {
            return $this->renderAccessDenied(self::PERMISSION_MANAGE_INVENTORY, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $filters = $this->getRequestedInventoryFilters();
        $events = $this->getInventoryEvents(10000);
        $filteredEvents = $this->filterInventoryEvents($events, $filters, 100);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('inventory');
        $out .= $this->renderInventorySummary($commerce, $events);
        $out .= $this->renderInventoryEvents($filteredEvents, $filters, $events);
        $out .= '</div>';

        return $out;
    }

    public function ___executeLaunch(): string {
        $this->headline($this->_('Mercato Launch Checklist'));
        if (!$this->hasCommercePermission(self::PERMISSION_LAUNCH_TOOLS)) {
            return $this->renderAccessDenied(self::PERMISSION_LAUNCH_TOOLS, 'dashboard');
        }

        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $cleanupResult = $this->handleReservationCleanup($commerce);
        $demoSetupResult = $this->handleDemoStorefrontSetup($commerce);
        $demoDiscountResult = $this->handleDemoDiscountSetup($commerce);
        $demoOrderResult = $this->handleDemoOrderCreation($commerce);
        $privacyRetentionResult = $this->handlePrivacyRetention($commerce);

        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav('launch');
        $out .= $this->renderLaunchChecklist($commerce, $cleanupResult, $demoOrderResult, $demoSetupResult, $demoDiscountResult, $privacyRetentionResult);
        $out .= '</div>';

        return $out;
    }

    public function ___executeExport(): string {
        /** @var Mercato $commerce */
        $commerce = $this->wire('modules')->get('Mercato');
        $type = strtolower((string) $this->wire('input')->get->text('type'));
        $status = $this->getRequestedOrderStatus();
        $permission = $this->getExportPermission($type);
        if (!$this->hasCommercePermission($permission)) {
            throw new WirePermissionException(sprintf('Missing permission: %s', $permission));
        }
        if ($type === 'privacy-customer') {
            $customer = $this->getCustomerByKey($commerce, $this->getRequestedCustomerKey());
            if (!$customer || trim((string) ($customer['email'] ?? '')) === '') throw new WireException('Customer privacy export subject was not found.');
            $this->sendJson('mercato-customer-privacy-' . date('Y-m-d') . '.json', $commerce->privacyService()->exportCustomer((string) $customer['email']));
            return '';
        }

        [$filename, $rows] = match ($type) {
            'products' => ['mercato-products-' . date('Y-m-d') . '.csv', $this->getProductExportRows($commerce, $this->getRequestedProductFilters())],
            'product-events' => ['mercato-product-events-' . date('Y-m-d') . '.csv', $this->getProductEventExportRows()],
            'customers' => ['mercato-customers-' . date('Y-m-d') . '.csv', $this->getCustomerExportRows($commerce, $this->getRequestedCustomerFilters())],
            'recovery' => ['mercato-recovery-' . date('Y-m-d') . '.csv', $this->getRecoveryExportRows($commerce, $this->getRequestedRecoveryFilters())],
            'recovery-events' => ['mercato-recovery-events-' . date('Y-m-d') . '.csv', $this->getRecoveryEventExportRows()],
            'customer-orders' => ['mercato-customer-orders-' . date('Y-m-d') . '.csv', $this->getCustomerOrderExportRows($commerce, $this->getRequestedCustomerKey())],
            'customer-notes' => ['mercato-customer-notes-' . date('Y-m-d') . '.csv', $this->getCustomerNoteExportRows($this->getRequestedCustomerKey())],
            'discounts' => ['mercato-discounts-' . date('Y-m-d') . '.csv', $this->getDiscountExportRows($commerce)],
            'discount-events' => ['mercato-discount-events-' . date('Y-m-d') . '.csv', $this->getDiscountEventExportRows($this->getRequestedDiscountEventFilters())],
            'webhooks' => ['mercato-webhooks-' . date('Y-m-d') . '.csv', $this->getWebhookExportRows($this->getRequestedWebhookFilters())],
            'inventory' => ['mercato-inventory-' . date('Y-m-d') . '.csv', $this->getInventoryExportRows($this->getRequestedInventoryFilters())],
            'stock-pressure' => ['mercato-stock-pressure-' . date('Y-m-d') . '.csv', $this->getStockPressureExportRows($commerce)],
            'tax-shipping-readiness' => ['mercato-tax-shipping-readiness-' . date('Y-m-d') . '.csv', $this->getTaxShippingReadinessExportRows($commerce)],
            'fulfilment' => ['mercato-fulfilment-' . date('Y-m-d') . '.csv', $this->getFulfilmentExportRows($this->getRequestedFulfilmentMethod())],
            'fulfilment-queue' => ['mercato-fulfilment-queue-' . date('Y-m-d') . '.csv', $this->getFulfilmentQueueExportRows($commerce, $this->getRequestedFulfilmentMethod(), $this->getRequestedFulfilmentQueueFilter())],
            'notifications' => ['mercato-notifications-' . date('Y-m-d') . '.csv', $this->getNotificationExportRows($this->getRequestedNotificationFilters())],
            'payments' => ['mercato-payments-' . date('Y-m-d') . '.csv', $this->getPaymentExportRows()],
            'payment-attempts' => ['mercato-payment-attempts-' . date('Y-m-d') . '.csv', $this->getPaymentAttemptExportRows($this->getRequestedPaymentAttemptFilters())],
            'refunds' => ['mercato-refunds-' . date('Y-m-d') . '.csv', $this->getRefundExportRows($this->getRequestedRefundFilters())],
            'order-items' => ['mercato-order-items-' . date('Y-m-d') . '.csv', $this->getOrderItemExportRows($commerce, $status)],
            'order-edits' => ['mercato-order-edits-' . date('Y-m-d') . '.csv', $this->getOrderEditExportRows()],
            'order-notes' => ['mercato-order-notes-' . date('Y-m-d') . '.csv', $this->getOrderNoteExportRows()],
            'launch-summary' => ['mercato-launch-summary-' . date('Y-m-d') . '.csv', $this->getLaunchSummaryExportRows($commerce)],
            'launch-checklist' => ['mercato-launch-checklist-' . date('Y-m-d') . '.csv', $this->getLaunchChecklistExportRows($commerce)],
            default => ['mercato-orders-' . date('Y-m-d') . '.csv', $this->getOrderExportRows($commerce, $status)],
        };

        $this->sendCsv($filename, $rows);
        return '';
    }



}
