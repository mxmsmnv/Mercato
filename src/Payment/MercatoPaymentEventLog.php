<?php
namespace ProcessWire;

/**
 * Structured audit bridge for manual payment support actions.
 */
final class MercatoPaymentEventLog extends Wire {

    protected string $logName = 'mercato-payments';

    public function reconciled(Page $order, string $from, string $to, string $reason, string $userName, array $context = []): void {
        $payload = array_merge([
            'event' => 'manual_reconciliation',
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
            'user' => $userName,
        ], $context);

        $log = new MercatoEventLog($this->logName);
        $log->setWire($this->wire());
        $log->record($payload, $to);
    }
}
