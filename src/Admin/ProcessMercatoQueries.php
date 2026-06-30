<?php
namespace ProcessWire;

trait ProcessMercatoQueries {

    protected function getOrders(Mercato $commerce, int $limit = 15) {
        $template = $this->wire('sanitizer')->selectorValue($commerce->order_template ?: 'mrc-order');
        $limit = max(1, $limit);
        return $this->wire('pages')->find("template=$template, include=all, sort=-created, limit=$limit");
    }

    protected function getFilteredOrders(Mercato $commerce, string $status = 'all', int $limit = 100) {
        $orders = $this->getOrders($commerce, $limit);
        if ($status === 'all') {
            return $orders;
        }

        $filtered = new PageArray();
        foreach ($orders as $order) {
            if ($this->getPaymentStatusBucketFromState($this->getOrderPaymentState($order)) === $status) {
                $filtered->add($order);
            }
        }

        return $filtered;
    }

    protected function getRequestedRecoveryFilters(): array {
        $age = strtolower(trim((string) ($this->wire('input')->get->text('age') ?: ($_GET['age'] ?? '60'))));
        $gateway = strtolower(trim((string) ($this->wire('input')->get->text('gateway') ?: ($_GET['gateway'] ?? 'all'))));
        $status = strtolower(trim((string) ($this->wire('input')->get->text('status') ?: ($_GET['status'] ?? 'all'))));

        return [
            'age' => in_array($age, ['15', '60', '240', '1440', 'all'], true) ? $age : '60',
            'gateway' => array_key_exists($gateway, $this->getGatewayFilterOptions(true, true)) ? $gateway : 'all',
            'status' => in_array($status, ['all', 'pending', 'processing', 'failed', 'canceled'], true) ? $status : 'all',
        ];
    }

    protected function getAbandonedCheckouts(Mercato $commerce, array $filters): array {
        $orders = $this->getOrders($commerce, 1000);
        $attemptsByOrder = $this->getLatestPaymentAttemptsByOrder();
        $paymentLinkEmails = $this->getLatestRecoverySentEventsByOrder() + $this->getLatestPaymentLinkEmailsByOrder();
        $minAge = (string) ($filters['age'] ?? '60');
        $gateway = (string) ($filters['gateway'] ?? 'all');
        $statusFilter = (string) ($filters['status'] ?? 'all');
        $now = time();
        $rows = [];

        foreach ($orders as $order) {
            $payment = $this->getOrderPaymentState($order);
            if (!empty($payment['paid'])) {
                continue;
            }

            $rawStatus = (string) ($payment['raw'] ?? '');
            $latestAttempt = $attemptsByOrder[(int) $order->id]['latest'] ?? [];
            $attemptCount = (int) ($attemptsByOrder[(int) $order->id]['count'] ?? 0);
            $attemptGateway = strtolower((string) ($latestAttempt['gateway'] ?? $this->getOrderGatewayKey($order)));
            if ($attemptGateway === '') {
                $attemptGateway = 'unknown';
            }
            if ($gateway !== 'all' && $attemptGateway !== $gateway) {
                continue;
            }
            if (!$this->recoveryStatusMatches($rawStatus, (string) ($latestAttempt['status'] ?? ''), $statusFilter)) {
                continue;
            }

            $lastActivity = $this->getPaymentAttemptTimestamp($latestAttempt);
            if ($lastActivity <= 0) {
                $lastActivity = (int) $order->created;
            }
            $ageMinutes = max(0, (int) floor(($now - $lastActivity) / 60));
            if ($minAge !== 'all' && $ageMinutes < (int) $minAge) {
                continue;
            }

            $recoveryEmail = $paymentLinkEmails[(int) $order->id] ?? [];
            $eligibility = $this->getRecoveryEmailEligibility($order, $recoveryEmail);
            $rows[] = [
                'order' => $order,
                'payment' => $payment,
                'latest_attempt' => $latestAttempt,
                'attempt_count' => $attemptCount,
                'gateway' => $attemptGateway,
                'age_minutes' => $ageMinutes,
                'last_activity' => $lastActivity,
                'recovery_email' => $recoveryEmail,
                'recoverable' => trim((string) $order->mrc_email) !== '',
                'recovery_allowed' => $eligibility['allowed'],
                'recovery_reason' => $eligibility['reason'],
                'recovery_cooldown_minutes' => $eligibility['cooldown_minutes'],
            ];
        }

        usort($rows, static fn(array $a, array $b): int => (int) $b['age_minutes'] <=> (int) $a['age_minutes']);
        return $rows;
    }

