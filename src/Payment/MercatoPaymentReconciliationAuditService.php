<?php
namespace ProcessWire;

final class MercatoPaymentReconciliationAuditService extends Wire {
    public function __construct(protected Mercato $commerce) { parent::__construct(); }

    public function inspect(Page $order, array $attempts = [], bool $refreshRemote = false): array {
        $details = json_decode((string) ($order->mrc_payment_details ?? ''), true); $details = is_array($details) ? $details : [];
        $remote = (array) ($details['_mercato_reconciliation']['remote'] ?? []);
        if ($refreshRemote) $remote = $this->verifyRemote($order);
        $items = json_decode((string) ($order->mrc_items ?? ''), true); $requiresInventory = false;
        foreach (is_array($items) ? $items : [] as $item) if (($item['product_type'] ?? 'physical') === 'physical') { $requiresInventory = true; break; }
        $local = ['status' => (string) ($order->mrc_payment_status ?: MercatoPaymentStatus::PENDING), 'paid' => (int) $order->mrc_payment_complete === 1, 'total' => (float) $order->mrc_total_amount, 'refunded_amount' => (float) ($order->mrc_refunded_amount ?? 0), 'inventory_adjusted' => (int) ($order->mrc_inventory_adjusted ?? 0) === 1, 'confirmation_sent' => trim((string) ($order->mrc_confirmation_sent_date ?? '')) !== '', 'tax_committed' => (int) ($order->mrc_tax_committed ?? 0) === 1, 'requires_inventory' => $requiresInventory];
        return MercatoPaymentReconciliationAudit::classify($local, $remote, $attempts) + ['order_id' => (int) $order->id, 'invoice' => (string) ($order->mrc_invoice_number ?: $order->title), 'remote' => $remote];
    }

    public function verifyRemote(Page $order): array {
        $gateway = $this->commerce->getGateway((string) $order->mrc_payment_method);
        if (!method_exists($gateway, 'retrievePaymentState')) throw new WireException('This gateway does not support remote payment verification.', 409);
        $remote = $gateway->retrievePaymentState($order);
        if (!is_array($remote) || empty($remote['status'])) throw new WireException('Gateway returned an invalid payment state.', 502);
        $remote = ['status' => strtolower((string) $remote['status']), 'amount' => round((float) ($remote['amount'] ?? 0), 2), 'refunded_amount' => round((float) ($remote['refunded_amount'] ?? 0), 2), 'currency' => strtoupper((string) ($remote['currency'] ?? $order->mrc_currency)), 'reference' => substr(trim((string) ($remote['reference'] ?? '')), 0, 240), 'verified_at' => date(DATE_ATOM)];
        $details = json_decode((string) ($order->mrc_payment_details ?? ''), true); $details = is_array($details) ? $details : [];
        $details['_mercato_reconciliation']['remote'] = $remote; $order->of(false); $order->mrc_payment_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $this->wire('pages')->save($order);
        $this->audit('remote_verified', $order, ['remote_status' => $remote['status'], 'reference' => $remote['reference']]);
        return $remote;
    }

    public function repair(Page $order, string $action, string $reason, string $user): array {
        if (strlen(trim($reason)) < 8) throw new WireException('Enter a detailed repair reason.');
        $audit = $this->inspect($order, [], true);
        if ($action === 'apply_remote_paid') {
            if (!in_array($audit['remote_status'], [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUNDED], true)) throw new WireException('Remote payment is not paid; repair was blocked.', 409);
            $service = new MercatoPaymentReconciliationService($this->commerce); $service->setWire($this->wire());
            $result = $service->reconcile($order, MercatoPaymentStatus::PAID, $reason, $user);
        } elseif ($action === 'replay_finalization') {
            if (!in_array((string) $order->mrc_payment_status, [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUNDED], true)) throw new WireException('Only locally paid orders can replay finalization.', 409);
            $inventory = $this->commerce->orderRepository()->decrementStockOnce($order); $this->commerce->paymentCompleted($order, MercatoPaymentStatus::PAID); $result = ['order' => $order, 'inventory' => $inventory];
        } elseif ($action === 'reconcile_refund') {
            $refunds = new MercatoRefundService($this->commerce); $refunds->setWire($this->wire());
            $result = $refunds->reconcilePending($order, $user);
        } else throw new WireException('Unknown reconciliation repair action.', 422);
        $this->audit('repair_applied', $order, ['action' => $action, 'reason' => $reason, 'user' => $user]);
        return ['audit' => $this->inspect($this->wire('pages')->get($order->id)), 'result' => $result];
    }

    protected function audit(string $event, Page $order, array $context): void { $log = new MercatoEventLog('mercato-payment-reconciliation'); $log->setWire($this->wire()); $log->record(['event' => $event, 'order_id' => (int) $order->id, 'invoice' => (string) ($order->mrc_invoice_number ?: $order->title), 'context' => $context], $event); }
}
