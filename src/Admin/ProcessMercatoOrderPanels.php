<?php
namespace ProcessWire;

trait ProcessMercatoOrderPanels {

    protected function renderRevenueChart($orders, Mercato $commerce): string {
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $timestamp = strtotime('-' . $i . ' days');
            $key = date('Y-m-d', $timestamp);
            $days[$key] = [
                'label' => date('M j', $timestamp),
                'orders' => 0,
                'revenue' => 0.0,
            ];
        }

        foreach ($orders as $order) {
            $key = date('Y-m-d', (int) $order->created);
            if (!isset($days[$key])) {
                continue;
            }

            $status = (string) ($order->mrc_payment_status ?: '');
            $isPaid = (int) $order->mrc_payment_complete === 1 || $status === Mercato::PAYMENT_STATUS_PAID;
            $days[$key]['orders']++;
            if ($isPaid) {
                $days[$key]['revenue'] += $this->getOrderTotal($order, $commerce);
            }
        }

        $maxRevenue = 0.0;
        foreach ($days as $day) {
            $maxRevenue = max($maxRevenue, (float) $day['revenue']);
        }

        $out = '<section class="pw-wrap mrc-admin-panel mrc-chart-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Revenue Overview')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Paid revenue and order activity for the last 7 days.')) . '</p>';
        $out .= '</div></div>';
        $out .= '<div class="mrc-revenue-chart" aria-label="' . $this->e($this->_('Revenue chart')) . '">';

        foreach ($days as $day) {
            $height = $maxRevenue > 0 ? max(8, (int) round(((float) $day['revenue'] / $maxRevenue) * 100)) : 0;
            $barClass = $height > 0 ? 'mrc-chart-bar' : 'mrc-chart-bar is-empty';
            $out .= '<div class="mrc-chart-day">';
            $out .= '<div class="mrc-chart-track"><span class="' . $barClass . '" style="height:' . $height . '%"></span></div>';
            $out .= '<strong>' . $this->e($commerce->formatPrice((float) $day['revenue'])) . '</strong>';
            $out .= '<small>' . $this->e((string) $day['orders']) . ' ' . $this->e($this->_('orders')) . '</small>';
            $out .= '<span>' . $this->e((string) $day['label']) . '</span>';
            $out .= '</div>';
        }

        $out .= '</div></section>';
        return $out;
    }

    protected function renderOrders($orders, Mercato $commerce, string $activeStatus = 'all'): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Orders')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Orders created by checkout, including pending and failed payment attempts.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-primary" href="' . $this->e($this->adminUrl('manual-order/')) . '"><i class="fa fa-plus uk-margin-small-right"></i>' . $this->e($this->_('Manual Order')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('orders', ['status' => $activeStatus])) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('order-items', ['status' => $activeStatus])) . '"><i class="fa fa-table uk-margin-small-right"></i>' . $this->e($this->_('Export line items')) . '</a>';
        $out .= $this->renderOrderFilters($activeStatus);
        $out .= '</div>';
        $out .= '</div>';
        $out .= $this->renderOrderStatusSummary($orders, $commerce);
        $out .= $this->renderOrdersTable($orders, $commerce, true);
        $out .= '</section>';

        return $out;
    }

    protected function renderOrderStatusSummary($orders, Mercato $commerce): string {
        $counts = [
            'all' => 0,
            'paid' => 0,
            'pending' => 0,
            'processing' => 0,
            'failed' => 0,
            'canceled' => 0,
        ];
        $openValue = 0.0;

        foreach ($orders as $order) {
            $counts['all']++;
            $state = $this->getOrderPaymentState($order);
            $bucket = $this->getPaymentStatusBucketFromState($state);
            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            }
            if (in_array($bucket, ['pending', 'processing'], true)) {
                $openValue += $this->getOrderTotal($order, $commerce);
            }
        }

        $cards = [
            [$this->_('All'), (string) $counts['all'], $this->_('Current query'), 'is-neutral'],
            [$this->_('Paid'), (string) $counts['paid'], $this->_('Payment complete'), 'is-paid'],
            [$this->_('Pending'), (string) $counts['pending'], $this->_('Awaiting payment'), 'is-pending'],
            [$this->_('Processing'), (string) $counts['processing'], $this->_('Awaiting gateway'), 'is-pending'],
            [$this->_('Failed'), (string) $counts['failed'], $this->_('Failed or expired'), 'is-failed'],
            [$this->_('Canceled'), (string) $counts['canceled'], $this->_('Merchant/customer canceled'), 'is-failed'],
            [$this->_('Open value'), $commerce->formatPrice($openValue), $this->_('Pending + processing'), 'is-neutral'],
        ];

        $out = '<div class="mrc-webhook-summary mrc-order-status-summary">';
        foreach ($cards as [$label, $value, $caption, $class]) {
            $out .= '<div class="mrc-webhook-summary-card ' . $class . '"><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong><small>' . $this->e($caption) . '</small></div>';
        }
        $out .= '</div>';
        return $out;
    }

    protected function renderManualOrder($products, Mercato $commerce, array $result = [], array $formValues = []): string {
        $values = $formValues ?: (array) ($result['values'] ?? $this->getManualOrderFormValues($commerce));
        if (empty($values['items']) || !is_array($values['items'])) {
            $values['items'] = [];
        }
        for ($i = 0; $i < 5; $i++) {
            if (!isset($values['items'][$i]) || !is_array($values['items'][$i])) {
                $values['items'][$i] = ['product_id' => 0, 'quantity' => 0];
            }
        }
        if ((int) ($values['items'][0]['product_id'] ?? 0) <= 0 && $products->count()) {
            $values['items'][0]['product_id'] = (int) $products->first()->id;
            $values['items'][0]['quantity'] = (int) ($values['items'][0]['quantity'] ?? 0) > 0 ? (int) $values['items'][0]['quantity'] : 1;
        }

        $paymentOptions = Mercato::getPaymentMethodOptions();
        $fulfilmentOptions = Mercato::getFulfilmentMethodOptions();
        $enabledPayments = $commerce->getEnabledPaymentMethods();
        $enabledFulfilment = $commerce->getEnabledFulfilmentMethods();
        $customerOptions = $this->getManualOrderCustomers($commerce, $values);

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Create Manual Order')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Create an unpaid order, reserve stock, and send a secure checkout link for payment.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('orders/')) . '"><i class="fa fa-list uk-margin-small-right"></i>' . $this->e($this->_('Orders')) . '</a></div>';
        $out .= '</div>';

        if ($result) {
            $class = !empty($result['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '">';
            $out .= '<p><strong>' . $this->e((string) ($result['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($result['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            if (!empty($result['preview']) && is_array($result['preview'])) {
                $preview = (array) $result['preview'];
                $out .= '<div class="mrc-manual-preview">';
                $out .= '<div><span>' . $this->e($this->_('Subtotal')) . '</span><strong>' . $this->e($commerce->formatPrice((float) ($preview['subtotal'] ?? 0))) . '</strong></div>';
                $out .= '<div><span>' . $this->e($this->_('Shipping')) . '</span><strong>' . $this->e($commerce->formatPrice((float) ($preview['shipping'] ?? 0))) . '</strong></div>';
                $out .= '<div><span>' . $this->e($this->_('Discount')) . '</span><strong>' . $this->e($commerce->formatPrice((float) ($preview['discount_amount'] ?? 0))) . '</strong></div>';
                $out .= '<div class="mrc-manual-preview-total"><span>' . $this->e($this->_('Total')) . '</span><strong>' . $this->e($commerce->formatPrice((float) ($preview['total'] ?? 0))) . '</strong></div>';
                $out .= '</div>';
                $fulfilment = (array) ($preview['fulfilment'] ?? []);
                if (!empty($fulfilment['label'])) {
                    $out .= '<p class="uk-text-muted">' . $this->e(sprintf($this->_('Fulfilment: %s'), (string) $fulfilment['label'])) . '</p>';
                }
            }
            if (!empty($result['order']) && $result['order'] instanceof Page && !empty($result['payment_link'])) {
                $order = $result['order'];
                if (!empty($result['payment_link_email'])) {
                    $email = (array) $result['payment_link_email'];
                    $emailClass = ($email['status'] ?? '') === 'sent' ? 'uk-alert-success' : 'uk-alert-warning';
                    $out .= '<div class="uk-alert ' . $emailClass . '"><p><strong>' . $this->e($this->_('Payment link email')) . ':</strong> ' . $this->e((string) ($email['message'] ?? '')) . '</p></div>';
                }
                $out .= '<div class="mrc-payment-link-box">';
                $out .= '<input class="uk-input" id="mrc-manual-payment-link-' . (int) $order->id . '" type="text" readonly value="' . $this->e((string) $result['payment_link']) . '">';
                $out .= '<button class="uk-button uk-button-default mrc-copy-payment-link" type="button" data-copy-target="mrc-manual-payment-link-' . (int) $order->id . '"><i class="fa fa-copy uk-margin-small-right"></i>' . $this->e($this->_('Copy link')) . '</button>';
                $out .= '<a class="uk-button uk-button-primary" href="' . $this->e((string) $result['payment_link']) . '" target="_blank" rel="noopener"><i class="fa fa-credit-card uk-margin-small-right"></i>' . $this->e($this->_('Open payment link')) . '</a>';
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->orderDetailUrl($order)) . '"><i class="fa fa-file-text-o uk-margin-small-right"></i>' . $this->e($this->_('Order detail')) . '</a>';
                $out .= '</div>';
            }
            $out .= '</div>';
        }

        if (!$products->count()) {
            $out .= '<p class="uk-alert uk-alert-warning">' . $this->e($this->_('No products exist yet. Create or import products before creating manual orders.')) . '</p>';
            return $out . '</section>';
        }

        if ((int) ($values['source_order_id'] ?? 0) > 0) {
            $sourceLabel = (string) (($values['source_order_label'] ?? '') ?: ('#' . (int) $values['source_order_id']));
            $out .= '<p class="uk-alert uk-alert-primary mrc-admin-inline-alert">' . $this->e(sprintf($this->_('Prefilled from order %s. Review stock, fulfilment, discounts, and customer details before creating the new order.'), $sourceLabel)) . '</p>';
        }

        $out .= '<form method="post" action="' . $this->e($this->adminUrl('manual-order/')) . '" class="mrc-manual-order-form">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_create_manual_order" value="1">';
        $out .= '<div class="mrc-form-grid">';
        $out .= '<div class="mrc-field mrc-field-full"><span>' . $this->e($this->_('Customer lookup')) . '</span>';
        $out .= '<div class="mrc-manual-customer-lookup">';
        $out .= '<input class="uk-input" type="search" name="customer_search" value="' . $this->e((string) ($values['customer_search'] ?? '')) . '" placeholder="' . $this->e($this->_('Name, email, phone, city...')) . '" aria-label="' . $this->e($this->_('Customer search')) . '">';
        $out .= '<select class="uk-select" name="customer_key" aria-label="' . $this->e($this->_('Customer')) . '">';
        $out .= '<option value="">' . $this->e($customerOptions ? $this->_('Select previous customer') : $this->_('No previous customers')) . '</option>';
        foreach ($customerOptions as $customer) {
            $key = (string) ($customer['key'] ?? '');
            $selected = $key !== '' && $key === (string) ($values['customer_key'] ?? '') ? ' selected' : '';
            $labelParts = [
                (string) (($customer['name'] ?? '') ?: ($customer['email'] ?? '')),
                (string) ($customer['email'] ?? ''),
                sprintf($this->_('%d orders'), (int) ($customer['orders'] ?? 0)),
            ];
            $out .= '<option value="' . $this->e($key) . '"' . $selected . '>' . $this->e(implode(' · ', array_filter($labelParts))) . '</option>';
        }
        $out .= '</select>';
        $out .= '<button class="uk-button uk-button-default" type="submit" name="mrc_manual_order_action" value="apply_customer"><i class="fa fa-user uk-margin-small-right"></i>' . $this->e($this->_('Use customer')) . '</button>';
        $out .= '</div>';
        $out .= '<small class="mrc-field-help">' . $this->e($this->_('Loads customer contact and address fields from the most recent order for that email.')) . '</small>';
        $out .= '</div>';
        $out .= '<label class="mrc-field mrc-field-full mrc-manual-product-search"><span>' . $this->e($this->_('Find products by title or SKU')) . '</span>';
        $out .= '<input class="uk-input" type="search" name="product_search" value="' . $this->e((string) ($values['product_search'] ?? '')) . '" placeholder="' . $this->e($this->_('Mug, SKU, plate...')) . '">';
        $out .= '<small class="mrc-field-help">' . $this->e($this->_('The product dropdowns below are filtered by this term. Selected products stay available after filtering.')) . '</small>';
        $out .= '</label>';
        $out .= '<div class="mrc-field mrc-field-full"><span>' . $this->e($this->_('Order items')) . '</span>';
        $out .= '<div class="mrc-manual-lines">';
        for ($i = 0; $i < 5; $i++) {
            $line = (array) ($values['items'][$i] ?? []);
            $out .= '<div class="mrc-manual-line">';
            $out .= '<select class="uk-select" name="product_id_' . $i . '" aria-label="' . $this->e(sprintf($this->_('Product line %d'), $i + 1)) . '">';
            $out .= '<option value="0">' . $this->e($i === 0 ? $this->_('Select product') : $this->_('Optional product')) . '</option>';
            foreach ($products as $product) {
                $selected = (int) ($line['product_id'] ?? 0) === (int) $product->id ? ' selected' : '';
                $label = (string) $product->title . ' · ' . $commerce->formatPrice((float) $product->mrc_price);
                if (trim((string) $product->mrc_sku) !== '') {
                    $label .= ' · ' . (string) $product->mrc_sku;
                }
                if ($product->hasField('mrc_stock')) {
                    $label .= ' · ' . sprintf($this->_('%d in stock'), (int) $product->mrc_stock);
                }
                $out .= '<option value="' . (int) $product->id . '"' . $selected . '>' . $this->e($label) . '</option>';
            }
            $quantity = (int) ($line['quantity'] ?? 0);
            $out .= '</select>';
            $out .= '<input class="uk-input" type="number" min="0" step="1" name="quantity_' . $i . '" value="' . $this->e((string) $quantity) . '" aria-label="' . $this->e(sprintf($this->_('Quantity line %d'), $i + 1)) . '">';
            $out .= '</div>';
        }
        $out .= '</div></div>';
        $out .= '<div class="mrc-field mrc-field-full"><span>' . $this->e($this->_('Custom line item')) . '</span>';
        $out .= '<div class="mrc-custom-line">';
        $out .= '<input class="uk-input" type="text" name="custom_title" value="' . $this->e((string) ($values['custom_title'] ?? '')) . '" placeholder="' . $this->e($this->_('Service, setup fee, adjustment')) . '" aria-label="' . $this->e($this->_('Custom line title')) . '">';
        $out .= '<input class="uk-input" type="number" min="0" step="0.01" name="custom_price" value="' . $this->e((string) ($values['custom_price'] ?? '')) . '" placeholder="' . $this->e($this->_('Unit price')) . '" aria-label="' . $this->e($this->_('Custom line unit price')) . '">';
        $out .= '<input class="uk-input" type="number" min="0" step="1" name="custom_quantity" value="' . $this->e((string) ($values['custom_quantity'] ?? 0)) . '" placeholder="' . $this->e($this->_('Qty')) . '" aria-label="' . $this->e($this->_('Custom line quantity')) . '">';
        $out .= '<input class="uk-input" type="number" min="0" step="0.01" name="custom_tax_rate" value="' . $this->e((string) ($values['custom_tax_rate'] ?? 0)) . '" placeholder="' . $this->e($this->_('Tax %')) . '" aria-label="' . $this->e($this->_('Custom line tax rate')) . '">';
        $out .= '</div></div>';
        $out .= '<label class="mrc-field"><span>' . $this->e($this->_('Discount code')) . '</span><input class="uk-input" type="text" name="discount_code" value="' . $this->e((string) ($values['discount_code'] ?? '')) . '" placeholder="' . $this->e($this->_('Optional')) . '"></label>';
        $out .= '<label class="mrc-field"><span>' . $this->e($this->_('Payment method')) . '</span><select class="uk-select" name="payment_method">';
        foreach ($enabledPayments as $method) {
            $selected = (string) ($values['payment_method'] ?? '') === $method ? ' selected' : '';
            $out .= '<option value="' . $this->e($method) . '"' . $selected . '>' . $this->e((string) ($paymentOptions[$method] ?? $method)) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label class="mrc-field"><span>' . $this->e($this->_('Fulfilment')) . '</span><select class="uk-select" name="fulfilment_method">';
        foreach ($enabledFulfilment as $method) {
            $selected = (string) ($values['fulfilment_method'] ?? '') === $method ? ' selected' : '';
            $out .= '<option value="' . $this->e($method) . '"' . $selected . '>' . $this->e((string) ($fulfilmentOptions[$method] ?? $method)) . '</option>';
        }
        $out .= '</select></label>';
        foreach ([
            'first_name' => $this->_('First name'),
            'last_name' => $this->_('Last name'),
            'email' => $this->_('Email'),
            'phone' => $this->_('Phone'),
            'address' => $this->_('Address'),
            'city' => $this->_('City'),
            'zip' => $this->_('Postal code'),
            'country' => $this->_('Country'),
        ] as $name => $label) {
            $type = $name === 'email' ? 'email' : 'text';
            $out .= '<label class="mrc-field"><span>' . $this->e($label) . '</span><input class="uk-input" type="' . $type . '" name="' . $this->e($name) . '" value="' . $this->e((string) ($values[$name] ?? '')) . '"></label>';
        }
        $out .= '<label class="mrc-field mrc-field-wide"><span>' . $this->e($this->_('Internal note')) . '</span><textarea class="uk-textarea" name="notes" rows="3">' . $this->e((string) ($values['notes'] ?? '')) . '</textarea></label>';
        $checked = !empty($values['email_payment_link']) ? ' checked' : '';
        $out .= '<label class="mrc-field mrc-field-wide mrc-checkbox-field"><input type="checkbox" name="email_payment_link" value="1"' . $checked . '> ' . $this->e($this->_('Email payment link after creation')) . '</label>';
        $out .= '</div>';
        $out .= '<div class="mrc-form-actions">';
        $out .= '<button class="uk-button uk-button-default" type="submit" name="mrc_manual_order_action" value="preview"><i class="fa fa-calculator uk-margin-small-right"></i>' . $this->e($this->_('Preview total')) . '</button>';
        $out .= '<button class="uk-button uk-button-primary" type="submit" name="mrc_manual_order_action" value="create"><i class="fa fa-plus uk-margin-small-right"></i>' . $this->e($this->_('Create unpaid order')) . '</button>';
        $out .= '</div>';
        $out .= '</form>';
        $out .= '</section>';

        return $out;
    }

    protected function renderOrderFilters(string $activeStatus): string {
        $filters = [
            'all' => $this->_('All'),
            'paid' => $this->_('Paid'),
            'pending' => $this->_('Pending'),
            'processing' => $this->_('Processing'),
            'failed' => $this->_('Failed'),
            'canceled' => $this->_('Canceled'),
        ];

        $out = '<div class="mrc-order-filters">';
        foreach ($filters as $status => $label) {
            $class = $status === $activeStatus ? 'uk-button uk-button-primary' : 'uk-button uk-button-default';
            $url = $this->adminUrl('orders/') . ($status === 'all' ? '' : '?status=' . rawurlencode($status));
            $out .= '<a class="' . $class . '" href="' . $this->e($url) . '">' . $this->e($label) . '</a>';
        }
        $out .= '</div>';
        return $out;
    }

    protected function getCustomerExportQuery(array $filters): array {
        $query = [];
        $segment = (string) ($filters['segment'] ?? 'all');
        $search = trim((string) ($filters['q'] ?? ''));
        if ($segment !== 'all') {
            $query['segment'] = $segment;
        }
        if ($search !== '') {
            $query['q'] = $search;
        }
        return $query;
    }

    protected function renderCustomerFilters(array $filters): string {
        $activeSegment = (string) ($filters['segment'] ?? 'all');
        $search = trim((string) ($filters['q'] ?? ''));
        $filters = [
            'all' => $this->_('All'),
            'new' => $this->_('New'),
            'repeat' => $this->_('Repeat'),
            'vip' => $this->_('VIP'),
            'needs_attention' => $this->_('Needs attention'),
        ];

        $out = '<div class="mrc-order-filters mrc-customer-segment-filters">';
        foreach ($filters as $segment => $label) {
            $class = $segment === $activeSegment ? 'uk-button uk-button-primary' : 'uk-button uk-button-default';
            $query = $segment === 'all' ? [] : ['segment' => $segment];
            if ($search !== '') {
                $query['q'] = $search;
            }
            $url = $this->adminUrl('customers/') . ($query ? '?' . http_build_query($query) : '');
            $out .= '<a class="' . $class . '" href="' . $this->e($url) . '">' . $this->e($label) . '</a>';
        }
        $out .= '<form method="get" class="mrc-customer-search">';
        if ($activeSegment !== 'all') {
            $out .= '<input type="hidden" name="segment" value="' . $this->e($activeSegment) . '">';
        }
        $out .= '<input type="search" name="q" value="' . $this->e($search) . '" placeholder="' . $this->e($this->_('Search customers')) . '">';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-search uk-margin-small-right"></i>' . $this->e($this->_('Search')) . '</button>';
        if ($search !== '') {
            $resetQuery = $activeSegment === 'all' ? [] : ['segment' => $activeSegment];
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('customers/') . ($resetQuery ? '?' . http_build_query($resetQuery) : '')) . '">' . $this->e($this->_('Clear')) . '</a>';
        }
        $out .= '</form>';
        $out .= '</div>';
        return $out;
    }

    protected function renderRecentOrders($orders, Mercato $commerce): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div><h2 class="uk-h3">' . $this->e($this->_('Recent Orders')) . '</h2></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('orders/')) . '"><i class="fa fa-table uk-margin-small-right"></i>' . $this->e($this->_('View all orders')) . '</a></div>';
        $out .= '</div>';

        return $out . $this->renderOrdersTable($orders, $commerce, false) . '</section>';
    }

    protected function renderFulfilment($orders, Mercato $commerce, array $actionResult = [], array $notificationResult = [], string $activeMethod = 'all', string $activeQueue = 'all', array $queueSummary = []): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Fulfilment Queue')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Paid orders requiring fulfilment work until delivered or returned.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions">';
        $exportQuery = $activeMethod === 'all' ? [] : ['method' => $activeMethod];
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('fulfilment', $exportQuery)) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export activity')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('fulfilment-queue', $this->getFulfilmentQueueExportQuery($activeMethod, $activeQueue))) . '"><i class="fa fa-table uk-margin-small-right"></i>' . $this->e($this->_('Export queue')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('orders/')) . '"><i class="fa fa-list uk-margin-small-right"></i>' . $this->e($this->_('All orders')) . '</a>';
        $out .= $this->renderFulfilmentFilters($activeMethod, $activeQueue);
        $out .= '</div>';
        $out .= '</div>';
        $out .= $this->renderFulfilmentQueueSummary($queueSummary, $activeQueue, $activeMethod);
        $out .= $this->renderFulfilmentQueueFilters($activeQueue, $activeMethod);

        if ($actionResult) {
            $class = !empty($actionResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '">';
            $out .= '<p><strong>' . $this->e((string) ($actionResult['summary'] ?? '')) . '</strong></p>';
            if (!empty($actionResult['errors'])) {
                $out .= '<ul>';
                foreach ((array) $actionResult['errors'] as $error) {
                    $out .= '<li>' . $this->e((string) $error) . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '</div>';
        }
        if ($notificationResult) {
            $class = !empty($notificationResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($notificationResult['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($notificationResult['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }

        $headings = [$this->_('Invoice'), $this->_('Customer'), $this->_('Fulfilment'), $this->_('Items'), $this->_('Total'), $this->_('Created'), $this->_('Update')];
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ($headings as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$orders->count()) {
            $out .= $this->renderSkeletonRows(4, count($headings));
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No fulfilment exceptions yet.')) . '</p></section>';
        }

        foreach ($orders as $order) {
            $fulfilment = $this->getOrderFulfilmentState($order);
            $out .= '<tr>';
            $out .= '<td><strong>' . $this->e($order->mrc_invoice_number ?: $order->title) . '</strong></td>';
            $out .= '<td>' . $this->e($this->getOrderCustomer($order) ?: '-') . '<br><small>' . $this->e((string) $order->mrc_email) . '</small></td>';
            $out .= '<td><strong>' . $this->e($this->getOrderFulfilmentMethodLabel($order)) . '</strong><br><span class="uk-label mrc-admin-status ' . $fulfilment['class'] . '">' . $this->e($fulfilment['label']) . '</span><br><small>' . $this->e($fulfilment['detail']) . '</small></td>';
            $out .= '<td>' . $this->renderOrderFulfilmentItems($order) . '</td>';
            $out .= '<td>' . $this->e($commerce->formatPrice($this->getOrderTotal($order, $commerce))) . '</td>';
            $out .= '<td>' . $this->e(date('Y-m-d H:i', (int) $order->created)) . '</td>';
            $out .= '<td>' . $this->renderFulfilmentUpdateForm($order, $activeMethod, $activeQueue) . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderFulfilmentQueueSummary(array $summary, string $activeQueue = 'all', string $activeMethod = 'all'): string {
        $cards = [
            'all' => [$this->_('All work'), $this->_('Paid orders still visible in fulfilment.')],
            'active' => [$this->_('Active'), $this->_('Unfulfilled or in-progress fulfilment.')],
            'attention' => [$this->_('Attention'), $this->_('Inventory or operational exception.')],
            'backorder' => [$this->_('Backorder'), $this->_('Accepted orders waiting for stock.')],
            'preorder' => [$this->_('Preorder'), $this->_('Orders accepted before stock arrives.')],
        ];

        $out = '<div class="mrc-attention-grid mrc-fulfilment-summary">';
        foreach ($cards as $queue => [$label, $note]) {
            $query = [];
            if ($activeMethod !== 'all') {
                $query['method'] = $activeMethod;
            }
            if ($queue !== 'all') {
                $query['queue'] = $queue;
            }
            $url = $this->adminUrl('fulfilment/') . ($query ? '?' . http_build_query($query) : '');
            $classes = 'mrc-attention-card ' . ($queue === $activeQueue ? 'is-pending' : '');
            $out .= '<a class="' . $classes . '" href="' . $this->e($url) . '" data-queue="' . $this->e($queue) . '">';
            $out .= '<span>' . $this->e((string) $label) . '</span>';
            $out .= '<strong>' . $this->e((string) (int) ($summary[$queue] ?? 0)) . '</strong>';
            $out .= '<small>' . $this->e((string) $note) . '</small>';
            $out .= '</a>';
        }
        return $out . '</div>';
    }

    protected function renderFulfilmentFilters(string $activeMethod, string $activeQueue = 'all'): string {
        $filters = [
            'all' => $this->_('All methods'),
            MercatoFulfilmentMethodType::CARRIER_DELIVERY => $this->_('Delivery'),
            MercatoFulfilmentMethodType::STORE_PICKUP => $this->_('Pickup'),
            MercatoFulfilmentMethodType::LOCAL_DELIVERY => $this->_('Local delivery'),
        ];

        $out = '<div class="mrc-order-filters mrc-fulfilment-filters" aria-label="' . $this->e($this->_('Filter fulfilment by method')) . '">';
        foreach ($filters as $method => $label) {
            $class = $method === $activeMethod ? 'uk-button uk-button-primary' : 'uk-button uk-button-default';
            $query = [];
            if ($method !== 'all') {
                $query['method'] = $method;
            }
            if ($activeQueue !== 'all') {
                $query['queue'] = $activeQueue;
            }
            $url = $this->adminUrl('fulfilment/') . ($query ? '?' . http_build_query($query) : '');
            $out .= '<a class="' . $class . '" href="' . $this->e($url) . '">' . $this->e($label) . '</a>';
        }
        return $out . '</div>';
    }

    protected function renderFulfilmentQueueFilters(string $activeQueue, string $activeMethod = 'all'): string {
        $filters = [
            'all' => $this->_('All fulfilment work'),
            'active' => $this->_('Active'),
            'attention' => $this->_('Needs attention'),
            'backorder' => $this->_('Backorder'),
            'preorder' => $this->_('Preorder'),
        ];

        $out = '<div class="mrc-order-filters mrc-fulfilment-queue-filters" aria-label="' . $this->e($this->_('Filter fulfilment by work type')) . '">';
        foreach ($filters as $queue => $label) {
            $class = $queue === $activeQueue ? 'uk-button uk-button-primary' : 'uk-button uk-button-default';
            $query = [];
            if ($activeMethod !== 'all') {
                $query['method'] = $activeMethod;
            }
            if ($queue !== 'all') {
                $query['queue'] = $queue;
            }
            $url = $this->adminUrl('fulfilment/') . ($query ? '?' . http_build_query($query) : '');
            $out .= '<a class="' . $class . '" href="' . $this->e($url) . '">' . $this->e($label) . '</a>';
        }
        return $out . '</div>';
    }

    protected function renderFulfilmentEvents(array $events): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Fulfilment Activity')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Recent manual shipment, delivery, return, and tracking updates.')) . '</p>';
        $out .= '</div></div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Time'), $this->_('Order'), $this->_('Change'), $this->_('Tracking'), $this->_('Note'), $this->_('User')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$events) {
            $out .= $this->renderSkeletonRows(4, 6);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No fulfilment activity logged yet.')) . '</p></section>';
        }

        foreach ($events as $event) {
            $orderId = (int) ($event['order_id'] ?? 0);
            $label = (string) ($event['invoice'] ?? ($orderId > 0 ? '#' . $orderId : '-'));
            $orderHtml = $this->e($label);
            if ($orderId > 0) {
                $order = $this->wire('pages')->get($orderId);
                if ($order && $order->id) {
                    $orderHtml = '<a href="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->e($label) . '</a>';
                }
            }
            $from = $this->getFulfilmentStatusLabel((string) ($event['from'] ?? ''));
            $toRaw = (string) ($event['to'] ?? '');
            $to = $this->getFulfilmentStatusLabel($toRaw);
            $class = in_array($toRaw, [MercatoFulfilmentStatus::FULFILLED, MercatoFulfilmentStatus::SHIPPED, MercatoFulfilmentStatus::COLLECTED, MercatoFulfilmentStatus::DELIVERED], true)
                ? 'is-paid'
                : ($toRaw === MercatoFulfilmentStatus::RETURNED ? 'is-failed' : 'is-pending');
            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['_time'] ?? '-')) . '</td>';
            $out .= '<td>' . $orderHtml . '</td>';
            $out .= '<td>' . $this->e($from) . ' &rarr; <span class="uk-label mrc-admin-status ' . $class . '">' . $this->e($to) . '</span></td>';
            $tracking = (string) ($event['tracking'] ?? '-');
            $trackingUrl = (string) ($event['tracking_url'] ?? '');
            $trackingHtml = '<small>' . $this->e($tracking) . '</small>';
            if ($trackingUrl !== '') {
                $trackingHtml = '<a href="' . $this->e($trackingUrl) . '" target="_blank" rel="noopener noreferrer"><small>' . $this->e($tracking) . '</small></a>';
            }
            $out .= '<td>' . $trackingHtml . '</td>';
            $out .= '<td>' . $this->e((string) ($event['note'] ?? '')) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['user'] ?? '-')) . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderNotificationEvents(array $events, array $filters = [], string $activeFulfilmentMethod = 'all'): string {
        $eventFilter = (string) ($filters['event'] ?? 'all');
        $statusFilter = (string) ($filters['status'] ?? 'all');
        $eventOptions = [
            'all' => $this->_('All email types'),
            'order_confirmation_email' => $this->_('Order confirmation'),
            'shipping_email' => $this->_('Shipping'),
            'pickup_ready_email' => $this->_('Pickup ready'),
            'local_delivery_email' => $this->_('Local delivery'),
            'payment_link_email' => $this->_('Payment link'),
            'test_email' => $this->_('Test email'),
        ];
        $statusOptions = [
            'all' => $this->_('All statuses'),
            'sent' => $this->_('Sent'),
            'failed' => $this->_('Failed'),
        ];
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Customer Emails')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Recent confirmations and fulfilment milestone emails.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('notifications', $this->getNotificationExportQuery($filters))) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a></div></div>';
        $out .= '<form method="get" class="mrc-webhook-filters mrc-notification-filters">';
        if ($activeFulfilmentMethod !== 'all') {
            $out .= '<input type="hidden" name="method" value="' . $this->e($activeFulfilmentMethod) . '">';
        }
        $out .= '<label><span>' . $this->e($this->_('Type')) . '</span><select name="notification_event">';
        foreach ($eventOptions as $value => $label) {
            $out .= '<option value="' . $this->e((string) $value) . '"' . ($eventFilter === (string) $value ? ' selected' : '') . '>' . $this->e((string) $label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Status')) . '</span><select name="notification_status">';
        foreach ($statusOptions as $value => $label) {
            $out .= '<option value="' . $this->e((string) $value) . '"' . ($statusFilter === (string) $value ? ' selected' : '') . '>' . $this->e((string) $label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-filter uk-margin-small-right"></i>' . $this->e($this->_('Apply filters')) . '</button>';
        if ($eventFilter !== 'all' || $statusFilter !== 'all') {
            $resetUrl = $this->adminUrl('fulfilment/') . ($activeFulfilmentMethod !== 'all' ? '?method=' . rawurlencode($activeFulfilmentMethod) : '');
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($resetUrl) . '">' . $this->e($this->_('Reset')) . '</a>';
        }
        $out .= '</form>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Time'), $this->_('Order'), $this->_('Type'), $this->_('Email'), $this->_('Status'), $this->_('Discount'), $this->_('Message')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        if (!$events) {
            $out .= $this->renderSkeletonRows(3, 7);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No customer notifications logged yet.')) . '</p></section>';
        }
        foreach ($events as $event) {
            $orderId = (int) ($event['order_id'] ?? 0);
            $invoice = (string) ($event['invoice'] ?? ($orderId ? '#' . $orderId : '-'));
            $orderHtml = $this->e($invoice);
            if ($orderId > 0) {
                $order = $this->wire('pages')->get($orderId);
                if ($order && $order->id) {
                    $orderHtml = '<a href="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->e($invoice) . '</a>';
                }
            }
            $status = (string) ($event['status'] ?? 'failed');
            $class = $status === 'sent' ? 'is-paid' : 'is-failed';
            $out .= '<tr><td>' . $this->e((string) ($event['_time'] ?? '-')) . '</td>';
            $out .= '<td>' . $orderHtml . '</td>';
            $out .= '<td>' . $this->e((string) ($event['event'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['recipient'] ?? '-')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $class . '">' . $this->e($status) . '</span></td>';
            $out .= '<td>' . $this->e((string) (($event['recovery_discount_code'] ?? '') ?: '-')) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['message'] ?? '')) . '</td></tr>';
        }
        $out .= '</tbody></table></div></section>';
        return $out;
    }


}
