<?php
namespace ProcessWire;

trait ProcessMercatoEventReaders {
    protected function getWebhookEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-webhooks.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if ($event) {
                $events[] = $event;
            }
            if (count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    protected function getInventoryEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-inventory.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || empty($event['event'])) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    protected function recordProductEvent(string $event, Page $product, array $payload = []): void {
        $user = $this->wire('user');
        $entry = array_merge([
            'event' => $event,
            'at' => date('c'),
            'product_id' => (int) $product->id,
            'title' => (string) $product->title,
            'name' => (string) $product->name,
            'sku' => $product->hasField('mrc_sku') ? (string) $product->mrc_sku : '',
            'user' => $user && $user->id ? (string) ($user->name ?: $user->id) : 'system',
        ], $payload);

        $this->wire('log')->save('mercato-products', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function getProductEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-products.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || empty($event['event'])) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    protected function getProductEventsForProduct(Page $product, int $limit = 100): array {
        $productId = (int) $product->id;
        $events = [];
        foreach ($this->getProductEvents(1000) as $event) {
            if ((int) ($event['product_id'] ?? 0) !== $productId) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }
        return $events;
    }

    protected function describeProductEvent(array $event): string {
        $name = (string) ($event['event'] ?? '');
        if ($name === 'product_stock_adjusted') {
            return sprintf(
                $this->_('Stock changed by %+d%s.'),
                (int) ($event['delta'] ?? 0),
                isset($event['after']) && $event['after'] !== null ? ' -> ' . (int) $event['after'] : ''
            );
        }
        if ($name === 'product_imported') {
            return sprintf(
                $this->_('CSV import %s: price %s -> %s, stock %s -> %s, %d image(s).'),
                (string) ($event['import_mode'] ?? ''),
                $event['from_price'] === null ? '-' : (string) $event['from_price'],
                $event['to_price'] === null ? '-' : (string) $event['to_price'],
                $event['from_stock'] === null ? '-' : (string) $event['from_stock'],
                $event['to_stock'] === null ? '-' : (string) $event['to_stock'],
                (int) ($event['imported_images'] ?? 0)
            );
        }
        if (in_array($name, ['product_manual_created', 'product_manual_edited'], true)) {
            $fields = array_filter(array_map('trim', explode(',', (string) ($event['changed_fields'] ?? ''))));
            return $fields
                ? sprintf($this->_('Manual page edit changed: %s.'), implode(', ', $fields))
                : $this->_('Manual page edit.');
        }
        if ($name === 'product_quick_updated') {
            $fields = array_filter(array_map('trim', explode(',', (string) ($event['changed_fields'] ?? ''))));
            return $fields
                ? sprintf($this->_('Product detail quick update changed: %s.'), implode(', ', $fields))
                : $this->_('Product detail quick update saved without field changes.');
        }
        if ($name === 'product_duplicated') {
            return sprintf(
                $this->_('Duplicated from #%d %s with %d image(s).'),
                (int) ($event['source_product_id'] ?? 0),
                (string) ($event['source_product_title'] ?? ''),
                (int) ($event['copied_images'] ?? 0)
            );
        }
        if ($name === 'product_bulk_price') {
            return sprintf(
                $this->_('Price %s: %s -> %s.'),
                (string) ($event['action'] ?? ''),
                $event['from_price'] === null ? '-' : (string) $event['from_price'],
                $event['to_price'] === null ? '-' : (string) $event['to_price']
            );
        }
        if ($name === 'product_bulk_policy') {
            return sprintf(
                $this->_('Policy %s: %s -> %s.'),
                (string) ($event['action'] ?? ''),
                (string) ($event['from_policy'] ?? '-'),
                (string) ($event['to_policy'] ?? '-')
            );
        }
        if ($name === 'product_bulk_lifecycle') {
            return sprintf(
                $this->_('Product status %s: %s -> %s.'),
                (string) ($event['action'] ?? ''),
                (string) ($event['from_product_status'] ?? '-'),
                (string) ($event['to_product_status'] ?? '-')
            );
        }
        if ($name === 'product_bulk_status') {
            return sprintf(
                $this->_('Status %s: %s -> %s.'),
                (string) ($event['action'] ?? ''),
                (string) ($event['from_status'] ?? '-'),
                (string) ($event['to_status'] ?? '-')
            );
        }
        return (string) ($event['message'] ?? $event['action'] ?? $name);
    }

    protected function getFulfilmentEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-fulfilment.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || ($event['event'] ?? '') !== 'status_updated') {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    protected function getNotificationEvents(int $limit = 100, array $filters = [], int $displayLimit = 0): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-notifications.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        $eventFilter = (string) ($filters['event'] ?? 'all');
        $statusFilter = (string) ($filters['status'] ?? 'all');
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || !in_array((string) ($event['event'] ?? ''), ['shipping_email', 'pickup_ready_email', 'local_delivery_email', 'order_confirmation_email', 'payment_link_email', 'test_email'], true)) {
                continue;
            }
            if ($eventFilter !== 'all' && (string) ($event['event'] ?? '') !== $eventFilter) {
                continue;
            }
            if ($statusFilter !== 'all' && strtolower((string) ($event['status'] ?? '')) !== $statusFilter) {
                continue;
            }
            $events[] = $event;
            $effectiveLimit = $displayLimit > 0 ? $displayLimit : $limit;
            if (count($events) >= $effectiveLimit) {
                break;
            }
        }
        return $events;
    }

    protected function getRecoveryEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-recovery.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            $eventName = (string) ($event['event'] ?? '');
            if (!$event || !in_array($eventName, ['recovery_email', 'recovery_automation_run'], true)) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }
        return $events;
    }

    protected function getPaymentEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-payments.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || ($event['event'] ?? '') !== 'manual_reconciliation') {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }
        return $events;
    }

    protected function getPaymentAttemptEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-payment-attempts.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || empty($event['id'])) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }
        return $events;
    }

    protected function getRefundEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-refunds.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || !in_array((string) ($event['event'] ?? ''), ['refund_issued', 'refund_reconciled'], true)) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }
        return $events;
    }

    protected function getOrderEditEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-order-edits.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || ($event['event'] ?? '') !== 'order_edited') {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }
        return $events;
    }

    protected function getOrderNoteEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-order-notes.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || ($event['event'] ?? '') !== 'order_note') {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }
        return $events;
    }

    protected function getCustomerNoteEvents(int $limit = 100): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-customer-notes.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event || ($event['event'] ?? '') !== 'customer_note') {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }
        return $events;
    }

    protected function parseWebhookLogLine(string $line): ?array {
        $jsonStart = strpos($line, '{');
        if ($jsonStart === false) {
            return null;
        }

        $json = substr($line, $jsonStart);
        $event = json_decode($json, true);
        if (!is_array($event)) {
            return null;
        }

        $event['_time'] = trim(substr($line, 0, $jsonStart)) ?: '-';
        return $event;
    }

    protected function getDiscountAuditEvents(int $limit = 100, array $filters = []): array {
        $logFile = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-discounts.txt';
        if (!is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        $eventFilter = (string) ($filters['event'] ?? 'all');
        $codeFilter = strtoupper(trim((string) ($filters['code'] ?? '')));
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseWebhookLogLine((string) $line);
            if (!$event) {
                continue;
            }
            if ($eventFilter !== 'all' && strtolower((string) ($event['event'] ?? '')) !== $eventFilter) {
                continue;
            }
            if ($codeFilter !== '' && strtoupper((string) ($event['code'] ?? '')) !== $codeFilter) {
                continue;
            }
            $events[] = $event;
            if (count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }
}
