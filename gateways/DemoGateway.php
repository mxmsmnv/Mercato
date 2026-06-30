<?php
namespace ProcessWire;

/**
 * Local checkout gateway for end-to-end storefront acceptance tests.
 *
 * It uses the regular pending-order and completion path, but never contacts
 * an external payment provider and cannot run when production mode is enabled.
 */
final class DemoGateway extends MercatoGatewayBase {

    public function __construct(
        protected Mercato $commerce,
    ) {
    }

    public function getName(): string {
        return 'demo';
    }

    public function getLabel(): string {
        return 'Demo Payment';
    }

    public function getPaymentMethods(): array {
        return [
            'demo' => 'Demo Payment (test only)',
        ];
    }

    public function getCapabilities(): MercatoGatewayCapabilities {
        return new MercatoGatewayCapabilities(
            name: $this->getName(),
            label: $this->getLabel(),
            paymentMethods: $this->getPaymentMethods(),
            supportsRedirect: true,
            supportsRefunds: true,
            supportsPartialRefunds: true,
        );
    }

    public function getSetupStatus(): MercatoGatewaySetupStatus {
        return new MercatoGatewaySetupStatus(
            gateway: $this->getName(),
            ready: !$this->commerce->production,
            errors: $this->commerce->production ? ['Demo Payment is unavailable in production mode.'] : [],
            details: ['mode' => $this->commerce->production ? 'disabled' : 'test'],
        );
    }

    public function initializePayment(array $pendingOrder, MercatoCart $cart): array {
        $this->assertTestMode();
        $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_PENDING;
        $pendingOrder['payment_complete'] = 0;
        $pendingOrder['payment_details'] = json_encode([
            'gateway' => 'demo',
            'state' => 'initialized',
            'external_charge' => false,
            'idempotency_key' => (string) ($pendingOrder['payment_attempt_idempotency_key'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'pending_order' => $pendingOrder,
            'redirect' => $this->getSuccessUrl(),
        ];
    }

    public function completePayment(array $pendingOrder, array $data): array {
        $this->assertTestMode();
        $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_PAID;
        $pendingOrder['payment_complete'] = 1;
        $pendingOrder['paid_date'] = date('Y-m-d H:i:s');
        $pendingOrder['payment_details'] = json_encode([
            'gateway' => 'demo',
            'state' => 'paid',
            'completed_at' => date(DATE_ATOM),
            'external_charge' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $pendingOrder;
    }

    public function refund(array $orderData, float $amount, string $reason): array {
        $this->assertTestMode();
        if ($amount <= 0) {
            throw new WireException('Refund amount must be greater than zero.');
        }

        $reason = trim($reason);
        $orderId = (int) ($orderData['mrc_order_page_id'] ?? 0);
        $reasonLower = strtolower($reason);
        $status = (str_contains($reasonLower, '[pending]') || str_contains($reasonLower, '[failed]')) ? 'pending' : 'succeeded';
        $idState = str_contains($reasonLower, '[failed]') ? 'failed_' : (str_contains($reasonLower, '[pending]') ? 'pending_' : '');
        return [
            'gateway' => $this->getName(),
            'id' => 'demo_re_' . $idState . ($orderId > 0 ? $orderId : time()) . '_' . substr(sha1((string) microtime(true)), 0, 8),
            'status' => $status,
            'amount' => $amount,
            'reason' => substr($reason, 0, 255),
            'payload' => [
                'mode' => 'test',
                'external_charge' => false,
                'mrc_order_id' => $orderId,
            ],
        ];
    }

    public function retrieveRefund(array $orderData, string $refundId): array {
        $this->assertTestMode();
        $refundId = trim($refundId);
        if ($refundId === '') {
            throw new WireException('Demo refund ID is empty.');
        }

        $status = str_starts_with($refundId, 'demo_re_failed_') ? 'failed' : 'succeeded';
        return [
            'gateway' => $this->getName(),
            'id' => $refundId,
            'status' => $status,
            'payload' => [
                'mode' => 'test',
                'external_charge' => false,
                'mrc_order_id' => (int) ($orderData['mrc_order_page_id'] ?? 0),
            ],
        ];
    }

    protected function getSuccessUrl(): string {
        $path = (string) ($this->commerce->success_page ?: 'checkout/success');
        $page = wire('pages')->get('/' . ltrim($path, '/') . '/');
        $url = ($page && $page->id)
            ? $page->httpUrl()
            : $this->commerce->getHttpRoot() . '/' . ltrim($path, '/') . '/';
        return $url . (str_contains($url, '?') ? '&' : '?') . 'demo_payment=1';
    }

    protected function assertTestMode(): void {
        if (!empty($this->commerce->production)) {
            throw new WireException($this->commerce->_('Demo Payment is disabled in production mode.'));
        }
    }
}
