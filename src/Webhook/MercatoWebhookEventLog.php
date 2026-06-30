<?php
namespace ProcessWire;

/**
 * Webhook event logger bridge.
 *
 * Uses ProcessWire logs for now. The class is the seam for future database/page
 * persistence and admin UI.
 */
class MercatoWebhookEventLog extends Wire {

    protected string $logName = 'mercato-webhooks';

    public function record(MercatoWebhookEvent $event): void {
        $log = new MercatoEventLog($this->logName);
        $log->setWire($this->wire());
        $log->record($event->toArray(), $event->gateway . ' ' . $event->status);
    }

    public function received(string $gateway, string $eventType, array $context = []): void {
        $this->record(new MercatoWebhookEvent(
            gateway: $gateway,
            eventType: $eventType,
            status: 'received',
            eventId: (string) ($context['event_id'] ?? ''),
            orderPageId: (int) ($context['order_page_id'] ?? 0),
            externalPaymentId: (string) ($context['external_payment_id'] ?? ''),
            context: $context,
        ));
    }

    public function processed(string $gateway, string $eventType, array $context = []): void {
        $this->record(new MercatoWebhookEvent(
            gateway: $gateway,
            eventType: $eventType,
            status: 'processed',
            eventId: (string) ($context['event_id'] ?? ''),
            orderPageId: (int) ($context['order_page_id'] ?? 0),
            externalPaymentId: (string) ($context['external_payment_id'] ?? ''),
            context: $context,
        ));
    }

    public function ignored(string $gateway, string $eventType, string $message, array $context = []): void {
        $this->record(new MercatoWebhookEvent(
            gateway: $gateway,
            eventType: $eventType,
            status: 'ignored',
            eventId: (string) ($context['event_id'] ?? ''),
            orderPageId: (int) ($context['order_page_id'] ?? 0),
            externalPaymentId: (string) ($context['external_payment_id'] ?? ''),
            message: $message,
            context: $context,
        ));
    }

    public function failed(string $gateway, string $eventType, string $message, array $context = []): void {
        $this->record(new MercatoWebhookEvent(
            gateway: $gateway,
            eventType: $eventType,
            status: 'failed',
            eventId: (string) ($context['event_id'] ?? ''),
            orderPageId: (int) ($context['order_page_id'] ?? 0),
            externalPaymentId: (string) ($context['external_payment_id'] ?? ''),
            message: $message,
            context: $context,
        ));
    }

    public function hasProcessed(MercatoWebhookEvent $event, int $limit = 10000): bool {
        return $this->containsProcessedDuplicate($event, $this->readRecentEvents($limit));
    }

    public function containsProcessedDuplicate(MercatoWebhookEvent $event, array $events): bool {
        $key = $event->idempotencyKey();
        if ($key === '') {
            return false;
        }

        foreach ($events as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if ((string) ($candidate['status'] ?? '') !== 'processed') {
                continue;
            }
            if (MercatoWebhookEvent::fromArray($candidate)->idempotencyKey() === $key) {
                return true;
            }
        }

        return false;
    }

    public function readRecentEvents(int $limit = 100): array {
        $logFile = $this->getLogFilePath();
        if ($logFile === '' || !is_file($logFile) || !is_readable($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = $this->parseLogLine((string) $line);
            if ($event) {
                $events[] = $event;
            }
            if (count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    public function redactPayloadsOlderThan(int $olderThanDays, ?callable $filter = null): array {
        $olderThanDays = max(1, $olderThanDays);
        $logFile = $this->getLogFilePath();
        if ($logFile === '' || !is_file($logFile) || !is_readable($logFile)) {
            return ['ok' => true, 'redacted' => 0, 'kept' => 0, 'exists' => false];
        }

        $lines = file($logFile);
        if (!is_array($lines)) {
            return ['ok' => false, 'redacted' => 0, 'kept' => 0, 'exists' => true, 'message' => 'Unable to read webhook log.'];
        }

        $cutoff = time() - ($olderThanDays * 86400);
        $redacted = 0;
        $kept = 0;
        $rewritten = [];
        foreach ($lines as $line) {
            $line = (string) $line;
            $event = $this->parseLogLine($line);
            if (!$event || ($filter && !$filter($event)) || !$this->isEventOlderThan($event, $cutoff) || $this->isPayloadRedacted($event)) {
                $rewritten[] = $line;
                $kept++;
                continue;
            }

            $jsonStart = strpos($line, '{');
            if ($jsonStart === false) {
                $rewritten[] = $line;
                $kept++;
                continue;
            }

            unset($event['_time']);
            $event['context'] = [
                'redacted_due_to_retention' => true,
                'retention_days' => $olderThanDays,
                'redacted_at' => date(DATE_ATOM),
            ];
            $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $rewritten[] = substr($line, 0, $jsonStart) . ($encoded ?: '{}') . PHP_EOL;
            $redacted++;
        }

        if ($redacted > 0) {
            $written = file_put_contents($logFile, implode('', $rewritten), LOCK_EX);
            if ($written === false) {
                return ['ok' => false, 'redacted' => 0, 'kept' => $kept, 'exists' => true, 'message' => 'Unable to write webhook log.'];
            }
        }

        return ['ok' => true, 'redacted' => $redacted, 'kept' => $kept, 'exists' => true];
    }

    protected function getLogFilePath(): string {
        $config = $this->wire('config');
        $logsPath = is_object($config) ? (string) ($config->paths->logs ?? '') : '';
        if ($logsPath === '') {
            return '';
        }

        return rtrim($logsPath, '/') . '/' . $this->logName . '.txt';
    }

    protected function parseLogLine(string $line): ?array {
        $jsonStart = strpos($line, '{');
        if ($jsonStart === false) {
            return null;
        }

        $event = json_decode(substr($line, $jsonStart), true);
        if (!is_array($event)) {
            return null;
        }

        $event['_time'] = trim(substr($line, 0, $jsonStart)) ?: '-';
        return $event;
    }

    protected function isEventOlderThan(array $event, int $cutoff): bool {
        $timestamp = strtotime((string) ($event['at'] ?? ''));
        if (!$timestamp) {
            $timestamp = strtotime((string) ($event['_time'] ?? ''));
        }
        return $timestamp > 0 && $timestamp < $cutoff;
    }

    protected function isPayloadRedacted(array $event): bool {
        $context = $event['context'] ?? null;
        return is_array($context) && !empty($context['redacted_due_to_retention']);
    }
}
