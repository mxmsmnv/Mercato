<?php
namespace ProcessWire;

trait ProcessMercatoWebhookInventoryPanels {

    protected function renderWebhooks(array $events, Mercato $commerce, array $simulationResult = [], array $filters = [], array $allEvents = []): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Webhook Events')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Recent Stripe and Mollie webhook lifecycle records from the ProcessWire log.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('webhooks', $this->getWebhookExportQuery($filters))) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a></div>';
        $out .= '</div>';
        $out .= $this->renderWebhookGatewayReadiness($commerce);
        $out .= $this->renderWebhookSimulator($commerce, $simulationResult);
        $out .= $this->renderWebhookHealthPanel($allEvents ?: $events);
        $out .= $this->renderWebhookQuickFilters($filters);
        $out .= $this->renderWebhookSummary($events);
        $out .= $this->renderWebhookFilters($filters);

        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Time'), $this->_('Gateway'), $this->_('Event'), $this->_('Status'), $this->_('Order'), $this->_('External ID'), $this->_('Message')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$events) {
            $out .= $this->renderSkeletonRows(4, 7);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No webhook events logged yet.')) . '</p></section>';
        }

        foreach ($events as $event) {
            $status = (string) ($event['status'] ?? '');
            $statusClass = match ($status) {
                'processed' => 'is-paid',
                'failed' => 'is-failed',
                default => 'is-pending',
            };
            $orderId = (int) ($event['order_page_id'] ?? 0);
            $orderLabel = $orderId > 0 ? '#' . $orderId : '-';
            $orderHtml = $this->e($orderLabel);
            if ($orderId > 0) {
                $order = $this->wire('pages')->get($orderId);
                if ($order && $order->id) {
                    $orderTitle = $this->e($order->mrc_invoice_number ?: $order->title ?: $orderLabel);
                    $orderHtml = '<a href="' . $this->e($this->orderDetailUrl($order)) . '">' . $orderTitle . '</a>'
                        . '<br><small><a href="' . $this->e($this->timelineUrl($order)) . '">' . $this->e($this->_('Timeline')) . '</a></small>';
                }
            }

            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['_time'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['gateway'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['event_type'] ?? '-')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $statusClass . '">' . $this->e($status ?: '-') . '</span></td>';
            $out .= '<td>' . $orderHtml . '</td>';
            $out .= '<td><small>' . $this->e((string) ($event['external_payment_id'] ?? '-')) . '</small></td>';
            $out .= '<td>' . $this->renderWebhookEventMessage($event) . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderWebhookHealthPanel(array $events): string {
        $latest = $events[0] ?? null;
        $latestFailure = null;
        $failed = 0;
        $processed = 0;
        $received = 0;
        foreach ($events as $event) {
            $status = strtolower((string) ($event['status'] ?? ''));
            if ($status === 'failed') {
                $failed++;
                if ($latestFailure === null) {
                    $latestFailure = $event;
                }
            } elseif ($status === 'processed') {
                $processed++;
            } elseif ($status === 'received') {
                $received++;
            }
        }

        $latestLabel = $latest
            ? trim((string) ($latest['gateway'] ?? 'gateway') . ' / ' . (string) ($latest['event_type'] ?? 'event') . ' / ' . (string) ($latest['status'] ?? 'status'), ' /')
            : $this->_('No webhook events yet');
        $failureLabel = $latestFailure
            ? trim((string) ($latestFailure['_time'] ?? '') . ' ' . (string) ($latestFailure['gateway'] ?? '') . ' ' . (string) ($latestFailure['message'] ?? ''), ' ')
            : $this->_('No recent webhook failures');
        $action = $failed > 0
            ? $this->_('Review failed events and confirm the matching order timeline.')
            : ($received > 0 && $processed === 0 ? $this->_('Received events are waiting for processing or were intentionally ignored.') : $this->_('Webhook processing looks healthy.'));

        $cards = [
            [$this->_('Latest event'), $latestLabel, $latest ? (string) ($latest['_time'] ?? '') : '-', 'is-neutral'],
            [$this->_('Failed events'), (string) $failed, $failureLabel, $failed > 0 ? 'is-failed' : 'is-paid'],
            [$this->_('Processed events'), (string) $processed, $this->_('Recent provider updates applied'), 'is-paid'],
            [$this->_('Operator action'), $failed > 0 ? $this->_('Review') : $this->_('None'), $action, $failed > 0 ? 'is-pending' : 'is-neutral'],
        ];

        $out = '<div class="mrc-webhook-health">';
        $out .= '<div class="mrc-webhook-health-head"><span class="ds-section-label">' . $this->e($this->_('Webhook visibility')) . '</span><h3>' . $this->e($this->_('Operational health')) . '</h3></div>';
        $out .= '<div class="mrc-webhook-health-grid">';
        foreach ($cards as [$label, $value, $caption, $class]) {
            $out .= '<div class="mrc-webhook-health-card ' . $class . '">';
            $out .= '<span>' . $this->e((string) $label) . '</span>';
            $out .= '<strong>' . $this->e((string) $value) . '</strong>';
            $out .= '<small>' . $this->e((string) $caption) . '</small>';
            $out .= '</div>';
        }
        $out .= '</div></div>';
        return $out;
    }

    protected function renderWebhookQuickFilters(array $filters): string {
        $activeGateway = (string) ($filters['gateway'] ?? 'all');
        $activeStatus = (string) ($filters['status'] ?? 'all');
        $chips = [
            [$this->_('All events'), ['gateway' => 'all', 'status' => 'all']],
            [$this->_('Failed only'), ['gateway' => 'all', 'status' => 'failed']],
            [$this->_('Processed'), ['gateway' => 'all', 'status' => 'processed']],
            [$this->_('Stripe'), ['gateway' => 'stripe', 'status' => 'all']],
            [$this->_('Mollie'), ['gateway' => 'mollie', 'status' => 'all']],
            [$this->_('PayPal'), ['gateway' => 'paypal', 'status' => 'all']],
        ];

        $out = '<div class="mrc-webhook-quickfilters">';
        $out .= '<span class="ds-section-label">' . $this->e($this->_('Quick filters')) . '</span>';
        foreach ($chips as [$label, $query]) {
            $queryGateway = (string) ($query['gateway'] ?? 'all');
            $queryStatus = (string) ($query['status'] ?? 'all');
            $active = ($queryGateway === 'all' && $queryStatus === 'all' && $activeGateway === 'all' && $activeStatus === 'all')
                || ($queryGateway !== 'all' && $queryGateway === $activeGateway && $queryStatus === 'all')
                || ($queryStatus !== 'all' && $queryStatus === $activeStatus && $queryGateway === 'all');
            $url = $this->adminUrl('webhooks/') . '?' . http_build_query($query);
            $out .= '<a class="uk-button uk-button-default' . ($active ? ' is-active' : '') . '" href="' . $this->e($url) . '">' . $this->e((string) $label) . '</a>';
        }
        $out .= '</div>';

        return $out;
    }

    protected function renderWebhookEventMessage(array $event): string {
        $message = trim((string) ($event['message'] ?? ''));
        $context = $event['context'] ?? [];
        $redacted = is_array($context) ? $this->redactSensitiveDebugData($context) : [];
        $hasContext = is_array($redacted) && count($redacted) > 0;

        $out = $message !== '' ? '<span>' . $this->e($message) . '</span>' : '<span class="uk-text-muted">-</span>';
        if ($hasContext) {
            $json = (string) json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $out .= '<details class="mrc-webhook-context"><summary>' . $this->e($this->_('Details')) . '</summary><pre>' . $this->e($json) . '</pre></details>';
        }
        return $out;
    }

    protected function renderWebhookSummary(array $events): string {
        $counts = [
            'total' => count($events),
            'received' => 0,
            'processed' => 0,
            'failed' => 0,
            'ignored' => 0,
        ];
        $gateways = [];
        foreach ($events as $event) {
            $status = strtolower((string) ($event['status'] ?? ''));
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            $gateway = strtolower((string) ($event['gateway'] ?? ''));
            if ($gateway !== '') {
                $gateways[$gateway] = true;
            }
        }

        $cards = [
            'total' => [$this->_('Events'), $this->_('Current filtered set'), 'is-neutral'],
            'received' => [$this->_('Received'), $this->_('Webhook accepted'), 'is-pending'],
            'processed' => [$this->_('Processed'), $this->_('Order updated'), 'is-paid'],
            'failed' => [$this->_('Failed'), $this->_('Needs attention'), 'is-failed'],
            'ignored' => [$this->_('Ignored'), $this->_('No transition'), 'is-pending'],
        ];

        $out = '<div class="mrc-webhook-summary">';
        foreach ($cards as $key => [$label, $caption, $class]) {
            $out .= '<div class="mrc-webhook-summary-card ' . $class . '">';
            $out .= '<span>' . $this->e($label) . '</span>';
            $out .= '<strong>' . $this->e((string) $counts[$key]) . '</strong>';
            $out .= '<small>' . $this->e($caption) . '</small>';
            $out .= '</div>';
        }
        $out .= '<div class="mrc-webhook-summary-card is-neutral">';
        $out .= '<span>' . $this->e($this->_('Gateways')) . '</span>';
        $out .= '<strong>' . $this->e((string) count($gateways)) . '</strong>';
        $out .= '<small>' . $this->e($gateways ? implode(', ', array_keys($gateways)) : $this->_('No events')) . '</small>';
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    protected function renderWebhookFilters(array $filters): string {
        $gateway = (string) ($filters['gateway'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');
        $event = (string) ($filters['event'] ?? 'all');
        $orderId = (int) ($filters['order'] ?? 0);
        $gateways = $this->getGatewayFilterOptions(false, false);
        $statuses = [
            'all' => $this->_('All statuses'),
            'received' => $this->_('Received'),
            'processed' => $this->_('Processed'),
            'ignored' => $this->_('Ignored'),
            'failed' => $this->_('Failed'),
        ];

        $out = '<form method="get" class="mrc-webhook-filters">';
        $out .= '<label><span>' . $this->e($this->_('Gateway')) . '</span><select name="gateway">';
        foreach ($gateways as $value => $label) {
            $out .= '<option value="' . $this->e($value) . '"' . ($gateway === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Status')) . '</span><select name="status">';
        foreach ($statuses as $value => $label) {
            $out .= '<option value="' . $this->e($value) . '"' . ($status === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Event type')) . '</span><input type="text" name="event" value="' . $this->e($event !== 'all' ? $event : '') . '" placeholder="' . $this->e($this->_('Any event')) . '"></label>';
        $out .= '<label><span>' . $this->e($this->_('Order ID')) . '</span><input type="number" name="order" value="' . ($orderId > 0 ? $this->e((string) $orderId) : '') . '" min="1" placeholder="' . $this->e($this->_('Any order')) . '"></label>';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-filter uk-margin-small-right"></i>' . $this->e($this->_('Apply filters')) . '</button>';
        if ($gateway !== 'all' || $status !== 'all' || $event !== 'all' || $orderId > 0) {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('webhooks/')) . '">' . $this->e($this->_('Reset')) . '</a>';
        }
        $out .= '</form>';
        return $out;
    }

    protected function renderWebhookGatewayReadiness(Mercato $commerce): string {
        $statuses = $commerce->getGatewaySetupStatuses();
        if (!$statuses) {
            return '';
        }

        $out = '<div class="mrc-gateway-readiness">';
        $out .= '<div class="mrc-gateway-readiness-head">';
        $out .= '<div>';
        $out .= '<h3 class="uk-h4">' . $this->e($this->_('Gateway Readiness')) . '</h3>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Webhook URLs and setup state for active payment providers. Secrets are never displayed here.')) . '</p>';
        $out .= '</div>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->wire('config')->urls->admin . 'module/edit?name=Mercato') . '"><i class="fa fa-cog uk-margin-small-right"></i>' . $this->e($this->_('Gateway settings')) . '</a>';
        $out .= '</div><div class="mrc-gateway-readiness-grid">';

        foreach ($statuses as $status) {
            $data = method_exists($status, 'toArray') ? $status->toArray() : [];
            $gateway = (string) ($data['gateway'] ?? 'gateway');
            $details = (array) ($data['details'] ?? []);
            $errors = array_filter(array_map('strval', (array) ($data['errors'] ?? [])));
            $warnings = array_filter(array_map('strval', (array) ($data['warnings'] ?? [])));
            $ready = !empty($data['ready']) && !$errors;
            $statusClass = $errors ? 'is-failed' : ($warnings ? 'is-pending' : 'is-paid');
            $label = $errors ? $this->_('Needs setup') : ($warnings ? $this->_('Check setup') : $this->_('Ready'));

            $out .= '<article class="mrc-gateway-readiness-card">';
            $out .= '<div class="mrc-gateway-readiness-title"><strong>' . $this->e(ucfirst($gateway)) . '</strong><span class="uk-label mrc-admin-status ' . $statusClass . '">' . $this->e($label) . '</span></div>';
            $out .= '<dl class="mrc-gateway-readiness-list">';
            $out .= '<dt>' . $this->e($this->_('Mode')) . '</dt><dd>' . $this->e((string) ($details['mode'] ?? '-')) . '</dd>';
            $out .= '<dt>' . $this->e($this->_('Credentials')) . '</dt><dd>' . $this->e((string) ($details['credential_status'] ?? '-')) . '</dd>';
            $out .= '<dt>' . $this->e($this->_('Webhook verification')) . '</dt><dd>' . $this->e((string) ($details['webhook_status'] ?? '-')) . '</dd>';
            $out .= '<dt>' . $this->e($this->_('Webhook URL')) . '</dt><dd><code>' . $this->e((string) ($details['webhook_url'] ?? '-')) . '</code></dd>';
            if (!empty($details['payment_method_source'])) {
                $out .= '<dt>' . $this->e($this->_('Payment methods')) . '</dt><dd>' . $this->e((string) $details['payment_method_source']) . '</dd>';
            }
            $out .= '</dl>';
            if (!empty($details['capabilities']) && is_array($details['capabilities'])) {
                $enabledCapabilities = [];
                foreach ($details['capabilities'] as $capability => $value) if ($value === true) $enabledCapabilities[] = str_replace('supports_', '', (string) $capability);
                if ($enabledCapabilities) $out .= '<p class="uk-text-muted"><strong>' . $this->e($this->_('Capabilities')) . ':</strong> ' . $this->e(implode(', ', $enabledCapabilities)) . '</p>';
            }
            if (!empty($details['required_events']) && is_array($details['required_events'])) {
                $out .= '<div class="mrc-gateway-events">';
                $out .= '<span class="ds-section-label">' . $this->e($this->_('Required events')) . '</span>';
                $out .= '<ul>';
                foreach ($details['required_events'] as $eventName) {
                    $out .= '<li><code>' . $this->e((string) $eventName) . '</code></li>';
                }
                $out .= '</ul></div>';
            }
            if (!empty($details['available_methods_note'])) {
                $out .= '<p class="uk-text-muted mrc-gateway-methods-note">' . $this->e((string) $details['available_methods_note']) . '</p>';
            }
            if (!empty($details['test_mode_note'])) {
                $out .= '<p class="uk-text-muted mrc-gateway-test-note">' . $this->e((string) $details['test_mode_note']) . '</p>';
            }
            if (!empty($details['setup_note'])) {
                $out .= '<p class="uk-text-muted mrc-gateway-setup-note">' . $this->e((string) $details['setup_note']) . '</p>';
            }
            if ($errors || $warnings) {
                $out .= '<ul class="mrc-gateway-readiness-notes">';
                foreach (array_merge($errors, $warnings) as $message) {
                    $out .= '<li>' . $this->e($message) . '</li>';
                }
                $out .= '</ul>';
            } elseif ($ready) {
                $out .= '<p class="uk-text-muted">' . $this->e($this->_('Keys and required webhook configuration are present.')) . '</p>';
            }
            $out .= '</article>';
        }

        $out .= '</div></div>';
        return $out;
    }

    protected function renderWebhookSimulator(Mercato $commerce, array $simulationResult = []): string {
        $orders = $this->getSimulatableWebhookOrders($commerce);
        $disabled = !empty($commerce->production) || !count($orders);

        $out = '<div class="mrc-admin-simulator">';
        $out .= '<div>';
        $out .= '<h3 class="uk-h4">' . $this->e($this->_('Local Webhook Simulator')) . '</h3>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Guided non-production verification for success, decline, cancellation, retry success, delayed webhook, and duplicate callback behavior. Refund verification remains in the paid order refund panel.')) . '</p>';
        $out .= '</div>';

        if ($simulationResult) {
            $class = !empty($simulationResult['errors']) ? (!empty($simulationResult['warning']) ? 'uk-alert-warning' : 'uk-alert-danger') : 'uk-alert-success';
            $out .= '<div class="uk-alert ' . $class . '"><p><strong>' . $this->e((string) ($simulationResult['summary'] ?? '')) . '</strong></p>';
            foreach ((array) ($simulationResult['errors'] ?? []) as $error) {
                $out .= '<p>' . $this->e((string) $error) . '</p>';
            }
            $out .= '</div>';
        }

        if (!empty($commerce->production)) {
            return $out . '<p class="uk-alert uk-alert-warning">' . $this->e($this->_('Switch to test mode before simulating webhooks.')) . '</p></div>';
        }
        if (!count($orders)) {
            return $out . '<p class="uk-alert uk-alert-warning">' . $this->e($this->_('No unpaid orders are available for simulation. Start a Stripe or Mollie checkout first, then return here.')) . '</p></div>';
        }

        $out .= '<form method="post" class="mrc-admin-inline-form">';
        $out .= $this->renderCsrfInput();
        $out .= '<input type="hidden" name="mrc_simulate_webhook" value="1">';
        $out .= '<label><span>' . $this->e($this->_('Order')) . '</span><select name="order_id" ' . ($disabled ? 'disabled' : '') . '>';
        foreach ($orders as $order) {
            $label = sprintf(
                '%s · %s · %s',
                (string) ($order->mrc_invoice_number ?: $order->title ?: '#' . $order->id),
                (string) ($order->mrc_email ?: $this->_('No email')),
                ucfirst(str_replace('_', ' ', (string) ($order->mrc_payment_status ?: MercatoPaymentStatus::PENDING)))
            );
            $out .= '<option value="' . (int) $order->id . '">' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Gateway')) . '</span><select name="gateway" ' . ($disabled ? 'disabled' : '') . '>';
        $out .= '<option value="stripe">' . $this->e($this->_('Stripe')) . '</option>';
        $out .= '<option value="mollie">' . $this->e($this->_('Mollie')) . '</option>';
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Scenario')) . '</span><select name="verification_scenario" ' . ($disabled ? 'disabled' : '') . '>';
        foreach (['success' => $this->_('Success and finalization'), 'decline' => $this->_('Decline'), 'cancellation' => $this->_('Cancellation'), 'retry_success' => $this->_('Retry success'), 'delayed_webhook' => $this->_('Delayed webhook / processing'), 'duplicate_callback' => $this->_('Duplicate callback replay')] as $scenario => $label) $out .= '<option value="' . $this->e($scenario) . '">' . $this->e($label) . '</option>';
        $out .= '</select></label>';
        $out .= '<button class="uk-button uk-button-default" type="submit" ' . ($disabled ? 'disabled' : '') . '><i class="fa fa-bolt uk-margin-small-right"></i>' . $this->e($this->_('Simulate webhook')) . '</button>';
        $out .= '</form></div>';

        return $out;
    }

    protected function renderInventorySummary(Mercato $commerce, array $events): string {
        $expiredReservations = $commerce->orderRepository()->countExpiredReservations();
        $lowStockProducts = $this->getLowStockProducts($commerce, 1000);
        $oversellDebt = $this->getOversellDebtSummary();
        $cards = [
            [
                $this->_('Expired reservations'),
                (string) $expiredReservations,
                $expiredReservations > 0 ? $this->_('Release expired holds before selling scarce stock.') : $this->_('No expired stock reservations.'),
            ],
            [
                $this->_('Low stock'),
                (string) $lowStockProducts->count(),
                $lowStockProducts->count() > 0 ? $this->_('Products at or below threshold.') : $this->_('No low-stock products.'),
            ],
            [
                $this->_('Oversell debt'),
                (string) (int) $oversellDebt['units'],
                (int) $oversellDebt['products'] > 0
                    ? sprintf($this->_('Across %d backorder/preorder product(s).'), (int) $oversellDebt['products'])
                    : $this->_('No backorder or preorder debt.'),
            ],
            [
                $this->_('Logged movements'),
                (string) count($events),
                $this->_('Recent reservation, release, sold, refund, and manual stock events.'),
            ],
        ];

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head"><div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Inventory Overview')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Current stock pressure before the movement log.')) . '</p>';
        $out .= '</div><div class="mrc-panel-actions">';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('stock-pressure')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export stock pressure')) . '</a>';
        $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('products/')) . '"><i class="fa fa-shopping-bag uk-margin-small-right"></i>' . $this->e($this->_('Review products')) . '</a>';
        $out .= '</div></div>';
        $out .= '<div class="mrc-admin-stats uk-child-width-1-4@l uk-child-width-1-2@s" uk-grid>';
        foreach ($cards as [$label, $value, $note]) {
            $out .= '<div><div class="uk-card uk-card-default uk-card-body uk-card-small mrc-admin-card">';
            $out .= '<span class="ds-section-label">' . $this->e((string) $label) . '</span>';
            $out .= '<strong class="uk-display-block">' . $this->e((string) $value) . '</strong>';
            $out .= '<small class="uk-text-muted">' . $this->e((string) $note) . '</small>';
            $out .= '</div></div>';
        }
        $out .= '</div></section>';
        return $out;
    }

    protected function renderInventoryEvents(array $events, array $filters = [], array $allEvents = []): string {
        $eventFilter = (string) ($filters['event'] ?? 'all');
        $orderFilter = (int) ($filters['order'] ?? 0);
        $eventOptions = [
            'all' => $this->_('All events'),
            'reserved' => $this->_('Reserved'),
            'released' => $this->_('Released'),
            'expired' => $this->_('Expired'),
            'sold' => $this->_('Sold'),
            'refunded' => $this->_('Refunded'),
            'manual_adjustment' => $this->_('Manual adjustment'),
        ];
        foreach ($allEvents as $event) {
            $eventName = strtolower((string) ($event['event'] ?? ''));
            if ($eventName !== '' && !isset($eventOptions[$eventName])) {
                $eventOptions[$eventName] = ucwords(str_replace(['_', '-'], ' ', $eventName));
            }
        }
        if ($eventFilter !== 'all' && !isset($eventOptions[$eventFilter])) {
            $eventOptions[$eventFilter] = ucwords(str_replace(['_', '-'], ' ', $eventFilter));
        }

        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Inventory Movements')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Recent stock reservations, releases, and paid order adjustments.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('inventory', $this->getInventoryExportQuery($filters))) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a></div>';
        $out .= '</div>';
        $out .= '<form method="get" class="mrc-webhook-filters mrc-inventory-filters">';
        $out .= '<label><span>' . $this->e($this->_('Event')) . '</span><select name="event">';
        foreach ($eventOptions as $value => $label) {
            $out .= '<option value="' . $this->e((string) $value) . '"' . ($eventFilter === (string) $value ? ' selected' : '') . '>' . $this->e((string) $label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Order ID')) . '</span><input type="number" min="1" step="1" name="order" value="' . ($orderFilter > 0 ? $this->e((string) $orderFilter) : '') . '" placeholder="' . $this->e($this->_('Any order')) . '"></label>';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-filter uk-margin-small-right"></i>' . $this->e($this->_('Apply filters')) . '</button>';
        if ($eventFilter !== 'all' || $orderFilter > 0) {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('inventory/')) . '">' . $this->e($this->_('Reset')) . '</a>';
        }
        $out .= '</form>';

        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Time'), $this->_('Event'), $this->_('Product'), $this->_('Quantity'), $this->_('Stock'), $this->_('Order'), $this->_('Context')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$events) {
            $out .= $this->renderSkeletonRows(4, 7);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No inventory movements logged yet.')) . '</p></section>';
        }

        foreach ($events as $event) {
            $eventName = (string) ($event['event'] ?? '');
            $statusClass = match ($eventName) {
                'sold' => 'is-paid',
                'expired' => 'is-failed',
                default => 'is-pending',
            };
            $orderId = (int) ($event['order_id'] ?? 0);
            $orderHtml = '-';
            if ($orderId > 0) {
                $order = $this->wire('pages')->get($orderId);
                $orderHtml = '#' . $orderId;
                if ($order && $order->id) {
                    $label = (string) ($order->mrc_invoice_number ?: $order->title ?: '#' . $orderId);
                    $orderHtml = '<a href="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->e($label) . '</a>';
                }
            }

            $stock = '-';
            if (array_key_exists('before', $event) || array_key_exists('after', $event)) {
                $before = $event['before'] ?? null;
                $after = $event['after'] ?? null;
                $stock = ($before === null ? '-' : (string) $before) . ' → ' . ($after === null ? '-' : (string) $after);
            }

            $context = [];
            if (!empty($event['reserved_until'])) {
                $context[] = $this->_('Until') . ' ' . (string) $event['reserved_until'];
            }
            if (!empty($event['invoice'])) {
                $context[] = $this->_('Invoice') . ' ' . (string) $event['invoice'];
            }
            if (array_key_exists('delta', $event)) {
                $context[] = $this->_('Delta') . ' ' . sprintf('%+d', (int) $event['delta']);
            }
            if (!empty($event['note'])) {
                $context[] = (string) $event['note'];
            }

            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['_time'] ?? $event['at'] ?? '-')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $statusClass . '">' . $this->e($eventName ?: '-') . '</span></td>';
            $out .= '<td><strong>' . $this->e((string) ($event['title'] ?? '-')) . '</strong><br><small>#' . $this->e((string) ($event['product_id'] ?? '')) . '</small></td>';
            $out .= '<td>' . $this->e((string) ($event['quantity'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e($stock) . '</td>';
            $out .= '<td>' . $orderHtml . '</td>';
            $out .= '<td><small>' . $this->e(implode(' / ', $context)) . '</small></td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

}
