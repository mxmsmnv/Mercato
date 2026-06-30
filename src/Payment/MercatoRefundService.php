<?php
namespace ProcessWire;

/**
 * Provider-backed full and partial refunds for paid orders.
 */
final class MercatoRefundService extends Wire {

    protected MercatoRefundEventLog $eventLog;

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
        $this->eventLog = new MercatoRefundEventLog();
    }

    public function refund(Page $order, float $amount, string $reason, string $userName): array {
        $reason = trim($reason);
        $this->assertRefundReady($order);
        if (strlen($reason) < 5) {
            throw new WireException($this->commerce->_('Enter a reason for the refund.'));
        }

        $status = strtolower(trim((string) $order->mrc_payment_status));
        if (!in_array($status, [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED], true)) {
            throw new WireException($this->commerce->_('Only paid or partially refunded orders can be refunded.'));
        }

        $total = $this->commerce->orderRepository()->getTotalAmount($order);
        $alreadyRefunded = round((float) $order->mrc_refunded_amount, 2);
        $pendingRefund = round((float) $order->mrc_refund_pending_amount, 2);
        if ($pendingRefund > 0) {
            throw new WireException($this->commerce->_('A refund is already pending gateway confirmation.'));
        }
        $remaining = round(max(0, $total - $alreadyRefunded - $pendingRefund), 2);
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $remaining) {
            throw new WireException(sprintf($this->commerce->_('Refund amount must be between 0.01 and %s.'), $this->commerce->formatPrice($remaining)));
        }

        $pending = $this->commerce->orderRepository()->pageToPendingData($order);
        $gateway = $this->commerce->getGateway((string) $order->mrc_payment_method);
        if (!method_exists($gateway, 'getCapabilities') || !$gateway->getCapabilities()->supportsRefunds) {
            throw new WireException($this->commerce->_('The selected payment gateway does not support refunds.'));
        }

        $refund = $gateway->refund($pending, $amount, $reason);
        $gatewayStatus = strtolower((string) ($refund['status'] ?? 'pending'));
        if (in_array($gatewayStatus, ['failed', 'canceled', 'cancelled'], true)) {
            throw new WireException($this->commerce->_('The gateway rejected the refund request.'));
        }
        $isConfirmed = in_array($gatewayStatus, ['succeeded', 'refunded'], true);
        $refundedAmount = $isConfirmed ? round($alreadyRefunded + $amount, 2) : $alreadyRefunded;
        $pendingAmount = $isConfirmed ? 0.0 : $amount;
        $isFull = $refundedAmount >= $total;
        $isFullRequested = round($alreadyRefunded + $pendingAmount, 2) >= $total;
        $newStatus = $isConfirmed
            ? ($isFull ? MercatoPaymentStatus::REFUNDED : MercatoPaymentStatus::PARTIALLY_REFUNDED)
            : ($isFullRequested ? MercatoPaymentStatus::REFUND_PENDING : MercatoPaymentStatus::PARTIAL_REFUND_PENDING);
        $previousOrderStatus = $this->commerce->getDerivedOrderStatus($order);
        $details = json_decode((string) $order->mrc_refund_details, true);
        $details = is_array($details) ? $details : [];
        $details[] = [
            'at' => date('Y-m-d H:i:s'),
            'amount' => $amount,
            'reason' => $reason,
            'gateway' => (string) ($refund['gateway'] ?? ''),
            'refund_id' => (string) ($refund['id'] ?? ''),
            'status' => (string) ($refund['status'] ?? ''),
        ];

        $order->of(false);
        $order->mrc_refunded_amount = $refundedAmount;
        $order->mrc_refund_pending_amount = $pendingAmount;
        $order->mrc_refunded_date = date('Y-m-d H:i:s');
        $order->mrc_refund_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $order->mrc_payment_status = $newStatus;
        if ($isFull && $isConfirmed) {
            $order->mrc_payment_complete = 0;
        }
        $this->wire('pages')->save($order);

        $inventory = ['restored' => false, 'errors' => []];
        if ($isFull && $isConfirmed) {
            $inventory = $this->commerce->orderRepository()->restoreStockAfterFullRefundOnce($order);
        }

        $this->eventLog->setWire($this->wire());
        $this->eventLog->issued($order, $refund, $amount, $refundedAmount, $pendingAmount, $newStatus, $reason, $userName);

        $result = [
            'order' => $order,
            'amount' => $amount,
            'total_refunded' => $refundedAmount,
            'pending_amount' => $pendingAmount,
            'remaining' => round(max(0, $total - $refundedAmount - $pendingAmount), 2),
            'status' => $newStatus,
            'gateway_refund' => $refund,
            'inventory' => $inventory,
        ];
        $this->commerce->paymentRefunded($order, $result);
        $this->commerce->emitOrderStatusChanged($order, $previousOrderStatus, [
            'source' => 'refundPayment',
            'payment_status' => $newStatus,
            'refund_amount' => $amount,
            'gateway_status' => $gatewayStatus,
        ]);

        return $result;
    }

    public function reconcilePending(Page $order, string $userName): array {
        $this->assertRefundReady($order);
        $currentStatus = strtolower(trim((string) $order->mrc_payment_status));
        if (!in_array($currentStatus, [MercatoPaymentStatus::REFUND_PENDING, MercatoPaymentStatus::PARTIAL_REFUND_PENDING], true)) {
            throw new WireException($this->commerce->_('This order has no pending refund to reconcile.'));
        }

        $amount = round((float) $order->mrc_refund_pending_amount, 2);
        if ($amount <= 0) {
            throw new WireException($this->commerce->_('Pending refund amount is missing.'));
        }
        $details = json_decode((string) $order->mrc_refund_details, true);
        $details = is_array($details) ? $details : [];
        $lastIndex = count($details) - 1;
        $refundId = $lastIndex >= 0 ? trim((string) ($details[$lastIndex]['refund_id'] ?? '')) : '';
        if ($refundId === '') {
            throw new WireException($this->commerce->_('Pending refund has no provider refund ID.'));
        }

        $pendingOrder = $this->commerce->orderRepository()->pageToPendingData($order);
        $gateway = $this->commerce->getGateway((string) $order->mrc_payment_method);
        $refund = $gateway->retrieveRefund($pendingOrder, $refundId);
        $gatewayStatus = strtolower(trim((string) ($refund['status'] ?? 'pending')));
        $isConfirmed = in_array($gatewayStatus, ['succeeded', 'refunded'], true);
        $isRejected = in_array($gatewayStatus, ['failed', 'canceled', 'cancelled'], true);
        $total = $this->commerce->orderRepository()->getTotalAmount($order);
        $alreadyRefunded = round((float) $order->mrc_refunded_amount, 2);

        if (!$isConfirmed && !$isRejected) {
            $this->eventLog->setWire($this->wire());
            $this->eventLog->reconciled($order, $refund, $amount, $alreadyRefunded, $amount, $currentStatus, 'Still awaiting gateway confirmation.', $userName);
            return [
                'order' => $order,
                'amount' => $amount,
                'total_refunded' => $alreadyRefunded,
                'pending_amount' => $amount,
                'status' => $currentStatus,
                'gateway_refund' => $refund,
                'pending' => true,
                'inventory' => ['restored' => false, 'errors' => []],
            ];
        }

        $refundedAmount = $isConfirmed ? round($alreadyRefunded + $amount, 2) : $alreadyRefunded;
        $isFull = $isConfirmed && $refundedAmount >= $total;
        $newStatus = $isRejected
            ? ($alreadyRefunded > 0 ? MercatoPaymentStatus::PARTIALLY_REFUNDED : MercatoPaymentStatus::PAID)
            : ($isFull ? MercatoPaymentStatus::REFUNDED : MercatoPaymentStatus::PARTIALLY_REFUNDED);
        $previousOrderStatus = $this->commerce->getDerivedOrderStatus($order);

        if ($lastIndex >= 0) {
            $details[$lastIndex]['status'] = (string) ($refund['status'] ?? '');
            $details[$lastIndex]['checked_at'] = date('Y-m-d H:i:s');
        }
        $order->of(false);
        $order->mrc_refund_pending_amount = 0;
        $order->mrc_refunded_amount = $refundedAmount;
        $order->mrc_refunded_date = date('Y-m-d H:i:s');
        $order->mrc_refund_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $order->mrc_payment_status = $newStatus;
        if ($isFull) {
            $order->mrc_payment_complete = 0;
        }
        $this->wire('pages')->save($order);

        $inventory = ['restored' => false, 'errors' => []];
        if ($isFull) {
            $inventory = $this->commerce->orderRepository()->restoreStockAfterFullRefundOnce($order);
        }
        $this->eventLog->setWire($this->wire());
        $this->eventLog->reconciled(
            $order,
            $refund,
            $amount,
            $refundedAmount,
            0.0,
            $newStatus,
            $isRejected ? 'Gateway rejected the pending refund.' : 'Gateway confirmed the refund.',
            $userName
        );
        $this->commerce->emitOrderStatusChanged($order, $previousOrderStatus, [
            'source' => 'reconcileRefund',
            'payment_status' => $newStatus,
            'refund_amount' => $amount,
            'gateway_status' => $gatewayStatus,
        ]);

        return [
            'order' => $order,
            'amount' => $amount,
            'total_refunded' => $refundedAmount,
            'pending_amount' => 0.0,
            'status' => $newStatus,
            'gateway_refund' => $refund,
            'rejected' => $isRejected,
            'inventory' => $inventory,
        ];
    }

    public function reconcilePendingFromWebhook(Page $order, string $gatewayName): array {
        $wire = $this->wire();
        $savedUser = $wire->user;
        $superuser = $wire->users->get('template=user, roles.name=superuser');
        if (!$superuser || !$superuser->id) {
            throw new WireException($this->commerce->_('Mercato: no superuser found - cannot reconcile refund webhook.'));
        }

        $wire->users->setCurrentUser($superuser);
        try {
            return $this->reconcilePending($order, trim($gatewayName) . '_webhook');
        } finally {
            $wire->users->setCurrentUser($savedUser);
        }
    }

    protected function assertRefundReady(Page $order): void {
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            throw new WireException($this->commerce->_('Order not found.'));
        }
        foreach (['mrc_refunded_amount', 'mrc_refund_pending_amount', 'mrc_refund_details', 'mrc_refunded_date', 'mrc_inventory_refund_restored'] as $fieldName) {
            if (!$order->hasField($fieldName)) {
                throw new WireException($this->commerce->_('Refund fields are missing. Run Mercato installer/repair from module settings.'));
            }
        }
    }
}
