<?php
namespace ProcessWire;

/**
 * Describes gateway capabilities without binding admin/settings logic to one
 * provider implementation.
 */
final class MercatoGatewayCapabilities {

    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly array $paymentMethods = [],
        public readonly bool $supportsRedirect = false,
        public readonly bool $supportsEmbeddedConfirmation = false,
        public readonly bool $supportsWebhooks = false,
        public readonly bool $supportsRefunds = false,
        public readonly bool $supportsPartialRefunds = false,
    ) {
    }

    public function supportsMethod(string $method): bool {
        return in_array($method, array_keys($this->paymentMethods), true);
    }

    public function toArray(): array {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'payment_methods' => $this->paymentMethods,
            'supports_redirect' => $this->supportsRedirect,
            'supports_embedded_confirmation' => $this->supportsEmbeddedConfirmation,
            'supports_webhooks' => $this->supportsWebhooks,
            'supports_refunds' => $this->supportsRefunds,
            'supports_partial_refunds' => $this->supportsPartialRefunds,
        ];
    }
}
