<?php
namespace ProcessWire;

trait ProcessMercatoPaymentRecoveryActions {

    protected function handlePaymentReconciliation(Mercato $commerce, Page $order): array {
        if ((string) $this->wire('input')->post->text('mrc_reconcile_payment') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_EDIT_ORDERS)) {
            return $this->permissionError(self::PERMISSION_EDIT_ORDERS, $this->_('Payment reconciliation was blocked.'));
        }

        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Payment reconciliation was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $postedOrderId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id'));
        if ($postedOrderId !== (int) $order->id) {
            return [
                'summary' => $this->_('Payment reconciliation failed.'),
                'errors' => [$this->_('Order does not match this detail screen.')],
            ];
        }

        $targetStatus = strtolower(trim((string) $this->wire('input')->post->text('payment_status')));
        $reason = trim((string) $this->wire('input')->post->textarea('payment_reason'));
        $service = new MercatoPaymentReconciliationService($commerce);
        $service->setWire($this->wire());

        try {
            $result = $service->reconcile($order, $targetStatus, $reason, (string) ($this->wire('user')->name ?? ''));
            $summary = sprintf(
                $this->_('Payment status changed from %s to %s.'),
                ucfirst(str_replace('_', ' ', (string) $result['from'])),
                ucfirst(str_replace('_', ' ', (string) $result['to']))
            );
            $errors = (array) ($result['inventory_errors'] ?? []);
            if ($errors) {
                $summary .= ' ' . $this->_('Payment was recorded, but inventory needs attention.');
            }
            $this->wire('session')->message($summary);
            return [
                'summary' => $summary,
                'errors' => $errors,
                'warning' => (bool) $errors,
            ];
        } catch (\Throwable $e) {
            return [
                'summary' => $this->_('Payment reconciliation failed.'),
                'errors' => [$e->getMessage()],
            ];
        }
    }

    protected function handleUnpaidOrderCancellation(Mercato $commerce, Page $order): array {
        if ((string) $this->wire('input')->post->text('mrc_cancel_unpaid_order') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_EDIT_ORDERS)) {
            return $this->permissionError(self::PERMISSION_EDIT_ORDERS, $this->_('Order cancellation was blocked.'));
        }

        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Order cancellation was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $postedOrderId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id'));
        if ($postedOrderId !== (int) $order->id) {
            return [
                'summary' => $this->_('Order cancellation failed.'),
                'errors' => [$this->_('Order does not match this detail screen.')],
            ];
        }

        if ((string) $this->wire('input')->post->text('cancel_confirmed') !== '1') {
            return [
                'summary' => $this->_('Order cancellation failed.'),
                'errors' => [$this->_('Confirm that this unpaid order should be canceled.')],
            ];
        }

        $reason = trim((string) $this->wire('input')->post->textarea('cancel_reason'));
        if ($reason === '') {
            $reason = $this->_('Merchant canceled unpaid order from Order Detail.');
        }

        return $this->cancelUnpaidOrder($commerce, $order, $reason);
    }

    protected function cancelUnpaidOrder(Mercato $commerce, Page $order, string $reason): array {
        if (!$this->isUnpaidOrderCancelable($order)) {
            return [
                'summary' => $this->_('Order cancellation failed.'),
                'errors' => [$this->_('Only unpaid payable orders can be canceled from this action.')],
            ];
        }

        $service = new MercatoPaymentReconciliationService($commerce);
        $service->setWire($this->wire());

        try {
            $result = $service->reconcile($order, MercatoPaymentStatus::CANCELED, $reason, (string) ($this->wire('user')->name ?? ''));
            $summary = sprintf(
                $this->_('Canceled unpaid order %s and released any active reservation.'),
                (string) ($order->mrc_invoice_number ?: $order->title)
            );
            $this->wire('session')->message($summary);
            return [
                'summary' => $summary,
                'errors' => (array) ($result['inventory_errors'] ?? []),
            ];
        } catch (\Throwable $e) {
            return [
                'summary' => $this->_('Order cancellation failed.'),
                'errors' => [$e->getMessage()],
            ];
        }
    }

    protected function isUnpaidOrderCancelable(Page $order): bool {
        if (!$order || !$order->id) {
            return false;
        }
        $status = strtolower(trim((string) ($order->mrc_payment_status ?: MercatoPaymentStatus::PENDING)));
        return (int) $order->mrc_payment_complete === 0
            && MercatoPaymentStatus::isPayable($status)
            && $status !== MercatoPaymentStatus::CANCELED;
    }

    protected function handleRecoveryOrderCancellation(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_cancel_recovery_order') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_RECOVERY)) {
            return $this->permissionError(self::PERMISSION_MANAGE_RECOVERY, $this->_('Recovery order cancellation was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Recovery order cancellation was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $orderId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id'));
        $order = $this->wire('pages')->get($orderId);
        if (!$order || !$order->id || $order->template->name !== $commerce->order_template) {
            return ['summary' => $this->_('Recovery order cancellation failed.'), 'errors' => [$this->_('Order not found.')]];
        }

        $result = $this->cancelUnpaidOrder($commerce, $order, $this->_('Merchant canceled abandoned checkout from Recovery.'));
        if (empty($result['errors'])) {
            $this->recordRecoveryEvent($order, 'canceled', 'Order canceled from Recovery.', [
                'source' => 'operator',
            ]);
        }
        return $result;
    }

    protected function handleRecoveryBulkOrderCancellation(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_bulk_cancel_recovery_orders') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_RECOVERY)) {
            return $this->permissionError(self::PERMISSION_MANAGE_RECOVERY, $this->_('Bulk recovery cancellation was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Bulk recovery cancellation was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }
        if ((string) $this->wire('input')->post->text('bulk_cancel_confirmed') !== '1') {
            return [
                'summary' => $this->_('Bulk recovery cancellation failed.'),
                'errors' => [$this->_('Confirm that the filtered abandoned orders should be canceled.')],
            ];
        }

        $filters = $this->getRequestedRecoveryFilters();
        $rows = $this->getAbandonedCheckouts($commerce, $filters);
        $cancelable = $this->getCancelableRecoveryOrders($rows);
        $limit = 25;
        $canceled = 0;
        $errors = [];

        foreach (array_slice($cancelable, 0, $limit) as $order) {
            $result = $this->cancelUnpaidOrder($commerce, $order, $this->_('Merchant bulk-canceled abandoned checkout from Recovery.'));
            if (!empty($result['errors'])) {
                $errors[] = sprintf('%s: %s', (string) ($order->mrc_invoice_number ?: $order->title), implode('; ', (array) $result['errors']));
                continue;
            }
            $this->recordRecoveryEvent($order, 'canceled', 'Order bulk-canceled from Recovery.', [
                'source' => 'operator_bulk',
            ]);
            $canceled++;
        }

        $summary = sprintf($this->_('Canceled %d abandoned order(s).'), $canceled);
        if (count($cancelable) > $limit) {
            $summary .= ' ' . sprintf($this->_('Limited to %d orders per run.'), $limit);
        }
        if ($canceled > 0) {
            $this->wire('session')->message($summary);
        }

        return [
            'summary' => $summary,
            'errors' => $errors,
            'warning' => (bool) $errors,
        ];
    }

    protected function handleWebhookSimulation(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_simulate_webhook') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_WEBHOOKS)) {
            return $this->permissionError(self::PERMISSION_MANAGE_WEBHOOKS, $this->_('Webhook simulation was blocked.'));
        }

        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Webhook simulation was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $orderId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id'));
        $order = $this->wire('pages')->get($orderId);
        $gateway = strtolower(trim((string) $this->wire('input')->post->text('gateway')));
        $targetStatus = strtolower(trim((string) $this->wire('input')->post->text('payment_status')));

        try {
            $result = $commerce->webhookService()->simulatePaymentStatus(
                $order,
                $gateway,
                $targetStatus,
                (string) ($this->wire('user')->name ?? '')
            );
            $updated = $result['order'];
            $summary = sprintf(
                $this->_('Simulated %s webhook for order %s: %s → %s.'),
                ucfirst((string) $result['gateway']),
                (string) ($updated->mrc_invoice_number ?: $updated->title),
                ucfirst(str_replace('_', ' ', (string) $result['from'])),
                ucfirst(str_replace('_', ' ', (string) $result['to']))
            );
            $errors = (array) ($result['inventory']['errors'] ?? []);
            if ($errors) {
                $summary .= ' ' . $this->_('Inventory needs attention.');
            }
            $this->wire('session')->message($summary);
            return [
                'summary' => $summary,
                'errors' => $errors,
                'warning' => (bool) $errors,
            ];
        } catch (\Throwable $e) {
            return [
                'summary' => $this->_('Webhook simulation failed.'),
                'errors' => [$e->getMessage()],
            ];
        }
    }

    protected function handleRefund(Mercato $commerce, Page $order): array {
        $issueRefund = (string) $this->wire('input')->post->text('mrc_issue_refund') === '1';
        $reconcileRefund = (string) $this->wire('input')->post->text('mrc_reconcile_refund') === '1';
        if (!$issueRefund && !$reconcileRefund) {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_REFUND_ORDERS)) {
            return $this->permissionError(self::PERMISSION_REFUND_ORDERS, $this->_('Refund was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Refund was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }
        $postedOrderId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id'));
        if ($postedOrderId !== (int) $order->id) {
            return ['summary' => $this->_('Refund failed.'), 'errors' => [$this->_('Order does not match this detail screen.')]];
        }
        if ($issueRefund && (string) $this->wire('input')->post->text('refund_confirmed') !== '1') {
            return ['summary' => $this->_('Refund failed.'), 'errors' => [$this->_('Confirm that this provider refund should be submitted.')]];
        }

        $service = new MercatoRefundService($commerce);
        $service->setWire($this->wire());

        try {
            if ($reconcileRefund) {
                $result = $service->reconcilePending($order, (string) ($this->wire('user')->name ?? ''));
                if (!empty($result['pending'])) {
                    $summary = sprintf(
                        $this->_('Refund of %s is still awaiting gateway confirmation.'),
                        $commerce->formatPrice((float) $result['amount'])
                    );
                } elseif (!empty($result['rejected'])) {
                    $summary = $this->_('The gateway rejected the pending refund. Payment status was restored.');
                } else {
                    $summary = sprintf(
                        $this->_('Gateway confirmed refund of %s. Payment status is now %s.'),
                        $commerce->formatPrice((float) $result['amount']),
                        ucfirst(str_replace('_', ' ', (string) $result['status']))
                    );
                }
            } else {
                $amount = (float) str_replace(',', '.', trim((string) $this->wire('input')->post('refund_amount')));
                $reason = trim((string) $this->wire('input')->post->textarea('refund_reason'));
                $result = $service->refund($order, $amount, $reason, (string) ($this->wire('user')->name ?? ''));
                $summary = sprintf(
                    in_array((string) $result['status'], [MercatoPaymentStatus::REFUND_PENDING, MercatoPaymentStatus::PARTIAL_REFUND_PENDING], true)
                        ? $this->_('Requested refund of %s. Payment status is now %s.')
                        : $this->_('Refunded %s. Payment status is now %s.'),
                    $commerce->formatPrice((float) $result['amount']),
                    ucfirst(str_replace('_', ' ', (string) $result['status']))
                );
            }
            $errors = (array) ($result['inventory']['errors'] ?? []);
            if ($errors) {
                $summary .= ' ' . $this->_('Refund succeeded, but stock restoration requires attention.');
            }
            $this->wire('session')->message($summary);
            return ['summary' => $summary, 'errors' => $errors, 'warning' => (bool) $errors];
        } catch (\Throwable $e) {
            return ['summary' => $this->_('Refund failed.'), 'errors' => [$e->getMessage()]];
        }
    }

    protected function handleShippingNotification(Mercato $commerce): array {
        if (
            (string) $this->wire('input')->post->text('mrc_send_shipping_notification') !== '1'
            && (string) $this->wire('input')->post->text('mrc_send_fulfilment_notification') !== '1'
        ) {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_FULFIL_ORDERS)) {
            return $this->permissionError(self::PERMISSION_FULFIL_ORDERS, $this->_('Fulfilment email was blocked.'));
        }

        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Fulfilment email was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $orderId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id'));
        $order = $this->wire('pages')->get($orderId);
        if (!$order || !$order->id || $order->template->name !== $commerce->order_template) {
            return [
                'summary' => $this->_('Fulfilment email failed.'),
                'errors' => [$this->_('Order not found.')],
            ];
        }

        $status = $order->hasField('mrc_fulfilment_status') ? (string) $order->mrc_fulfilment_status : '';
        $method = $this->getOrderFulfilmentMethod($order);
        $service = new MercatoShippingNotificationService($commerce);
        $service->setWire($this->wire());
        if ($method === MercatoFulfilmentMethodType::CARRIER_DELIVERY && $status === MercatoFulfilmentStatus::SHIPPED) {
            $result = $service->sendShippingNotification($order);
            $sentLabel = $this->_('shipping');
        } elseif ($method === MercatoFulfilmentMethodType::STORE_PICKUP && $status === MercatoFulfilmentStatus::READY_FOR_PICKUP) {
            $result = $service->sendPickupReadyNotification($order);
            $sentLabel = $this->_('pickup-ready');
        } elseif ($method === MercatoFulfilmentMethodType::LOCAL_DELIVERY && $status === MercatoFulfilmentStatus::OUT_FOR_DELIVERY) {
            $result = $service->sendLocalDeliveryNotification($order);
            $sentLabel = $this->_('local-delivery');
        } else {
            return [
                'summary' => $this->_('Fulfilment email failed.'),
                'errors' => [$this->_('Set the appropriate customer-facing fulfilment status before sending an email.')],
            ];
        }

        $success = ($result['status'] ?? '') === 'sent';
        $summary = $success
            ? sprintf($this->_('Sent %s email for %s.'), $sentLabel, (string) ($order->mrc_invoice_number ?: $order->title))
            : $this->_('Fulfilment email failed.');
        if ($success) {
            $this->wire('session')->message($summary);
        }
        return [
            'summary' => $summary,
            'errors' => $success ? [] : [(string) ($result['message'] ?? $this->_('Unknown mail error.'))],
        ];
    }

    protected function handleOrderConfirmation(Mercato $commerce, Page $order): array {
        if ((string) $this->wire('input')->post->text('mrc_send_order_confirmation') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_EDIT_ORDERS)) {
            return $this->permissionError(self::PERMISSION_EDIT_ORDERS, $this->_('Confirmation email was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Confirmation email was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }
        if ((int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id')) !== (int) $order->id) {
            return ['summary' => $this->_('Confirmation email failed.'), 'errors' => [$this->_('Order does not match this detail screen.')]];
        }

        $service = new MercatoOrderConfirmationService($commerce);
        $service->setWire($this->wire());
        $result = $service->send($order, true);
        $success = ($result['status'] ?? '') === 'sent';
        $summary = $success
            ? sprintf($this->_('Sent order confirmation for %s.'), (string) ($order->mrc_invoice_number ?: $order->title))
            : $this->_('Confirmation email failed.');
        if ($success) {
            $this->wire('session')->message($summary);
        }
        return [
            'summary' => $summary,
            'errors' => $success ? [] : [(string) ($result['message'] ?? $this->_('Unknown mail error.'))],
        ];
    }

    protected function handleOrderStatusLinkRegeneration(Mercato $commerce, Page $order): array {
        if ((string) $this->wire('input')->post->text('mrc_regenerate_status_link') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_EDIT_ORDERS)) {
            return $this->permissionError(self::PERMISSION_EDIT_ORDERS, $this->_('Status link reset was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Status link reset was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }
        if ((int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id')) !== (int) $order->id) {
            return ['summary' => $this->_('Status link reset failed.'), 'errors' => [$this->_('Order does not match this detail screen.')]];
        }

        try {
            $commerce->regenerateOrderStatusTokenSeed($order);
        } catch (\Throwable $e) {
            return ['summary' => $this->_('Status link reset failed.'), 'errors' => [$e->getMessage()]];
        }

        $summary = sprintf($this->_('Generated a new public status link for %s.'), (string) ($order->mrc_invoice_number ?: $order->title));
        $this->wire('session')->message($summary);
        return ['summary' => $summary, 'errors' => []];
    }


    protected function handlePaymentLinkEmail(Mercato $commerce, Page $order): array {
        if ((string) $this->wire('input')->post->text('mrc_send_payment_link') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_EDIT_ORDERS)) {
            return $this->permissionError(self::PERMISSION_EDIT_ORDERS, $this->_('Payment link email was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Payment link email was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }
        if ((int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id')) !== (int) $order->id) {
            return ['summary' => $this->_('Payment link email failed.'), 'errors' => [$this->_('Order does not match this detail screen.')]];
        }

        $service = new MercatoPaymentLinkService($commerce);
        $service->setWire($this->wire());
        $result = $service->send($order);
        $success = ($result['status'] ?? '') === 'sent';
        $summary = $success
            ? sprintf($this->_('Sent payment link for %s.'), (string) ($order->mrc_invoice_number ?: $order->title))
            : $this->_('Payment link email failed.');
        if ($success) {
            $this->wire('session')->message($summary);
        }
        return [
            'summary' => $summary,
            'errors' => $success ? [] : [(string) ($result['message'] ?? $this->_('Unknown mail error.'))],
        ];
    }

    protected function handleRecoveryPaymentLinkEmail(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_send_recovery_link') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_RECOVERY)) {
            return $this->permissionError(self::PERMISSION_MANAGE_RECOVERY, $this->_('Recovery email was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Recovery email was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $orderId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id'));
        $order = $this->wire('pages')->get($orderId);
        if (!$order || !$order->id || $order->template->name !== $commerce->order_template) {
            return ['summary' => $this->_('Recovery email failed.'), 'errors' => [$this->_('Order not found.')]];
        }
        if (!empty($this->getOrderPaymentState($order)['paid'])) {
            $this->recordRecoveryEvent($order, 'skipped', 'Order is already paid.');
            return ['summary' => $this->_('Recovery email skipped.'), 'errors' => [$this->_('Order is already paid.')]];
        }
        $latestEmails = $this->getLatestRecoverySentEventsByOrder() + $this->getLatestPaymentLinkEmailsByOrder();
        $eligibility = $this->getRecoveryEmailEligibility($order, (array) ($latestEmails[(int) $order->id] ?? []));
        if (empty($eligibility['allowed'])) {
            $this->recordRecoveryEvent($order, 'blocked', (string) ($eligibility['reason'] ?? $this->_('This order is not currently eligible for recovery email.')), [
                'cooldown_minutes' => (int) ($eligibility['cooldown_minutes'] ?? 0),
            ]);
            return [
                'summary' => $this->_('Recovery email skipped.'),
                'errors' => [(string) ($eligibility['reason'] ?? $this->_('This order is not currently eligible for recovery email.'))],
            ];
        }

        $service = new MercatoPaymentLinkService($commerce);
        $service->setWire($this->wire());
        $result = $service->send($order);
        $success = ($result['status'] ?? '') === 'sent';
        $summary = $success
            ? sprintf($this->_('Sent recovery payment link for %s.'), (string) ($order->mrc_invoice_number ?: $order->title))
            : $this->_('Recovery email failed.');
        if ($success) {
            $this->wire('session')->message($summary);
        }
        $this->recordRecoveryEvent($order, $success ? 'sent' : 'failed', (string) ($result['message'] ?? $summary), [
            'recipient' => (string) ($result['recipient'] ?? $order->mrc_email),
        ]);
        return [
            'summary' => $summary,
            'errors' => $success ? [] : [(string) ($result['message'] ?? $this->_('Unknown mail error.'))],
        ];
    }

    protected function handleRecoverySuppressEmail(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_suppress_recovery_email') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_RECOVERY)) {
            return $this->permissionError(self::PERMISSION_MANAGE_RECOVERY, $this->_('Recovery suppression was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Recovery suppression was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $orderId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id'));
        $order = $this->wire('pages')->get($orderId);
        if (!$order || !$order->id || $order->template->name !== $commerce->order_template) {
            return ['summary' => $this->_('Recovery suppression failed.'), 'errors' => [$this->_('Order not found.')]];
        }

        $email = strtolower((string) $this->wire('sanitizer')->email((string) $order->mrc_email));
        if ($email === '') {
            return ['summary' => $this->_('Recovery suppression failed.'), 'errors' => [$this->_('Order has no valid customer email.')]];
        }
        if ($commerce->isRecoveryEmailSuppressed($email)) {
            return ['summary' => sprintf($this->_('%s is already suppressed for recovery.'), $email), 'errors' => []];
        }

        $modules = $this->wire('modules');
        $config = array_merge(Mercato::getDefaultConfig(), (array) $modules->getConfig('Mercato'));
        $suppressed = $commerce->getRecoverySuppressedEmails();
        $suppressed[] = $email;
        $suppressed = array_values(array_unique($suppressed));
        sort($suppressed, SORT_STRING);
        $config['recovery_suppressed_emails'] = implode("\n", $suppressed);

        if (!$modules->saveConfig('Mercato', $config)) {
            return [
                'summary' => $this->_('Recovery suppression failed.'),
                'errors' => [$this->_('ProcessWire rejected the module configuration update.')],
            ];
        }
        $commerce->set('recovery_suppressed_emails', $config['recovery_suppressed_emails']);
        $this->recordRecoveryEvent($order, 'suppressed', 'Email suppressed from recovery.', [
            'recipient' => $email,
            'source' => 'operator',
        ]);

        $summary = sprintf($this->_('Suppressed %s from recovery emails.'), $email);
        $this->wire('session')->message($summary);
        return ['summary' => $summary, 'errors' => []];
    }

    protected function handleRecoveryUnsuppressEmail(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_unsuppress_recovery_email') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_RECOVERY)) {
            return $this->permissionError(self::PERMISSION_MANAGE_RECOVERY, $this->_('Recovery unsuppression was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Recovery unsuppression was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $email = strtolower((string) $this->wire('sanitizer')->email((string) $this->wire('input')->post->text('email')));
        if ($email === '') {
            return ['summary' => $this->_('Recovery unsuppression failed.'), 'errors' => [$this->_('Invalid email address.')]];
        }

        $suppressed = $commerce->getRecoverySuppressedEmails();
        if (!in_array($email, $suppressed, true)) {
            return ['summary' => sprintf($this->_('%s is not suppressed for recovery.'), $email), 'errors' => []];
        }

        $suppressed = array_values(array_filter($suppressed, static fn(string $item): bool => $item !== $email));
        sort($suppressed, SORT_STRING);

        $modules = $this->wire('modules');
        $config = array_merge(Mercato::getDefaultConfig(), (array) $modules->getConfig('Mercato'));
        $config['recovery_suppressed_emails'] = implode("\n", $suppressed);
        if (!$modules->saveConfig('Mercato', $config)) {
            return [
                'summary' => $this->_('Recovery unsuppression failed.'),
                'errors' => [$this->_('ProcessWire rejected the module configuration update.')],
            ];
        }

        $commerce->set('recovery_suppressed_emails', $config['recovery_suppressed_emails']);
        $this->recordRecoverySuppressionEvent('unsuppressed', $email, 'Email removed from recovery suppression.');

        $summary = sprintf($this->_('Removed %s from recovery suppression.'), $email);
        $this->wire('session')->message($summary);
        return ['summary' => $summary, 'errors' => []];
    }

    protected function handleRecoveryAutomationPreview(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_preview_recovery_automation') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_RECOVERY)) {
            return $this->permissionError(self::PERMISSION_MANAGE_RECOVERY, $this->_('Recovery automation preview was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Recovery automation preview was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $preview = $commerce->recoveryService()->run([
            'dry_run' => true,
            'force' => true,
            'limit' => $commerce->getRecoveryAutomationBatchLimit(),
        ]);

        return [
            'summary' => sprintf(
                $this->_('Recovery automation preview: %d eligible order(s), %d checked.'),
                (int) ($preview['eligible'] ?? 0),
                (int) ($preview['checked'] ?? 0)
            ),
            'errors' => [],
            'automation_preview' => $preview,
        ];
    }

    protected function recordRecoveryEvent(Page $order, string $status, string $message, array $context = []): void {
        $event = array_merge([
            'event' => 'recovery_email',
            'status' => $status,
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'email' => (string) $order->mrc_email,
            'message' => $message,
            'user' => (string) $this->wire('user')->name,
            'recovery_discount_code' => $this->getRecoveryDiscountCode(),
        ], $context);
        $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->wire('log')->save('mercato-recovery', $encoded ?: $status);
    }

    protected function recordRecoverySuppressionEvent(string $status, string $email, string $message): void {
        $event = [
            'event' => 'recovery_email',
            'status' => $status,
            'order_id' => 0,
            'invoice' => '',
            'email' => $email,
            'recipient' => $email,
            'message' => $message,
            'user' => (string) $this->wire('user')->name,
            'source' => 'operator',
            'recovery_discount_code' => $this->getRecoveryDiscountCode(),
        ];
        $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->wire('log')->save('mercato-recovery', $encoded ?: $status);
    }

    protected function getRecoveryDiscountCode(): string {
        $commerce = $this->wire('modules')->get('Mercato');
        $code = $commerce instanceof Mercato ? strtoupper(trim((string) ($commerce->recovery_discount_code ?? ''))) : '';
        return substr(preg_replace('/[^A-Z0-9_-]+/', '', $code) ?: '', 0, 64);
    }

    protected function handleOrderNote(Mercato $commerce, Page $order): array {
        if ((string) $this->wire('input')->post->text('mrc_add_order_note') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_EDIT_ORDERS)) {
            return $this->permissionError(self::PERMISSION_EDIT_ORDERS, $this->_('Order note was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Order note was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }
        if ((int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id')) !== (int) $order->id) {
            return ['summary' => $this->_('Order note failed.'), 'errors' => [$this->_('Order does not match this detail screen.')]];
        }

        $note = trim((string) $this->wire('input')->post->textarea('order_note'));
        if ($note === '') {
            return ['summary' => $this->_('Order note failed.'), 'errors' => [$this->_('Enter a note before saving.')]];
        }

        $this->recordOrderNoteEvent($order, $note);
        $summary = $this->_('Order note saved.');
        $this->wire('session')->message($summary);
        return ['summary' => $summary, 'errors' => []];
    }

    protected function handleCustomerNote(array $customer): array {
        if ((string) $this->wire('input')->post->text('mrc_add_customer_note') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_CUSTOMERS)) {
            return $this->permissionError(self::PERMISSION_MANAGE_CUSTOMERS, $this->_('Customer note was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Customer note was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $key = (string) ($customer['key'] ?? '');
        if ((string) $this->wire('input')->post->text('customer_key') !== $key) {
            return ['summary' => $this->_('Customer note failed.'), 'errors' => [$this->_('Customer does not match this profile screen.')]];
        }

        $note = trim((string) $this->wire('input')->post->textarea('customer_note'));
        if ($note === '') {
            return ['summary' => $this->_('Customer note failed.'), 'errors' => [$this->_('Enter a note before saving.')]];
        }

        $this->recordCustomerNoteEvent($customer, $note);
        $summary = $this->_('Customer note saved.');
        $this->wire('session')->message($summary);
        return ['summary' => $summary, 'errors' => []];
    }

    protected function handleUnpaidOrderTotalsUpdate(Mercato $commerce, Page $order): array {
        if ((string) $this->wire('input')->post->text('mrc_update_order_totals') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_EDIT_ORDERS)) {
            return $this->permissionError(self::PERMISSION_EDIT_ORDERS, $this->_('Order totals update was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Order totals update was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }
        if ((int) $this->wire('sanitizer')->int($this->wire('input')->post('order_id')) !== (int) $order->id) {
            return ['summary' => $this->_('Order totals update failed.'), 'errors' => [$this->_('Order does not match this detail screen.')]];
        }
        if (!empty($this->getOrderPaymentState($order)['paid'])) {
            return ['summary' => $this->_('Order totals update failed.'), 'errors' => [$this->_('Paid orders cannot be repriced from Mercato. Create a refund or adjustment instead.')]];
        }

        $method = (string) $this->wire('input')->post->text('edit_fulfilment_method');
        if (!in_array($method, $commerce->getEnabledFulfilmentMethods(), true)) {
            return ['summary' => $this->_('Order totals update failed.'), 'errors' => [$this->_('Select an enabled fulfilment method.')]];
        }

        $items = json_decode((string) $order->mrc_items, true);
        if (!is_array($items) || !$items) {
            return ['summary' => $this->_('Order totals update failed.'), 'errors' => [$this->_('This order has no editable item snapshot.')]];
        }

        try {
            $previousSnapshot = [
                'items' => $items,
                'shipping' => $order->hasField('mrc_shipping_amount') ? (float) $order->mrc_shipping_amount : 0.0,
                'discount' => $order->hasField('mrc_discount_total') ? (float) $order->mrc_discount_total : 0.0,
                'total' => $this->getOrderTotal($order, $commerce),
            ];
            $editedItems = [];
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $quantity = max(0, (float) $this->wire('input')->post('edit_item_quantity_' . $index));
                if ($quantity <= 0) {
                    continue;
                }
                $item['quantity'] = $quantity;
                $editedItems[] = $item;
            }
            $addProductId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('edit_add_product_id'));
            $addQuantity = max(0, (float) $this->wire('input')->post('edit_add_quantity'));
            if ($addProductId > 0 && $addQuantity > 0) {
                $product = $this->wire('pages')->get($addProductId);
                if (!$product || !$product->id || $product->template->name !== 'mrc-product') {
                    return ['summary' => $this->_('Order totals update failed.'), 'errors' => [$this->_('Select a valid product to add.')]];
                }
                $found = false;
                foreach ($editedItems as &$item) {
                    $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);
                    if ($productId === (int) $product->id) {
                        $item['quantity'] = (float) ($item['quantity'] ?? 0) + $addQuantity;
                        $found = true;
                        break;
                    }
                }
                unset($item);
                if (!$found) {
                    $editedItems[] = ['id' => (int) $product->id, 'quantity' => $addQuantity];
                }
            }
            if (!$editedItems) {
                return ['summary' => $this->_('Order totals update failed.'), 'errors' => [$this->_('Keep at least one line item quantity above zero.')]];
            }

            $cart = $commerce->productList($editedItems);
            $commerce->orderRepository()->assertStockAvailable($cart, (int) $order->id);
            $customer = [
                'first_name' => (string) $order->mrc_first_name,
                'last_name' => (string) $order->mrc_last_name,
                'email' => (string) $order->mrc_email,
                'phone' => (string) $order->mrc_phone,
                'address' => (string) $order->mrc_address,
                'city' => (string) $order->mrc_city,
                'zip' => (string) $order->mrc_zip,
                'country' => (string) $order->mrc_country,
            ];
            $fulfilment = $commerce->fulfilmentService()->resolveSelection($method, $cart, $customer);
            $subtotal = $cart->getSubtotal();
            $shipping = round((float) ($fulfilment['amount'] ?? 0), 2);
            $discountCode = strtoupper(trim((string) $this->wire('input')->post->text('edit_discount_code')));
            $discount = ['valid' => false, 'code' => '', 'amount' => 0.0, 'title' => '', 'type' => ''];
            if ($discountCode !== '') {
                $discount = $commerce->discountService()->resolveCartDiscount(
                    $discountCode,
                    $cart,
                    (string) $order->mrc_email,
                    true,
                    ['source' => 'order_edit', 'email' => (string) $order->mrc_email, 'order_page_id' => (int) $order->id]
                );
                if (empty($discount['valid'])) {
                    return [
                        'summary' => $this->_('Order totals update failed.'),
                        'errors' => [(string) ($discount['message'] ?? $this->_('Discount code could not be applied.'))],
                    ];
                }
            }
            $discountAmount = round((float) ($discount['amount'] ?? 0), 2);
            $total = round(max(0, $subtotal + $shipping - $discountAmount), 2);

            $order->of(false);
            if ($order->hasField('mrc_items')) $order->mrc_items = json_encode($cart->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($order->hasField('mrc_subtotal_amount')) $order->mrc_subtotal_amount = $subtotal;
            if ($order->hasField('mrc_shipping_amount')) $order->mrc_shipping_amount = $shipping;
            if ($order->hasField('mrc_discount_code')) $order->mrc_discount_code = (string) ($discount['code'] ?? '');
            if ($order->hasField('mrc_discount_total')) $order->mrc_discount_total = $discountAmount;
            if ($order->hasField('mrc_discount_details')) $order->mrc_discount_details = json_encode($discount, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($order->hasField('mrc_total_amount')) $order->mrc_total_amount = $total;
            if ($order->hasField('mrc_fulfilment_method')) $order->mrc_fulfilment_method = (string) ($fulfilment['type'] ?? $method);
            if ($order->hasField('mrc_fulfilment_label')) $order->mrc_fulfilment_label = (string) ($fulfilment['label'] ?? '');
            if ($order->hasField('mrc_fulfilment_details')) $order->mrc_fulfilment_details = json_encode($fulfilment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->wire('pages')->save($order);
            if ($order->hasField('mrc_inventory_reserved') && (int) $order->mrc_inventory_reserved === 1) {
                $commerce->orderRepository()->reserveStock($order, $commerce->getReservationTtlMinutes());
            }
            $this->recordOrderEditEvent($order, [
                'summary' => $this->_('Unpaid order repriced.'),
                'from_total' => $previousSnapshot['total'],
                'to_total' => $total,
                'shipping_before' => $previousSnapshot['shipping'],
                'shipping_after' => $shipping,
                'discount_before' => $previousSnapshot['discount'],
                'discount_after' => $discountAmount,
                'items_before' => $this->summarizeOrderEditItems($previousSnapshot['items']),
                'items_after' => $this->summarizeOrderEditItems($cart->toArray()),
            ]);

            if (!empty($discount['valid'])) {
                $commerce->discountService()->recordAuditEvent('attached_to_order', $discount, [
                    'source' => 'order_edit',
                    'email' => (string) $order->mrc_email,
                    'order_page_id' => (int) $order->id,
                    'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
                ]);
            }

            $summary = sprintf($this->_('Updated unpaid order total to %s.'), $commerce->formatPrice($total));
            $this->wire('session')->message($summary);
            return [
                'summary' => $summary,
                'errors' => [],
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            return [
                'summary' => $this->_('Order totals update failed.'),
                'errors' => [$e->getMessage()],
            ];
        }
    }

    protected function summarizeOrderEditItems(array $items): array {
        $summary = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $summary[] = [
                'id' => (string) ($item['product_id'] ?? $item['id'] ?? ''),
                'title' => (string) ($item['title'] ?? $item['name'] ?? ''),
                'sku' => (string) ($item['sku'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'sum' => round((float) ($item['sum'] ?? ((float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 1))), 2),
            ];
        }
        return $summary;
    }

    protected function recordOrderEditEvent(Page $order, array $payload): void {
        $event = [
            'event' => 'order_edited',
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'user' => $this->wire('user') && $this->wire('user')->id ? (string) $this->wire('user')->name : 'system',
        ] + $payload;
        $this->wire('log')->save('mercato-order-edits', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function recordOrderNoteEvent(Page $order, string $note): void {
        $event = [
            'event' => 'order_note',
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'note' => $note,
            'user' => $this->wire('user') && $this->wire('user')->id ? (string) $this->wire('user')->name : 'system',
        ];
        $this->wire('log')->save('mercato-order-notes', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function recordCustomerNoteEvent(array $customer, string $note): void {
        $event = [
            'event' => 'customer_note',
            'customer_key' => (string) ($customer['key'] ?? ''),
            'name' => (string) ($customer['name'] ?? ''),
            'email' => (string) ($customer['email'] ?? ''),
            'note' => $note,
            'user' => $this->wire('user') && $this->wire('user')->id ? (string) $this->wire('user')->name : 'system',
        ];
        $this->wire('log')->save('mercato-customer-notes', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
