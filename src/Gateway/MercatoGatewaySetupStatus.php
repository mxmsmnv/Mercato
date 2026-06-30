<?php
namespace ProcessWire;

/**
 * Gateway readiness result for future setup checklist diagnostics.
 */
final class MercatoGatewaySetupStatus {

    public function __construct(
        public readonly string $gateway,
        public readonly bool $ready,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $details = [],
    ) {
    }

    public function toArray(): array {
        return [
            'gateway' => $this->gateway,
            'ready' => $this->ready,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'details' => $this->details,
        ];
    }
}
