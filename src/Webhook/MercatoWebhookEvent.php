<?php
namespace ProcessWire;

/**
 * Lightweight webhook event DTO.
 *
 * This is intentionally storage-agnostic. Today it is written to ProcessWire's
 * log; later it can be persisted as pages or rows without changing webhook
 * handling code.
 */
final class MercatoWebhookEvent {

    public function __construct(
        public readonly string $gateway,
        public readonly string $eventType,
        public readonly string $status,
        public readonly string $eventId = '',
        public readonly int $orderPageId = 0,
        public readonly string $externalPaymentId = '',
        public readonly string $message = '',
        public readonly array $context = [],
    ) {
    }

    public function toArray(): array {
        return [
            'gateway' => $this->gateway,
            'event_type' => $this->eventType,
            'status' => $this->status,
            'event_id' => $this->eventId,
            'order_page_id' => $this->orderPageId,
            'external_payment_id' => $this->externalPaymentId,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }

    public function idempotencyKey(): string {
        $gateway = strtolower(trim($this->gateway));
        if ($gateway === '') {
            return '';
        }

        $eventId = trim($this->eventId);
        if ($eventId !== '') {
            return $gateway . ':event:' . strtolower($eventId);
        }

        $eventType = strtolower(trim($this->eventType));
        $externalPaymentId = strtolower(trim($this->externalPaymentId));
        $orderPageId = max(0, $this->orderPageId);
        $status = strtolower(trim((string) ($this->context['status'] ?? '')));
        if ($eventType === '' || ($externalPaymentId === '' && $orderPageId <= 0)) {
            return '';
        }

        return $gateway . ':fallback:' . $eventType . ':' . $externalPaymentId . ':' . $orderPageId . ':' . $status;
    }

    public static function fromArray(array $event): self {
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];

        return new self(
            gateway: (string) ($event['gateway'] ?? ''),
            eventType: (string) ($event['event_type'] ?? $event['eventType'] ?? $context['event_type'] ?? ''),
            status: (string) ($event['status'] ?? ''),
            eventId: (string) ($event['event_id'] ?? $event['eventId'] ?? $context['event_id'] ?? ''),
            orderPageId: (int) ($event['order_page_id'] ?? $event['orderPageId'] ?? $context['order_page_id'] ?? 0),
            externalPaymentId: (string) ($event['external_payment_id'] ?? $event['externalPaymentId'] ?? $context['external_payment_id'] ?? ''),
            message: (string) ($event['message'] ?? ''),
            context: $context,
        );
    }
}
