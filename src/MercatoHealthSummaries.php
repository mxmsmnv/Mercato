<?php
namespace ProcessWire;

trait MercatoHealthSummaries {
    public function recordEvent(string $logName, array $payload, string $fallback = 'event'): array {
        $this->requireArchitectureClasses();
        $eventLog = new MercatoEventLog($logName);
        $eventLog->setWire($this->wire());
        return $eventLog->record($payload, $fallback);
    }

    public function getAdminAuditStreams(): array {
        $streams = [
            'payments' => [
                'label' => 'Payments',
                'log' => 'mercato-payments',
                'export' => 'payments',
            ],
            'refunds' => [
                'label' => 'Refunds',
                'log' => 'mercato-refunds',
                'export' => 'refunds',
            ],
            'recovery' => [
                'label' => 'Recovery',
                'log' => 'mercato-recovery',
                'export' => 'recovery-events',
            ],
            'fulfilment' => [
                'label' => 'Fulfilment',
                'log' => 'mercato-fulfilment',
                'export' => 'fulfilment',
            ],
            'notifications' => [
                'label' => 'Notifications',
                'log' => 'mercato-notifications',
                'export' => 'notifications',
            ],
            'inventory' => [
                'label' => 'Inventory',
                'log' => 'mercato-inventory',
                'export' => 'inventory',
            ],
            'products' => [
                'label' => 'Product activity',
                'log' => 'mercato-products',
                'export' => 'products',
            ],
            'webhooks' => [
                'label' => 'Webhooks',
                'log' => 'mercato-webhooks',
                'export' => 'webhooks',
            ],
            'discounts' => [
                'label' => 'Discount activity',
                'log' => 'mercato-discounts',
                'export' => 'discount-activity',
            ],
            'store_credit' => [
                'label' => 'Store credit',
                'log' => 'mercato-store-credit',
                'export' => null,
            ],
            'settings' => [
                'label' => 'Settings',
                'log' => 'mercato-settings',
                'export' => null,
            ],
            'order_edits' => [
                'label' => 'Order edits',
                'log' => 'mercato-order-edits',
                'export' => 'order-edits',
            ],
            'order_notes' => [
                'label' => 'Order notes',
                'log' => 'mercato-order-notes',
                'export' => 'order-notes',
            ],
            'customer_notes' => [
                'label' => 'Customer notes',
                'log' => 'mercato-customer-notes',
                'export' => 'customer-notes',
            ],
            'returns' => [
                'label' => 'Returns',
                'log' => 'mercato-returns',
                'export' => null,
            ],
            'events' => [
                'label' => 'Order events',
                'log' => 'mercato-events',
                'export' => null,
            ],
        ];

        $logsPath = rtrim((string) $this->wire('config')->paths->logs, '/');
        foreach ($streams as $key => $stream) {
            $log = (string) ($stream['log'] ?? '');
            $path = $log !== '' ? $logsPath . '/' . $log . '.txt' : '';
            $streams[$key]['file'] = $path;
            $streams[$key]['file_exists'] = $path !== '' && is_file($path);
            $streams[$key]['size'] = $path !== '' && is_file($path) ? (int) filesize($path) : 0;
        }

        return $streams;
    }

    public function getWebhookHealthSummary(int $limit = 250): array {
        $this->requireArchitectureClasses();
        $eventLog = new MercatoWebhookEventLog();
        $eventLog->setWire($this->wire());
        $events = $eventLog->readRecentEvents(max(1, $limit));

        $statuses = [
            'received' => 0,
            'processed' => 0,
            'ignored' => 0,
            'failed' => 0,
        ];
        $gateways = [];
        $latestFailure = null;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $status = strtolower((string) ($event['status'] ?? ''));
            if ($status !== '') {
                $statuses[$status] = (int) ($statuses[$status] ?? 0) + 1;
            }
            $gateway = strtolower((string) ($event['gateway'] ?? ''));
            if ($gateway !== '') {
                $gateways[$gateway] = (int) ($gateways[$gateway] ?? 0) + 1;
            }
            if ($status === 'failed' && $latestFailure === null) {
                $latestFailure = $this->summarizeWebhookEvent($event);
            }
        }

