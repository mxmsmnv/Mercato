<?php
namespace ProcessWire;

/** Provider boundary failure with an explicit retryability contract. */
final class MercatoTaxProviderException extends WireException {
    public function __construct(string $message, public readonly bool $retryable = false, int $code = 0, ?\Throwable $previous = null, public readonly bool $ambiguous = false) {
        parent::__construct($message, $code, $previous);
    }
}
