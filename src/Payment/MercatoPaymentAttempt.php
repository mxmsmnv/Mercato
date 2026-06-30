<?php
namespace ProcessWire;

/**
 * Gateway payment attempt DTO.
 *
 * This is a non-persistent bridge for the future PaymentAttempt repository. It
 * keeps the shape explicit before the schema is added.
 */
final class MercatoPaymentAttempt {

    public function __construct(
        public readonly string $id,
        public readonly int $orderPageId,
        public readonly string $gateway,
        public readonly string $method,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $status = MercatoPaymentStatus::CREATED,
        public readonly string $externalId = '',
        public readonly string $idempotencyKey = '',
        public readonly array $payload = [],
    ) {
    }

    public static function fromArray(array $data): self {
        return new self(
            (string) ($data['id'] ?? ''),
            (int) ($data['order_page_id'] ?? $data['orderPageId'] ?? 0),
            (string) ($data['gateway'] ?? ''),
            (string) ($data['method'] ?? ''),
            (float) ($data['amount'] ?? 0),
            strtoupper((string) ($data['currency'] ?? '')),
            (string) ($data['status'] ?? MercatoPaymentStatus::CREATED),
            (string) ($data['external_id'] ?? $data['externalId'] ?? ''),
            (string) ($data['idempotency_key'] ?? $data['idempotencyKey'] ?? ''),
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'order_page_id' => $this->orderPageId,
            'gateway' => $this->gateway,
            'method' => $this->method,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'external_id' => $this->externalId,
            'idempotency_key' => $this->idempotencyKey,
            'payload' => $this->payload,
        ];
    }
}
