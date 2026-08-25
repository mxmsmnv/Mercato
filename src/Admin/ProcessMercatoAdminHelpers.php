<?php
namespace ProcessWire;

trait ProcessMercatoAdminHelpers {

    protected function renderAdminNav(string $active = 'dashboard'): string {
        $out = "<div class='mrc-admin-nav uk-margin-medium-bottom'><ul class='uk-subnav uk-subnav-pill'>\n";
        foreach ($this->getAdminTabs() as $key => $item) {
            $permission = (string) ($item['permission'] ?? self::PERMISSION_ADMIN);
            if (!$this->hasCommercePermission($permission)) {
                continue;
            }
            $label = $item['label'];
            $path = $item['path'];
            $class = $key === $active ? " class='uk-active'" : '';
            $url = $this->adminUrl($path);
            $out .= "<li{$class}><a href='" . $this->e($url) . "'>" . $this->e($label) . "</a></li>\n";
        }
        $settingsUrl = $this->wire('config')->urls->admin . 'module/edit?name=Mercato';
        $settingsLabel = $this->_('Settings');
        $out .= "</ul><a class='mrc-settings-link' href='" . $this->e($settingsUrl) . "' title='" . $this->e($settingsLabel) . "' aria-label='" . $this->e($settingsLabel) . "'>" . $this->renderSettingsIcon() . "</a></div>\n";
        return $out;
    }

    protected function getAdminTabs(): array {
        return [
            'dashboard' => [
                'label' => $this->_('Dashboard'),
                'path' => '',
                'permission' => self::PERMISSION_ADMIN,
            ],
            'orders' => [
                'label' => $this->_('Orders'),
                'path' => 'orders/',
                'permission' => self::PERMISSION_VIEW_ORDERS,
            ],
            'quotes' => [
                'label' => $this->_('Quotes'),
                'path' => 'quotes/',
                'permission' => self::PERMISSION_VIEW_QUOTES,
            ],
            'manual-order' => [
                'label' => $this->_('Manual Order'),
                'path' => 'manual-order/',
                'permission' => self::PERMISSION_MANUAL_ORDERS,
            ],
            'fulfilment' => [
                'label' => $this->_('Fulfilment'),
                'path' => 'fulfilment/',
                'permission' => self::PERMISSION_FULFIL_ORDERS,
            ],
            'products' => [
                'label' => $this->_('Products'),
                'path' => 'products/',
                'permission' => self::PERMISSION_MANAGE_PRODUCTS,
            ],
            'customers' => [
                'label' => $this->_('Customers'),
                'path' => 'customers/',
                'permission' => self::PERMISSION_VIEW_CUSTOMERS,
            ],
            'recovery' => [
                'label' => $this->_('Recovery'),
                'path' => 'recovery/',
                'permission' => self::PERMISSION_MANAGE_RECOVERY,
            ],
            'search' => [
                'label' => $this->_('Search'),
                'path' => 'search/',
                'permission' => self::PERMISSION_ADMIN,
            ],
            'reports' => [
                'label' => $this->_('Reports'),
                'path' => 'reports/',
                'permission' => self::PERMISSION_VIEW_REPORTS,
            ],
            'discounts' => [
                'label' => $this->_('Discounts'),
                'path' => 'discounts/',
                'permission' => self::PERMISSION_MANAGE_DISCOUNTS,
            ],
            'inventory' => [
                'label' => $this->_('Inventory'),
                'path' => 'inventory/',
                'permission' => self::PERMISSION_MANAGE_INVENTORY,
            ],
            'launch' => [
                'label' => $this->_('Launch'),
                'path' => 'launch/',
                'permission' => self::PERMISSION_LAUNCH_TOOLS,
            ],
            'webhooks' => [
                'label' => $this->_('Webhooks'),
                'path' => 'webhooks/',
                'permission' => self::PERMISSION_MANAGE_WEBHOOKS,
            ],
            'payment-attempts' => [
                'label' => $this->_('Payment Attempts'),
                'path' => 'payment-attempts/',
                'permission' => self::PERMISSION_MANAGE_WEBHOOKS,
            ],
            'refunds' => [
                'label' => $this->_('Refunds'),
                'path' => 'refunds/',
                'permission' => self::PERMISSION_MANAGE_WEBHOOKS,
            ],
        ];
    }

    protected function hasCommercePermission(string $permission): bool {
        if ($permission === '') {
            return true;
        }
        $user = $this->wire('user');
        if ($user && method_exists($user, 'isSuperuser') && $user->isSuperuser()) {
            return true;
        }
        return (bool) ($user && $user->hasPermission($permission));
    }