        $received = (int) ($statuses['received'] ?? 0);
        $processed = (int) ($statuses['processed'] ?? 0);
        $failed = (int) ($statuses['failed'] ?? 0);
        $action = $failed > 0
            ? 'review_failed_events'
            : ($received > 0 && $processed === 0 ? 'check_received_events' : 'none');

        ksort($gateways);

        return [
            'total' => count($events),
            'statuses' => $statuses,
            'gateways' => $gateways,
            'latest' => isset($events[0]) && is_array($events[0]) ? $this->summarizeWebhookEvent($events[0]) : null,
            'latest_failure' => $latestFailure,
            'action' => $action,
        ];
    }

    protected function summarizeWebhookEvent(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'gateway' => (string) ($event['gateway'] ?? ''),
            'event_type' => (string) ($event['event_type'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'event_id' => (string) ($event['event_id'] ?? ''),
            'order_page_id' => (int) ($event['order_page_id'] ?? 0),
            'external_payment_id' => (string) ($event['external_payment_id'] ?? ''),
            'message' => (string) ($event['message'] ?? ''),
        ];
    }

    public function getNotificationHealthSummary(int $limit = 250): array {
        $events = $this->readNotificationEvents(max(1, $limit));
        $statuses = [
            'sent' => 0,
            'failed' => 0,
        ];
        $types = [];
        $latestFailure = null;

        foreach ($events as $event) {
            $status = strtolower((string) ($event['status'] ?? ''));
            if ($status !== '') {
                $statuses[$status] = (int) ($statuses[$status] ?? 0) + 1;
            }
            $type = (string) ($event['event'] ?? '');
            if ($type !== '') {
                $types[$type] = (int) ($types[$type] ?? 0) + 1;
            }
            if ($status === 'failed' && $latestFailure === null) {
                $latestFailure = $this->summarizeNotificationEvent($event);
            }
        }

        ksort($types);
        $failed = (int) ($statuses['failed'] ?? 0);

        return [
            'total' => count($events),
            'statuses' => $statuses,
            'types' => $types,
            'latest' => isset($events[0]) ? $this->summarizeNotificationEvent($events[0]) : null,
            'latest_failure' => $latestFailure,
            'action' => $failed > 0 ? 'review_failed_notifications' : 'none',
        ];
    }

    protected function readNotificationEvents(int $limit = 250): array {
        $config = $this->wire('config');
        $logsPath = is_object($config) ? (string) ($config->paths->logs ?? '') : '';
        $logFile = $logsPath !== '' ? rtrim($logsPath, '/') . '/mercato-notifications.txt' : '';
        if ($logFile === '' || !is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        $allowed = ['shipping_email', 'pickup_ready_email', 'local_delivery_email', 'order_confirmation_email', 'payment_link_email', 'recovery_email', 'test_email'];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseJsonLogLine((string) $line);
            if (!$event || !in_array((string) ($event['event'] ?? ''), $allowed, true)) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    protected function summarizeNotificationEvent(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'order_id' => (int) ($event['order_id'] ?? 0),
            'invoice' => (string) ($event['invoice'] ?? ''),
            'recipient' => (string) ($event['recipient'] ?? ($event['email'] ?? '')),
            'message' => (string) ($event['message'] ?? ''),
        ];
    }

    public function getPaymentOperationsHealthSummary(int $limit = 250): array {
        $limit = max(1, $limit);
        $attempts = $this->readJsonLogEvents('mercato-payment-attempts', $limit);
        $payments = $this->readJsonLogEvents('mercato-payments', $limit, ['manual_reconciliation']);
        $refunds = $this->readJsonLogEvents('mercato-refunds', $limit, ['refund_issued', 'refund_reconciled']);

        $attemptEvents = [];
        $attemptStatuses = [];
        $attemptGateways = [];
        $latestAttemptFailure = null;
        foreach ($attempts as $event) {
            $eventName = strtolower((string) ($event['event'] ?? ''));
            $status = strtolower((string) ($event['status'] ?? ''));
            $gateway = strtolower((string) ($event['gateway'] ?? ''));
            if ($eventName !== '') {
                $attemptEvents[$eventName] = (int) ($attemptEvents[$eventName] ?? 0) + 1;
            }
            if ($status !== '') {
                $attemptStatuses[$status] = (int) ($attemptStatuses[$status] ?? 0) + 1;
            }
            if ($gateway !== '') {
                $attemptGateways[$gateway] = (int) ($attemptGateways[$gateway] ?? 0) + 1;
            }
            if ($latestAttemptFailure === null && $this->isPaymentAttemptFailure($event)) {
                $latestAttemptFailure = $this->summarizePaymentAttemptOperation($event);
            }
        }

        $reconciliationTransitions = [];
        foreach ($payments as $event) {
            $from = strtolower((string) ($event['from'] ?? ''));
            $to = strtolower((string) ($event['to'] ?? ''));
            $transition = trim($from . '_to_' . $to, '_');
            if ($transition !== '' && $transition !== 'to') {
                $reconciliationTransitions[$transition] = (int) ($reconciliationTransitions[$transition] ?? 0) + 1;
            }
        }

        $refundEvents = [];
        $refundGatewayStatuses = [];
        $pendingRefunds = 0;
        $failedRefunds = 0;
        $latestPendingRefund = null;
        $latestRefundFailure = null;
        foreach ($refunds as $event) {
            $eventName = strtolower((string) ($event['event'] ?? ''));
            $gatewayStatus = strtolower((string) ($event['gateway_status'] ?? ''));
            if ($eventName !== '') {
                $refundEvents[$eventName] = (int) ($refundEvents[$eventName] ?? 0) + 1;
            }
            if ($gatewayStatus !== '') {
                $refundGatewayStatuses[$gatewayStatus] = (int) ($refundGatewayStatuses[$gatewayStatus] ?? 0) + 1;
            }
            if ($this->isRefundPending($event)) {
                $pendingRefunds++;
                if ($latestPendingRefund === null) {
                    $latestPendingRefund = $this->summarizeRefundOperation($event);
                }
            }
            if ($this->isRefundFailure($event)) {
                $failedRefunds++;
                if ($latestRefundFailure === null) {
                    $latestRefundFailure = $this->summarizeRefundOperation($event);
                }
            }
        }

        ksort($attemptEvents);
        ksort($attemptStatuses);
        ksort($attemptGateways);
        ksort($reconciliationTransitions);
        ksort($refundEvents);
        ksort($refundGatewayStatuses);

        $failedAttempts = 0;
        foreach ($attempts as $event) {
            if ($this->isPaymentAttemptFailure($event)) {
                $failedAttempts++;
            }
        }
        $action = $failedRefunds > 0
            ? 'review_failed_refunds'
            : ($pendingRefunds > 0 ? 'check_pending_refunds' : ($failedAttempts > 0 ? 'review_failed_payment_attempts' : 'none'));

        return [
            'attempts' => [
                'total' => count($attempts),
                'events' => $attemptEvents,
                'statuses' => $attemptStatuses,
                'gateways' => $attemptGateways,
                'failed' => $failedAttempts,
                'latest' => isset($attempts[0]) ? $this->summarizePaymentAttemptOperation($attempts[0]) : null,
                'latest_failure' => $latestAttemptFailure,
            ],
            'reconciliations' => [
                'total' => count($payments),
                'transitions' => $reconciliationTransitions,
                'latest' => isset($payments[0]) ? $this->summarizePaymentReconciliationOperation($payments[0]) : null,
            ],
            'refunds' => [
                'total' => count($refunds),
                'events' => $refundEvents,
                'gateway_statuses' => $refundGatewayStatuses,
                'pending' => $pendingRefunds,
                'failed' => $failedRefunds,
                'latest' => isset($refunds[0]) ? $this->summarizeRefundOperation($refunds[0]) : null,
                'latest_pending' => $latestPendingRefund,
                'latest_failure' => $latestRefundFailure,
            ],
            'action' => $action,
        ];
    }

    protected function readJsonLogEvents(string $logName, int $limit = 250, array $allowedEvents = []): array {
        $config = $this->wire('config');
        $logsPath = is_object($config) ? (string) ($config->paths->logs ?? '') : '';
        $safeLogName = preg_replace('/[^a-z0-9_-]/i', '', $logName);
        $logFile = $logsPath !== '' && $safeLogName !== '' ? rtrim($logsPath, '/') . '/' . $safeLogName . '.txt' : '';
        if ($logFile === '' || !is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseJsonLogLine((string) $line);
            if (!$event) {
                continue;
            }
            if ($allowedEvents && !in_array((string) ($event['event'] ?? ''), $allowedEvents, true)) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    protected function summarizePaymentAttemptOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'gateway' => (string) ($event['gateway'] ?? ''),
            'method' => (string) ($event['method'] ?? ''),
            'order_page_id' => (int) ($event['order_page_id'] ?? 0),
            'attempt_id' => (string) ($event['id'] ?? ''),
            'external_id' => (string) ($event['external_id'] ?? ''),
        ];
    }

    protected function summarizePaymentReconciliationOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'order_id' => (int) ($event['order_id'] ?? 0),
            'invoice' => (string) ($event['invoice'] ?? ''),
            'from' => (string) ($event['from'] ?? ''),
            'to' => (string) ($event['to'] ?? ''),
            'reason' => (string) ($event['reason'] ?? ''),
            'user' => (string) ($event['user'] ?? ''),
        ];
    }

    protected function summarizeRefundOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'order_id' => (int) ($event['order_id'] ?? 0),
            'invoice' => (string) ($event['invoice'] ?? ''),
            'gateway' => (string) ($event['gateway'] ?? ''),
            'refund_id' => (string) ($event['refund_id'] ?? ''),
            'gateway_status' => (string) ($event['gateway_status'] ?? ''),
            'payment_status' => (string) ($event['payment_status'] ?? ''),
            'pending_amount' => (float) ($event['pending_amount'] ?? 0),
            'reason' => (string) ($event['reason'] ?? ''),
        ];
    }

    protected function isPaymentAttemptFailure(array $event): bool {
        $eventName = strtolower((string) ($event['event'] ?? ''));
        $status = strtolower((string) ($event['status'] ?? ''));
        return in_array($eventName, ['failed', 'canceled', 'cancelled', 'expired'], true)
            || in_array($status, ['failed', 'canceled', 'cancelled', 'expired'], true)
            || str_contains($status, 'failed');
    }

    protected function isRefundPending(array $event): bool {
        $gatewayStatus = strtolower((string) ($event['gateway_status'] ?? ''));
        $paymentStatus = strtolower((string) ($event['payment_status'] ?? ''));
        return str_contains($paymentStatus, 'refund_pending')
            || (float) ($event['pending_amount'] ?? 0) > 0
            || in_array($gatewayStatus, ['pending', 'queued', 'processing'], true);
    }

    protected function isRefundFailure(array $event): bool {
        $gatewayStatus = strtolower((string) ($event['gateway_status'] ?? ''));
        return in_array($gatewayStatus, ['failed', 'rejected', 'canceled', 'cancelled'], true);
    }

    public function getFulfilmentHealthSummary(int $limit = 250): array {
        $this->requireArchitectureClasses();

        $orders = $this->getFulfilmentHealthOrders();
        $queue = [
            'all' => 0,
            'active' => 0,
            'attention' => 0,
            'backorder' => 0,
            'preorder' => 0,
            'completed' => 0,
        ];
        $statuses = [];
        $methods = [];

        foreach ($orders as $order) {
            if (!$order instanceof Page || !$this->isOrderPaidForFulfilment($order)) {
                continue;
            }

            $status = $this->getOrderFulfilmentHealthStatus($order);
            $method = $this->getOrderFulfilmentHealthMethod($order);
            $statuses[$status] = (int) ($statuses[$status] ?? 0) + 1;
            if ($method !== '') {
                $methods[$method] = (int) ($methods[$method] ?? 0) + 1;
            }

            $queue['all']++;
            if (in_array($status, [MercatoFulfilmentStatus::FULFILLED, MercatoFulfilmentStatus::SHIPPED, MercatoFulfilmentStatus::COLLECTED, MercatoFulfilmentStatus::DELIVERED], true)) {
                $queue['completed']++;
                continue;
            }
            if ($status === 'attention') {
                $queue['attention']++;
            } elseif ($status === 'backorder') {
                $queue['backorder']++;
            } elseif ($status === 'preorder') {
                $queue['preorder']++;
            } else {
                $queue['active']++;
            }
        }

        $events = $this->readJsonLogEvents('mercato-fulfilment', max(1, $limit), ['status_updated', 'partial_fulfilment_recorded', 'shipment_recorded']);
        $eventTypes = [];
        $eventStatuses = [];
        $latestReturn = null;
        foreach ($events as $event) {
            $eventName = (string) ($event['event'] ?? '');
            $status = (string) ($event['to'] ?? ($event['status'] ?? ''));
            if ($eventName !== '') {
                $eventTypes[$eventName] = (int) ($eventTypes[$eventName] ?? 0) + 1;
            }
            if ($status !== '') {
                $eventStatuses[$status] = (int) ($eventStatuses[$status] ?? 0) + 1;
            }
            if ($latestReturn === null && $status === MercatoFulfilmentStatus::RETURNED) {
                $latestReturn = $this->summarizeFulfilmentOperation($event);
            }
        }

        ksort($statuses);
        ksort($methods);
        ksort($eventTypes);
        ksort($eventStatuses);

        $attention = (int) ($queue['attention'] + $queue['backorder'] + $queue['preorder']);
        $action = $attention > 0
            ? 'review_fulfilment_attention'
            : ((int) $queue['active'] > 0 ? 'work_fulfilment_queue' : 'none');

        return [
            'total_orders' => count($orders),
            'paid_orders' => (int) $queue['all'],
            'queue' => $queue,
            'statuses' => $statuses,
            'methods' => $methods,
            'events' => [
                'total' => count($events),
                'types' => $eventTypes,
                'statuses' => $eventStatuses,
                'latest' => isset($events[0]) ? $this->summarizeFulfilmentOperation($events[0]) : null,
                'latest_return' => $latestReturn,
            ],
            'action' => $action,
        ];
    }

    protected function getFulfilmentHealthOrders(): PageArray {
        $template = (string) ($this->order_template ?? 'mrc-order');
        $safeTemplate = $this->wire('sanitizer')->selectorValue($template);
        return $this->wire('pages')->find('template=' . $safeTemplate . ', include=all, sort=-created, limit=10000');
    }

    protected function isOrderPaidForFulfilment(Page $order): bool {
        $status = strtolower(trim((string) ($order->mrc_payment_status ?? '')));
        return (int) ($order->mrc_payment_complete ?? 0) === 1
            || in_array($status, [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED], true);
    }

    protected function getOrderFulfilmentHealthStatus(Page $order): string {
        $status = strtolower(trim((string) ($order->mrc_fulfilment_status ?? '')));
        if ($status !== '' && MercatoFulfilmentStatus::isValid($status)) {
            return $status;
        }

        $policies = $this->getOrderFulfilmentPolicyCounts($order);
        if ((int) ($policies['backorder'] ?? 0) > 0) {
            return 'backorder';
        }
        if ((int) ($policies['preorder'] ?? 0) > 0) {
            return 'preorder';
        }

        return MercatoFulfilmentStatus::UNFULFILLED;
    }

    protected function getOrderFulfilmentHealthMethod(Page $order): string {
        $method = strtolower(trim((string) ($order->mrc_fulfilment_method ?? '')));
        if ($method !== '' && MercatoFulfilmentMethodType::isValid($method)) {
            return $method;
        }
        return $this->getDefaultFulfilmentMethod();
    }

    protected function getOrderFulfilmentPolicyCounts(Page $order): array {
        $items = json_decode((string) ($order->mrc_items ?? ''), true);
        $counts = ['backorder' => 0, 'preorder' => 0];
        if (!is_array($items)) {
            return $counts;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $policy = strtolower(trim((string) ($item['stock_policy'] ?? $item['inventory_policy'] ?? '')));
            if (isset($counts[$policy])) {
                $counts[$policy]++;
            }
        }

        return $counts;
    }

    protected function summarizeFulfilmentOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'order_id' => (int) ($event['order_id'] ?? 0),
            'invoice' => (string) ($event['invoice'] ?? ''),
            'from' => (string) ($event['from'] ?? ''),
            'to' => (string) ($event['to'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'method' => (string) ($event['method'] ?? ''),
            'tracking_changed' => (bool) ($event['tracking_changed'] ?? false),
        ];
    }

    public function getRecoveryHealthSummary(int $limit = 250): array {
        $orders = $this->getRecoveryHealthOrders();
        $latestEmails = $this->getLatestRecoveryHealthEmailsByOrder();
        $minAge = $this->getRecoveryAutomationMinAgeMinutes();
        $cooldown = $this->getRecoveryEmailCooldownMinutes();
        $now = time();

        $paymentStatuses = [];
        $gateways = [];
        $eligibility = [
            'eligible' => 0,
            'too_recent' => 0,
            'cooldown' => 0,
            'no_email' => 0,
            'suppressed' => 0,
        ];
        $oldestAgeMinutes = 0;

        foreach ($orders as $order) {
            if (!$order instanceof Page || !$order->id) {
                continue;
            }
            if ((int) ($order->mrc_payment_complete ?? 0) === 1) {
                continue;
            }

            $status = strtolower(trim((string) ($order->mrc_payment_status ?? 'pending'))) ?: 'pending';
            if ($status === MercatoPaymentStatus::PAID) {
                continue;
            }
            $paymentStatuses[$status] = (int) ($paymentStatuses[$status] ?? 0) + 1;

            $gateway = strtolower(trim((string) ($order->mrc_payment_method ?? $order->mrc_gateway ?? 'unknown'))) ?: 'unknown';
            $gateways[$gateway] = (int) ($gateways[$gateway] ?? 0) + 1;

            $ageMinutes = max(0, (int) floor(($now - (int) $order->created) / 60));
            $oldestAgeMinutes = max($oldestAgeMinutes, $ageMinutes);

            $email = strtolower((string) $this->wire('sanitizer')->email((string) ($order->mrc_email ?? '')));
            if ($email === '') {
                $eligibility['no_email']++;
                continue;
            }
            if ($this->isRecoveryEmailSuppressed($email)) {
                $eligibility['suppressed']++;
                continue;
            }
            if ($ageMinutes < $minAge) {
                $eligibility['too_recent']++;
                continue;
            }

            $latestEmail = (array) ($latestEmails[(int) $order->id] ?? []);
            $sentAt = $this->getLogEventTimestamp($latestEmail);
            if ($sentAt > 0 && ($cooldown - max(0, (int) floor(($now - $sentAt) / 60))) > 0) {
                $eligibility['cooldown']++;
                continue;
            }

            $eligibility['eligible']++;
        }

        $events = $this->readJsonLogEvents('mercato-recovery', max(1, $limit));
        $eventTypes = [];
        $eventStatuses = [];
        $latestFailure = null;
        $latestAutomationRun = null;
        foreach ($events as $event) {
            $eventName = (string) ($event['event'] ?? '');
            $status = strtolower((string) ($event['status'] ?? ''));
            if ($eventName !== '') {
                $eventTypes[$eventName] = (int) ($eventTypes[$eventName] ?? 0) + 1;
            }
            if ($status !== '') {
                $eventStatuses[$status] = (int) ($eventStatuses[$status] ?? 0) + 1;
            }
            if ($latestFailure === null && in_array($status, ['failed', 'blocked'], true)) {
                $latestFailure = $this->summarizeRecoveryOperation($event);
            }
            if ($latestAutomationRun === null && $eventName === 'recovery_automation_run') {
                $latestAutomationRun = $this->summarizeRecoveryOperation($event);
            }
        }

        ksort($paymentStatuses);
        ksort($gateways);
        ksort($eventTypes);
        ksort($eventStatuses);

        $automationEnabled = (bool) ($this->recovery_automation_enabled ?? false) && $this->getRecoveryAutomationSchedule() !== 'disabled';
        $action = $latestFailure !== null
            ? 'review_recovery_failures'
            : ((int) $eligibility['eligible'] > 0 ? ($automationEnabled ? 'run_recovery_automation' : 'send_recovery_emails') : 'none');

        return [
            'unpaid_orders' => array_sum($paymentStatuses),
            'payment_statuses' => $paymentStatuses,
            'gateways' => $gateways,
            'eligibility' => $eligibility,
            'oldest_age_minutes' => $oldestAgeMinutes,
            'automation' => [
                'enabled' => $automationEnabled,
                'schedule' => $this->getRecoveryAutomationSchedule(),
                'min_age_minutes' => $minAge,
                'batch_limit' => $this->getRecoveryAutomationBatchLimit(),
                'cooldown_minutes' => $cooldown,
                'suppressed_emails' => count($this->getRecoverySuppressedEmails()),
                'discount_configured' => $this->getRecoveryDiscountCode() !== '',
            ],
            'events' => [
                'total' => count($events),
                'types' => $eventTypes,
                'statuses' => $eventStatuses,
                'latest' => isset($events[0]) ? $this->summarizeRecoveryOperation($events[0]) : null,
                'latest_failure' => $latestFailure,
                'latest_automation_run' => $latestAutomationRun,
            ],
            'action' => $action,
        ];
    }

    protected function getRecoveryHealthOrders(): PageArray {
        $template = (string) ($this->order_template ?? 'mrc-order');
        $safeTemplate = $this->wire('sanitizer')->selectorValue($template);
        return $this->wire('pages')->find('template=' . $safeTemplate . ', include=all, mrc_payment_complete=0, sort=created, limit=10000');
    }

    protected function getLatestRecoveryHealthEmailsByOrder(): array {
        $map = [];
        foreach ([
            ['mercato-recovery', ['recovery_email']],
            ['mercato-notifications', ['payment_link_email', 'recovery_email']],
        ] as [$logName, $allowedEvents]) {
            foreach ($this->readJsonLogEvents($logName, 10000, $allowedEvents) as $event) {
                if ((string) ($event['status'] ?? '') !== 'sent') {
                    continue;
                }
                $orderId = (int) ($event['order_id'] ?? 0);
                if ($orderId <= 0) {
                    continue;
                }
                $eventTime = $this->getLogEventTimestamp($event);
                $existingTime = isset($map[$orderId]) ? $this->getLogEventTimestamp((array) $map[$orderId]) : 0;
                if (!isset($map[$orderId]) || $eventTime >= $existingTime) {
                    $map[$orderId] = $event;
                }
            }
        }
        return $map;
    }

    protected function summarizeRecoveryOperation(array $event): array {
        return [
            'time' => (string) ($event['_time'] ?? ($event['at'] ?? '')),
            'event' => (string) ($event['event'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'order_id' => (int) ($event['order_id'] ?? 0),
            'invoice' => (string) ($event['invoice'] ?? ''),
            'recipient' => (string) ($event['recipient'] ?? ($event['email'] ?? '')),
            'message' => (string) ($event['message'] ?? ''),
            'checked' => (int) ($event['checked'] ?? 0),
            'eligible' => (int) ($event['eligible'] ?? 0),
            'sent' => (int) ($event['sent'] ?? 0),
            'failed' => (int) ($event['failed'] ?? 0),
            'blocked' => (int) ($event['blocked'] ?? 0),
        ];
    }

    protected function getLogEventTimestamp(array $event): int {
        $time = trim((string) ($event['_time'] ?? ($event['at'] ?? '')));
        if ($time === '' || $time === '-') {
            return 0;
        }
        if (preg_match('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $time, $matches)) {
            $time = $matches[0];
        }
        $timestamp = strtotime($time);
        return $timestamp !== false ? (int) $timestamp : 0;
    }

    public function getInventoryHealthSummary(): array {
        $productTemplate = (string) ($this->product_template ?? $this->cart_template ?? 'mrc-product');
        $safeTemplate = $this->wire('sanitizer')->selectorValue($productTemplate);
        $products = $this->wire('pages')->find('template=' . $safeTemplate . ', include=all, limit=10000');
        $states = [
            'in_stock' => 0,
            'low_stock' => 0,
            'out_of_stock' => 0,
            'backorder' => 0,
            'preorder' => 0,
        ];
        $owedUnits = 0;

        foreach ($products as $product) {
            if (!$product instanceof Page || !$product->hasField('mrc_stock')) {
                continue;
            }
            $state = $this->getProductInventoryState($product);
            $raw = (string) ($state['raw'] ?? 'in_stock');
            $states[$raw] = (int) ($states[$raw] ?? 0) + 1;
            if (in_array($raw, ['backorder', 'preorder'], true) && (int) $product->mrc_stock < 0) {
                $owedUnits += abs((int) $product->mrc_stock);
            }
        }

        $expiredReservations = $this->orderRepository()->countExpiredReservations();
        $attention = (int) ($states['low_stock'] ?? 0)
            + (int) ($states['out_of_stock'] ?? 0)
            + (int) ($states['backorder'] ?? 0)
            + (int) ($states['preorder'] ?? 0)
            + $expiredReservations;

        return [
            'products' => count($products),
            'states' => $states,
            'owed_units' => $owedUnits,
            'expired_reservations' => $expiredReservations,
            'attention_count' => $attention,
            'action' => $attention > 0 ? 'review_inventory' : 'none',
        ];
    }

    protected function getProductInventoryState(Page $product): array {
        $stock = $product->hasField('mrc_stock') ? (int) $product->mrc_stock : 0;
        $threshold = $product->hasField('mrc_low_stock_threshold') && (int) $product->mrc_low_stock_threshold > 0
            ? (int) $product->mrc_low_stock_threshold
            : $this->getLowStockThreshold();
        $policy = $product->hasField('mrc_stock_policy') ? strtolower(trim((string) $product->mrc_stock_policy)) : '';
        $policy = in_array($policy, ['deny', 'backorder', 'preorder'], true) ? $policy : 'deny';

        if ($stock <= 0 && $policy === 'backorder') {
            return ['raw' => 'backorder', 'stock' => $stock, 'threshold' => $threshold, 'policy' => $policy];
        }
        if ($stock <= 0 && $policy === 'preorder') {
            return ['raw' => 'preorder', 'stock' => $stock, 'threshold' => $threshold, 'policy' => $policy];
        }
        if ($stock <= 0) {
            return ['raw' => 'out_of_stock', 'stock' => $stock, 'threshold' => $threshold, 'policy' => $policy];
        }
        if ($threshold > 0 && $stock <= $threshold) {
            return ['raw' => 'low_stock', 'stock' => $stock, 'threshold' => $threshold, 'policy' => $policy];
        }
        return ['raw' => 'in_stock', 'stock' => $stock, 'threshold' => $threshold, 'policy' => $policy];
    }

    protected function recordSettingsAuditEvents(array $before, array $after): void {
        $actor = $this->getSettingsAuditActor();
        $at = date('c');

        if (!empty($before['production']) !== !empty($after['production'])) {
            $this->recordEvent('mercato-settings', [
                'event' => 'production_mode_toggled',
                'at' => $at,
                'user' => $actor,
                'from' => !empty($before['production']) ? 'enabled' : 'disabled',
                'to' => !empty($after['production']) ? 'enabled' : 'disabled',
            ], 'production_mode_toggled');
        }

        $gatewayChanges = self::getSettingsAuditGatewayChanges($before, $after);
        if ($gatewayChanges !== []) {
            $this->recordEvent('mercato-settings', [
                'event' => 'gateway_settings_changed',
                'at' => $at,
                'user' => $actor,
                'changes' => $gatewayChanges,
            ], 'gateway_settings_changed');
        }
    }

    protected function getSettingsAuditActor(): string {
        $user = $this->wire('user');
        return $user && $user->id ? (string) ($user->name ?: $user->id) : 'system';
    }

    protected static function getSettingsAuditGatewayChanges(array $before, array $after): array {
        $changes = [];
        foreach (self::getSettingsAuditGatewayFields() as $field => $secret) {
            $from = self::getSettingsAuditComparableValue($before[$field] ?? null, $secret);
            $to = self::getSettingsAuditComparableValue($after[$field] ?? null, $secret);
            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }
        return $changes;
    }

    protected static function getSettingsAuditGatewayFields(): array {
        return [
            'enabled_payment_methods' => false,
            'stripe_automatic_payment_methods' => false,
            'stripe_test_pk' => true,
            'stripe_test_sk' => true,
            'stripe_live_pk' => true,
            'stripe_live_sk' => true,
            'stripe_webhook_secret' => true,
            'mollie_test_key' => true,
            'mollie_live_key' => true,
            'paypal_test_client_id' => true,
            'paypal_test_secret' => true,
            'paypal_test_webhook_id' => true,
            'paypal_live_client_id' => true,
            'paypal_live_secret' => true,
            'paypal_live_webhook_id' => true,
        ];
    }

    protected static function getSettingsAuditComparableValue(mixed $value, bool $secret): string {
        if ($secret) {
            return trim((string) $value) === '' ? 'empty' : 'configured';
        }
        if (is_array($value)) {
            $value = array_values(array_map('strval', $value));
            sort($value, SORT_STRING);
            return implode(',', $value);
        }
        if (is_bool($value)) {
            return $value ? 'enabled' : 'disabled';
        }
        return (string) $value;
    }
}
