<?php
namespace ProcessWire;

/**
 * Structured audit bridge for merchant fulfilment changes.
 *
 * Uses ProcessWire logs now and keeps the event-writing boundary independent
 * from the admin UI so storage can later move to pages or database rows.
 */
final class MercatoFulfilmentEventLog extends Wire {

    protected string $logName = 'mercato-fulfilment';

    public function updated(Page $order, string $from, string $to, string $previousTracking, string $tracking, string $trackingUrl, string $notes, string $userName): void {
        $payload = [
            'event' => 'status_updated',
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'from' => $from,
            'to' => $to,
            'tracking' => $tracking,
            'tracking_url' => $trackingUrl,
            'tracking_changed' => $previousTracking !== $tracking,
            'note' => $notes,
            'user' => $userName,
        ];

        $log = new MercatoEventLog($this->logName);
        $log->setWire($this->wire());
        $log->record($payload, $to);
    }
}