    protected function getLatestPaymentAttemptsByOrder(): array {
        $map = [];
        foreach ($this->getPaymentAttemptEvents(10000) as $event) {
            $orderId = (int) ($event['order_page_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            if (!isset($map[$orderId])) {
                $map[$orderId] = ['latest' => $event, 'count' => 0];
            }
            $map[$orderId]['count']++;
        }
        return $map;
    }

    protected function getLatestPaymentLinkEmailsByOrder(): array {
        $map = [];
        foreach ($this->getNotificationEvents(10000) as $event) {
            if ((string) ($event['event'] ?? '') !== 'payment_link_email') {
                continue;
            }
            $orderId = (int) ($event['order_id'] ?? 0);
            if ($orderId > 0 && !isset($map[$orderId])) {
                $map[$orderId] = $event;
            }
        }
        return $map;
    }

    protected function getLatestRecoverySentEventsByOrder(): array {
        $map = [];
        foreach ($this->getRecoveryEvents(10000) as $event) {
            if ((string) ($event['event'] ?? '') !== 'recovery_email' || (string) ($event['status'] ?? '') !== 'sent') {
                continue;
            }
            $orderId = (int) ($event['order_id'] ?? 0);
            if ($orderId > 0 && !isset($map[$orderId])) {
                $map[$orderId] = $event;
            }
        }
        return $map;
    }

    protected function getRecoveryEmailEligibility(Page $order, array $latestEmail): array {
        $email = trim((string) $order->mrc_email);
        if ($email === '') {
            return [
                'allowed' => false,
                'reason' => $this->_('No customer email'),
                'cooldown_minutes' => 0,
            ];
        }

        $commerce = $this->wire('modules')->get('Mercato');
        if ($commerce instanceof Mercato && method_exists($commerce, 'isRecoveryEmailSuppressed') && $commerce->isRecoveryEmailSuppressed($email)) {
            return [
                'allowed' => false,
                'reason' => $this->_('Email suppressed'),
                'cooldown_minutes' => 0,
            ];
        }

        $cooldownMinutes = $this->getRecoveryEmailCooldownMinutes();
        $sentAt = $this->getPaymentAttemptTimestamp($latestEmail);
        if ($sentAt > 0) {
            $elapsed = max(0, (int) floor((time() - $sentAt) / 60));
            $remaining = max(0, $cooldownMinutes - $elapsed);
            if ($remaining > 0) {
                return [
                    'allowed' => false,
                    'reason' => sprintf($this->_('Cooldown: wait %s'), $this->formatAgeMinutes($remaining)),
                    'cooldown_minutes' => $remaining,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => $this->_('Ready to email'),
            'cooldown_minutes' => 0,
        ];
    }

    protected function getRecoveryEmailCooldownMinutes(): int {
        $commerce = $this->wire('modules')->get('Mercato');
        return method_exists($commerce, 'getRecoveryEmailCooldownMinutes')
            ? $commerce->getRecoveryEmailCooldownMinutes()
            : 1440;
    }

    protected function recoveryStatusMatches(string $orderStatus, string $attemptStatus, string $filter): bool {
        if ($filter === 'all') {
            return true;
        }
        $status = $attemptStatus !== '' ? $attemptStatus : $orderStatus;
        return $this->getPaymentStatusBucketForRaw($status) === $filter;
    }

    protected function getPaymentAttemptTimestamp(array $attempt): int {
        $time = trim((string) ($attempt['_time'] ?? ''));
        if ($time === '' || $time === '-') {
            return 0;
        }
        $timestamp = strtotime($time);
        return $timestamp !== false ? (int) $timestamp : 0;
    }

    protected function getGatewayFilterOptions(bool $includeBankTransfer = true, bool $includeUnknown = true): array {
        $options = [
            'all' => $this->_('All gateways'),
            'stripe' => $this->_('Stripe'),
            'mollie' => $this->_('Mollie'),
            'paypal' => $this->_('PayPal'),
        ];
        if ($includeBankTransfer) {
            $options['bank-transfer'] = $this->_('Bank Transfer');
        }
        $options['demo'] = $this->_('Demo');
        if ($includeUnknown) {
            $options['unknown'] = $this->_('Unknown');
        }
        return $options;
    }

    protected function getRequestedWebhookFilters(): array {
        $gateway = strtolower(trim((string) ($this->wire('input')->get->text('gateway') ?: ($_GET['gateway'] ?? ''))));
        $status = strtolower(trim((string) ($this->wire('input')->get->text('status') ?: ($_GET['status'] ?? ''))));
        $event = strtolower(trim((string) ($this->wire('input')->get->text('event') ?: ($_GET['event'] ?? ''))));
        $orderId = (int) ($this->wire('input')->get->int('order') ?: ($_GET['order'] ?? 0));

        return [
            'gateway' => array_key_exists($gateway, $this->getGatewayFilterOptions(false, false)) ? $gateway : 'all',
            'status' => in_array($status, ['received', 'processed', 'ignored', 'failed'], true) ? $status : 'all',
            'event' => $event !== '' ? $event : 'all',
            'order' => max(0, $orderId),
        ];
    }

    protected function filterWebhookEvents(array $events, array $filters): array {
        $gateway = (string) ($filters['gateway'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');
        $eventType = strtolower((string) ($filters['event'] ?? 'all'));
        $orderId = (int) ($filters['order'] ?? 0);

        return array_values(array_filter($events, function (array $event) use ($gateway, $status, $eventType, $orderId): bool {
            if ($gateway !== 'all' && strtolower((string) ($event['gateway'] ?? '')) !== $gateway) {
                return false;
            }
            if ($status !== 'all' && strtolower((string) ($event['status'] ?? '')) !== $status) {
                return false;
            }
            if ($eventType !== 'all' && strtolower((string) ($event['event_type'] ?? '')) !== $eventType) {
                return false;
            }
            if ($orderId > 0 && (int) ($event['order_page_id'] ?? 0) !== $orderId) {
                return false;
            }
            return true;
        }));
    }

    protected function getRequestedPaymentAttemptFilters(): array {
        $gateway = strtolower(trim((string) ($this->wire('input')->get->text('gateway') ?: ($_GET['gateway'] ?? ''))));
        $status = strtolower(trim((string) ($this->wire('input')->get->text('status') ?: ($_GET['status'] ?? ''))));
        $event = strtolower(trim((string) ($this->wire('input')->get->text('event') ?: ($_GET['event'] ?? ''))));
        $orderId = (int) ($this->wire('input')->get->int('order') ?: ($_GET['order'] ?? 0));

        return [
            'gateway' => array_key_exists($gateway, $this->getGatewayFilterOptions(true, true)) ? $gateway : 'all',
            'status' => MercatoPaymentStatus::isValid($status) ? $status : 'all',
            'event' => in_array($event, ['created', 'initialized', 'completed', 'processing', 'failed', 'canceled', 'updated'], true) ? $event : 'all',
            'order' => max(0, $orderId),
        ];
    }

    protected function filterPaymentAttemptEvents(array $events, array $filters): array {
        $gateway = (string) ($filters['gateway'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');
        $eventName = (string) ($filters['event'] ?? 'all');
        $orderId = (int) ($filters['order'] ?? 0);

        return array_values(array_filter($events, static function (array $event) use ($gateway, $status, $eventName, $orderId): bool {
            if ($gateway !== 'all' && strtolower((string) ($event['gateway'] ?? '')) !== $gateway) {
                return false;
            }
            if ($status !== 'all' && strtolower((string) ($event['status'] ?? '')) !== $status) {
                return false;
            }
            if ($eventName !== 'all' && strtolower((string) ($event['event'] ?? '')) !== $eventName) {
                return false;
            }
            if ($orderId > 0 && (int) ($event['order_page_id'] ?? 0) !== $orderId) {
                return false;
            }
            return true;
        }));
    }

    protected function getRequestedRefundFilters(): array {
        $gateway = strtolower(trim((string) ($this->wire('input')->get->text('gateway') ?: ($_GET['gateway'] ?? ''))));
        $state = strtolower(trim((string) ($this->wire('input')->get->text('state') ?: ($_GET['state'] ?? ''))));
        $orderId = (int) ($this->wire('input')->get->int('order') ?: ($_GET['order'] ?? 0));

        return [
            'gateway' => array_key_exists($gateway, $this->getGatewayFilterOptions(true, true)) ? $gateway : 'all',
            'state' => in_array($state, ['issued', 'reconciled', 'pending', 'failed'], true) ? $state : 'all',
            'order' => max(0, $orderId),
        ];
    }

    protected function filterRefundEvents(array $events, array $filters): array {
        $gateway = (string) ($filters['gateway'] ?? 'all');
        $state = (string) ($filters['state'] ?? 'all');
        $orderId = (int) ($filters['order'] ?? 0);

        return array_values(array_filter($events, function (array $event) use ($gateway, $state, $orderId): bool {
            if ($gateway !== 'all' && strtolower((string) ($event['gateway'] ?? '')) !== $gateway) {
                return false;
            }
            if ($orderId > 0 && (int) ($event['order_id'] ?? 0) !== $orderId) {
                return false;
            }
            if ($state === 'all') {
                return true;
            }

            $eventName = strtolower((string) ($event['event'] ?? ''));
            $gatewayStatus = strtolower((string) ($event['gateway_status'] ?? ''));
            $paymentStatus = strtolower((string) ($event['payment_status'] ?? ''));
            $pendingAmount = (float) ($event['pending_amount'] ?? 0);

            return match ($state) {
                'issued' => $eventName === 'refund_issued',
                'reconciled' => $eventName === 'refund_reconciled',
                'pending' => str_contains($paymentStatus, 'refund_pending') || $pendingAmount > 0 || in_array($gatewayStatus, ['pending', 'queued', 'processing'], true),
                'failed' => in_array($gatewayStatus, ['failed', 'rejected', 'canceled', 'cancelled'], true),
                default => true,
            };
        }));
    }

    protected function getWebhookExportQuery(array $filters): array {
        $query = [];
        foreach (['gateway', 'status', 'event'] as $key) {
            $value = (string) ($filters[$key] ?? 'all');
            if ($value !== 'all') {
                $query[$key] = $value;
            }
        }
        $orderId = (int) ($filters['order'] ?? 0);
        if ($orderId > 0) {
            $query['order'] = $orderId;
        }
        return $query;
    }

    protected function getPaymentAttemptExportQuery(array $filters): array {
        $query = [];
        foreach (['gateway', 'status', 'event'] as $key) {
            $value = (string) ($filters[$key] ?? 'all');
            if ($value !== 'all') {
                $query[$key] = $value;
            }
        }
        $orderId = (int) ($filters['order'] ?? 0);
        if ($orderId > 0) {
            $query['order'] = $orderId;
        }
        return $query;
    }

    protected function getRefundExportQuery(array $filters): array {
        $query = [];
        foreach (['gateway', 'state'] as $key) {
            $value = (string) ($filters[$key] ?? 'all');
            if ($value !== 'all') {
                $query[$key] = $value;
            }
        }
        $orderId = (int) ($filters['order'] ?? 0);
        if ($orderId > 0) {
            $query['order'] = $orderId;
        }
        return $query;
    }

    protected function getRecoveryExportQuery(array $filters): array {
        $query = [];
        foreach (['age', 'gateway', 'status'] as $key) {
            $value = (string) ($filters[$key] ?? '');
            if ($value !== '' && !($key === 'age' && $value === '60') && !($key !== 'age' && $value === 'all')) {
                $query[$key] = $value;
            }
        }
        return $query;
    }

    protected function getInventoryExportQuery(array $filters): array {
        $query = [];
        $event = (string) ($filters['event'] ?? 'all');
        if ($event !== 'all') {
            $query['event'] = $event;
        }
        $orderId = (int) ($filters['order'] ?? 0);
        if ($orderId > 0) {
            $query['order'] = $orderId;
        }
        return $query;
    }

    protected function getFulfilmentQueueExportQuery(string $method = 'all', string $queue = 'all'): array {
        $query = [];
        if ($method !== 'all') {
            $query['method'] = $method;
        }
        if ($queue !== 'all') {
            $query['queue'] = $queue;
        }
        return $query;
    }

    protected function getProductExportQuery(array $filters): array {
        $query = [];
        foreach (['stock', 'status', 'lifecycle', 'product_type', 'policy', 'sort'] as $key) {
            $value = (string) ($filters[$key] ?? ($key === 'sort' ? 'modified_desc' : 'all'));
            if (($key === 'sort' && $value !== 'modified_desc') || ($key !== 'sort' && $value !== 'all')) {
                $query[$key] = $value;
            }
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query['q'] = $search;
        }
        return $query;
    }

    protected function getDiscountEventExportQuery(array $filters): array {
        $query = [];
        $event = (string) ($filters['event'] ?? 'all');
        if ($event !== 'all') {
            $query['event'] = $event;
        }
        $code = strtoupper(trim((string) ($filters['code'] ?? '')));
        if ($code !== '') {
            $query['code'] = $code;
        }
        return $query;
    }

    protected function getRequestedDiscountEventFilters(): array {
        $event = strtolower(trim((string) $this->wire('input')->get->text('event')));
        $code = strtoupper(trim((string) $this->wire('input')->get->text('code')));
        if ($event === '' || !preg_match('/^[a-z0-9_-]+$/', $event)) {
            $event = 'all';
        }
        return [
            'event' => $event,
            'code' => $code,
        ];
    }

    protected function getNotificationExportQuery(array $filters): array {
        $query = [];
        $event = (string) ($filters['event'] ?? 'all');
        if ($event !== 'all') {
            $query['notification_event'] = $event;
        }
        $status = (string) ($filters['status'] ?? 'all');
        if ($status !== 'all') {
            $query['notification_status'] = $status;
        }
        return $query;
    }

    protected function getRequestedNotificationFilters(): array {
        $event = strtolower(trim((string) $this->wire('input')->get->text('notification_event')));
        $status = strtolower(trim((string) $this->wire('input')->get->text('notification_status')));
        if ($event === '' || !preg_match('/^[a-z0-9_-]+$/', $event)) {
            $event = 'all';
        }
        return [
            'event' => $event,
            'status' => in_array($status, ['all', 'sent', 'failed'], true) ? $status : 'all',
        ];
    }

    protected function getRequestedInventoryFilters(): array {
        $event = strtolower(trim((string) $this->wire('input')->get->text('event')));
        $orderId = max(0, (int) $this->wire('input')->get->int('order'));
        if ($event === '' || !preg_match('/^[a-z0-9_-]+$/', $event)) {
            $event = 'all';
        }

        return [
            'event' => $event,
            'order' => $orderId,
        ];
    }

    protected function filterInventoryEvents(array $events, array $filters = [], int $limit = 0): array {
        $eventFilter = (string) ($filters['event'] ?? 'all');
        $orderFilter = (int) ($filters['order'] ?? 0);
        $filtered = [];

        foreach ($events as $event) {
            if ($eventFilter !== 'all' && strtolower((string) ($event['event'] ?? '')) !== $eventFilter) {
                continue;
            }
            if ($orderFilter > 0 && (int) ($event['order_id'] ?? 0) !== $orderFilter) {
                continue;
            }
            $filtered[] = $event;
            if ($limit > 0 && count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    protected function getSimulatableWebhookOrders(Mercato $commerce, int $limit = 25) {
        $template = $this->wire('sanitizer')->selectorValue($commerce->order_template ?: 'mrc-order');
        $limit = max(1, $limit);
        return $this->wire('pages')->find("template=$template, include=all, mrc_payment_complete=0, sort=-created, limit=$limit");
    }

    protected function getFulfilmentOrders(Mercato $commerce, int $limit = 100, string $method = 'all', string $queueFilter = 'all') {
        $orders = $this->getOrders($commerce, 1000);
        $filtered = new PageArray();

        foreach ($orders as $order) {
            $payment = $this->getOrderPaymentState($order);
            if (!$payment['paid']) continue;
            if ($method !== 'all' && $this->getOrderFulfilmentMethod($order) !== $method) continue;

            $fulfilment = $this->getOrderFulfilmentState($order);
            if (!$this->matchesFulfilmentQueueFilter((string) ($fulfilment['raw'] ?? ''), $queueFilter)) {
                continue;
            }
            if (in_array((string) ($fulfilment['raw'] ?? ''), ['unfulfilled', 'partially_fulfilled', 'fulfilled', 'shipped', 'ready_for_pickup', 'out_for_delivery', 'backorder', 'preorder', 'attention'], true)) {
                $filtered->add($order);
            }
            if ($filtered->count() >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    protected function filterFulfilmentEventsByMethod(array $events, string $method = 'all', int $limit = 0): array {
        if ($method === 'all') {
            return $limit > 0 ? array_slice($events, 0, $limit) : $events;
        }
        if (!MercatoFulfilmentMethodType::isValid($method)) {
            return [];
        }

        $filtered = [];
        $methodByOrder = [];
        foreach ($events as $event) {
            $orderId = (int) ($event['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            if (!array_key_exists($orderId, $methodByOrder)) {
                $order = $this->wire('pages')->get($orderId);
                $methodByOrder[$orderId] = ($order && $order->id) ? $this->getOrderFulfilmentMethod($order) : '';
            }
            if ($methodByOrder[$orderId] !== $method) {
                continue;
            }
            $filtered[] = $event;
            if ($limit > 0 && count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    protected function getRequestedFulfilmentMethod(): string {
        $method = trim((string) $this->wire('input')->get->text('method'));
        return MercatoFulfilmentMethodType::isValid($method) ? $method : 'all';
    }

    protected function getRequestedFulfilmentQueueFilter(): string {
        $queue = strtolower(trim((string) $this->wire('input')->get->text('queue')));
        return in_array($queue, ['all', 'active', 'attention', 'backorder', 'preorder'], true) ? $queue : 'all';
    }

    protected function matchesFulfilmentQueueFilter(string $rawStatus, string $queueFilter): bool {
        return match ($queueFilter) {
            'active' => in_array($rawStatus, [
                'unfulfilled',
                'partially_fulfilled',
                'shipped',
                'ready_for_pickup',
                'out_for_delivery',
            ], true),
            'attention' => $rawStatus === 'attention',
            'backorder' => $rawStatus === 'backorder',
            'preorder' => $rawStatus === 'preorder',
            default => true,
        };
    }

    protected function getFulfilmentQueueSummary(Mercato $commerce, string $method = 'all'): array {
        $summary = [
            'all' => 0,
            'active' => 0,
            'attention' => 0,
            'backorder' => 0,
            'preorder' => 0,
        ];

        foreach ($this->getFulfilmentOrders($commerce, 1000, $method, 'all') as $order) {
            $raw = (string) ($this->getOrderFulfilmentState($order)['raw'] ?? '');
            $summary['all']++;
            foreach (['active', 'attention', 'backorder', 'preorder'] as $queue) {
                if ($this->matchesFulfilmentQueueFilter($raw, $queue)) {
                    $summary[$queue]++;
                }
            }
        }

        return $summary;
    }

    protected function getRequestedOrderStatus(): string {
        $status = strtolower((string) $this->wire('input')->get->text('status'));
        return in_array($status, ['all', 'paid', 'pending', 'processing', 'failed', 'canceled'], true) ? $status : 'all';
    }

    protected function getRequestedCustomerSegment(): string {
        $segment = strtolower((string) $this->wire('input')->get->text('segment'));
        return in_array($segment, ['all', 'new', 'repeat', 'vip', 'needs_attention'], true) ? $segment : 'all';
    }

    protected function getRequestedCustomerFilters(): array {
        return [
            'segment' => $this->getRequestedCustomerSegment(),
            'q' => trim((string) $this->wire('input')->get->text('q')),
        ];
    }

    protected function getRequestedProductFilters(): array {
        $q = trim((string) $this->wire('input')->get->text('q'));
        $stock = strtolower(trim((string) $this->wire('input')->get->text('stock')));
        $status = strtolower(trim((string) $this->wire('input')->get->text('status')));
        $lifecycle = strtolower(trim((string) $this->wire('input')->get->text('lifecycle')));
        $productType = strtolower(trim((string) $this->wire('input')->get->text('product_type')));
        $policy = strtolower(trim((string) $this->wire('input')->get->text('policy')));
        $sort = strtolower(trim((string) $this->wire('input')->get->text('sort')));

        return [
            'q' => $q,
            'stock' => in_array($stock, ['all', 'in_stock', 'low_stock', 'out_of_stock', 'backorder', 'preorder'], true) ? $stock : 'all',
            'status' => in_array($status, ['all', 'published', 'hidden', 'unpublished'], true) ? $status : 'all',
            'lifecycle' => in_array($lifecycle, ['all', 'active', 'archived', 'discontinued'], true) ? $lifecycle : 'all',
            'product_type' => in_array($productType, ['all', 'physical', 'digital', 'service', 'placeholder', 'recurring'], true) ? $productType : 'all',
            'policy' => in_array($policy, ['all', 'deny', 'backorder', 'preorder'], true) ? $policy : 'all',
            'sort' => in_array($sort, ['modified_desc', 'modified_asc', 'title_asc', 'price_asc', 'price_desc', 'stock_asc', 'stock_desc'], true) ? $sort : 'modified_desc',
        ];
    }

    protected function getRequestedCustomerKey(): string {
        return trim((string) $this->wire('input')->get->text('key'));
    }

    protected function getProducts(Mercato $commerce, int $limit = 50, array $filters = []) {
        $limit = max(1, $limit);
        $query = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $lifecycle = (string) ($filters['lifecycle'] ?? 'all');
        $productType = (string) ($filters['product_type'] ?? 'all');
        $policy = (string) ($filters['policy'] ?? 'all');
        $stock = (string) ($filters['stock'] ?? 'all');
        $sort = (string) ($filters['sort'] ?? 'modified_desc');

        $selector = ['template=mrc-product', 'include=all'];
        if ($query !== '') {
            $selectorValue = $this->wire('sanitizer')->selectorValue($query);
            if ($selectorValue !== '') {
                $selector[] = "title|mrc_sku|mrc_description%=$selectorValue";
            }
        }
        if ($status === 'published') {
            $selector[] = 'status<' . Page::statusHidden;
        } elseif ($status === 'hidden') {
            $selector[] = 'status=' . Page::statusHidden;
        } elseif ($status === 'unpublished') {
            $selector[] = 'status=' . Page::statusUnpublished;
        }
        if (in_array($policy, ['deny', 'backorder', 'preorder'], true)) {
            $selector[] = 'mrc_stock_policy=' . $this->wire('sanitizer')->selectorValue($policy);
        }

        $selector[] = 'sort=' . match ($sort) {
            'title_asc' => 'title',
            'price_asc' => 'mrc_price',
            'price_desc' => '-mrc_price',
            'stock_asc' => 'mrc_stock',
            'stock_desc' => '-mrc_stock',
            'modified_asc' => 'modified',
            default => '-modified',
        };
        $needsRuntimeFilter = $stock !== 'all'
            || in_array($lifecycle, ['active', 'archived', 'discontinued'], true)
            || in_array($productType, ['physical', 'digital', 'service', 'placeholder', 'recurring'], true);
        $selector[] = 'limit=' . ($needsRuntimeFilter ? 1000 : $limit);
        $products = $this->wire('pages')->find(implode(', ', $selector));

        if (!$needsRuntimeFilter) {
            return $products;
        }

        $filtered = new PageArray();
        foreach ($products as $product) {
            if (in_array($lifecycle, ['active', 'archived', 'discontinued'], true) && $this->getProductLifecycleStatus($product) !== $lifecycle) {
                continue;
            }
            if (in_array($productType, ['physical', 'digital', 'service', 'placeholder', 'recurring'], true) && $this->getProductType($product) !== $productType) {
                continue;
            }
            if ($stock !== 'all') {
                $state = $this->getProductStockState($product, $commerce);
                $raw = (string) ($state['raw'] ?? '');
                if ($raw !== $stock) {
                    continue;
                }
            }
            $filtered->add($product);
            if ($filtered->count() >= $limit) {
                break;
            }
        }
        return $filtered;
    }

    protected function getManualOrderProducts(Mercato $commerce, array $values) {
        $query = trim((string) ($values['product_search'] ?? ''));
        $selectedIds = [];
        foreach ((array) ($values['items'] ?? []) as $line) {
            $id = (int) ($line['product_id'] ?? 0);
            if ($id > 0) {
                $selectedIds[$id] = $id;
            }
        }

        if ($query !== '') {
            $selectorValue = $this->wire('sanitizer')->selectorValue($query);
            $products = $selectorValue !== ''
                ? $this->wire('pages')->find("template=mrc-product, include=all, title|mrc_sku|mrc_description%=$selectorValue, sort=title, limit=100")
                : new PageArray();
        } else {
            $products = $this->getProducts($commerce, 100);
        }

        foreach ($selectedIds as $id) {
            if ($products->has($id)) {
                continue;
            }
            $product = $this->wire('pages')->get($id);
            if ($product && $product->id && $product->template->name === 'mrc-product') {
                $products->add($product);
            }
        }

        return $products;
    }

    protected function getManualOrderCustomers(Mercato $commerce, array $values, int $limit = 25): array {
        $query = strtolower(trim((string) ($values['customer_search'] ?? '')));
        $selectedKey = trim((string) ($values['customer_key'] ?? ''));
        $matches = [];
        $selected = null;

        foreach ($this->getCustomersFromOrders($commerce) as $customer) {
            $haystack = strtolower(implode(' ', array_filter([
                (string) ($customer['name'] ?? ''),
                (string) ($customer['email'] ?? ''),
                (string) ($customer['phone'] ?? ''),
                (string) ($customer['city'] ?? ''),
                (string) ($customer['zip'] ?? ''),
            ])));
            $isSelected = $selectedKey !== '' && (string) ($customer['key'] ?? '') === $selectedKey;
            if ($isSelected) {
                $selected = $customer;
            }
            if ($query !== '' && !str_contains($haystack, $query) && !$isSelected) {
                continue;
            }
            $matches[] = $customer;
            if (count($matches) >= $limit && $selected !== null) {
                break;
            }
        }

        if ($selected !== null) {
            $hasSelected = false;
            foreach ($matches as $customer) {
                if ((string) ($customer['key'] ?? '') === (string) ($selected['key'] ?? '')) {
                    $hasSelected = true;
                    break;
                }
            }
            if (!$hasSelected) {
                array_unshift($matches, $selected);
            }
        }

        return array_slice($matches, 0, $limit);
    }

    protected function getRequestedSearchQuery(): string {
        return trim((string) $this->wire('input')->get->text('q'));
    }

    protected function getSearchResults(Mercato $commerce, string $query): array {
        $selectorValue = $this->wire('sanitizer')->selectorValue($query);
        if ($selectorValue === '') {
            return [
                'orders' => new PageArray(),
                'products' => new PageArray(),
                'customers' => [],
            ];
        }

        $orderTemplate = $this->wire('sanitizer')->selectorValue($commerce->order_template ?: 'mrc-order');
        $orders = $this->wire('pages')->find("template=$orderTemplate, include=all, title|mrc_invoice_number|mrc_email|mrc_first_name|mrc_last_name|mrc_phone|mrc_stripe_payment_intent_id|mrc_mollie_payment_id|mrc_payment_details%=$selectorValue, sort=-created, limit=25");
        $products = $this->wire('pages')->find("template=mrc-product, include=all, title|mrc_sku|mrc_description%=$selectorValue, sort=title, limit=25");

        $customers = array_values(array_filter($this->getCustomersFromOrders($commerce), function (array $customer) use ($query): bool {
            $needle = strtolower($query);
            return str_contains(strtolower((string) ($customer['name'] ?? '')), $needle)
                || str_contains(strtolower((string) ($customer['email'] ?? '')), $needle);
        }));
        $customers = array_slice($customers, 0, 25);

        return [
            'orders' => $orders,
            'products' => $products,
            'customers' => $customers,
        ];
    }

    protected function getLowStockProducts(Mercato $commerce, int $limit = 50) {
        $limit = max(1, $limit);
        $products = $this->wire('pages')->find("template=mrc-product, include=all, sort=mrc_stock, limit=1000");
        $lowStock = new PageArray();
        foreach ($products as $product) {
            if ($this->isLowStockProduct($product, $commerce)) {
                $lowStock->add($product);
            }
            if ($lowStock->count() >= $limit) {
                break;
            }
        }
        return $lowStock;
    }

}
