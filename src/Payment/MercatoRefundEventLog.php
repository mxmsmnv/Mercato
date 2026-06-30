<?php
namespace ProcessWire;

/**
 * Audit bridge for provider-backed refunds.
 */
final class MercatoRefundEventLog extends Wire {

    protected string $logName = 'mercato-refunds';

    public function issued(Page $order, array $refund, float $amount, float $totalRefunded, float $pendingAmount, string $paymentStatus, string $reason, string $userName): void {
        $this->record([
            'event' => 'refund_issued',
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'gateway' => (string) ($refund['gateway'] ?? ''),
            'refund_id' => (string) ($refund['id'] ?? ''),
            'gateway_status' => (string) ($refund['status'] ?? ''),
            'amount' => $amount,
            'total_refunded' => $totalRefunded,
            'pending_amount' => $pendingAmount,
            'payment_status' => $paymentStatus,
            'reason' => $reason,
            'user' => $userName,
        ]);
    }

    public function reconciled(Page $order, array $refund, float $amount, float $totalRefunded, float $pendingAmount, string $paymentStatus, string $outcome, string $userName): void {
        $this->record([
            'event' => 'refund_reconciled',
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'gateway' => (string) ($refund['gateway'] ?? ''),
            'refund_id' => (string) ($refund['id'] ?? ''),
            'gateway_status' => (string) ($refund['status'] ?? ''),
            'amount' => $amount,
            'total_refunded' => $totalRefunded,
            'pending_amount' => $pendingAmount,
            'payment_status' => $paymentStatus,
            'reason' => $outcome,
            'user' => $userName,
        ]);
    }

    protected function record(array $payload): void {
        $log = new MercatoEventLog($this->logName);
        $log->setWire($this->wire());
        $log->record($payload, (string) ($payload['payment_status'] ?? 'refund'));
    }
}