    protected function renderAccessDenied(string $permission, string $active = 'dashboard'): string {
        $out = $this->renderStyles();
        $out .= '<div class="mrc-admin-dashboard">';
        $out .= $this->renderAdminNav($active);
        $out .= '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Access denied')) . '</h2>';
        $out .= '<p class="uk-alert uk-alert-danger">' . $this->e(sprintf($this->_('This Mercato action requires the "%s" permission.'), $permission)) . '</p>';
        $out .= '</section></div>';
        return $out;
    }

    protected function permissionError(string $permission, string $summary): array {
        return [
            'summary' => $summary,
            'errors' => [sprintf($this->_('Missing permission: %s'), $permission)],
        ];
    }

    protected function getExportPermission(string $type): string {
        return match ($type) {
            'products', 'product-events' => self::PERMISSION_MANAGE_PRODUCTS,
            'customers', 'customer-orders', 'customer-notes' => self::PERMISSION_VIEW_CUSTOMERS,
            'privacy-customer' => self::PERMISSION_MANAGE_PRIVACY,
            'recovery', 'recovery-events' => self::PERMISSION_MANAGE_RECOVERY,
            'discounts', 'discount-events' => self::PERMISSION_MANAGE_DISCOUNTS,
            'webhooks', 'payments', 'payment-attempts', 'refunds' => self::PERMISSION_MANAGE_WEBHOOKS,
            'inventory', 'stock-pressure' => self::PERMISSION_MANAGE_INVENTORY,
            'tax-shipping-readiness' => self::PERMISSION_VIEW_REPORTS,
            'fulfilment', 'fulfilment-queue', 'notifications' => self::PERMISSION_FULFIL_ORDERS,
            'launch-checklist', 'launch-summary' => self::PERMISSION_LAUNCH_TOOLS,
            default => self::PERMISSION_VIEW_ORDERS,
        };
    }

    protected function renderHeader(Mercato $commerce): string {
        $ordersUrl = $this->adminUrl('orders/');
        $manualOrderUrl = $this->adminUrl('manual-order/');
        $productsParent = $this->wire('pages')->get('/products/');
        $productsUrl = $this->adminUrl('products/');
        $productTemplate = $this->wire('templates')->get('mrc-product');
        $addProductUrl = ($productsParent && $productsParent->id && $productTemplate)
            ? $this->wire('config')->urls->admin . 'page/add/?parent_id=' . (int) $productsParent->id . '&template_id=' . (int) $productTemplate->id
            : '';

        $out = '<div class="pw-wrap mrc-admin-header">';
        $out .= '<div>';
        $out .= '<div class="ds-section-label">' . $this->e($this->_('Commerce')) . '</div>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Orders, payment status, and recent commerce activity.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-admin-actions">';
        $out .= '<a class="uk-button uk-button-primary" href="' . $this->e($ordersUrl) . '"><i class="fa fa-list uk-margin-small-right"></i>' . $this->e($this->_('Orders')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($manualOrderUrl) . '"><i class="fa fa-credit-card uk-margin-small-right"></i>' . $this->e($this->_('Manual Order')) . '</a>';
        if ($productsUrl) {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($productsUrl) . '"><i class="fa fa-shopping-bag uk-margin-small-right"></i>' . $this->e($this->_('Products')) . '</a>';
        }
        if ($addProductUrl) {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($addProductUrl) . '"><i class="fa fa-plus uk-margin-small-right"></i>' . $this->e($this->_('Add Product')) . '</a>';
        }
        $out .= '</div></div>';
        return $out;
    }

    protected function renderStats(array $stats, Mercato $commerce): string {
        $cards = [
            [$this->_('Orders'), (string) $stats['total'], $this->_('All orders, including pending.')],
            [$this->_('Paid'), (string) $stats['paid'], $this->_('Orders marked payment complete.')],
            [$this->_('Pending'), (string) $stats['pending'], $this->_('Awaiting payment.')],
            [$this->_('Processing'), (string) ($stats['processing'] ?? 0), $this->_('Awaiting gateway.')],
            [$this->_('Failed'), (string) ($stats['failed'] ?? 0), $this->_('Failed or expired.')],
            [$this->_('Canceled'), (string) ($stats['canceled'] ?? 0), $this->_('Canceled before payment.')],
            [$this->_('Revenue'), $commerce->formatPrice((float) $stats['revenue']), $this->_('Paid order total.')],
            [$this->_('Open value'), $commerce->formatPrice((float) ($stats['open_value'] ?? 0)), $this->_('Pending + processing.')],
            [$this->_('Products'), (string) $stats['products'], $this->_('Published and unpublished products.')],
            [$this->_('Low stock'), (string) ($stats['low_stock'] ?? 0), $this->_('Products at or below threshold.')],
        ];

        $out = '<div class="mrc-admin-stats uk-child-width-1-5@l uk-child-width-1-3@m uk-child-width-1-2@s" uk-grid>';
        foreach ($cards as [$label, $value, $note]) {
            $out .= '<div><div class="uk-card uk-card-default uk-card-body uk-card-small mrc-admin-card">';
            $out .= '<span class="ds-section-label">' . $this->e($label) . '</span>';
            $out .= '<strong class="uk-display-block">' . $this->e($value) . '</strong>';
            $out .= '<small class="uk-text-muted">' . $this->e($note) . '</small>';
            $out .= '</div></div>';
        }
        $out .= '</div>';
        return $out;
    }

    protected function renderOperationsAttention(Mercato $commerce, array $stats): string {
        $pendingRefunds = 0;
        foreach ($this->getRefundEvents(500) as $event) {
            $gatewayStatus = strtolower((string) ($event['gateway_status'] ?? ''));
            $paymentStatus = strtolower((string) ($event['payment_status'] ?? ''));
            if (str_contains($paymentStatus, 'refund_pending') || (float) ($event['pending_amount'] ?? 0) > 0 || in_array($gatewayStatus, ['pending', 'queued', 'processing'], true)) {
                $pendingRefunds++;
            }
        }
        $expiredReservations = $commerce->orderRepository()->countExpiredReservations();
        $fulfilmentOrders = $this->getFulfilmentOrders($commerce, 100);
        $fulfilmentWork = $fulfilmentOrders->count();
        $failedPayments = (int) ($stats['failed'] ?? 0);
        $lowStock = (int) ($stats['low_stock'] ?? 0);
        $fulfilmentByMethod = [
            MercatoFulfilmentMethodType::CARRIER_DELIVERY => 0,
            MercatoFulfilmentMethodType::STORE_PICKUP => 0,
            MercatoFulfilmentMethodType::LOCAL_DELIVERY => 0,
        ];
        foreach ($fulfilmentOrders as $order) {
            $method = $this->getOrderFulfilmentMethod($order);
            if (array_key_exists($method, $fulfilmentByMethod)) {
                $fulfilmentByMethod[$method]++;
            }
        }
        $fulfilmentUrl = $this->adminUrl('fulfilment/');
        foreach ($fulfilmentByMethod as $method => $count) {
            if ($fulfilmentWork > 0 && $count === $fulfilmentWork) {
                $fulfilmentUrl .= '?method=' . rawurlencode($method);
                break;
            }
        }
        $fulfilmentDetail = $fulfilmentWork > 0
            ? sprintf(
                $this->_('Delivery %d / pickup %d / local %d.'),
                $fulfilmentByMethod[MercatoFulfilmentMethodType::CARRIER_DELIVERY],
                $fulfilmentByMethod[MercatoFulfilmentMethodType::STORE_PICKUP],
                $fulfilmentByMethod[MercatoFulfilmentMethodType::LOCAL_DELIVERY]
            )
            : $this->_('No active fulfilment work.');

        $stockAttention = $lowStock + $expiredReservations;
        $stockUrl = $lowStock > 0
            ? $this->adminUrl('products/') . '?stock=low_stock'
            : $this->adminUrl('inventory/');
        if ($lowStock > 0 && $expiredReservations > 0) {
            $stockDetail = sprintf($this->_('%d low-stock product(s), %d expired reservation(s).'), $lowStock, $expiredReservations);
        } elseif ($lowStock > 0) {
            $stockDetail = $this->_('Low-stock products need review.');
        } elseif ($expiredReservations > 0) {
            $stockDetail = sprintf($this->_('%d expired reservation(s).'), $expiredReservations);
        } else {
            $stockDetail = $this->_('Stock looks clear.');
        }

        $items = [
            [
                'key' => 'pending-refunds',
                'label' => $this->_('Pending refunds'),
                'value' => $pendingRefunds,
                'detail' => $pendingRefunds > 0 ? $this->_('Provider confirmation needed.') : $this->_('No pending refunds.'),
                'url' => $this->adminUrl('refunds/') . '?state=pending',
                'permission' => self::PERMISSION_MANAGE_WEBHOOKS,
                'class' => $pendingRefunds > 0 ? 'is-pending' : 'is-paid',
            ],
            [
                'key' => 'failed-payments',
                'label' => $this->_('Failed payments'),
                'value' => $failedPayments,
                'detail' => $failedPayments > 0 ? $this->_('Review failed orders or recovery.') : $this->_('No failed payments.'),
                'url' => $this->adminUrl('orders/') . '?status=failed',
                'permission' => self::PERMISSION_VIEW_ORDERS,
                'class' => $failedPayments > 0 ? 'is-failed' : 'is-paid',
            ],
            [
                'key' => 'fulfilment-work',
                'label' => $this->_('Fulfilment work'),
                'value' => $fulfilmentWork,
                'detail' => $fulfilmentDetail,
                'url' => $fulfilmentUrl,
                'permission' => self::PERMISSION_FULFIL_ORDERS,
                'class' => $fulfilmentWork > 0 ? 'is-pending' : 'is-paid',
            ],
            [
                'key' => 'stock-attention',
                'label' => $this->_('Stock attention'),
                'value' => $stockAttention,
                'detail' => $stockDetail,
                'url' => $stockUrl,
                'permission' => self::PERMISSION_MANAGE_INVENTORY,
                'class' => $stockAttention > 0 ? 'is-pending' : 'is-paid',
            ],
        ];

        $out = '<section class="pw-wrap mrc-admin-panel mrc-operations-attention">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Operations Attention')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('A compact queue of payment, refund, fulfilment, and stock work that may need action.')) . '</p></div></div>';
        $out .= '<div class="mrc-attention-grid">';
        foreach ($items as $item) {
            if (!$this->hasCommercePermission((string) $item['permission'])) {
                continue;
            }
            $value = (int) $item['value'];
            $out .= '<a class="mrc-attention-card ' . $this->e((string) $item['class']) . '" data-attention="' . $this->e((string) $item['key']) . '" href="' . $this->e((string) $item['url']) . '">';
            $out .= '<span>' . $this->e((string) $item['label']) . '</span>';
            $out .= '<strong>' . $this->e((string) $value) . '</strong>';
            $out .= '<small>' . $this->e((string) $item['detail']) . '</small>';
            $out .= '</a>';
        }
        $out .= '</div></section>';

        return $out;
    }

    protected function renderSkeletonRows(int $rows, int $columns): string {
        $out = '';
        for ($row = 0; $row < $rows; $row++) {
            $out .= '<tr class="mrc-skeleton-row">';
            for ($column = 0; $column < $columns; $column++) {
                $width = 42 + (($row + $column) % 4) * 13;
                $out .= '<td><span class="mrc-skeleton" style="width:' . $width . '%"></span></td>';
            }
            $out .= '</tr>';
        }
        return $out;
    }

    protected function getOrderPaymentState(Page $order): array {
        $raw = (string) ($order->mrc_payment_status ?: '');
        $isPaid = (int) $order->mrc_payment_complete === 1 || $raw === Mercato::PAYMENT_STATUS_PAID;
        $label = $raw !== '' ? ucfirst(str_replace('_', ' ', $raw)) : ($isPaid ? $this->_('Paid') : $this->_('Pending'));
        if ($raw === MercatoPaymentStatus::REFUNDED) {
            $class = 'is-failed';
        } elseif (in_array($raw, [MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUND_PENDING, MercatoPaymentStatus::PARTIAL_REFUND_PENDING], true)) {
            $class = 'is-pending';
        } else {
            $class = match ($this->getPaymentStatusBucketForRaw($raw, $isPaid)) {
                'paid' => 'is-paid',
                'failed', 'canceled' => 'is-failed',
                default => 'is-pending',
            };
        }

        return [
            'raw' => $raw,
            'paid' => $isPaid,
            'label' => $label,
            'class' => $class,
        ];
    }

    protected function getPaymentStatusBucketFromState(array $state): string {
        return $this->getPaymentStatusBucketForRaw((string) ($state['raw'] ?? ''), !empty($state['paid']));
    }

    protected function getPaymentStatusBucketForRaw(string $raw, bool $isPaid = false): string {
        $raw = strtolower(trim($raw));
        if ($isPaid || $raw === MercatoPaymentStatus::PAID) {
            return 'paid';
        }

        return match (true) {
            in_array($raw, [MercatoPaymentStatus::PROCESSING, MercatoPaymentStatus::REQUIRES_ACTION, MercatoPaymentStatus::REQUIRES_CONFIRMATION], true) => 'processing',
            in_array($raw, [MercatoPaymentStatus::FAILED, MercatoPaymentStatus::EXPIRED], true) => 'failed',
            $raw === MercatoPaymentStatus::CANCELED => 'canceled',
            default => 'pending',
        };
    }

    protected function getOrderInventoryState(Page $order): array {
        $payment = $this->getOrderPaymentState($order);
        if (!$payment['paid']) {
            return ['raw' => 'waiting_payment', 'label' => $this->_('Waiting'), 'class' => 'is-pending'];
        }

        if ($order->hasField('mrc_inventory_adjusted') && (int) $order->mrc_inventory_adjusted === 1) {
            return ['raw' => 'adjusted', 'label' => $this->_('Adjusted'), 'class' => 'is-paid'];
        }

        if ($order->hasField('mrc_inventory_reserved') && (int) $order->mrc_inventory_reserved === 1) {
            $until = $order->hasField('mrc_inventory_reserved_until') ? strtotime((string) $order->mrc_inventory_reserved_until) : false;
            if ($until !== false && $until < time()) {
                return ['raw' => 'expired', 'label' => $this->_('Expired'), 'class' => 'is-failed'];
            }
            return ['raw' => 'reserved', 'label' => $this->_('Reserved'), 'class' => 'is-pending'];
        }

        $details = $order->hasField('mrc_inventory_details') ? json_decode((string) $order->mrc_inventory_details, true) : null;
        if (is_array($details) && !empty($details['errors'])) {
            return ['raw' => 'attention', 'label' => $this->_('Attention'), 'class' => 'is-failed'];
        }

        return ['raw' => 'pending', 'label' => $this->_('Pending'), 'class' => 'is-pending'];
    }

    protected function getOrderFulfilmentState(Page $order): array {
        $payment = $this->getOrderPaymentState($order);
        if (!$payment['paid']) {
            return ['raw' => 'waiting_payment', 'label' => $this->_('Waiting'), 'class' => 'is-pending', 'detail' => $this->_('Payment is not complete.')];
        }

        $stored = $order->hasField('mrc_fulfilment_status') ? strtolower(trim((string) $order->mrc_fulfilment_status)) : '';
        if (MercatoFulfilmentStatus::isValid($stored) && $stored !== MercatoFulfilmentStatus::UNFULFILLED) {
            $detail = $order->hasField('mrc_fulfilment_tracking') && (string) $order->mrc_fulfilment_tracking !== ''
                ? sprintf($this->_('Tracking: %s'), (string) $order->mrc_fulfilment_tracking)
                : $this->_('Manual fulfilment status.');
            $class = in_array($stored, [MercatoFulfilmentStatus::FULFILLED, MercatoFulfilmentStatus::SHIPPED, MercatoFulfilmentStatus::COLLECTED, MercatoFulfilmentStatus::DELIVERED], true)
                ? 'is-paid'
                : ($stored === MercatoFulfilmentStatus::RETURNED ? 'is-failed' : 'is-pending');
            return ['raw' => $stored, 'label' => $this->getFulfilmentStatusLabel($stored), 'class' => $class, 'detail' => $detail];
        }

        $policies = $this->getOrderStockPolicies($order);
        $inventory = $this->getOrderInventoryState($order);
        if ($inventory['class'] === 'is-failed') {
            return ['raw' => 'attention', 'label' => $this->_('Attention'), 'class' => 'is-failed', 'detail' => $inventory['label']];
        }
        if (($policies['backorder'] ?? 0) > 0) {
            return ['raw' => 'backorder', 'label' => $this->_('Backorder'), 'class' => 'is-pending', 'detail' => sprintf($this->_('%d item(s) need backorder handling.'), (int) $policies['backorder'])];
        }
        if (($policies['preorder'] ?? 0) > 0) {
            return ['raw' => 'preorder', 'label' => $this->_('Preorder'), 'class' => 'is-pending', 'detail' => sprintf($this->_('%d item(s) are preorder items.'), (int) $policies['preorder'])];
        }
        if (($inventory['raw'] ?? '') === 'adjusted') {
            return ['raw' => 'fulfilled_inventory', 'label' => $this->_('Ready'), 'class' => 'is-paid', 'detail' => $this->_('Inventory adjusted.')];
        }
        return ['raw' => 'unfulfilled', 'label' => $this->_('Unfulfilled'), 'class' => 'is-pending', 'detail' => $this->_('Awaiting manual fulfilment.')];
    }

    protected function getOrderStockPolicies(Page $order): array {
        $items = json_decode((string) $order->mrc_items, true);
        $counts = ['deny' => 0, 'backorder' => 0, 'preorder' => 0];
        if (!is_array($items)) {
            return $counts;
        }

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $policy = strtolower(trim((string) ($item['stock_policy'] ?? 'deny')));
            if (!isset($counts[$policy])) $policy = 'deny';
            $counts[$policy] += (int) ceil((float) ($item['quantity'] ?? 1));
        }
        return $counts;
    }

    protected function renderOrderFulfilmentItems(Page $order): string {
        $items = json_decode((string) $order->mrc_items, true);
        if (!is_array($items) || !$items) {
            return '-';
        }

        $out = '<div class="mrc-fulfilment-items">';
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $policy = strtolower(trim((string) ($item['stock_policy'] ?? 'deny')));
            $policy = in_array($policy, ['deny', 'backorder', 'preorder'], true) ? $policy : 'deny';
            $label = $policy === 'deny' ? '' : ' · ' . ucfirst($policy);
            $out .= '<div><strong>' . $this->e((string) ($item['title'] ?? $item['name'] ?? '-')) . '</strong> <small>x' . $this->e((string) ($item['quantity'] ?? 1)) . $this->e($label) . '</small></div>';
        }
        $out .= '</div>';
        return $out;
    }

    protected function renderFulfilmentUpdateForm(Page $order, string $activeMethod = 'all', string $activeQueue = 'all'): string {
        $current = $order->hasField('mrc_fulfilment_status') ? strtolower(trim((string) $order->mrc_fulfilment_status)) : '';
        if (!MercatoFulfilmentStatus::isValid($current)) {
            $current = MercatoFulfilmentStatus::UNFULFILLED;
        }
        $method = $this->getOrderFulfilmentMethod($order);
        $statuses = $this->getFulfilmentStatusesForMethod($method);
        if (!in_array($current, $statuses, true)) {
            $statuses[] = $current;
        }

        $query = [];
        if ($activeMethod !== 'all') {
            $query['method'] = $activeMethod;
        }
        if ($activeQueue !== 'all') {
            $query['queue'] = $activeQueue;
        }
        $action = $this->adminUrl('fulfilment/') . ($query ? '?' . http_build_query($query) : '');
        $out = '<form class="mrc-fulfilment-form" method="post" action="' . $this->e($action) . '">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="order_id" value="' . (int) $order->id . '">';
        $out .= '<select class="uk-select" name="fulfilment_status" aria-label="' . $this->e($this->_('Fulfilment status')) . '">';
        foreach ($statuses as $status) {
            $selected = $status === $current ? ' selected' : '';
            $out .= '<option value="' . $this->e($status) . '"' . $selected . '>' . $this->e($this->getFulfilmentStatusLabel($status)) . '</option>';
        }
        $out .= '</select>';
        if ($this->fulfilmentMethodSupportsTracking($method)) {
            $out .= '<input class="uk-input" type="text" name="fulfilment_tracking" value="' . $this->e($order->hasField('mrc_fulfilment_tracking') ? (string) $order->mrc_fulfilment_tracking : '') . '" placeholder="' . $this->e($this->_('Tracking')) . '">';
            $out .= '<input class="uk-input" type="url" name="fulfilment_tracking_url" value="' . $this->e($order->hasField('mrc_fulfilment_tracking_url') ? (string) $order->mrc_fulfilment_tracking_url : '') . '" placeholder="' . $this->e($this->_('Tracking URL')) . '">';
            $out .= '<input class="uk-input" type="text" name="fulfilment_carrier_reference" value="" placeholder="' . $this->e($this->_('Carrier reference')) . '">';
            $out .= '<input class="uk-input" type="url" name="fulfilment_label_url" value="" placeholder="' . $this->e($this->_('Label URL')) . '">';
        }
        if ($method === MercatoFulfilmentMethodType::LOCAL_DELIVERY) {
            $out .= '<input class="uk-input" type="text" name="fulfilment_courier" value="" placeholder="' . $this->e($this->_('Courier / driver')) . '">';
            $out .= '<input class="uk-input" type="text" name="fulfilment_proof" value="" placeholder="' . $this->e($this->_('Proof of delivery')) . '">';
        }
        $notesLabel = $method === MercatoFulfilmentMethodType::STORE_PICKUP
            ? $this->_('Pickup notes')
            : ($method === MercatoFulfilmentMethodType::LOCAL_DELIVERY ? $this->_('Courier / delivery notes') : $this->_('Notes'));
        $out .= '<textarea class="uk-textarea" name="fulfilment_notes" rows="2" placeholder="' . $this->e($notesLabel) . '">' . $this->e($order->hasField('mrc_fulfilment_notes') ? (string) $order->mrc_fulfilment_notes : '') . '</textarea>';
        $out .= '<div class="mrc-table-actions">';
        $out .= '<button class="uk-button uk-button-primary" type="submit" name="mrc_update_fulfilment" value="1"><i class="fa fa-check uk-margin-small-right"></i>' . $this->e($this->_('Save')) . '</button>';
        if ($method === MercatoFulfilmentMethodType::CARRIER_DELIVERY && $current === MercatoFulfilmentStatus::SHIPPED) {
            $out .= '<button class="uk-button uk-button-default" type="submit" name="mrc_send_fulfilment_notification" value="1"><i class="fa fa-envelope-o uk-margin-small-right"></i>' . $this->e($this->_('Send shipping email')) . '</button>';
        } elseif ($method === MercatoFulfilmentMethodType::STORE_PICKUP && $current === MercatoFulfilmentStatus::READY_FOR_PICKUP) {
            $out .= '<button class="uk-button uk-button-default" type="submit" name="mrc_send_fulfilment_notification" value="1"><i class="fa fa-envelope-o uk-margin-small-right"></i>' . $this->e($this->_('Send pickup email')) . '</button>';
        } elseif ($method === MercatoFulfilmentMethodType::LOCAL_DELIVERY && $current === MercatoFulfilmentStatus::OUT_FOR_DELIVERY) {
            $out .= '<button class="uk-button uk-button-default" type="submit" name="mrc_send_fulfilment_notification" value="1"><i class="fa fa-envelope-o uk-margin-small-right"></i>' . $this->e($this->_('Send delivery email')) . '</button>';
        }
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->timelineUrl($order)) . '"><i class="fa fa-clock-o uk-margin-small-right"></i>' . $this->e($this->_('Timeline')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->orderDetailUrl($order)) . '"><i class="fa fa-file-text-o uk-margin-small-right"></i>' . $this->e($this->_('Detail')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->editUrl($order)) . '"><i class="fa fa-pencil uk-margin-small-right"></i>' . $this->e($this->_('Edit')) . '</a>';
        $out .= '</div></form>';

        return $out;
    }

    protected function getFulfilmentStatusLabel(string $status): string {
        return match ($status) {
            MercatoFulfilmentStatus::PARTIALLY_FULFILLED => $this->_('Partially fulfilled'),
            MercatoFulfilmentStatus::FULFILLED => $this->_('Fulfilled'),
            MercatoFulfilmentStatus::SHIPPED => $this->_('Shipped'),
            MercatoFulfilmentStatus::READY_FOR_PICKUP => $this->_('Ready for pickup'),
            MercatoFulfilmentStatus::COLLECTED => $this->_('Collected'),
            MercatoFulfilmentStatus::OUT_FOR_DELIVERY => $this->_('Out for delivery'),
            MercatoFulfilmentStatus::DELIVERED => $this->_('Delivered'),
            MercatoFulfilmentStatus::RETURNED => $this->_('Returned'),
            default => $this->_('Unfulfilled'),
        };
    }

    protected function getOrderCustomer(Page $order): string {
        $customer = trim((string) $order->mrc_first_name . ' ' . (string) $order->mrc_last_name);
        return $customer !== '' ? $customer : (string) $order->mrc_email;
    }

    protected function getOrderCustomerProfileUrl(Page $order): string {
        $email = strtolower(trim((string) $order->mrc_email));
        return $email !== '' ? $this->customerDetailUrl(['key' => $email]) : '';
    }

    protected function getOrderItemCount(Page $order): int {
        $items = json_decode((string) $order->mrc_items, true);
        return is_array($items) ? count($items) : 0;
    }

    protected function getOrderItemsSummary(Page $order): string {
        $items = json_decode((string) $order->mrc_items, true);
        if (!is_array($items) || !$items) {
            return '';
        }

        $labels = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
            if ($title === '') {
                $title = 'Item';
            }
            $quantity = (float) ($item['quantity'] ?? 1);
            $quantityLabel = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
            $labels[] = $title . ' x' . ($quantityLabel === '' ? '1' : $quantityLabel);
        }

        return implode(' | ', $labels);
    }

    protected function getOrdersContainingProduct(Mercato $commerce, Page $product, int $limit = 25) {
        $orders = $this->getOrders($commerce, 1000);
        $matches = new PageArray();
        foreach ($orders as $order) {
            if ($this->getOrderProductQuantity($order, $product) <= 0) {
                continue;
            }
            $matches->add($order);
            if ($matches->count() >= $limit) {
                break;
            }
        }
        return $matches;
    }

    protected function getOrderProductQuantity(Page $order, Page $product): int {
        $items = json_decode((string) $order->mrc_items, true);
        if (!is_array($items)) {
            return 0;
        }
        $quantity = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);
            if ($productId !== (int) $product->id) {
                continue;
            }
            $quantity += (int) ceil((float) ($item['quantity'] ?? 1));
        }
        return $quantity;
    }

    protected function getProductOrderMetrics($orders, Page $product, Mercato $commerce): array {
        $quantity = 0;
        $revenue = 0.0;
        foreach ($orders as $order) {
            $items = json_decode((string) $order->mrc_items, true);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);
                if ($productId !== (int) $product->id) {
                    continue;
                }
                $lineQuantity = (float) ($item['quantity'] ?? 1);
                $quantity += (int) ceil($lineQuantity);
                $linePrice = (float) ($item['price'] ?? $item['unit_price'] ?? $product->mrc_price ?? 0);
                $revenue += $linePrice * $lineQuantity;
            }
        }
        return [
            'orders' => $orders->count(),
            'quantity' => $quantity,
            'revenue' => round($revenue, 2),
        ];
    }

    protected function getOrderGatewayLabel(Page $order): string {
        $method = (string) ($order->mrc_payment_method ?: '');
        if ($method === '') {
            return '-';
        }

        $options = Mercato::getPaymentMethodOptions();
        return (string) ($options[$method] ?? ucfirst(str_replace(['-', '_'], ' ', $method)));
    }

    protected function getGatewayChecklistLabel(string $gateway): string {
        return match (strtolower(trim($gateway))) {
            'stripe', 'stripe-card' => 'Stripe',
            'mollie' => 'Mollie',
            'paypal' => 'PayPal',
            'demo' => 'Demo',
            default => ucfirst(str_replace(['-', '_'], ' ', trim($gateway) ?: 'gateway')),
        };
    }

    protected function getOrderPolicyAcceptanceSummary(Page $order): string {
        if (!$order->hasField('mrc_policy_accepted')) {
            return '-';
        }
        if ((int) $order->mrc_policy_accepted !== 1) {
            return $this->_('Not recorded');
        }

        $details = $order->hasField('mrc_policy_acceptance_details')
            ? json_decode((string) $order->mrc_policy_acceptance_details, true)
            : null;
        if (!is_array($details)) {
            return $this->_('Accepted');
        }

        $acceptedAt = (string) ($details['accepted_at'] ?? '');
        $pages = array_filter(array_map(
            static fn(array $page): string => (string) ($page['title'] ?? $page['path'] ?? ''),
            array_filter((array) ($details['pages'] ?? []), 'is_array')
        ));
        $parts = [$this->_('Accepted')];
        if ($acceptedAt !== '') {
            $parts[] = $acceptedAt;
        }
        if ($pages) {
            $parts[] = implode(', ', $pages);
        }
        return implode(' / ', $parts);
    }

    protected function getOrderAddressSummary(Page $order, string $fieldName): string {
        if (!$order->hasField($fieldName)) {
            return '-';
        }
        $decoded = json_decode((string) $order->get($fieldName), true);
        if (!is_array($decoded)) {
            return '-';
        }

        $type = (string) ($decoded['type'] ?? '');
        if ($type === 'pickup') {
            return trim(implode(' / ', array_filter([
                (string) ($decoded['fulfilment_label'] ?? $this->_('Store pickup')),
                (string) ($decoded['pickup_code'] ?? ''),
                (string) ($decoded['pickup_address'] ?? ''),
                (string) ($decoded['pickup_instructions'] ?? ''),
            ])));
        }

        return trim(implode(', ', array_filter([
            trim((string) ($decoded['first_name'] ?? '') . ' ' . (string) ($decoded['last_name'] ?? '')),
            (string) ($decoded['company'] ?? ''),
            trim((string) ($decoded['tax_number'] ?? '')) !== '' ? 'Tax/VAT: ' . trim((string) $decoded['tax_number']) : '',
            trim((string) ($decoded['purchase_order_number'] ?? '')) !== '' ? 'PO: ' . trim((string) $decoded['purchase_order_number']) : '',
            (string) ($decoded['address'] ?? ''),
            (string) ($decoded['city'] ?? ''),
            (string) ($decoded['zip'] ?? ''),
            (string) ($decoded['country'] ?? ''),
            (string) ($decoded['delivery_window'] ?? ''),
            (string) ($decoded['delivery_note'] ?? ''),
        ])));
    }

    protected function getOrderGatewayKey(Page $order): string {
        $method = strtolower(trim((string) ($order->mrc_payment_method ?: '')));
        if (str_starts_with($method, 'stripe')) return 'stripe';
        if (str_starts_with($method, 'mollie')) return 'mollie';
        if ($method === 'demo') return 'demo';
        return $method !== '' ? $method : 'unknown';
    }

    protected function formatAgeMinutes(int $minutes): string {
        if ($minutes >= 1440) {
            return sprintf($this->_('%d day(s)'), (int) floor($minutes / 1440));
        }
        if ($minutes >= 60) {
            return sprintf($this->_('%d hour(s)'), (int) floor($minutes / 60));
        }
        return sprintf($this->_('%d minute(s)'), max(0, $minutes));
    }

    protected function getOrderFulfilmentMethod(Page $order): string {
        $method = $order->hasField('mrc_fulfilment_method') ? trim((string) $order->mrc_fulfilment_method) : '';
        return MercatoFulfilmentMethodType::isValid($method)
            ? $method
            : MercatoFulfilmentMethodType::CARRIER_DELIVERY;
    }

    protected function getFulfilmentStatusesForMethod(string $method): array {
        return match ($method) {
            MercatoFulfilmentMethodType::STORE_PICKUP => [
                MercatoFulfilmentStatus::UNFULFILLED,
                MercatoFulfilmentStatus::READY_FOR_PICKUP,
                MercatoFulfilmentStatus::COLLECTED,
                MercatoFulfilmentStatus::RETURNED,
            ],
            MercatoFulfilmentMethodType::LOCAL_DELIVERY => [
                MercatoFulfilmentStatus::UNFULFILLED,
                MercatoFulfilmentStatus::OUT_FOR_DELIVERY,
                MercatoFulfilmentStatus::DELIVERED,
                MercatoFulfilmentStatus::RETURNED,
            ],
            default => [
                MercatoFulfilmentStatus::UNFULFILLED,
                MercatoFulfilmentStatus::PARTIALLY_FULFILLED,
                MercatoFulfilmentStatus::FULFILLED,
                MercatoFulfilmentStatus::SHIPPED,
                MercatoFulfilmentStatus::DELIVERED,
                MercatoFulfilmentStatus::RETURNED,
            ],
        };
    }

    protected function fulfilmentMethodSupportsTracking(string $method): bool {
        return $method === MercatoFulfilmentMethodType::CARRIER_DELIVERY;
    }

    protected function getOrderFulfilmentMethodLabel(Page $order): string {
        if ($order->hasField('mrc_fulfilment_label') && trim((string) $order->mrc_fulfilment_label) !== '') {
            return (string) $order->mrc_fulfilment_label;
        }
        $method = $this->getOrderFulfilmentMethod($order);
        return (string) (Mercato::getFulfilmentMethodOptions()[$method] ?? $this->_('Delivery'));
    }

    protected function getOrderFulfilmentMethodDetails(Page $order): string {
        if (!$order->hasField('mrc_fulfilment_details')) {
            return '';
        }
        $decoded = json_decode((string) $order->mrc_fulfilment_details, true);
        return is_array($decoded) ? trim((string) ($decoded['details'] ?? '')) : '';
    }

    protected function getOrderReceiptDetailsSummary(Page $order, Mercato $commerce): string {
        $details = '';
        $capturedAt = '';
        $currency = '';
        $taxLines = [];
        if ($order->hasField('mrc_receipt_details')) {
            $decoded = json_decode((string) $order->mrc_receipt_details, true);
            if (is_array($decoded)) {
                $details = trim((string) ($decoded['merchant_legal_details'] ?? ''));
                $capturedAt = trim((string) ($decoded['captured_at'] ?? ''));
                $currency = trim((string) ($decoded['currency'] ?? ''));
                foreach ((array) ($decoded['tax_breakdown'] ?? []) as $rate) {
                    if (!is_array($rate)) continue;
                    $taxRate = (float) ($rate['tax_rate'] ?? $rate['taxRate'] ?? 0);
                    $sum = round((float) ($rate['sum'] ?? 0), 2);
                    if ($taxRate <= 0 || $sum < 0) continue;
                    $taxLines[] = sprintf('%s %s%%: %s', $commerce->getTaxLabel($order), rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.'), $commerce->formatPrice($sum));
                }
            }
        }
        if ($details === '') {
            $details = $commerce->getMerchantLegalDetailsText();
        }
        return trim(implode("\n", array_filter([
            $details,
            $capturedAt !== '' ? $this->_('Captured') . ': ' . $capturedAt : '',
            $currency !== '' ? $this->_('Currency') . ': ' . $currency : '',
            ...$taxLines,
        ])));
    }

    protected function getProductCollectionLabel(Page $product): string {
        if (!$product->hasField('mrc_collections') || !($product->mrc_collections instanceof PageArray) || !count($product->mrc_collections)) {
            return '-';
        }

        $labels = [];
        foreach ($product->mrc_collections as $collection) {
            if ($collection instanceof Page && $collection->id) {
                $labels[] = (string) $collection->title;
            }
        }
        return $labels ? implode(', ', $labels) : '-';
    }

    protected function getOrderTotal(Page $order, Mercato $commerce): float {
        $hasSnapshot = $order->hasField('mrc_fulfilment_method') && trim((string) $order->mrc_fulfilment_method) !== '';
        if ($order->hasField('mrc_total_amount') && ((float) $order->mrc_total_amount > 0 || $hasSnapshot)) {
            return round((float) $order->mrc_total_amount, 2);
        }

        $items = json_decode((string) $order->mrc_items, true);
        if (!is_array($items)) {
            return 0.0;
        }

        try {
            return $commerce->productList($items)->getSum();
        } catch (\Throwable $e) {
            $sum = 0.0;
            foreach ($items as $item) {
                $sum += (float) ($item['sum'] ?? ((float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 1)));
            }
            return round($sum, 2);
        }
    }
}
