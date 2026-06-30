<?php
namespace ProcessWire;

trait ProcessMercatoPaymentPanels {
    protected function renderPaymentAttemptsPanel(Page $order, Mercato $commerce): string {
        $attempts = array_values(array_filter($this->getPaymentAttemptEvents(10000), static fn(array $event): bool => (int) ($event['order_page_id'] ?? 0) === (int) $order->id));
        $out = '<section class="pw-wrap mrc-admin-panel mrc-payment-attempts">';
        $out .= '<div class="mrc-admin-panel-head"><div><h2 class="uk-h3">' . $this->e($this->_('Payment Attempts')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Gateway attempt lifecycle for this order, including retries and client-side confirmations.')) . '</p></div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('payment-attempts')) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export attempts')) . '</a></div></div>';

        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Time'), $this->_('Event'), $this->_('Gateway'), $this->_('Method'), $this->_('Amount'), $this->_('Status'), $this->_('Context'), $this->_('External ID'), $this->_('Attempt ID')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$attempts) {
            $out .= $this->renderSkeletonRows(3, 9);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No gateway attempts have been logged for this order yet.')) . '</p></section>';
        }

        foreach ($attempts as $attempt) {
            $status = (string) ($attempt['status'] ?? '');
            $event = (string) ($attempt['event'] ?? '');
            $class = match ($status) {
                MercatoPaymentStatus::PAID => 'is-paid',
                default => MercatoPaymentStatus::isFailureOutcome($status) ? 'is-failed' : 'is-pending',
            };
            $amount = (float) ($attempt['amount'] ?? 0);
            $currency = strtoupper((string) ($attempt['currency'] ?? $commerce->currency));
            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($attempt['_time'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e(ucfirst(str_replace('_', ' ', $event))) . '</td>';
            $out .= '<td>' . $this->e((string) ($attempt['gateway'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e((string) ($attempt['method'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e($currency . ' ' . number_format($amount, 2, '.', '')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $class . '">' . $this->e(ucfirst(str_replace('_', ' ', $status))) . '</span></td>';
            $out .= '<td>' . $this->renderPaymentAttemptContext($attempt) . '</td>';
            $out .= '<td><small>' . $this->e((string) ($attempt['external_id'] ?? '')) . '</small></td>';
            $out .= '<td><small>' . $this->e((string) ($attempt['id'] ?? '')) . '</small></td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderPaymentAttempts(array $events, Mercato $commerce, array $filters = []): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Payment Attempts')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Gateway attempt lifecycle across checkout, payment links, retries, and client-side confirmations.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('payment-attempts', $this->getPaymentAttemptExportQuery($filters))) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a></div>';
        $out .= '</div>';
        $out .= $this->renderPaymentAttemptSummary($events);
        $out .= $this->renderPaymentAttemptHealthPanel($events);
        $out .= $this->renderPaymentAttemptQuickFilters($filters);
        $out .= $this->renderPaymentAttemptFilters($filters);

        $headings = [$this->_('Time'), $this->_('Event'), $this->_('Gateway'), $this->_('Method'), $this->_('Order'), $this->_('Amount'), $this->_('Status'), $this->_('Context'), $this->_('External ID'), $this->_('Attempt ID')];
        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ($headings as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$events) {
            $out .= $this->renderSkeletonRows(5, count($headings));
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No payment attempts match the current filters yet.')) . '</p></section>';
        }

        foreach ($events as $event) {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $status = (string) ($event['status'] ?? '');
            $orderId = (int) ($event['order_page_id'] ?? 0);
            $orderHtml = '-';
            if ($orderId > 0) {
                $order = $this->wire('pages')->get($orderId);
                $label = (string) ($payload['invoice'] ?? ('#' . $orderId));
                if ($order && $order->id) {
                    $label = (string) ($order->mrc_invoice_number ?: $order->title ?: $label);
                    $orderHtml = '<a href="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->e($label) . '</a>';
                } else {
                    $orderHtml = $this->e($label);
                }
            }
            $amount = (float) ($event['amount'] ?? 0);
            $currency = strtoupper((string) ($event['currency'] ?? $commerce->currency));
            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['_time'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e(ucfirst(str_replace('_', ' ', (string) ($event['event'] ?? '-')))) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['gateway'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['method'] ?? '-')) . '</td>';
            $out .= '<td>' . $orderHtml . '</td>';
            $out .= '<td>' . $this->e($currency . ' ' . number_format($amount, 2, '.', '')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $this->getPaymentAttemptStatusClass($status) . '">' . $this->e(ucfirst(str_replace('_', ' ', $status ?: '-'))) . '</span></td>';
            $out .= '<td>' . $this->renderPaymentAttemptContext($event) . '</td>';
            $out .= '<td><small>' . $this->e((string) ($event['external_id'] ?? '')) . '</small></td>';
            $out .= '<td><small>' . $this->e((string) ($event['id'] ?? '')) . '</small></td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderPaymentAttemptSummary(array $events): string {
        $counts = [
            'total' => count($events),
            'completed' => 0,
            'processing' => 0,
            'failed' => 0,
            'gateways' => [],
        ];
        foreach ($events as $event) {
            $eventName = strtolower((string) ($event['event'] ?? ''));
            $status = strtolower((string) ($event['status'] ?? ''));
            if ($eventName === 'completed' || $status === MercatoPaymentStatus::PAID) {
                $counts['completed']++;
            } elseif ($eventName === 'processing' || $status === MercatoPaymentStatus::PROCESSING) {
                $counts['processing']++;
            } elseif (in_array($eventName, ['failed', 'canceled'], true) || MercatoPaymentStatus::isFailureOutcome($status)) {
                $counts['failed']++;
            }
            $gateway = strtolower((string) ($event['gateway'] ?? ''));
            if ($gateway !== '') {
                $counts['gateways'][$gateway] = true;
            }
        }

        $cards = [
            [$this->_('Attempts'), (string) $counts['total'], $this->_('Current filtered set'), 'is-neutral'],
            [$this->_('Completed'), (string) $counts['completed'], $this->_('Paid attempts'), 'is-paid'],
            [$this->_('Processing'), (string) $counts['processing'], $this->_('Awaiting final state'), 'is-pending'],
            [$this->_('Failed'), (string) $counts['failed'], $this->_('Needs retry or review'), 'is-failed'],
            [$this->_('Gateways'), (string) count($counts['gateways']), $counts['gateways'] ? implode(', ', array_keys($counts['gateways'])) : $this->_('No events'), 'is-neutral'],
        ];

        $out = '<div class="mrc-webhook-summary">';
        foreach ($cards as [$label, $value, $caption, $class]) {
            $out .= '<div class="mrc-webhook-summary-card ' . $class . '">';
            $out .= '<span>' . $this->e($label) . '</span>';
            $out .= '<strong>' . $this->e($value) . '</strong>';
            $out .= '<small>' . $this->e($caption) . '</small>';
            $out .= '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    protected function renderPaymentAttemptHealthPanel(array $events): string {
        $latest = $events[0] ?? null;
        $latestFailure = null;
        $failed = 0;
        $processing = 0;
        $completed = 0;
        foreach ($events as $event) {
            $eventName = strtolower((string) ($event['event'] ?? ''));
            $status = strtolower((string) ($event['status'] ?? ''));
            $isFailure = in_array($eventName, ['failed', 'canceled'], true)
                || MercatoPaymentStatus::isFailureOutcome($status);
            if ($isFailure) {
                $failed++;
                if ($latestFailure === null) {
                    $latestFailure = $event;
                }
            } elseif ($eventName === 'processing' || $status === MercatoPaymentStatus::PROCESSING) {
                $processing++;
            } elseif ($eventName === 'completed' || $status === MercatoPaymentStatus::PAID) {
                $completed++;
            }
        }

        $latestLabel = $latest
            ? trim((string) ($latest['gateway'] ?? 'gateway') . ' / ' . (string) ($latest['event'] ?? 'event') . ' / ' . (string) ($latest['status'] ?? 'status'), ' /')
            : $this->_('No payment attempts yet');
        $failureContext = $latestFailure ? $this->summarizePaymentAttemptContext($this->getPaymentAttemptSafeContext($latestFailure)) : '';
        $failureLabel = $latestFailure
            ? trim((string) ($latestFailure['_time'] ?? '') . ' ' . $failureContext, ' ')
            : $this->_('No failed attempts in this set');
        $action = $failed > 0
            ? $this->_('Open the related order and retry payment or send a recovery link.')
            : ($processing > 0 ? $this->_('Wait for provider confirmation or webhook reconciliation.') : $this->_('Attempt flow looks healthy for this filter.'));

        $cards = [
            [$this->_('Latest attempt'), $latestLabel, $latest ? (string) ($latest['_time'] ?? '') : '-', 'is-neutral'],
            [$this->_('Failed attempts'), (string) $failed, $failureLabel, $failed > 0 ? 'is-failed' : 'is-paid'],
            [$this->_('Processing attempts'), (string) $processing, $this->_('Awaiting gateway confirmation'), 'is-pending'],
            [$this->_('Operator action'), $failed > 0 ? $this->_('Review') : ($processing > 0 ? $this->_('Watch') : $this->_('None')), $action, $failed > 0 ? 'is-pending' : 'is-neutral'],
        ];

        $out = '<div class="mrc-attempt-health">';
        $out .= '<div class="mrc-attempt-health-head"><span class="ds-section-label">' . $this->e($this->_('Attempt visibility')) . '</span><h3>' . $this->e($this->_('Operational health')) . '</h3></div>';
        $out .= '<div class="mrc-attempt-health-grid">';
        foreach ($cards as [$label, $value, $caption, $class]) {
            $out .= '<div class="mrc-attempt-health-card ' . $class . '">';
            $out .= '<span>' . $this->e((string) $label) . '</span>';
            $out .= '<strong>' . $this->e((string) $value) . '</strong>';
            $out .= '<small>' . $this->e((string) $caption) . '</small>';
            $out .= '</div>';
        }
        $out .= '</div></div>';
        return $out;
    }

    protected function renderPaymentAttemptQuickFilters(array $filters): string {
        $activeGateway = (string) ($filters['gateway'] ?? 'all');
        $activeStatus = (string) ($filters['status'] ?? 'all');
        $activeEvent = (string) ($filters['event'] ?? 'all');
        $activeOrder = (int) ($filters['order'] ?? 0);
        $chips = [
            [$this->_('All attempts'), ['gateway' => 'all', 'status' => 'all', 'event' => 'all']],
            [$this->_('Failed only'), ['gateway' => 'all', 'status' => MercatoPaymentStatus::FAILED, 'event' => 'failed']],
            [$this->_('Processing'), ['gateway' => 'all', 'status' => MercatoPaymentStatus::PROCESSING, 'event' => 'processing']],
            [$this->_('Paid'), ['gateway' => 'all', 'status' => MercatoPaymentStatus::PAID, 'event' => 'completed']],
            [$this->_('Stripe'), ['gateway' => 'stripe', 'status' => 'all', 'event' => 'all']],
            [$this->_('Mollie'), ['gateway' => 'mollie', 'status' => 'all', 'event' => 'all']],
            [$this->_('PayPal'), ['gateway' => 'paypal', 'status' => 'all', 'event' => 'all']],
            [$this->_('Bank Transfer'), ['gateway' => 'bank-transfer', 'status' => 'all', 'event' => 'all']],
        ];

        $out = '<div class="mrc-attempt-quickfilters">';
        $out .= '<span class="ds-section-label">' . $this->e($this->_('Quick filters')) . '</span>';
        foreach ($chips as [$label, $query]) {
            $queryGateway = (string) ($query['gateway'] ?? 'all');
            $queryStatus = (string) ($query['status'] ?? 'all');
            $queryEvent = (string) ($query['event'] ?? 'all');
            $active = $activeOrder === 0 && (
                ($queryGateway === 'all' && $queryStatus === 'all' && $queryEvent === 'all' && $activeGateway === 'all' && $activeStatus === 'all' && $activeEvent === 'all')
                || ($queryGateway !== 'all' && $queryGateway === $activeGateway && $queryStatus === 'all' && $queryEvent === 'all')
                || ($queryStatus !== 'all' && $queryStatus === $activeStatus && ($queryEvent === 'all' || $queryEvent === $activeEvent) && $queryGateway === 'all')
                || ($queryEvent !== 'all' && $queryEvent === $activeEvent && ($queryStatus === 'all' || $queryStatus === $activeStatus) && $queryGateway === 'all')
            );
            $url = $this->adminUrl('payment-attempts/') . '?' . http_build_query($query);
            $out .= '<a class="uk-button uk-button-default' . ($active ? ' is-active' : '') . '" href="' . $this->e($url) . '">' . $this->e((string) $label) . '</a>';
        }
        if ($activeOrder > 0) {
            $out .= '<span class="mrc-attempt-order-chip">' . $this->e(sprintf($this->_('Order #%d filter active'), $activeOrder)) . '</span>';
        }
        $out .= '</div>';

        return $out;
    }

    protected function renderPaymentAttemptFilters(array $filters): string {
        $gateway = (string) ($filters['gateway'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');
        $event = (string) ($filters['event'] ?? 'all');
        $orderId = (int) ($filters['order'] ?? 0);
        $gateways = $this->getGatewayFilterOptions(true, true);
        $statuses = ['all' => $this->_('All statuses')];
        foreach (MercatoPaymentStatus::all() as $statusValue) {
            $statuses[$statusValue] = ucfirst(str_replace('_', ' ', $statusValue));
        }
        $events = [
            'all' => $this->_('All events'),
            'created' => $this->_('Created'),
            'initialized' => $this->_('Initialized'),
            'completed' => $this->_('Completed'),
            'processing' => $this->_('Processing'),
            'failed' => $this->_('Failed'),
            'canceled' => $this->_('Canceled'),
            'updated' => $this->_('Updated'),
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
        $out .= '<label><span>' . $this->e($this->_('Event')) . '</span><select name="event">';
        foreach ($events as $value => $label) {
            $out .= '<option value="' . $this->e($value) . '"' . ($event === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Order ID')) . '</span><input class="uk-input" type="number" name="order" min="0" value="' . ($orderId > 0 ? (int) $orderId : '') . '" placeholder="' . $this->e($this->_('Any')) . '"></label>';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-filter uk-margin-small-right"></i>' . $this->e($this->_('Apply filters')) . '</button>';
        if ($gateway !== 'all' || $status !== 'all' || $event !== 'all' || $orderId > 0) {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('payment-attempts/')) . '">' . $this->e($this->_('Reset')) . '</a>';
        }
        $out .= '</form>';
        return $out;
    }

    protected function getPaymentAttemptStatusClass(string $status): string {
        return match ($status) {
            MercatoPaymentStatus::PAID, MercatoPaymentStatus::AUTHORIZED => 'is-paid',
            MercatoPaymentStatus::FAILED, MercatoPaymentStatus::CANCELED, MercatoPaymentStatus::EXPIRED => 'is-failed',
            default => 'is-pending',
        };
    }

    protected function renderPaymentAttemptContext(array $event): string {
        $context = $this->getPaymentAttemptSafeContext($event);
        if (!$context) {
            return '<span class="uk-text-muted">-</span>';
        }

        $summary = $this->summarizePaymentAttemptContext($context);
        $json = (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '<details class="mrc-attempt-context"><summary>' . $this->e($summary) . '</summary><pre>' . $this->e($json) . '</pre></details>';
    }

    protected function getPaymentAttemptSafeContext(array $event): array {
        $context = is_array($event['context'] ?? null) ? (array) $event['context'] : [];
        $context = $this->redactSensitiveDebugData($context);
        $context = is_array($context) ? array_filter($context, static function ($value): bool {
            if ($value === '' || $value === null) return false;
            if (is_array($value) && count($value) === 0) return false;
            return true;
        }) : [];
        return $context;
    }

    protected function summarizePaymentAttemptContext(array $context): string {
        if (!$context) {
            return '';
        }

        $summaryParts = [];
        foreach (['source', 'error', 'redirect', 'requires_client_confirmation'] as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            $summaryParts[] = $key . ': ' . $this->stringifyPaymentAttemptContextValue($context[$key]);
        }
        if (!$summaryParts) {
            $firstKey = (string) array_key_first($context);
            $summaryParts[] = $firstKey . ': ' . $this->stringifyPaymentAttemptContextValue($context[$firstKey]);
        }
        return implode(' · ', array_slice($summaryParts, 0, 2));
    }

    protected function stringifyPaymentAttemptContextValue(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }

    protected function renderRefunds(array $events, Mercato $commerce, array $filters = []): string {
        $out = '<section class="pw-wrap mrc-admin-panel">';
        $out .= '<div class="mrc-admin-panel-head">';
        $out .= '<div>';
        $out .= '<h2 class="uk-h3">' . $this->e($this->_('Refunds')) . '</h2>';
        $out .= '<p class="uk-text-muted">' . $this->e($this->_('Recent provider-backed refund requests and reconciliation events.')) . '</p>';
        $out .= '</div>';
        $out .= '<div class="mrc-panel-actions"><a class="uk-button uk-button-default" href="' . $this->e($this->exportUrl('refunds', $this->getRefundExportQuery($filters))) . '"><i class="fa fa-download uk-margin-small-right"></i>' . $this->e($this->_('Export CSV')) . '</a></div>';
        $out .= '</div>';
        $out .= $this->renderRefundSummary($events, $commerce);
        $out .= $this->renderRefundHealthPanel($events, $commerce);
        $out .= $this->renderRefundQuickFilters($filters);
        $out .= $this->renderRefundFilters($filters);

        $out .= '<div class="mrc-admin-table-wrap"><table class="uk-table uk-table-divider uk-table-hover uk-table-small mrc-admin-table">';
        $out .= '<thead><tr>';
        foreach ([$this->_('Time'), $this->_('Event'), $this->_('Order'), $this->_('Gateway'), $this->_('Gateway Status'), $this->_('Refund ID'), $this->_('Amount'), $this->_('Payment'), $this->_('Reason'), $this->_('Actions')] as $heading) {
            $out .= '<th>' . $this->e($heading) . '</th>';
        }
        $out .= '</tr></thead><tbody>';

        if (!$events) {
            $out .= $this->renderSkeletonRows(4, 10);
            $out .= '</tbody></table></div>';
            return $out . '<p class="uk-text-muted mrc-admin-empty-note">' . $this->e($this->_('No refund events logged yet.')) . '</p></section>';
        }

        foreach ($events as $event) {
            $paymentStatus = strtolower((string) ($event['payment_status'] ?? ''));
            $gatewayStatus = strtolower((string) ($event['gateway_status'] ?? ''));
            $isPending = str_contains($paymentStatus, 'refund_pending') || (float) ($event['pending_amount'] ?? 0) > 0 || in_array($gatewayStatus, ['pending', 'queued', 'processing'], true);
            $statusClass = $isPending
                ? 'is-pending'
                : (in_array($gatewayStatus, ['failed', 'rejected', 'canceled', 'cancelled'], true) ? 'is-failed' : 'is-paid');
            $orderId = (int) ($event['order_id'] ?? 0);
            $orderHtml = $orderId > 0 ? '#' . $orderId : '-';
            $actionsHtml = '<span class="uk-text-muted">-</span>';
            if ($orderId > 0) {
                $order = $this->wire('pages')->get($orderId);
                if ($order && $order->id) {
                    $label = (string) ($order->mrc_invoice_number ?: $order->title ?: '#' . $orderId);
                    $orderHtml = '<a href="' . $this->e($this->orderDetailUrl($order)) . '">' . $this->e($label) . '</a>'
                        . '<br><small><a href="' . $this->e($this->timelineUrl($order)) . '">' . $this->e($this->_('Timeline')) . '</a></small>';
                    $actionsHtml = '<a class="uk-button uk-button-default" href="' . $this->e($this->orderDetailUrl($order)) . '"><i class="fa fa-file-text-o uk-margin-small-right"></i>' . $this->e($this->_('Detail')) . '</a>';
                    $actionsHtml .= '<a class="uk-button uk-button-default" href="' . $this->e($this->timelineUrl($order)) . '"><i class="fa fa-clock-o uk-margin-small-right"></i>' . $this->e($this->_('Timeline')) . '</a>';
                    if ($isPending) {
                        $actionsHtml .= '<form method="post" class="mrc-inline-form" action="' . $this->e($this->orderDetailUrl($order)) . '">';
                        $actionsHtml .= $this->renderCsrfInput();
                        $actionsHtml .= '<input type="hidden" name="mrc_reconcile_refund" value="1"><input type="hidden" name="order_id" value="' . (int) $order->id . '">';
                        $actionsHtml .= '<button class="uk-button uk-button-primary" type="submit"><i class="fa fa-refresh uk-margin-small-right"></i>' . $this->e($this->_('Check status')) . '</button></form>';
                    }
                }
            }
            $amount = (float) ($event['amount'] ?? 0);

            $out .= '<tr>';
            $out .= '<td>' . $this->e((string) ($event['_time'] ?? '-')) . '</td>';
            $out .= '<td><span class="uk-label mrc-admin-status ' . $statusClass . '">' . $this->e((string) ($event['event'] ?? '-')) . '</span></td>';
            $out .= '<td>' . $orderHtml . '</td>';
            $out .= '<td>' . $this->e((string) ($event['gateway'] ?? '-')) . '</td>';
            $out .= '<td>' . $this->e((string) ($event['gateway_status'] ?? '-')) . '</td>';
            $out .= '<td><small>' . $this->e((string) ($event['refund_id'] ?? '-')) . '</small></td>';
            $out .= '<td>' . $this->e($amount > 0 ? $commerce->formatPrice($amount) : '-') . '</td>';
            $out .= '<td><small>' . $this->e((string) ($event['payment_status'] ?? '-')) . '</small></td>';
            $out .= '<td><small>' . $this->e((string) ($event['reason'] ?? '-')) . '</small></td>';
            $out .= '<td class="mrc-table-actions">' . $actionsHtml . '</td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div></section>';
        return $out;
    }

    protected function renderRefundQuickFilters(array $filters): string {
        $activeGateway = (string) ($filters['gateway'] ?? 'all');
        $activeState = (string) ($filters['state'] ?? 'all');
        $activeOrder = (int) ($filters['order'] ?? 0);
        $chips = [
            [$this->_('All refunds'), ['gateway' => 'all', 'state' => 'all']],
            [$this->_('Pending'), ['gateway' => 'all', 'state' => 'pending']],
            [$this->_('Reconciled'), ['gateway' => 'all', 'state' => 'reconciled']],
            [$this->_('Failed'), ['gateway' => 'all', 'state' => 'failed']],
            [$this->_('Stripe'), ['gateway' => 'stripe', 'state' => 'all']],
            [$this->_('Mollie'), ['gateway' => 'mollie', 'state' => 'all']],
            [$this->_('PayPal'), ['gateway' => 'paypal', 'state' => 'all']],
            [$this->_('Demo'), ['gateway' => 'demo', 'state' => 'all']],
        ];

        $out = '<div class="mrc-refund-quickfilters">';
        $out .= '<span class="ds-section-label">' . $this->e($this->_('Quick filters')) . '</span>';
        foreach ($chips as [$label, $query]) {
            $queryGateway = (string) ($query['gateway'] ?? 'all');
            $queryState = (string) ($query['state'] ?? 'all');
            $active = $activeOrder === 0 && (
                ($queryGateway === 'all' && $queryState === 'all' && $activeGateway === 'all' && $activeState === 'all')
                || ($queryGateway !== 'all' && $queryGateway === $activeGateway && $queryState === 'all')
                || ($queryState !== 'all' && $queryState === $activeState && $queryGateway === 'all')
            );
            $url = $this->adminUrl('refunds/') . '?' . http_build_query($query);
            $out .= '<a class="uk-button uk-button-default' . ($active ? ' is-active' : '') . '" href="' . $this->e($url) . '">' . $this->e((string) $label) . '</a>';
        }
        if ($activeOrder > 0) {
            $out .= '<span class="mrc-attempt-order-chip">' . $this->e(sprintf($this->_('Order #%d filter active'), $activeOrder)) . '</span>';
        }
        $out .= '</div>';

        return $out;
    }

    protected function renderRefundFilters(array $filters): string {
        $gateway = (string) ($filters['gateway'] ?? 'all');
        $state = (string) ($filters['state'] ?? 'all');
        $orderId = (int) ($filters['order'] ?? 0);
        $gateways = $this->getGatewayFilterOptions(true, true);
        $states = [
            'all' => $this->_('All states'),
            'issued' => $this->_('Issued'),
            'reconciled' => $this->_('Reconciled'),
            'pending' => $this->_('Pending'),
            'failed' => $this->_('Failed'),
        ];

        $out = '<form method="get" class="mrc-webhook-filters">';
        $out .= '<label><span>' . $this->e($this->_('Gateway')) . '</span><select name="gateway">';
        foreach ($gateways as $value => $label) {
            $out .= '<option value="' . $this->e($value) . '"' . ($gateway === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('State')) . '</span><select name="state">';
        foreach ($states as $value => $label) {
            $out .= '<option value="' . $this->e($value) . '"' . ($state === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }
        $out .= '</select></label>';
        $out .= '<label><span>' . $this->e($this->_('Order ID')) . '</span><input class="uk-input" type="number" name="order" min="0" value="' . ($orderId > 0 ? (int) $orderId : '') . '" placeholder="' . $this->e($this->_('Any')) . '"></label>';
        $out .= '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-filter uk-margin-small-right"></i>' . $this->e($this->_('Apply filters')) . '</button>';
        if ($gateway !== 'all' || $state !== 'all' || $orderId > 0) {
            $out .= '<a class="uk-button uk-button-default" href="' . $this->e($this->adminUrl('refunds/')) . '">' . $this->e($this->_('Reset')) . '</a>';
        }
        $out .= '</form>';
        return $out;
    }

    protected function renderRefundSummary(array $events, Mercato $commerce): string {
        $issued = 0;
        $reconciled = 0;
        $pending = 0;
        $amount = 0.0;
        foreach ($events as $event) {
            $eventName = (string) ($event['event'] ?? '');
            if ($eventName === 'refund_issued') {
                $issued++;
            } elseif ($eventName === 'refund_reconciled') {
                $reconciled++;
            }
            if (str_contains(strtolower((string) ($event['payment_status'] ?? '')), 'refund_pending')
                || (float) ($event['pending_amount'] ?? 0) > 0
            ) {
                $pending++;
            }
            $amount += max(0, (float) ($event['amount'] ?? 0));
        }

        $cards = [
            [$this->_('Refund events'), (string) count($events), $this->_('Current audit window'), 'is-neutral'],
            [$this->_('Issued'), (string) $issued, $this->_('Provider refund requested'), 'is-pending'],
            [$this->_('Reconciled'), (string) $reconciled, $this->_('Provider result applied'), 'is-paid'],
            [$this->_('Pending'), (string) $pending, $this->_('Awaiting provider confirmation'), $pending > 0 ? 'is-pending' : 'is-paid'],
            [$this->_('Amount'), $commerce->formatPrice($amount), $this->_('Logged refund amount'), 'is-neutral'],
        ];

        $out = '<div class="mrc-refund-summary">';
        foreach ($cards as [$label, $value, $caption, $class]) {
            $out .= '<div class="mrc-refund-summary-card ' . $class . '">';
            $out .= '<span>' . $this->e((string) $label) . '</span>';
            $out .= '<strong>' . $this->e((string) $value) . '</strong>';
            $out .= '<small>' . $this->e((string) $caption) . '</small>';
            $out .= '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    protected function renderRefundHealthPanel(array $events, Mercato $commerce): string {
        $latest = $events[0] ?? null;
        $latestPending = null;
        $latestFailure = null;
        foreach ($events as $event) {
            $gatewayStatus = strtolower((string) ($event['gateway_status'] ?? ''));
            $paymentStatus = strtolower((string) ($event['payment_status'] ?? ''));
            if ($latestFailure === null && in_array($gatewayStatus, ['failed', 'rejected', 'canceled', 'cancelled'], true)) {
                $latestFailure = $event;
            }
            if ($latestPending === null && (str_contains($paymentStatus, 'refund_pending') || (float) ($event['pending_amount'] ?? 0) > 0 || in_array($gatewayStatus, ['pending', 'queued', 'processing'], true))) {
                $latestPending = $event;
            }
            if ($latestFailure !== null && $latestPending !== null) {
                break;
            }
        }

        $latestLabel = $latest
            ? trim((string) ($latest['gateway'] ?? 'gateway') . ' / ' . (string) ($latest['event'] ?? 'event') . ' / ' . (string) ($latest['gateway_status'] ?? 'status'), ' /')
            : $this->_('No refund events yet');
        $pendingLabel = $latestPending
            ? trim((string) ($latestPending['_time'] ?? '') . ' ' . $commerce->formatPrice((float) ($latestPending['pending_amount'] ?? $latestPending['amount'] ?? 0)), ' ')
            : $this->_('No pending refunds in the audit window');
        $failureLabel = $latestFailure
            ? trim((string) ($latestFailure['_time'] ?? '') . ' ' . (string) ($latestFailure['reason'] ?? ''), ' ')
            : $this->_('No rejected refunds in the audit window');
        $action = $latestFailure
            ? $this->_('Open the order, review provider status, and avoid duplicate refunds.')
            : ($latestPending ? $this->_('Check refund status from the order detail page until the provider confirms.') : $this->_('Refund audit looks healthy.'));

        $cards = [
            [$this->_('Latest refund event'), $latestLabel, $latest ? (string) ($latest['_time'] ?? '') : '-', 'is-neutral'],
            [$this->_('Pending provider result'), $latestPending ? $this->_('Yes') : $this->_('No'), $pendingLabel, $latestPending ? 'is-pending' : 'is-paid'],
            [$this->_('Provider rejection'), $latestFailure ? $this->_('Review') : $this->_('None'), $failureLabel, $latestFailure ? 'is-failed' : 'is-paid'],
            [$this->_('Operator action'), $latestFailure || $latestPending ? $this->_('Review') : $this->_('None'), $action, $latestFailure ? 'is-failed' : ($latestPending ? 'is-pending' : 'is-neutral')],
        ];

        $out = '<div class="mrc-refund-health">';
        $out .= '<div class="mrc-refund-health-head"><span class="ds-section-label">' . $this->e($this->_('Refund visibility')) . '</span><h3>' . $this->e($this->_('Operational health')) . '</h3></div>';
        $out .= '<div class="mrc-refund-health-grid">';
        foreach ($cards as [$label, $value, $caption, $class]) {
            $out .= '<div class="mrc-refund-health-card ' . $class . '">';
            $out .= '<span>' . $this->e((string) $label) . '</span>';
            $out .= '<strong>' . $this->e((string) $value) . '</strong>';
            $out .= '<small>' . $this->e((string) $caption) . '</small>';
            $out .= '</div>';
        }
        $out .= '</div></div>';
        return $out;
    }
}
