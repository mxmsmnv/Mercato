<?php
namespace ProcessWire;

interface MercatoEmailWebhookAdapterInterface {
    public function getName(): string;

    /**
     * Verify the provider signature and return normalized bounce/complaint events.
     * Each row requires event_id, type, and provider_message_id.
     */
    public function verifyAndParse(string $payload, array $headers): array;
}
