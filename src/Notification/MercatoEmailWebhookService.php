<?php
namespace ProcessWire;

final class MercatoEmailWebhookService extends Wire {
    public function __construct(private Mercato $commerce) { parent::__construct(); }

    public function process(string $provider, string $payload, array $headers): array {
        $adapters = $this->commerce->emailWebhookAdapters([]);
        $adapter = $adapters[$provider] ?? null;
        if (!$adapter instanceof MercatoEmailWebhookAdapterInterface) throw new WireException('Unknown transactional email webhook provider.', 404);
        $events = $adapter->verifyAndParse($payload, $headers);
        $processed = 0; $duplicates = 0;
        foreach ($events as $event) {
            $type = strtolower((string) ($event['type'] ?? ''));
            $eventId = trim((string) ($event['event_id'] ?? ''));
            if (!in_array($type, ['bounce', 'complaint', 'delivered', 'deferred'], true) || $eventId === '') throw new WireException('Invalid transactional email webhook event.', 422);
            if ($this->wasProcessed($provider, $eventId)) { $duplicates++; continue; }
            $this->record(['event' => 'email_delivery_' . $type, 'status' => $type, 'provider' => $provider, 'event_id' => $eventId, 'provider_message_id' => (string) ($event['provider_message_id'] ?? ''), 'recipient_hash' => (string) ($event['recipient_hash'] ?? ''), 'reason' => (string) ($event['reason'] ?? '')]);
            $processed++;
        }
        return ['provider' => $provider, 'processed' => $processed, 'duplicates' => $duplicates];
    }

    private function wasProcessed(string $provider, string $eventId): bool {
        $path = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-notifications.txt';
        foreach (array_reverse(array_slice(is_readable($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [], -5000)) as $line) {
            $json = strstr((string) $line, '{'); $row = $json ? json_decode($json, true) : null;
            if (is_array($row) && (string) ($row['provider'] ?? '') === $provider && (string) ($row['event_id'] ?? '') === $eventId) return true;
        }
        return false;
    }

    private function record(array $payload): void {
        $log = new MercatoEventLog('mercato-notifications'); $log->setWire($this->wire()); $log->record($payload, 'email_webhook');
    }
}
