<?php
namespace ProcessWire;

/**
 * Shared structured log writer with conservative secret redaction.
 */
final class MercatoEventLog extends Wire {

    public function __construct(
        protected string $logName = 'mercato-events',
    ) {
        parent::__construct();
    }

    public function record(array $payload, string $fallback = 'event'): array {
        $payload = $this->redact($payload);
        if (!isset($payload['at'])) {
            $payload = ['at' => date(DATE_ATOM)] + $payload;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->wire('log')->save($this->logName, $encoded ?: $fallback);

        return $payload;
    }

    public function redact(mixed $value): mixed {
        if (is_string($value)) {
            return $this->looksSensitiveValue($value) ? '[redacted]' : $value;
        }
        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string) $key);
            if (preg_match('/(?:secret|token|api[_-]?key|password|authorization|signature)/', $keyString)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = $this->redact($item);
        }

        return $redacted;
    }

    protected function looksSensitiveValue(string $value): bool {
        if ($value === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b(?:sk_(?:test|live)_[A-Za-z0-9_]+|pk_(?:test|live)_[A-Za-z0-9_]+|whsec_[A-Za-z0-9_]+|(?:test|live)_[A-Za-z0-9_]{8,}|Bearer\s+[A-Za-z0-9._-]{12,})\b/',
            $value
        );
    }
}
