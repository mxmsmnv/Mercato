<?php
namespace ProcessWire;

trait ProcessMercatoOrderDetailPanels {

    protected function renderOrderTimeline(Page $order, array $events, Mercato $commerce): string {
        $payment = $this->getOrderPaymentState($order);
        $fulfilment = $this->getOrderFulfilmentState($order);
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div>';
        $out .= '<h2 class="uk-h3">' . $this->e((string) ($order->mrc_invoice_number ?: $order->title)) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->getOrderCustomer($order)) . ' / ' . $this->e($commerce->formatPrice($this->getOrderTotal($order, $commerce))) . '</p>';
        $out .= '</div><div class="mrc-panel-actions">';
        $out .= '<span class="uk-label mrc-admin-status ' . $payment['class'] . '">' . $this->e($payment['label']) . '</span>';
        $out .= '<span class="uk-label mrc-admin-status ' . $fulfilment['class'] . '">' . $this->e($fulfilment['label']) . '</span>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->orderDetailUrl($order)) . '"><i class="fa fa-file-text-o uk-margin-small-right"></i>' . $this->e($this->_('Order detail')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->editUrl($order)) . '"><i class="fa fa-pencil uk-margin-small-right"></i>' . $this->e($this->_('Edit order')) . '</a>';
        $out .= '</div></div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr><th>' . $this->e($this->_('Time')) . '</th><th>' . $this->e($this->_('Area')) . '</th><th>' . $this->e($this->_('Event')) . '</th><th>' . $this->e($this->_('Details')) . '</th></tr></thead><tbody>';
        foreach ($events as $event) {
            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['time'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e(ucfirst((string) ($event['type'] ?? '-'))) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $this->e((string) ($event['class'] ?? 'is-pending')) . '">' . $this->e(ucfirst(str_replace('_', ' ', (string) ($event['label'] ?? '-')))) . '</span></td>';
            $out .= '<td>' . $this->renderTimelineEventDetails($event) . '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderOrderDetail(Page $order, array $events, Mercato $commerce, array $actionResult = [], array $refundResult = [], array $confirmationResult = [], array $paymentLinkResult = [], array $orderEditResult = [], array $orderNoteResult = [], array $cancelResult = [], array $statusLinkResult = []): string {
        $orderStatus = $commerce->deriveOrderStatus($order);
        $payment = $this->getOrderPaymentState($order);
        $inventory = $this->getOrderInventoryState($order);
        $fulfilment = $this->getOrderFulfilmentState($order);
        $invoice = (string) ($order->mrc_invoice_number ?: $order->title);
        $gatewayId = $this->getOrderGatewayReference($order);
        $tracking = $order->hasField('mrc_fulfilment_tracking') ? trim((string) $order->mrc_fulfilment_tracking) : '';
        $trackingUrl = $order->hasField('mrc_fulfilment_tracking_url') ? trim((string) $order->mrc_fulfilment_tracking_url) : '';
        $methodLabel = $this->getOrderFulfilmentMethodLabel($order);
        $methodDetails = $this->getOrderFulfilmentMethodDetails($order);
        $fulfilmentSnapshot = json_decode((string) ($order->mrc_fulfilment_details ?? ''), true);
        $fulfilmentSnapshot = is_array($fulfilmentSnapshot) ? $fulfilmentSnapshot : [];
        $manualLabelUrl = trim((string) ($fulfilmentSnapshot['manual_label_url'] ?? ''));
        $customerProfileUrl = $this->getOrderCustomerProfileUrl($order);

        $out = '<section class="pw-wrap mrc-admin-panel mrc-order-detail-head">';
        $out .= '<div class="mrc-admin-panel-head"><div>';
        $out .= '<div class="ds-section-label">' . $this->e($this->_('Order')) . '</div>';
        $out .= '<h2 class="uk-h3">' . $this->e($invoice) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e(date('Y-m-d H:i', (int) $order->created)) . ' / ' . $this->e($this->getOrderCustomer($order)) . '</p>';
        $out .= '</div><div class="mrc-panel-actions">';
        $out .= '<span class="uk-label mrc-admin-status ' . $this->e((string) $orderStatus['class']) . '">' . $this->e((string) $orderStatus['label']) . '</span>';
        $out .= '<span class="uk-label mrc-admin-status ' . $payment['class'] . '">' . $this->e($payment['label']) . '</span>';
        $out .= '<span class="uk-label mrc-admin-status ' . $inventory['class'] . '">' . $this->e($inventory['label']) . '</span>';
        $out .= '<span class="uk-label mrc-admin-status ' . $fulfilment['class'] . '">' . $this->e($fulfilment['label']) . '</span>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->timelineUrl($order)) . '"><i class="fa fa-clock-o uk-margin-small-right"></i>' . $this->e($this->_('Timeline')) . '</a>';
        if ($commerce->isOrderReceiptAvailable($order)) {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($commerce->getOrderReceiptUrl($order)) . '" target="_blank" rel="noopener"><i class="fa fa-print uk-margin-small-right"></i>' . $this->e($this->_('Receipt')) . '</a>';
        }
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($commerce->getOrderPackingSlipPdfUrl($order)) . '" target="_blank" rel="noopener"><i class="fa fa-file-pdf-o uk-margin-small-right"></i>' . $this->e($this->_('Packing slip')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->editUrl($order)) . '"><i class="fa fa-pencil uk-margin-small-right"></i>' . $this->e($this->_('Edit fields')) . '</a>';
        $out .= '</div></div></section>';

        $out .= '<div class="mrc-order-detail-grid">';
        $out .= $this->renderOrderInfoPanel($this->_('Customer'), [
            [$this->_('Name'), $this->getOrderCustomer($order)],
            [$this->_('Email'), (string) $order->mrc_email],
            [$this->_('Profile'), $customerProfileUrl !== '' ? $this->_('Open customer profile') : '', $customerProfileUrl],
            [$this->_('Phone'), (string) $order->mrc_phone],
            [$this->_('Billing address'), $this->getOrderAddressSummary($order, 'mrc_billing_address')],
            [$this->_('Receipt details'), $this->getOrderReceiptDetailsSummary($order, $commerce)],
            [$this->_('Legacy address'), trim(implode(', ', array_filter([(string) $order->mrc_address, (string) $order->mrc_city, (string) $order->mrc_zip, (string) $order->mrc_country])))],
            [$this->_('Notes'), (string) $order->mrc_notes],
        ]);
        $paymentRows = [
            [$this->_('Order status'), (string) $orderStatus['label']],
            [$this->_('Status'), $payment['label']],
            [$this->_('Method'), $this->getOrderGatewayLabel($order)],
            [$this->_('Gateway ID'), $gatewayId],
            [$this->_('Paid date'), (string) $order->mrc_paid_date],
            [$this->_('Policy acceptance'), $this->getOrderPolicyAcceptanceSummary($order)],
            [$this->_('Refunded'), $order->hasField('mrc_refunded_amount') && (float) $order->mrc_refunded_amount > 0 ? $commerce->formatPrice((float) $order->mrc_refunded_amount) : ''],
            [$this->_('Pending refund'), $order->hasField('mrc_refund_pending_amount') && (float) $order->mrc_refund_pending_amount > 0 ? $commerce->formatPrice((float) $order->mrc_refund_pending_amount) : ''],
            [$this->_('Last refund request'), $order->hasField('mrc_refunded_date') ? (string) $order->mrc_refunded_date : ''],
        ];
        $failureSummary = $this->getOrderPaymentFailureSummary($order);
        if ($failureSummary !== '') {
            array_splice($paymentRows, 2, 0, [[$this->_('Failure reason'), $failureSummary]]);
        }
        $out .= $this->renderOrderInfoPanel($this->_('Payment'), $paymentRows);
        $out .= $this->renderOrderInfoPanel($this->_('Shipping'), [
            [$this->_('Method'), $methodLabel],
            [$this->_('Method details'), $methodDetails],
            [$this->_('Shipping address'), $this->getOrderAddressSummary($order, 'mrc_shipping_address')],
            [$this->_('Status'), $fulfilment['label']],
            [$this->_('Tracking'), $tracking],
            [$this->_('Tracking URL'), $trackingUrl, $trackingUrl],
            [$this->_('Manual label URL'), $manualLabelUrl, $manualLabelUrl],
            [$this->_('Notes'), $order->hasField('mrc_fulfilment_notes') ? (string) $order->mrc_fulfilment_notes : ''],
        ]);
        $out .= '</div>';

        $out .= $this->renderOrderNotesPanel($order, $orderNoteResult);
        $out .= $this->renderUnpaidOrderEditPanel($order, $commerce, $orderEditResult);
        $out .= $this->renderPaymentLinkPanel($order, $commerce, $paymentLinkResult);
        $out .= $this->renderPaymentOperations($order, $commerce, $actionResult, $refundResult, $cancelResult);
        $out .= $this->renderPaymentAttemptsPanel($order, $commerce);
        $out .= $this->renderPaymentReconciliationAuditPanel($order, $commerce);
        $out .= $this->renderOrderCommunication($order, $commerce, $confirmationResult, $statusLinkResult);
        $out .= $this->renderOrderItemsPanel($order, $commerce);
        $out .= $this->renderShippingProviderPanel($order, $commerce, $actionResult);
        $out .= $this->renderOrderLatestActivity($events);

        $paymentDetails = trim((string) $order->mrc_payment_details);
        if ($paymentDetails !== '' || $gatewayId !== '') {
            $formattedDetails = '';
            $decoded = json_decode($paymentDetails, true);
            if (is_array($decoded)) {
                $formattedDetails = (string) json_encode($this->redactSensitiveDebugData($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif ($paymentDetails !== '') {
                $formattedDetails = '[unreadable gateway payload withheld]';
            }
            $out .= '<section class="pw-wrap mrc-admin-panel mrc-order-debug">';
            $out .= '<details><summary>' . $this->e($this->_('Gateway metadata / debug')) . '</summary>';
            if ($gatewayId !== '') {
                $out .= '<p><strong>' . $this->e($this->_('Gateway ID')) . ':</strong> ' . $this->e($gatewayId) . '</p>';
            }
            if ($formattedDetails !== '') {
                $out .= '<pre>' . $this->e($formattedDetails) . '</pre>';
            }
            $out .= '</details></section>';
        }

        return $out;
    }

    protected function renderPaymentLinkPanel(Page $order, Mercato $commerce, array $paymentLinkResult = []): string {
        $payment = $this->getOrderPaymentState($order);
        if ($payment['paid']) {
            return '';
        }

        $url = $commerce->getPaymentLinkUrl($order);
        $out = '<section class="pw-wrap mrc-admin-panel mrc-payment-link-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Payment Link')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Send this link to let the customer pay this unpaid order through the checkout flow.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-primary" href="' . $this->e($url) . '" target="_blank" rel="noopener"><i class="fa fa-credit-card uk-margin-small-right"></i>' . $this->e($this->_('Open payment link')) . '</a></div></div>';
        if ($paymentLinkResult) {
            $class = !empty($paymentLinkResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($paymentLinkResult['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($paymentLinkResult['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }
        $out .= '<div class="mrc-payment-link-copy-row">';
        $out .= '<input class="uk-input" id="mrc-payment-link-' . (int) $order->id . '" readonly value="' . $this->e($url) . '" onclick="this.select()">';
        $out .= '<button class="uk-button uk-button-default mrc-copy-payment-link" type="button" data-copy-target="mrc-payment-link-' . (int) $order->id . '"><i class="fa fa-copy uk-margin-small-right"></i>' . $this->e($this->_('Copy link')) . '</button>';
        $out .= '</div>';
        $out .= '<form method="post" action="' . $this->e($this->orderDetailUrl($order)) . '" class="mrc-inline-form mrc-payment-link-email-form">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_send_payment_link" value="1">';
        $out .= '<input type="hidden" name="order_id" value="' . (int) $order->id . '">';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-envelope-o uk-margin-small-right"></i>' . $this->e($this->_('Email payment link')) . '</button>';
        $out .= '</form>';
        return $out . '</section>';
    }

    protected function getOrderPaymentFailureSummary(Page $order): string {
        $status = strtolower(trim((string) ($order->mrc_payment_status ?: '')));
        if (!in_array($status, [MercatoPaymentStatus::FAILED, MercatoPaymentStatus::CANCELED, MercatoPaymentStatus::EXPIRED], true)) {
            return '';
        }

        $details = json_decode((string) $order->mrc_payment_details, true);
        if (!is_array($details)) {
            return '';
        }
        $details = $this->redactSensitiveDebugData($details);
        if (!is_array($details)) {
            return '';
        }

        $parts = [];
        foreach (['state' => $this->_('State'), 'error' => $this->_('Error'), 'failed_at' => $this->_('Failed at'), 'gateway' => $this->_('Gateway')] as $key => $label) {
            $value = trim((string) ($details[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $parts);
    }

    protected function getOrderGatewayReference(Page $order): string {
        $direct = trim((string) ($order->mrc_stripe_payment_intent_id ?: $order->mrc_mollie_payment_id ?: ''));
        if ($direct !== '') {
            return $direct;
        }

        $details = json_decode((string) $order->mrc_payment_details, true);
        if (!is_array($details)) {
            return '';
        }

        foreach (['paypal_order_id', 'order_id', 'external_payment_id', 'payment_id', 'id'] as $key) {
            $value = trim((string) ($details[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $purchaseUnits = $details['purchase_units'] ?? [];
        if (is_array($purchaseUnits)) {
            foreach ($purchaseUnits as $unit) {
                if (!is_array($unit)) {
                    continue;
                }
                $payments = $unit['payments']['captures'] ?? [];
                if (!is_array($payments)) {
                    continue;
                }
                foreach ($payments as $capture) {
                    $captureId = trim((string) (is_array($capture) ? ($capture['id'] ?? '') : ''));
                    if ($captureId !== '') {
                        return $captureId;
                    }
                }
            }
        }

        return '';
    }

    protected function renderUnpaidOrderEditPanel(Page $order, Mercato $commerce, array $result = []): string {
        $payment = $this->getOrderPaymentState($order);
        if (!empty($payment['paid'])) {
            return '';
        }

        $items = json_decode((string) $order->mrc_items, true);
        if (!is_array($items) || !$items) {
            return '';
        }

        $fulfilmentOptions = Mercato::getFulfilmentMethodOptions();
        $enabledFulfilment = $commerce->getEnabledFulfilmentMethods();
        $products = $this->getProducts($commerce, 100);
        $currentMethod = $order->hasField('mrc_fulfilment_method') && trim((string) $order->mrc_fulfilment_method) !== ''
            ? (string) $order->mrc_fulfilment_method
            : $commerce->getDefaultFulfilmentMethod();

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Edit Unpaid Order')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Recalculate fulfilment, discount, and payable total before the customer pays.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('order-edits')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export order edits')) . '</a></div></div>';

        if ($result) {
            $class = !empty($result['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($result['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($result['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }

        $out .= '<div class="mrc-admin-table-wrap uk-margin-bottom"><table class="uk-table uk-table-divider uk-table-small mrc-admin-table">';
        $out .= '<thead><tr><th>' . $this->e($this->_('Line item')) . '</th><th>' . $this->e($this->_('Current')) . '</th><th>' . $this->e($this->_('New quantity')) . '</th></tr></thead><tbody>';
        foreach ($items as $index => $item) {
            if (!is_array($item)) continue;
            $quantity = (float) ($item['quantity'] ?? 1);
            $out .= '<tr>';
            $out .= '<td><strong>' . $this->e((string) ($item['title'] ?? $item['name'] ?? '-')) . '</strong><br><small>' . $this->e((string) ($item['variant_label'] ?? '')) . (($item['variant_label'] ?? '') !== '' ? ' · ' : '') . $this->e((string) ($item['sku'] ?? $item['uid'] ?? '')) . '</small></td>';
            $out .= '<td>' . $this->e((string) $quantity) . '</td>';
            $out .= '<td><input class="uk-input" type="number" min="0" step="1" name="edit_item_quantity_' . (int) $index . '" value="' . $this->e((string) $quantity) . '" form="mrc-unpaid-order-edit-' . (int) $order->id . '"></td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';

        $out .= '<form id="mrc-unpaid-order-edit-' . (int) $order->id . '" class="mrc-payment-reconcile-form" method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_update_order_totals" value="1">';
        $out .= '<input type="hidden" name="order_id" value="' . (int) $order->id . '">';
        $out .= '<label><span class="ds-section-label">' . $this->e($this->_('Add product')) . '</span><select class="uk-select" name="edit_add_product_id">';
        $out .= '<option value="0">' . $this->e($this->_('Optional product')) . '</option>';
        foreach ($products as $product) {
            $label = (string) $product->title . ' · ' . $commerce->formatPrice((float) $product->mrc_price);
            if (trim((string) $product->mrc_sku) !== '') {
                $label .= ' · ' . (string) $product->mrc_sku;
            }
            if ($product->hasField('mrc_stock')) {
                $label .= ' · ' . sprintf($this->_('%d in stock'), (int) $product->mrc_stock);
            }
            $out .= '<option value="' . (int) $product->id . '">' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span class="ds-section-label">' . $this->e($this->_('Add quantity')) . '</span><input class="uk-input" type="number" min="0" step="1" name="edit_add_quantity" value="0"></label>';
        $out .= '<label><span class="ds-section-label">' . $this->e($this->_('Fulfilment')) . '</span><select class="uk-select" name="edit_fulfilment_method">';
        foreach ($enabledFulfilment as $method) {
            $selected = $method === $currentMethod ? ' selected' : '';
            $out .= '<option value="' . $this->e($method) . '"' . $selected . '>' . $this->e((string) ($fulfilmentOptions[$method] ?? $method)) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span class="ds-section-label">' . $this->e($this->_('Discount code')) . '</span><input class="uk-input" type="text" name="edit_discount_code" value="' . $this->e((string) $order->mrc_discount_code) . '" placeholder="' . $this->e($this->_('Optional')) . '"></label>';
        $out .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-calculator uk-margin-small-right"></i>' . $this->e($this->_('Update total')) . '</button>';
        $out .= '</form>';
        $out .= '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('Set a quantity to 0 to remove that line from this unpaid order. Stock availability is checked before saving.')) . '</p>';
        return $out . '</section>';
    }

    protected function renderOrderNotesPanel(Page $order, array $result = []): string {
        $notes = array_values(array_filter($this->getOrderNoteEvents(50), fn(array $event): bool => (int) ($event['order_id'] ?? 0) === (int) $order->id));
        $notes = array_slice($notes, 0, 5);

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Internal Notes')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Private operational notes for this order. Notes are appended to the order timeline.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('order-notes')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export order notes')) . '</a></div></div>';
        if ($result) {
            $class = !empty($result['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($result['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($result['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }
        if ($notes) {
            $out .= '<div class="mrc-notes-list">';
            foreach ($notes as $note) {
                $out .= '<article class="mrc-note-item"><p>' . nl2br($this->e((string) ($note['note'] ?? ''))) . '</p>';
                $out .= '<small>' . $this->e((string) ($note['_time'] ?? '-')) . ' / ' . $this->e((string) ($note['user'] ?? 'system')) . '</small></article>';
            }
            $out .= '</div>';
        }
        $out .= '<form method="post" action="' . $this->e($this->orderDetailUrl($order)) . '" class="mrc-order-note-form">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_add_order_note" value="1">';
        $out .= '<input type="hidden" name="order_id" value="' . (int) $order->id . '">';
        $out .= '<textarea class="uk-textarea" name="order_note" rows="3" placeholder="' . $this->e($this->_('Add an internal note...')) . '"></textarea>';
        $out .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-comment-o uk-margin-small-right"></i>' . $this->e($this->_('Add note')) . '</button>';
        $out .= '</form>';
        return $out . '</section>';
    }

    protected function renderOrderCommunication(Page $order, Mercato $commerce, array $confirmationResult = [], array $statusLinkResult = []): string {
        $payment = $this->getOrderPaymentState($order);
        $canSend = in_array($payment['raw'], [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED], true);
        $sentAt = $order->hasField('mrc_confirmation_sent_date') ? trim((string) $order->mrc_confirmation_sent_date) : '';
        $count = $order->hasField('mrc_confirmation_send_count') ? (int) $order->mrc_confirmation_send_count : 0;
        $statusUrl = $commerce->getOrderStatusUrl($order);
        $receiptPdfUrl = $commerce->getOrderReceiptPdfUrl($order);
        $packingSlipPdfUrl = $commerce->getOrderPackingSlipPdfUrl($order);

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Customer Communication')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Payment confirmation receipts are sent automatically after a paid transition and can be resent here.')) . '</p></div></div>';
        if ($confirmationResult) {
            $class = !empty($confirmationResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($confirmationResult['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($confirmationResult['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }
        if ($statusLinkResult) {
            $class = !empty($statusLinkResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($statusLinkResult['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($statusLinkResult['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }
        if (!$order->hasField('mrc_confirmation_sent_date')) {
            return $out . '<p class="uk-alert uk-alert-warning">' . $this->e($this->_('Run Mercato installer/repair before sending confirmation emails.')) . '</p></section>';
        }
        $out .= '<div class="mrc-refund-summary"><strong>' . $this->e($this->_('Order confirmation')) . ': ' . $this->e($sentAt !== '' ? $this->_('Sent') : $this->_('Not sent')) . '</strong>';
        if ($sentAt !== '') {
            $out .= '<span>' . $this->e($this->_('Last sent')) . ': ' . $this->e($sentAt) . '</span>';
            $out .= '<span>' . $this->e($this->_('Send count')) . ': ' . $this->e((string) $count) . '</span>';
        }
        $out .= '</div>';
        $out .= '<div class="mrc-status-link-box">';
        $out .= '<label class="uk-form-label">' . $this->e($this->_('Public status link')) . '</label>';
        $out .= '<div class="mrc-copy-line"><input class="uk-input" type="text" readonly value="' . $this->e($statusUrl) . '"><a class="uk-button uk-button-default" href="' . $this->e($statusUrl) . '" target="_blank" rel="noopener"><i class="fa fa-external-link uk-margin-small-right"></i>' . $this->e($this->_('Open')) . '</a></div>';
        if ($receiptPdfUrl !== '') {
            $out .= '<label class="uk-form-label">' . $this->e($this->_('Receipt PDF')) . '</label>';
            $out .= '<div class="mrc-copy-line"><input class="uk-input" type="text" readonly value="' . $this->e($receiptPdfUrl) . '"><a class="uk-button uk-button-default" href="' . $this->e($receiptPdfUrl) . '" target="_blank" rel="noopener"><i class="fa fa-file-pdf-o uk-margin-small-right"></i>' . $this->e($this->_('Open PDF')) . '</a></div>';
        }
        if ($packingSlipPdfUrl !== '') {
            $out .= '<label class="uk-form-label">' . $this->e($this->_('Packing slip PDF')) . '</label>';
            $out .= '<div class="mrc-copy-line"><input class="uk-input" type="text" readonly value="' . $this->e($packingSlipPdfUrl) . '"><a class="uk-button uk-button-default" href="' . $this->e($packingSlipPdfUrl) . '" target="_blank" rel="noopener"><i class="fa fa-file-pdf-o uk-margin-small-right"></i>' . $this->e($this->_('Open PDF')) . '</a></div>';
        }
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Regenerate the link if a customer-facing status URL was shared with the wrong recipient. Old status links stop working after regeneration.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-communication-actions">';
        if ($canSend) {
            $out .= '<form method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">';
            $out .= $this->renderCsrfInput();
            $out .= '<input type="hidden" name="mrc_send_order_confirmation" value="1"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
            $out .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-envelope-o uk-margin-small-right"></i>' . $this->e($sentAt !== '' ? $this->_('Resend confirmation') : $this->_('Send confirmation')) . '</button></form>';
        } else {
            $out .= '<p class="uk-text-muted mrc-communication-note">' . $this->e($this->_('Confirmation email is available after payment is marked paid.')) . '</p>';
        }
        $out .= '<form method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_regenerate_status_link" value="1"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-refresh uk-margin-small-right"></i>' . $this->e($this->_('Regenerate status link')) . '</button></form>';
        $out .= '</div>';
        return $out . '</section>';
    }

    protected function renderPaymentOperations(Page $order, Mercato $commerce, array $actionResult = [], array $refundResult = [], array $cancelResult = []): string {
        $payment = $this->getOrderPaymentState($order);
        $isRefundable = ($payment['paid'] && $payment['raw'] !== MercatoPaymentStatus::REFUNDED)
            || $payment['raw'] === MercatoPaymentStatus::PARTIALLY_REFUNDED;
        $isCancelable = !$payment['paid']
            && MercatoPaymentStatus::isPayable((string) $payment['raw'])
            && $payment['raw'] !== MercatoPaymentStatus::CANCELED;
        $alreadyRefunded = $order->hasField('mrc_refunded_amount') ? round((float) $order->mrc_refunded_amount, 2) : 0.0;
        $pendingRefund = $order->hasField('mrc_refund_pending_amount') ? round((float) $order->mrc_refund_pending_amount, 2) : 0.0;
        $remaining = round(max(0, $this->getOrderTotal($order, $commerce) - $alreadyRefunded - $pendingRefund), 2);
        $out = '<section class="pw-wrap mrc-admin-panel mrc-payment-operations">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Payment Operations')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Use manual reconciliation only after verifying the payment in the provider dashboard or bank records.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('payments')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export payment audit')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('refunds')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export refunds')) . '</a></div></div>';

        if ($actionResult) {
            $class = !empty($actionResult['errors']) ? (!empty($actionResult['warning']) ? 'uk-alert-warning' : 'uk-alert-danger') : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($actionResult['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($actionResult['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }

        if ($refundResult) {
            $class = !empty($refundResult['errors']) ? (!empty($refundResult['warning']) ? 'uk-alert-warning' : 'uk-alert-danger') : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($refundResult['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($refundResult['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }

        if ($cancelResult) {
            $class = !empty($cancelResult['errors']) ? 'uk-alert-danger' : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($cancelResult['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($cancelResult['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }

        if (in_array($payment['raw'], [MercatoPaymentStatus::REFUND_PENDING, MercatoPaymentStatus::PARTIAL_REFUND_PENDING], true)) {
            $out .= '<p class="uk-alert uk-alert-warning">' . $this->e(sprintf($this->_('A refund of %s is awaiting gateway confirmation. Do not issue another refund until it is reconciled.'), $commerce->formatPrice($pendingRefund))) . '</p>';
            $out .= '<form method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">';
            $out .= $this->renderCsrfInput();
            $out .= '<input type="hidden" name="mrc_reconcile_refund" value="1"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
            $out .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-refresh uk-margin-small-right"></i>' . $this->e($this->_('Check refund status')) . '</button></form>';
            return $out . '</section>';
        }

        if ($isRefundable && $remaining > 0) {
            if (!$order->hasField('mrc_refunded_amount')) {
                return $out . '<p class="uk-alert uk-alert-warning">' . $this->e($this->_('Run Mercato installer/repair before issuing refunds.')) . '</p></section>';
            }
            $out .= '<div class="mrc-refund-summary"><strong>' . $this->e($this->_('Refundable balance')) . ': ' . $this->e($commerce->formatPrice($remaining)) . '</strong>';
            if ($alreadyRefunded > 0) {
                $out .= '<span>' . $this->e($this->_('Already refunded')) . ': ' . $this->e($commerce->formatPrice($alreadyRefunded)) . '</span>';
            }
            $out .= '</div>';
            $out .= '<form class="mrc-payment-reconcile-form" method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">';
            $out .= $this->renderCsrfInput();
            $out .= '<input type="hidden" name="mrc_issue_refund" value="1"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
            $out .= '<div><label class="uk-form-label" for="mrc-refund-amount-' . (int) $order->id . '">' . $this->e($this->_('Refund amount')) . '</label>';
            $out .= '<input class="uk-input" id="mrc-refund-amount-' . (int) $order->id . '" type="number" name="refund_amount" min="0.01" max="' . $this->e(number_format($remaining, 2, '.', '')) . '" step="0.01" value="' . $this->e(number_format($remaining, 2, '.', '')) . '" required></div>';
            $out .= '<div><label class="uk-form-label" for="mrc-refund-reason-' . (int) $order->id . '">' . $this->e($this->_('Refund reason')) . '</label>';
            $out .= '<textarea class="uk-textarea" id="mrc-refund-reason-' . (int) $order->id . '" name="refund_reason" rows="2" required placeholder="' . $this->e($this->_('Example: Customer requested return.')) . '"></textarea></div>';
            $out .= '<div class="mrc-payment-reconcile-action"><label class="mrc-confirm-action"><input class="uk-checkbox" type="checkbox" name="refund_confirmed" value="1" required> ' . $this->e($this->_('Submit provider refund')) . '</label>';
            $out .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-reply uk-margin-small-right"></i>' . $this->e($this->_('Issue refund')) . '</button></div></form>';
            return $out . '</section>';
        }

        if ($payment['raw'] === MercatoPaymentStatus::REFUNDED) {
            $out .= '<p class="uk-text-muted">' . $this->e($this->_('This order has been fully refunded.')) . '</p>';
            return $out . '</section>';
        }

        if ($isCancelable) {
            $out .= '<form class="mrc-payment-reconcile-form mrc-cancel-unpaid-order" method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">';
            $out .= $this->renderCsrfInput();
            $out .= '<input type="hidden" name="mrc_cancel_unpaid_order" value="1">';
            $out .= '<input type="hidden" name="order_id" value="' . (int) $order->id . '">';
            $out .= '<div><label class="uk-form-label" for="mrc-cancel-reason-' . (int) $order->id . '">' . $this->e($this->_('Cancel unpaid order')) . '</label>';
            $out .= '<textarea class="uk-textarea" id="mrc-cancel-reason-' . (int) $order->id . '" name="cancel_reason" rows="2" placeholder="' . $this->e($this->_('Optional audit note. Example: Customer abandoned checkout.')) . '"></textarea></div>';
            $out .= '<div class="mrc-payment-reconcile-action"><label class="mrc-confirm-action"><input class="uk-checkbox" type="checkbox" name="cancel_confirmed" value="1" required> ' . $this->e($this->_('Release reservations and mark this unpaid order canceled')) . '</label>';
            $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-ban uk-margin-small-right"></i>' . $this->e($this->_('Cancel unpaid order')) . '</button></div></form>';
        }

        $out .= '<form class="mrc-payment-reconcile-form" method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_reconcile_payment" value="1">';
        $out .= '<input type="hidden" name="order_id" value="' . (int) $order->id . '">';
        $out .= '<div><label class="uk-form-label" for="mrc-payment-status-' . (int) $order->id . '">' . $this->e($this->_('Verified status')) . '</label>';
        $out .= '<select class="uk-select" id="mrc-payment-status-' . (int) $order->id . '" name="payment_status">';
        foreach ([
            MercatoPaymentStatus::PAID => $this->_('Paid'),
            MercatoPaymentStatus::FAILED => $this->_('Failed'),
            MercatoPaymentStatus::CANCELED => $this->_('Canceled'),
        ] as $status => $label) {
            $out .= '<option value="' . $this->e($status) . '">' . $this->e($label) . '</option>';
        }
        $out .= '</select></div>';
        $out .= '<div><label class="uk-form-label" for="mrc-payment-reason-' . (int) $order->id . '">' . $this->e($this->_('Audit reason')) . '</label>';
        $out .= '<textarea class="uk-textarea" id="mrc-payment-reason-' . (int) $order->id . '" name="payment_reason" rows="2" required placeholder="' . $this->e($this->_('Example: Confirmed in Mollie dashboard after delayed webhook.')) . '"></textarea></div>';
        $out .= '<div class="mrc-payment-reconcile-action"><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-check uk-margin-small-right"></i>' . $this->e($this->_('Record reconciliation')) . '</button></div>';
        return $out . '</form></section>';
    }

    protected function renderOrderInfoPanel(string $title, array $rows): string {
        $out = '<section class="pw-wrap mrc-admin-panel mrc-order-info-panel">';
        $out .= '<h2 class="uk-h3">' . $this->e($title) . '</h2><dl class="mrc-detail-list">';
        foreach ($rows as $row) {
            $label = (string) ($row[0] ?? '');
            $value = trim((string) ($row[1] ?? ''));
            $url = trim((string) ($row[2] ?? ''));
            $out .= '<dt>' . $this->e($label) . '</dt><dd>';
            if ($url !== '') {
                $out .= '<a href="' . $this->e($url) . '" target="_blank" rel="noopener noreferrer">' . $this->e($value ?: $url) . '</a>';
            } else {
                $out .= $this->e($value !== '' ? $value : '-');
            }
            $out .= '</dd>';
        }
        return $out . '</dl></section>';
    }

    protected function renderOrderItemsPanel(Page $order, Mercato $commerce): string {
        $items = json_decode((string) $order->mrc_items, true);
        $items = is_array($items) ? $items : [];
        $calculatedSubtotal = 0.0;
        foreach ($items as $item) {
            if (is_array($item)) {
                $calculatedSubtotal += (float) ($item['sum'] ?? ((float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 1)));
            }
        }
        $subtotal = $order->hasField('mrc_subtotal_amount') && (float) $order->mrc_subtotal_amount > 0
            ? (float) $order->mrc_subtotal_amount
            : round($calculatedSubtotal, 2);
        $shipping = $order->hasField('mrc_shipping_amount') ? (float) $order->mrc_shipping_amount : 0.0;
        $discount = $order->hasField('mrc_discount_total') ? (float) $order->mrc_discount_total : 0.0;
        $storedTax = $commerce->taxService()->getStoredBreakdown($order);
        $taxAmount = $order->hasField('mrc_tax_amount') ? (float) $order->mrc_tax_amount : array_sum(array_column($storedTax, 'sum'));

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Items and Totals')) . '</h2></div></div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small mrc-admin-table">';
        $out .= '<thead><tr><th>' . $this->e($this->_('Product')) . '</th><th>' . $this->e($this->_('Quantity')) . '</th><th>' . $this->e($this->_('Price')) . '</th><th>' . $this->e($this->_('Tax')) . '</th><th>' . $this->e($this->_('Total')) . '</th></tr></thead><tbody>';
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $quantity = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            $tax = (float) ($item['sum_tax'] ?? $item['sumTax'] ?? 0);
            $sum = (float) ($item['sum'] ?? ($price * $quantity));
            $out .= '<tr><td><strong>' . $this->e((string) ($item['title'] ?? $item['name'] ?? '-')) . '</strong><br><small>' . $this->e((string) ($item['variant_label'] ?? '')) . (($item['variant_label'] ?? '') !== '' ? ' · ' : '') . $this->e((string) ($item['sku'] ?? $item['uid'] ?? '')) . '</small></td>';
            $out .= '<td>' . $this->e((string) $quantity) . '</td><td>' . $this->e($commerce->formatPrice($price)) . '</td><td>' . $this->e($commerce->formatPrice($tax)) . '</td><td>' . $this->e($commerce->formatPrice($sum)) . '</td></tr>';
        }
        if (!$items) {
            $out .= '<tr><td colspan="5" class="uk-text-muted">' . $this->e($this->_('No item snapshot is stored for this order.')) . '</td></tr>';
        }
        $out .= '</tbody><tfoot class="mrc-detail-totals">';
        $out .= '<tr><th colspan="4">' . $this->e($this->_('Subtotal')) . '</th><td>' . $this->e($commerce->formatPrice($subtotal)) . '</td></tr>';
        $out .= '<tr><th colspan="4">' . $this->e($this->_('Shipping')) . '</th><td>' . $this->e($commerce->formatPrice($shipping)) . '</td></tr>';
        if ($discount > 0) {
            $out .= '<tr><th colspan="4">' . $this->e($this->_('Discount')) . ' ' . $this->e((string) $order->mrc_discount_code) . '</th><td>-' . $this->e($commerce->formatPrice($discount)) . '</td></tr>';
        }
        foreach ($storedTax as $rate) {
            $label = trim((string) ($rate['jurisdiction'] ?? '')) ?: $commerce->getTaxLabel($order);
            $out .= '<tr><th colspan="4">' . $this->e($label . ' ' . rtrim(rtrim(number_format((float) ($rate['tax_rate'] ?? 0), 4, '.', ''), '0'), '.') . '%') . '</th><td>' . $this->e($commerce->formatPrice((float) ($rate['sum'] ?? 0))) . '</td></tr>';
        }
        if (!$storedTax && $taxAmount > 0) {
            $out .= '<tr><th colspan="4">' . $this->e($commerce->getTaxLabel($order)) . '</th><td>' . $this->e($commerce->formatPrice($taxAmount)) . '</td></tr>';
        }
        $out .= '<tr class="mrc-detail-total"><th colspan="4">' . $this->e($this->_('Total')) . '</th><td>' . $this->e($commerce->formatPrice($this->getOrderTotal($order, $commerce))) . '</td></tr>';
        return $out . '</tfoot></table></div></section>';
    }

    protected function renderShippingProviderPanel(Page $order, Mercato $commerce, array $actionResult = []): string {
        $details = json_decode((string) ($order->mrc_fulfilment_details ?? ''), true);
        $details = is_array($details) ? $details : [];
        $quote = (array) ($details['shipping_provider_quote'] ?? []);
        if (!$quote) return '';
        $state = (array) ($details['provider_shipping'] ?? []);
        $shipment = (array) ($state['shipment'] ?? []); $label = (array) ($state['label'] ?? []); $void = (array) ($state['void'] ?? []);
        $rate = (array) ($quote['rate'] ?? []);
        $out = '<section class="pw-wrap mrc-admin-panel"><div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Shipping Provider')) . '</h2><p class="uk-text-muted">' . $this->e($this->_('Provider references and lifecycle audit. Sensitive label URLs are restricted to this authorized order view.')) . '</p></div></div>';
        $out .= '<dl class="mrc-detail-list"><dt>' . $this->e($this->_('Provider')) . '</dt><dd>' . $this->e((string) ($quote['provider'] ?? '-')) . '</dd><dt>' . $this->e($this->_('Service')) . '</dt><dd>' . $this->e((string) ($rate['label'] ?? $rate['service'] ?? '-')) . '</dd><dt>' . $this->e($this->_('Rate reference')) . '</dt><dd>' . $this->e((string) ($rate['provider_reference'] ?? '-')) . '</dd><dt>' . $this->e($this->_('Shipment reference')) . '</dt><dd>' . $this->e((string) ($shipment['shipment_reference'] ?? '-')) . '</dd><dt>' . $this->e($this->_('Label reference')) . '</dt><dd>' . $this->e((string) ($label['label_reference'] ?? '-')) . '</dd><dt>' . $this->e($this->_('Tracking events')) . '</dt><dd>' . count((array) ($state['tracking_events'] ?? [])) . '</dd></dl>';
        $out .= '<div class="mrc-panel-actions">';
        if (!$label && !$void) $out .= $this->renderShippingProviderActionForm($order, 'purchase_label', $this->_('Purchase label'));
        if ($label && !$void) { $out .= $this->renderShippingProviderActionForm($order, 'reprint_label', $this->_('Refresh label')); if (!empty($label['label_url'])) $out .= '<a class="uk-button uk-button-primary" href="' . $this->e((string) $label['label_url']) . '" target="_blank" rel="noopener noreferrer">' . $this->e($this->_('Open / reprint label')) . '</a>'; $out .= $this->renderShippingProviderActionForm($order, 'void_label', $this->_('Void and refund label')); }
        $out .= '</div>';
        if (!empty($state['tracking_events'])) {
            $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small"><thead><tr><th>' . $this->e($this->_('Event')) . '</th><th>' . $this->e($this->_('Provider status')) . '</th><th>' . $this->e($this->_('Mercato status')) . '</th><th>' . $this->e($this->_('Time')) . '</th></tr></thead><tbody>';
            foreach (array_reverse((array) $state['tracking_events']) as $event) $out .= '<tr><td>' . $this->e((string) ($event['event_id'] ?? '')) . '</td><td>' . $this->e((string) ($event['provider_status'] ?? '')) . '</td><td>' . $this->e((string) ($event['status'] ?? '')) . '</td><td>' . $this->e((string) ($event['at'] ?? '')) . '</td></tr>';
            $out .= '</tbody></table></div>';
        }
        $out .= '</section>';
        return $out;
    }

    protected function renderPaymentReconciliationAuditPanel(Page $order, Mercato $commerce): string {
        $attempts = array_values(array_filter($this->getPaymentAttemptEvents(10000), static fn(array $event): bool => (int) ($event['order_page_id'] ?? 0) === (int) $order->id));
        $audit = $commerce->paymentReconciliationAuditService()->inspect($order, $attempts);
        $issues = (array) ($audit['issues'] ?? []); $remote = (array) ($audit['remote'] ?? []);
        $out = '<section class="pw-wrap mrc-admin-panel"><div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Payment Reconciliation Audit')) . '</h2><p class="uk-text-muted">' . $this->e($this->_('Compare local finalization, attempts, refunds, and the last explicitly verified remote state.')) . '</p></div></div>';
        $out .= '<dl class="mrc-detail-list"><dt>' . $this->e($this->_('Local status')) . '</dt><dd>' . $this->e((string) $audit['local_status']) . '</dd><dt>' . $this->e($this->_('Remote status')) . '</dt><dd>' . $this->e((string) $audit['remote_status']) . '</dd><dt>' . $this->e($this->_('Remote verified')) . '</dt><dd>' . $this->e((string) ($remote['verified_at'] ?? 'Never')) . '</dd><dt>' . $this->e($this->_('Mismatch states')) . '</dt><dd>' . $this->e($issues ? implode(', ', $issues) : $this->_('None detected')) . '</dd></dl>';
        $out .= '<form method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->renderCsrfInput() . '<input type="hidden" name="mrc_payment_audit_action" value="verify_remote"><button class="uk-button uk-button-default" type="submit">' . $this->e($this->_('Verify remote state')) . '</button></form>';
        $repairActions = [];
        if (in_array('paid_unfinalized', $issues, true)) $repairActions[(string) $audit['local_status'] === MercatoPaymentStatus::PAID ? 'replay_finalization' : 'apply_remote_paid'] = $this->_('Finalize verified payment');
        if (in_array('refund_mismatch', $issues, true)) $repairActions['reconcile_refund'] = $this->_('Reconcile pending refund');
        if ($repairActions) {
            $out .= '<form class="mrc-payment-reconcile-form" method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->renderCsrfInput() . '<label><span>' . $this->e($this->_('Repair action')) . '</span><select class="uk-select" name="mrc_payment_audit_action">';
            foreach ($repairActions as $key => $label) $out .= '<option value="' . $this->e($key) . '">' . $this->e($label) . '</option>';
            $out .= '</select></label><label><span>' . $this->e($this->_('Reason')) . '</span><textarea class="uk-textarea" name="repair_reason" required></textarea></label><label><input class="uk-checkbox" type="checkbox" name="repair_confirmed" value="1" required> ' . $this->e($this->_('I verified the provider state and want to run this idempotent repair.')) . '</label><button class="uk-button uk-button-primary" type="submit">' . $this->e($this->_('Run repair')) . '</button></form>';
        }
        return $out . '</section>';
    }

    protected function renderShippingProviderActionForm(Page $order, string $action, string $label): string {
        return '<form method="post" action="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->renderCsrfInput() . '<input type="hidden" name="mrc_shipping_provider_action" value="' . $this->e($action) . '"><button class="uk-button uk-button-default" type="submit">' . $this->e($label) . '</button></form>';
    }

    protected function renderOrderLatestActivity(array $events): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Latest Activity')) . '</h2></div></div>';
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small mrc-admin-table"><thead><tr>';
        foreach ([$this->_('Time'), $this->_('Area'), $this->_('Event'), $this->_('Details')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach (array_slice($events, 0, 6) as $event) {
            $out .= '<tr><td>' . $this->e((string) ($event['time'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e(ucfirst((string) ($event['type'] ?? '-'))) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $this->e((string) ($event['class'] ?? 'is-pending')) . '">' . $this->e(ucfirst(str_replace('_', ' ', (string) ($event['label'] ?? '-')))) . '</span></td>';
            $out .= '<td>' . $this->renderTimelineEventDetails($event) . '</td></tr>';
        }
        if (!$events) {
            $out .= '<tr><td colspan="4" class="uk-text-muted">' . $this->e($this->_('No activity is logged for this order.')) . '</td></tr>';
        }
        return $out . '</tbody></table></div></section>';
    }

    protected function renderTimelineEventDetails(array $event): string {
        $details = trim((string) ($event['details'] ?? ''));
        $out = $details !== '' ? '<span>' . $this->e($details) . '</span>' : '<span class="uk-text-muted">-</span>';
        $meta = is_array($event['meta'] ?? null) ? (array) $event['meta'] : [];
        $redacted = $this->redactSensitiveDebugData($meta);
        $redacted = is_array($redacted) ? array_filter($redacted, static function ($value): bool {
            if ($value === '' || $value === null) return false;
            if (is_array($value) && count($value) === 0) return false;
            return true;
        }) : [];
        if ($redacted) {
            $json = (string) json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $out .= '<details class="mrc-timeline-context"><summary>' . $this->e($this->_('Context')) . '</summary><pre>' . $this->e($json) . '</pre></details>';
        }
        return $out;
    }

    protected function redactSensitiveDebugData(mixed $value): mixed {
        return (new MercatoEventLog())->redact($value);
    }

    protected function renderOrdersTable($orders, Mercato $commerce, bool $includeGateway = false): string {
        $headings = $includeGateway
            ? [$this->_('Invoice'), $this->_('Customer'), $this->_('Items'), $this->_('Total'), $this->_('Payment'), $this->_('Inventory'), $this->_('Fulfilment'), $this->_('Gateway'), $this->_('Created'), '']
            : [$this->_('Invoice'), $this->_('Customer'), $this->_('Total'), $this->_('Payment'), $this->_('Created'), ''];

        if (!$orders->count()) {
            $out = '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-small mrc-admin-table">';
            $out .= '<thead><tr>';
            foreach ($headings as $heading) {
                $out .= '<th>' . $this->e($heading) . '</th>';
            }
            $out .= '</tr></thead><tbody>';
            $out .= $this->renderSkeletonRows(4, count($headings));
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No orders yet.')) . '</p>';
        }

        $out = '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ($headings as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        foreach ($orders as $order) {
            $state = $this->getOrderPaymentState($order);
            $customer = $this->getOrderCustomer($order);

            $out .= '<tr>';
            $out .= '<td><a href="' . $this->e($this->orderDetailUrl($order)) . '"><strong>' . $this->e($order->mrc_invoice_number ?: $order->title) . '</strong></a></td>';
            $out .= '<td>' . $this->e($customer ?: '-') . '<br><small>' . $this->e((string) $order->mrc_email) . '</small></td>';
            if ($includeGateway) {
                $out .= '<td>' . $this->e((string) $this->getOrderItemCount($order)) . '</td>';
            }
            $out .= '<td>' . $this->e($commerce->formatPrice($this->getOrderTotal($order, $commerce))) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $state['class'] . '">' . $this->e($state['label']) . '</span>';
            $failureSummary = $this->getOrderPaymentFailureSummary($order);
            if ($failureSummary !== '') {
                $out .= '<br><small class="mrc-payment-failure-summary"><strong>' . $this->e($this->_('Failure reason')) . ':</strong><br>' . nl2br($this->e($failureSummary), false) . '</small>';
            }
            $out .= '</td>';
            if ($includeGateway) {
                $inventory = $this->getOrderInventoryState($order);
                $out .= '<td><span class="uk-label mrc-admin-status ' . $inventory['class'] . '">' . $this->e($inventory['label']) . '</span></td>';
                $fulfilment = $this->getOrderFulfilmentState($order);
                $out .= '<td><span class="uk-label mrc-admin-status ' . $fulfilment['class'] . '">' . $this->e($fulfilment['label']) . '</span></td>';
                $out .= '<td>' . $this->e($this->getOrderGatewayLabel($order)) . '</td>';
            }
            $out .= '<td>' . $this->e(date('Y-m-d H:i', (int) $order->created)) . '</td>';
            $out .= '<td class="mrc-table-actions">';
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->orderDetailUrl($order)) . '"><i class="fa fa-file-text-o uk-margin-small-right"></i>' . $this->e($this->_('Detail')) . '</a>';
            if ($includeGateway) {
                $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->manualOrderFromOrderUrl($order)) . '"><i class="fa fa-repeat uk-margin-small-right"></i>' . $this->e($this->_('Repeat')) . '</a>';
            }
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->timelineUrl($order)) . '"><i class="fa fa-clock-o uk-margin-small-right"></i>' . $this->e($this->_('Timeline')) . '</a>';
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->editUrl($order)) . '"><i class="fa fa-pencil uk-margin-small-right"></i>' . $this->e($this->_('Edit')) . '</a></td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div>';
        return $out;
    }
}
