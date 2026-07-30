<?php
namespace ProcessWire;

trait ProcessMercatoExports {
    protected function getOrderExportRows(Mercato $commerce, string $status = 'all'): array {
        $orders = $this->getFilteredOrders($commerce, $status, 10000);
        return $this->getOrderExportRowsForOrders($orders, $commerce);
    }

    protected function getOrderItemExportRows(Mercato $commerce, string $status = 'all'): array {
        $orders = $this->getFilteredOrders($commerce, $status, 10000);
        $rows = [[
            'order_id', 'order_url', 'invoice', 'created', 'customer', 'email',
            'payment_status', 'fulfilment_status', 'currency',
            'line_index', 'product_id', 'product_url', 'title', 'sku', 'product_type',
            'quantity', 'unit_price', 'line_total', 'tax_rate', 'shipping_price',
            'stripe_price_id', 'item_json',
        ]];

        foreach ($orders as $order) {
            $items = json_decode((string) $order->mrc_items, true);
            $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
            foreach ($items as $index => $item) {
                $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);
                $productUrl = '';
                if ($productId > 0) {
                    $product = $this->wire('pages')->get($productId);
                    if ($product instanceof Page && $product->id) {
                        $productUrl = $this->productDetailUrl($product);
                    }
                }
                $quantity = (float) ($item['quantity'] ?? 1);
                $unit = (float) ($item['price'] ?? 0);
                $line = (float) ($item['sum'] ?? ($unit * $quantity));
                $rows[] = [
                    (int) $order->id,
                    $this->orderDetailUrl($order),
                    (string) ($order->mrc_invoice_number ?: $order->title),
                    date('Y-m-d H:i:s', (int) $order->created),
                    $this->getOrderCustomer($order),
                    (string) $order->mrc_email,
                    (string) ($order->mrc_payment_status ?: Mercato::PAYMENT_STATUS_PENDING),
                    $order->hasField('mrc_fulfilment_status') ? (string) $order->mrc_fulfilment_status : '',
                    (string) ($order->mrc_currency ?: $commerce->currency),
                    $index + 1,
                    $productId,
                    $productUrl,
                    (string) ($item['title'] ?? $item['name'] ?? ''),
                    (string) ($item['sku'] ?? ''),
                    (string) ($item['product_type'] ?? ''),
                    $this->formatCsvAmount($quantity),
                    $this->formatCsvAmount($unit),
                    $this->formatCsvAmount($line),
                    $this->formatCsvAmount((float) ($item['tax_rate'] ?? $item['taxRate'] ?? 0)),
                    $this->formatCsvAmount((float) ($item['shipping_price'] ?? $item['shippingPrice'] ?? 0)),
                    (string) ($item['stripe_price_id'] ?? ''),
                    json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
                ];
            }
        }

