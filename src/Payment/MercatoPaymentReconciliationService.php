<?php
namespace ProcessWire;

/**
 * Explicit admin reconciliation for payments verified outside Mercato.
 *
 * This does not contact a gateway and cannot reverse an already paid order.
 * Refunds must use a provider-backed service so money and inventory stay aligned.
 */
final class MercatoPaymentReconciliationService extends Wire {

    protected MercatoPaymentEventLog $eventLog;

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
        $this->eventLog = new MercatoPaymentEventLog();
    }

    public function reconcile(Page $order, string $targetStatus, string $reason, string $userName): array {
        $reason = trim($reason);
        $targetStatus = strtolower(trim($targetStatus));
        $allowed = [
            MercatoPaymentStatus::PAID,
            MercatoPaymentStatus::FAILED,
            MercatoPaymentStatus::CANCELED,
        ];
        if (!in_array($targetStatus, $allowed, true)) {
            throw new WireException($this->commerce->_('Invalid manual payment status.'));
        }
        if (strlen($reason) < 5) {
            throw new WireException($this->commerce->_('Enter a reason for the manual payment change.'));
        }
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            throw new WireException($this->commerce->_('Order not found.'));
        }

        $from = strtolower(trim((string) $order->mrc_payment_status)) ?: MercatoPaymentStatus::PENDING;
        $wasPaid = (int) $order->mrc_payment_complete === 1 || $from === MercatoPaymentStatus::PAID;
        if ($wasPaid || in_array($from, [MercatoPaymentStatus::REFUNDED, MercatoPaymentStatus::PARTIALLY_REFUNDED], true)) {
            throw new WireException($this->commerce->_('Paid or refunded orders require a gateway-backed refund action.'));
        }
        if ($from === $targetStatus) {
            throw new WireException($this->commerce->_('The order already has that payment status.'));
        }

        $previousOrderStatus = $this->commerce->getDerivedOrderStatus($order);
        $pending = $this->commerce->orderRepository()->pageToPendingData($order);
        $pending['payment_status'] = $targetStatus;
        $pending['payment_complete'] = $targetStatus === MercatoPaymentStatus::PAID ? 1 : 0;
        if ($targetStatus === MercatoPaymentStatus::PAID) {
            $pending['paid_date'] = date('Y-m-d H:i:s');
        }
        $updated = $this->commerce->orderRepository()->savePendingOrder($pending);

        $inventoryErrors = [];
        if ($targetStatus === MercatoPaymentStatus::PAID) {
            $inventory = $this->commerce->orderRepository()->decrementStockOnce($updated);
            $inventoryErrors = (array) ($inventory['errors'] ?? []);
            $this->commerce->paymentCompleted($updated, $targetStatus);
        } else {
            $this->commerce->orderRepository()->releaseStockReservation($updated, 'manual_' . $targetStatus);
        }

        $this->eventLog->setWire($this->wire());
        $this->eventLog->reconciled($updated, $from, $targetStatus, $reason, $userName, [
            'inventory_errors' => $inventoryErrors,
        ]);
        $this->commerce->emitOrderStatusChanged($updated, $previousOrderStatus, [
            'source' => 'paymentReconciliation',
            'payment_status_from' => $from,
            'payment_status_to' => $targetStatus,
            'reason' => $reason,
        ]);

        return [
            'order' => $updated,
            'from' => $from,
            'to' => $targetStatus,
            'inventory_errors' => $inventoryErrors,
        ];
    }
}
