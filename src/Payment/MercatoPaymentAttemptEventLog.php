<?php
namespace ProcessWire;

/**
 * Structured audit log for gateway payment attempts.
 */
final class MercatoPaymentAttemptEventLog extends Wire {

    protected string $logName = 'mercato-payment-attempts';

    public function record(string $event, MercatoPaymentAttempt $attempt, array $context = []): void {
        $payload = array_merge([
            'event' => $event,
        ], $attempt->toArray(), [
            'context' => $context,
        ]);

        $log = new MercatoEventLog($this->logName);
        $log->setWire($this->wire());
        $log->record($payload, $event);
    }
}