        return $rows;
    }

    protected function getCustomerOrderExportRows(Mercato $commerce, string $customerKey): array {
        $customer = $this->getCustomerByKey($commerce, $customerKey);
        if (!$customer) {
            return $this->getOrderExportRowsForOrders(new PageArray(), $commerce);
        }
        return $this->getOrderExportRowsForOrders($this->getCustomerOrders($commerce, $customer), $commerce);
    }

    protected function getOrderExportRowsForOrders($orders, Mercato $commerce): array {
        $rows = [[
            'id', 'order_url', 'invoice', 'created', 'customer', 'email', 'phone', 'country', 'purchase_order_number', 'billing_address_json', 'shipping_address_json', 'receipt_details_json',
            'payment_status', 'payment_complete', 'payment_method', 'gateway_id', 'stripe_customer_id', 'payment_failure_reason',
            'subscription_id', 'subscription_status', 'subscription_current_period_end', 'subscription_cancel_at_period_end', 'subscription_canceled_at', 'subscription_cancel_details_json', 'subscription_details_json', 'subscription_renewal_details_json',
            'inventory_reserved', 'inventory_reserved_until', 'inventory_adjusted', 'inventory_details',
            'fulfilment_method', 'fulfilment_label', 'fulfilment_details', 'shipping_calculation_mode', 'actual_weight_kg', 'volume_cm3', 'dimensional_weight_kg', 'billable_weight_kg', 'shipping_rate_band',
            'fulfilment_status', 'fulfilment_tracking', 'fulfilment_tracking_url', 'fulfilment_notes', 'fulfilled_date',
            'currency', 'subtotal', 'shipping', 'discount_code', 'discount_total',
            'total', 'items_count', 'items_json',
        ]];

        foreach ($orders as $order) {
            $gatewayId = $this->getOrderGatewayReference($order);
            $billingSnapshot = $order->hasField('mrc_billing_address') ? json_decode((string) $order->mrc_billing_address, true) : [];
            $billingSnapshot = is_array($billingSnapshot) ? $billingSnapshot : [];
            $fulfilmentSnapshot = $order->hasField('mrc_fulfilment_details') ? json_decode((string) $order->mrc_fulfilment_details, true) : [];
            $shippingCalculation = is_array($fulfilmentSnapshot['shipping_calculation'] ?? null) ? $fulfilmentSnapshot['shipping_calculation'] : [];
            $rows[] = [
                (int) $order->id,
                $this->orderDetailUrl($order),
                (string) ($order->mrc_invoice_number ?: $order->title),
                date('Y-m-d H:i:s', (int) $order->created),
                $this->getOrderCustomer($order),
                (string) $order->mrc_email,
                (string) $order->mrc_phone,
                (string) $order->mrc_country,
                (string) ($billingSnapshot['purchase_order_number'] ?? ''),
                $order->hasField('mrc_billing_address') ? (string) $order->mrc_billing_address : '',
                $order->hasField('mrc_shipping_address') ? (string) $order->mrc_shipping_address : '',
                $order->hasField('mrc_receipt_details') ? (string) $order->mrc_receipt_details : '',
                (string) ($order->mrc_payment_status ?: Mercato::PAYMENT_STATUS_PENDING),
                (int) $order->mrc_payment_complete,
                (string) $order->mrc_payment_method,
                $gatewayId,
                $order->hasField('mrc_stripe_customer_id') ? (string) $order->mrc_stripe_customer_id : '',
                $this->getOrderPaymentFailureSummary($order),
                $order->hasField('mrc_subscription_id') ? (string) $order->mrc_subscription_id : '',
                $order->hasField('mrc_subscription_status') ? $commerce->getOrderSubscriptionStatus($order) : Mercato::SUBSCRIPTION_STATUS_NONE,
                $order->hasField('mrc_subscription_current_period_end') ? (string) $order->mrc_subscription_current_period_end : '',
                $order->hasField('mrc_subscription_cancel_at_period_end') ? (int) $order->mrc_subscription_cancel_at_period_end : 0,
                $order->hasField('mrc_subscription_canceled_at') ? (string) $order->mrc_subscription_canceled_at : '',
                $order->hasField('mrc_subscription_cancel_details') ? (string) $order->mrc_subscription_cancel_details : '',
                $order->hasField('mrc_subscription_details') ? (string) $order->mrc_subscription_details : '',
                $order->hasField('mrc_subscription_renewal_details') ? (string) $order->mrc_subscription_renewal_details : '',
                $order->hasField('mrc_inventory_reserved') ? (int) $order->mrc_inventory_reserved : 0,
                $order->hasField('mrc_inventory_reserved_until') ? (string) $order->mrc_inventory_reserved_until : '',
                $order->hasField('mrc_inventory_adjusted') ? (int) $order->mrc_inventory_adjusted : 0,
                $order->hasField('mrc_inventory_details') ? (string) $order->mrc_inventory_details : '',
                $order->hasField('mrc_fulfilment_method') ? (string) $order->mrc_fulfilment_method : '',
                $order->hasField('mrc_fulfilment_label') ? (string) $order->mrc_fulfilment_label : '',
                $order->hasField('mrc_fulfilment_details') ? (string) $order->mrc_fulfilment_details : '',
                (string) ($shippingCalculation['mode'] ?? 'flat'),
                (string) ($shippingCalculation['actual_weight_kg'] ?? ''),
                (string) ($shippingCalculation['volume_cm3'] ?? ''),
                (string) ($shippingCalculation['dimensional_weight_kg'] ?? ''),
                (string) ($shippingCalculation['billable_weight_kg'] ?? ''),
                json_encode($shippingCalculation['rate_band'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
                $order->hasField('mrc_fulfilment_status') ? (string) $order->mrc_fulfilment_status : '',
                $order->hasField('mrc_fulfilment_tracking') ? (string) $order->mrc_fulfilment_tracking : '',
                $order->hasField('mrc_fulfilment_tracking_url') ? (string) $order->mrc_fulfilment_tracking_url : '',
                $order->hasField('mrc_fulfilment_notes') ? (string) $order->mrc_fulfilment_notes : '',
                $order->hasField('mrc_fulfilled_date') ? (string) $order->mrc_fulfilled_date : '',
                (string) ($order->mrc_currency ?: $commerce->currency),
                $this->formatCsvAmount((float) $order->mrc_subtotal_amount),
                $this->formatCsvAmount((float) $order->mrc_shipping_amount),
                (string) $order->mrc_discount_code,
                $this->formatCsvAmount((float) $order->mrc_discount_total),
                $this->formatCsvAmount($this->getOrderTotal($order, $commerce)),
                $this->getOrderItemCount($order),
                (string) $order->mrc_items,
            ];
        }

        return $rows;
    }

    protected function getProductExportRows(Mercato $commerce, array $filters = []): array {
        $products = $this->getProducts($commerce, 10000, $filters);
        $rows = [['id', 'product_url', 'title', 'name', 'url', 'sku', 'price', 'tax_rate', 'shipping_price', 'stock', 'low_stock_threshold', 'stock_policy', 'product_type', 'product_status', 'stripe_price_id', 'download_limit', 'download_expiry_days', 'digital_files_count', 'status', 'collections', 'image_urls', 'images_count', 'description']];

        foreach ($products as $product) {
            $imagesCount = $product->hasField('mrc_images') ? count($product->mrc_images) : 0;
            $imageUrls = [];
            if ($product->hasField('mrc_images')) {
                foreach ($product->mrc_images as $image) {
                    $imageUrls[] = $image->httpUrl();
                }
            }
            $rows[] = [
                (int) $product->id,
                $this->productDetailUrl($product),
                (string) $product->title,
                (string) $product->name,
                $product->httpUrl(),
                (string) $product->mrc_sku,
                $this->formatCsvAmount((float) $product->mrc_price),
                $this->formatCsvAmount((float) $product->mrc_tax_rate),
                $this->formatCsvAmount((float) $product->mrc_shipping_price),
                (int) $product->mrc_stock,
                $product->hasField('mrc_low_stock_threshold') ? (int) $product->mrc_low_stock_threshold : '',
                $this->getProductStockPolicy($product),
                $this->getProductType($product),
                $this->getProductLifecycleStatus($product),
                $product->hasField('mrc_stripe_price_id') ? (string) $product->mrc_stripe_price_id : '',
                $product->hasField('mrc_download_limit') ? (int) $product->mrc_download_limit : '',
                $product->hasField('mrc_download_expiry_days') ? (int) $product->mrc_download_expiry_days : '',
                $product->hasField('mrc_digital_files') ? count($product->mrc_digital_files) : '',
                $this->getProductPublicationStatus($product),
                $this->getProductCollectionLabel($product),
                implode('|', $imageUrls),
                $imagesCount,
                (string) $product->mrc_description,
            ];
        }

        return $rows;
    }

    protected function getStockPressureExportRows(Mercato $commerce): array {
        $products = $this->getProducts($commerce, 10000, ['sort' => 'stock_asc']);
        $rows = [['id', 'product_url', 'title', 'sku', 'status', 'stock_policy', 'stock', 'reserved_quantity', 'available_quantity', 'owed_quantity', 'low_stock_threshold', 'stock_state', 'stock_state_label', 'collections', 'url']];

        foreach ($products as $product) {
            $policy = $this->getProductStockPolicy($product);
            $stock = $product->hasField('mrc_stock') ? (int) $product->mrc_stock : 0;
            $reserved = $commerce->orderRepository()->getReservedQuantityForProduct((int) $product->id);
            $available = $policy === 'deny' ? max(0, $stock - $reserved) : '';
            $owed = in_array($policy, ['backorder', 'preorder'], true) && $stock < 0 ? abs($stock) : 0;
            $state = $this->getProductStockState($product, $commerce);
            $rows[] = [
                (int) $product->id,
                $this->productDetailUrl($product),
                (string) $product->title,
                (string) $product->mrc_sku,
                $this->getProductPublicationStatus($product),
                $policy,
                $stock,
                $reserved,
                $available,
                $owed,
                $product->hasField('mrc_low_stock_threshold') ? (int) $product->mrc_low_stock_threshold : '',
                (string) ($state['raw'] ?? ''),
                (string) ($state['label'] ?? ''),
                $this->getProductCollectionLabel($product),
                $product->httpUrl(),
            ];
        }

        return $rows;
    }

    protected function getTaxShippingReadinessExportRows(Mercato $commerce): array {
        $data = $this->getTaxShippingReportData($commerce);
        $rows = [['area', 'current_setting', 'operational_note']];
        foreach ($this->getTaxShippingReadinessRows($data, $commerce) as [$area, $value, $note]) {
            $rows[] = [(string) $area, (string) $value, (string) $note];
        }
        return $rows;
    }

    protected function getProductEventExportRows(): array {
        $rows = [['time', 'event', 'product_id', 'product_url', 'title', 'name', 'sku', 'source', 'source_product_id', 'source_product_title', 'import_mode', 'imported_images', 'copied_images', 'changed_fields', 'action', 'from_status', 'to_status', 'from_policy', 'to_policy', 'from_price', 'to_price', 'from_shipping_price', 'to_shipping_price', 'from_low_stock_threshold', 'to_low_stock_threshold', 'from_stock', 'to_stock', 'delta', 'before', 'after', 'note', 'user']];
        foreach ($this->getProductEvents(10000) as $event) {
            $productId = (int) ($event['product_id'] ?? 0);
            $product = $productId > 0 ? $this->wire('pages')->get("id=$productId, include=all") : null;
            $rows[] = [
                (string) ($event['_time'] ?? $event['at'] ?? ''),
                (string) ($event['event'] ?? ''),
                $productId,
                ($product && $product->id) ? $this->productDetailUrl($product) : '',
                (string) ($event['title'] ?? ''),
                (string) ($event['name'] ?? ''),
                (string) ($event['sku'] ?? ''),
                (string) ($event['source'] ?? ''),
                (int) ($event['source_product_id'] ?? 0),
                (string) ($event['source_product_title'] ?? ''),
                (string) ($event['import_mode'] ?? ''),
                array_key_exists('imported_images', $event) ? (int) $event['imported_images'] : '',
                array_key_exists('copied_images', $event) ? (int) $event['copied_images'] : '',
                (string) ($event['changed_fields'] ?? ''),
                (string) ($event['action'] ?? ''),
                (string) ($event['from_status'] ?? ''),
                (string) ($event['to_status'] ?? ''),
                (string) ($event['from_policy'] ?? ''),
                (string) ($event['to_policy'] ?? ''),
                array_key_exists('from_price', $event) && $event['from_price'] !== null ? $this->formatCsvAmount((float) $event['from_price']) : '',
                array_key_exists('to_price', $event) && $event['to_price'] !== null ? $this->formatCsvAmount((float) $event['to_price']) : '',
                array_key_exists('from_shipping_price', $event) && $event['from_shipping_price'] !== null ? $this->formatCsvAmount((float) $event['from_shipping_price']) : '',
                array_key_exists('to_shipping_price', $event) && $event['to_shipping_price'] !== null ? $this->formatCsvAmount((float) $event['to_shipping_price']) : '',
                array_key_exists('from_low_stock_threshold', $event) && $event['from_low_stock_threshold'] !== null ? (int) $event['from_low_stock_threshold'] : '',
                array_key_exists('to_low_stock_threshold', $event) && $event['to_low_stock_threshold'] !== null ? (int) $event['to_low_stock_threshold'] : '',
                array_key_exists('from_stock', $event) && $event['from_stock'] !== null ? (int) $event['from_stock'] : '',
                array_key_exists('to_stock', $event) && $event['to_stock'] !== null ? (int) $event['to_stock'] : '',
                array_key_exists('delta', $event) ? (int) $event['delta'] : '',
                array_key_exists('before', $event) && $event['before'] !== null ? (int) $event['before'] : '',
                array_key_exists('after', $event) && $event['after'] !== null ? (int) $event['after'] : '',
                (string) ($event['note'] ?? ''),
                (string) ($event['user'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function getCustomerExportRows(Mercato $commerce, array|string $filters = 'all'): array {
        if (is_string($filters)) {
            $filters = ['segment' => $filters, 'q' => ''];
        }
        $rows = [['key', 'customer_url', 'name', 'email', 'phone', 'address', 'city', 'zip', 'country', 'segments', 'orders', 'paid_orders', 'pending_orders', 'processing_orders', 'failed_orders', 'canceled_orders', 'revenue', 'last_order', 'last_order_id', 'last_order_url', 'last_order_created']];

        foreach ($this->filterCustomers($this->getCustomersFromOrders($commerce), $filters) as $customer) {
            $lastOrder = $customer['last_order'] ?? null;
            $segments = array_map(static fn(array $item): string => (string) ($item['key'] ?? ''), (array) ($customer['segments'] ?? []));
            $rows[] = [
                (string) ($customer['key'] ?? ''),
                $this->customerDetailUrl(['key' => (string) ($customer['key'] ?? '')]),
                (string) ($customer['name'] ?? ''),
                (string) ($customer['email'] ?? ''),
                (string) ($customer['phone'] ?? ''),
                (string) ($customer['address'] ?? ''),
                (string) ($customer['city'] ?? ''),
                (string) ($customer['zip'] ?? ''),
                (string) ($customer['country'] ?? ''),
                implode('|', array_filter($segments)),
                (int) ($customer['orders'] ?? 0),
                (int) ($customer['paid_orders'] ?? 0),
                (int) ($customer['pending_orders'] ?? 0),
                (int) ($customer['processing_orders'] ?? 0),
                (int) ($customer['failed_orders'] ?? 0),
                (int) ($customer['canceled_orders'] ?? 0),
                $this->formatCsvAmount((float) ($customer['revenue'] ?? 0)),
                $lastOrder instanceof Page ? (string) ($lastOrder->mrc_invoice_number ?: $lastOrder->title) : '',
                $lastOrder instanceof Page ? (int) $lastOrder->id : 0,
                $lastOrder instanceof Page ? $this->orderDetailUrl($lastOrder) : '',
                !empty($customer['last_order_created']) ? date('Y-m-d H:i:s', (int) $customer['last_order_created']) : '',
            ];
        }

        return $rows;
    }

    protected function getRecoveryExportRows(Mercato $commerce, array $filters = []): array {
        $recoveryDiscountCode = strtoupper(trim((string) ($commerce->recovery_discount_code ?? '')));
        $rows = [['order_id', 'order_url', 'invoice', 'created', 'customer', 'email', 'payment_status', 'gateway', 'attempts', 'latest_attempt_event', 'latest_attempt_status', 'age_minutes', 'total', 'recovery_email_sent_at', 'recoverable', 'recovery_allowed', 'recovery_reason', 'recovery_cooldown_minutes', 'recovery_discount_code']];
        foreach ($this->getAbandonedCheckouts($commerce, $filters) as $row) {
            $order = $row['order'] ?? null;
            if (!$order instanceof Page || !$order->id) {
                continue;
            }
            $attempt = (array) ($row['latest_attempt'] ?? []);
            $recoveryEmail = (array) ($row['recovery_email'] ?? []);
            $rows[] = [
                (int) $order->id,
                $this->orderDetailUrl($order),
                (string) ($order->mrc_invoice_number ?: $order->title),
                date('Y-m-d H:i:s', (int) $order->created),
                $this->getOrderCustomer($order),
                (string) $order->mrc_email,
                (string) ($order->mrc_payment_status ?: MercatoPaymentStatus::PENDING),
                (string) ($row['gateway'] ?? ''),
                (int) ($row['attempt_count'] ?? 0),
                (string) ($attempt['event'] ?? ''),
                (string) ($attempt['status'] ?? ''),
                (int) ($row['age_minutes'] ?? 0),
                $this->formatCsvAmount($this->getOrderTotal($order, $commerce)),
                (string) ($recoveryEmail['_time'] ?? ''),
                !empty($row['recoverable']) ? 1 : 0,
                !empty($row['recovery_allowed']) ? 1 : 0,
                (string) ($row['recovery_reason'] ?? ''),
                (int) ($row['recovery_cooldown_minutes'] ?? 0),
                $recoveryDiscountCode,
            ];
        }
        return $rows;
    }

    protected function getRecoveryEventExportRows(): array {
        $rows = [['time', 'event', 'status', 'order_id', 'invoice', 'order_url', 'email', 'recipient', 'message', 'user', 'cooldown_minutes', 'source', 'checked', 'eligible', 'sent', 'failed', 'blocked', 'min_age_minutes', 'batch_limit', 'recovery_discount_code']];
        foreach ($this->getRecoveryEvents(10000) as $event) {
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (string) ($event['status'] ?? ''),
                (int) ($event['order_id'] ?? 0),
                (string) ($event['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['email'] ?? ''),
                (string) ($event['recipient'] ?? ''),
                (string) ($event['message'] ?? ''),
                (string) ($event['user'] ?? ''),
                (int) ($event['cooldown_minutes'] ?? 0),
                (string) ($event['source'] ?? ''),
                (int) ($event['checked'] ?? 0),
                (int) ($event['eligible'] ?? 0),
                (int) ($event['sent'] ?? 0),
                (int) ($event['failed'] ?? 0),
                (int) ($event['blocked'] ?? 0),
                (int) ($event['min_age_minutes'] ?? 0),
                (int) ($event['batch_limit'] ?? 0),
                (string) ($event['recovery_discount_code'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function getDiscountExportRows(Mercato $commerce): array {
        $rows = [['id', 'discount_url', 'title', 'code', 'type', 'amount', 'percent', 'active', 'usage_limit', 'per_customer_limit', 'minimum_order_total', 'used_count', 'product_ids', 'collection_ids', 'customer_targets', 'notes']];

        foreach ($commerce->discountService()->listDiscounts(10000) as $discount) {
            $data = $discount->toArray();
            $pageId = (int) ($data['page_id'] ?? 0);
            $page = $pageId > 0 ? $this->wire('pages')->get("id=$pageId, include=all") : null;
            $rows[] = [
                $pageId,
                ($page && $page->id) ? $this->editUrl($page) : '',
                (string) ($data['title'] ?? ''),
                (string) ($data['code'] ?? ''),
                (string) ($data['type'] ?? ''),
                $this->formatCsvAmount((float) ($data['amount'] ?? 0)),
                $this->formatCsvAmount((float) ($data['percent'] ?? 0)),
                !empty($data['active']) ? 1 : 0,
                (int) ($data['usage_limit'] ?? 0),
                (int) ($data['per_customer_limit'] ?? 0),
                $this->formatCsvAmount((float) ($data['minimum_order_total'] ?? 0)),
                (int) ($data['used_count'] ?? 0),
                implode('|', array_map('strval', (array) ($data['product_ids'] ?? []))),
                implode('|', array_map('strval', (array) ($data['collection_ids'] ?? []))),
                implode('|', array_map('strval', (array) ($data['customer_targets'] ?? []))),
                (string) ($data['notes'] ?? ''),
            ];
        }

        return $rows;
    }

    protected function getDiscountEventExportRows(array $filters = []): array {
        $rows = [['time', 'event', 'code', 'valid', 'amount', 'email', 'source', 'order_page_id', 'invoice', 'order_url', 'message']];
        foreach ($this->getDiscountAuditEvents(10000, $filters) as $event) {
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (string) ($event['code'] ?? ''),
                !empty($event['valid']) ? 1 : 0,
                $this->formatCsvAmount((float) ($event['amount'] ?? 0)),
                (string) ($event['email'] ?? ''),
                (string) ($event['source'] ?? ''),
                (int) ($event['order_page_id'] ?? 0),
                (string) ($event['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['message'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function getWebhookExportRows(array $filters = []): array {
        $rows = [['time', 'gateway', 'event_type', 'status', 'order_page_id', 'order_url', 'external_payment_id', 'message']];
        foreach ($this->filterWebhookEvents($this->getWebhookEvents(10000), $filters) as $event) {
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['gateway'] ?? ''),
                (string) ($event['event_type'] ?? ''),
                (string) ($event['status'] ?? ''),
                (int) ($event['order_page_id'] ?? 0),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['external_payment_id'] ?? ''),
                (string) ($event['message'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function getInventoryExportRows(array $filters = []): array {
        $rows = [['time', 'event', 'product_id', 'title', 'quantity', 'before', 'after', 'order_id', 'invoice', 'order_url', 'reserved_until']];
        foreach ($this->filterInventoryEvents($this->getInventoryEvents(10000), $filters) as $event) {
            $rows[] = [
                (string) ($event['_time'] ?? $event['at'] ?? ''),
                (string) ($event['event'] ?? ''),
                (int) ($event['product_id'] ?? 0),
                (string) ($event['title'] ?? ''),
                (int) ($event['quantity'] ?? 0),
                array_key_exists('before', $event) && $event['before'] !== null ? (int) $event['before'] : '',
                array_key_exists('after', $event) && $event['after'] !== null ? (int) $event['after'] : '',
                (int) ($event['order_id'] ?? 0),
                (string) ($event['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['reserved_until'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function getFulfilmentExportRows(string $method = 'all'): array {
        $rows = [['time', 'event', 'order_id', 'invoice', 'order_url', 'from', 'to', 'tracking', 'tracking_url', 'tracking_changed', 'note', 'user']];
        foreach ($this->filterFulfilmentEventsByMethod($this->getFulfilmentEvents(10000), $method) as $event) {
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (int) ($event['order_id'] ?? 0),
                (string) ($event['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['from'] ?? ''),
                (string) ($event['to'] ?? ''),
                (string) ($event['tracking'] ?? ''),
                (string) ($event['tracking_url'] ?? ''),
                !empty($event['tracking_changed']) ? 1 : 0,
                (string) ($event['note'] ?? ''),
                (string) ($event['user'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function getFulfilmentQueueExportRows(Mercato $commerce, string $method = 'all', string $queue = 'all'): array {
        $rows = [[
            'order_id', 'order_url', 'invoice', 'created', 'customer', 'email',
            'fulfilment_method', 'fulfilment_label', 'work_type', 'work_detail',
            'stored_fulfilment_status', 'tracking', 'tracking_url', 'notes',
            'currency', 'total', 'items_count', 'items_summary', 'items_json',
        ]];

        foreach ($this->getFulfilmentOrders($commerce, 10000, $method, $queue) as $order) {
            $state = $this->getOrderFulfilmentState($order);
            $rows[] = [
                (int) $order->id,
                $this->orderDetailUrl($order),
                (string) ($order->mrc_invoice_number ?: $order->title),
                date('Y-m-d H:i:s', (int) $order->created),
                $this->getOrderCustomer($order),
                (string) $order->mrc_email,
                $this->getOrderFulfilmentMethod($order),
                $this->getOrderFulfilmentMethodLabel($order),
                (string) ($state['raw'] ?? ''),
                (string) ($state['detail'] ?? ''),
                $order->hasField('mrc_fulfilment_status') ? (string) $order->mrc_fulfilment_status : '',
                $order->hasField('mrc_fulfilment_tracking') ? (string) $order->mrc_fulfilment_tracking : '',
                $order->hasField('mrc_fulfilment_tracking_url') ? (string) $order->mrc_fulfilment_tracking_url : '',
                $order->hasField('mrc_fulfilment_notes') ? (string) $order->mrc_fulfilment_notes : '',
                (string) ($order->mrc_currency ?: $commerce->currency),
                $this->formatCsvAmount($this->getOrderTotal($order, $commerce)),
                $this->getOrderItemCount($order),
                $this->getOrderItemsSummary($order),
                (string) $order->mrc_items,
            ];
        }

        return $rows;
    }

    protected function getNotificationExportRows(array $filters = []): array {
        $rows = [['time', 'event', 'status', 'order_id', 'invoice', 'order_url', 'recipient', 'message', 'recovery_discount_code']];
        foreach ($this->getNotificationEvents(10000, $filters) as $event) {
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (string) ($event['status'] ?? ''),
                (int) ($event['order_id'] ?? 0),
                (string) ($event['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['recipient'] ?? ''),
                (string) ($event['message'] ?? ''),
                (string) ($event['recovery_discount_code'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function getPaymentExportRows(): array {
        $rows = [['time', 'event', 'order_id', 'invoice', 'order_url', 'from', 'to', 'reason', 'user', 'inventory_errors', 'details']];
        foreach ($this->getPaymentEvents(10000) as $event) {
            $inventoryErrors = implode(' | ', array_map('strval', (array) ($event['inventory_errors'] ?? [])));
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (int) ($event['order_id'] ?? 0),
                (string) ($event['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['from'] ?? ''),
                (string) ($event['to'] ?? ''),
                (string) ($event['reason'] ?? ''),
                (string) ($event['user'] ?? ''),
                $inventoryErrors,
                $this->summarizePaymentAuditEvent($event, $inventoryErrors),
            ];
        }
        return $rows;
    }

    protected function summarizePaymentAuditEvent(array $event, string $inventoryErrors = ''): string {
        $parts = [];
        $transition = trim((string) ($event['from'] ?? '')) . ' -> ' . trim((string) ($event['to'] ?? ''));
        if (trim($transition) !== '->') {
            $parts[] = 'status: ' . $transition;
        }
        $reason = trim((string) ($event['reason'] ?? ''));
        if ($reason !== '') {
            $parts[] = 'reason: ' . $reason;
        }
        $user = trim((string) ($event['user'] ?? ''));
        if ($user !== '') {
            $parts[] = 'user: ' . $user;
        }
        if ($inventoryErrors !== '') {
            $parts[] = 'inventory: ' . $inventoryErrors;
        }
        return implode(' | ', $parts);
    }

    protected function getAuditExportOrderUrl(array $event): string {
        $orderId = (int) ($event['order_id'] ?? $event['order_page_id'] ?? 0);
        if ($orderId <= 0) {
            return '';
        }
        $order = $this->wire('pages')->get("id=$orderId, include=all");
        $commerce = $this->wire('modules')->get('Mercato');
        $orderTemplate = $commerce ? (string) ($commerce->order_template ?: 'mrc-order') : 'mrc-order';
        if (!$order || !$order->id || (string) $order->template->name !== $orderTemplate) {
            return '';
        }
        return $this->orderDetailUrl($order);
    }

    protected function getPaymentAttemptExportRows(array $filters = []): array {
        $rows = [['time', 'event', 'attempt_id', 'order_id', 'invoice', 'order_url', 'gateway', 'method', 'amount', 'currency', 'status', 'external_id', 'idempotency_key', 'context_summary', 'context_json']];
        $events = $filters ? $this->filterPaymentAttemptEvents($this->getPaymentAttemptEvents(10000), $filters) : $this->getPaymentAttemptEvents(10000);
        foreach ($events as $event) {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $context = $this->getPaymentAttemptSafeContext($event);
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (string) ($event['id'] ?? ''),
                (int) ($event['order_page_id'] ?? 0),
                (string) ($payload['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['gateway'] ?? ''),
                (string) ($event['method'] ?? ''),
                $this->formatCsvAmount((float) ($event['amount'] ?? 0)),
                (string) ($event['currency'] ?? ''),
                (string) ($event['status'] ?? ''),
                (string) ($event['external_id'] ?? ''),
                (string) ($event['idempotency_key'] ?? ''),
                $this->summarizePaymentAttemptContext($context),
                json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }
        return $rows;
    }

    protected function getRefundExportRows(array $filters = []): array {
        $rows = [['time', 'event', 'order_id', 'invoice', 'order_url', 'gateway', 'refund_id', 'gateway_status', 'amount', 'total_refunded', 'pending_amount', 'payment_status', 'reason', 'user', 'details']];
        $events = $filters ? $this->filterRefundEvents($this->getRefundEvents(10000), $filters) : $this->getRefundEvents(10000);
        foreach ($events as $event) {
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (int) ($event['order_id'] ?? 0),
                (string) ($event['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['gateway'] ?? ''),
                (string) ($event['refund_id'] ?? ''),
                (string) ($event['gateway_status'] ?? ''),
                $this->formatCsvAmount((float) ($event['amount'] ?? 0)),
                $this->formatCsvAmount((float) ($event['total_refunded'] ?? 0)),
                $this->formatCsvAmount((float) ($event['pending_amount'] ?? 0)),
                (string) ($event['payment_status'] ?? ''),
                (string) ($event['reason'] ?? ''),
                (string) ($event['user'] ?? ''),
                $this->summarizeRefundAuditEvent($event),
            ];
        }
        return $rows;
    }

    protected function summarizeRefundAuditEvent(array $event): string {
        $parts = [];
        foreach ([
            'event' => 'event',
            'gateway_status' => 'gateway',
            'payment_status' => 'payment',
            'reason' => 'reason',
            'user' => 'user',
        ] as $key => $label) {
            $value = trim((string) ($event[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }
        foreach ([
            'amount' => 'amount',
            'total_refunded' => 'total_refunded',
            'pending_amount' => 'pending',
        ] as $key => $label) {
            $value = (float) ($event[$key] ?? 0);
            if ($value > 0) {
                $parts[] = $label . ': ' . $this->formatCsvAmount($value);
            }
        }
        return implode(' | ', $parts);
    }

    protected function getOrderEditExportRows(): array {
        $rows = [['time', 'event', 'order_id', 'invoice', 'order_url', 'user', 'from_total', 'to_total', 'shipping_before', 'shipping_after', 'discount_before', 'discount_after', 'items_before', 'items_after']];
        foreach ($this->getOrderEditEvents(10000) as $event) {
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (int) ($event['order_id'] ?? 0),
                (string) ($event['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['user'] ?? ''),
                $this->formatCsvAmount((float) ($event['from_total'] ?? 0)),
                $this->formatCsvAmount((float) ($event['to_total'] ?? 0)),
                $this->formatCsvAmount((float) ($event['shipping_before'] ?? 0)),
                $this->formatCsvAmount((float) ($event['shipping_after'] ?? 0)),
                $this->formatCsvAmount((float) ($event['discount_before'] ?? 0)),
                $this->formatCsvAmount((float) ($event['discount_after'] ?? 0)),
                json_encode($event['items_before'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($event['items_after'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }
        return $rows;
    }

    protected function getOrderNoteExportRows(): array {
        $rows = [['time', 'event', 'order_id', 'invoice', 'order_url', 'user', 'note']];
        foreach ($this->getOrderNoteEvents(10000) as $event) {
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (int) ($event['order_id'] ?? 0),
                (string) ($event['invoice'] ?? ''),
                $this->getAuditExportOrderUrl($event),
                (string) ($event['user'] ?? ''),
                (string) ($event['note'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function getCustomerNoteExportRows(string $customerKey = ''): array {
        $rows = [['time', 'event', 'customer_key', 'customer_url', 'name', 'email', 'user', 'note']];
        foreach ($this->getCustomerNoteEvents(10000) as $event) {
            if ($customerKey !== '' && (string) ($event['customer_key'] ?? '') !== $customerKey) {
                continue;
            }
            $rows[] = [
                (string) ($event['_time'] ?? ''),
                (string) ($event['event'] ?? ''),
                (string) ($event['customer_key'] ?? ''),
                $this->customerDetailUrl(['key' => (string) ($event['customer_key'] ?? '')]),
                (string) ($event['name'] ?? ''),
                (string) ($event['email'] ?? ''),
                (string) ($event['user'] ?? ''),
                (string) ($event['note'] ?? ''),
            ];
        }
        return $rows;
    }

    protected function sendCsv(string $filename, array $rows): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]+/', '-', $filename) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    protected function formatCsvAmount(float $amount): string {
        return number_format($amount, 2, '.', '');
    }

    protected function editUrl(Page $page): string {
        return $this->wire('config')->urls->admin . 'page/edit/?id=' . (int) $page->id;
    }

    protected function timelineUrl(Page $order): string {
        return $this->adminUrl('order-timeline/') . '?id=' . (int) $order->id;
    }

    protected function orderDetailUrl(Page $order): string {
        return $this->adminUrl('order-detail/') . '?id=' . (int) $order->id;
    }

    protected function productDetailUrl(Page $product): string {
        return $this->adminUrl('product-detail/') . '?id=' . (int) $product->id;
    }

    protected function customerDetailUrl(array $customer): string {
        return $this->adminUrl('customer-detail/') . '?' . http_build_query(['key' => (string) ($customer['key'] ?? '')]);
    }

    protected function manualOrderCustomerUrl(array $customer): string {
        return $this->adminUrl('manual-order/') . '?' . http_build_query(['customer_key' => (string) ($customer['key'] ?? '')]);
    }

    protected function manualOrderFromOrderUrl(Page $order): string {
        return $this->adminUrl('manual-order/') . '?' . http_build_query(['source_order' => (int) $order->id]);
    }

    protected function exportUrl(string $type, array $query = []): string {
        $query = ['type' => $type] + $query;
        return $this->adminUrl('export/') . '?' . http_build_query($query);
    }
}
