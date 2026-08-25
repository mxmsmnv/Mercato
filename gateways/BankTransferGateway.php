<?php
namespace ProcessWire;

/**
 * Offline bank transfer gateway.
 *
 * It lets customers place an order without an online provider. The payment
 * stays processing until a merchant confirms funds from Order Detail.
 */
final class BankTransferGateway extends MercatoGatewayBase {

    public function __construct(
        protected Mercato $commerce,
    ) {
    }

    public function getName(): string {
        return 'bank-transfer';
    }

    public function getLabel(): string {
        return 'Bank Transfer';
    }

    public function getPaymentMethods(): array {
        return [
            'bank-transfer' => 'Bank transfer / invoice',
        ];
    }

    public function getCapabilities(): MercatoGatewayCapabilities {
        return new MercatoGatewayCapabilities(
            name: $this->getName(),
            label: $this->getLabel(),
            paymentMethods: $this->getPaymentMethods(),
            supportsRedirect: true,
        );
    }

    public function getSetupStatus(): MercatoGatewaySetupStatus {
        $warnings = [];
        if ($this->getInstructions() === '') {
            $warnings[] = 'Bank transfer instructions are empty.';
        }

        return new MercatoGatewaySetupStatus(
            gateway: $this->getName(),
            ready: true,
            warnings: $warnings,
            details: [
                'mode' => 'offline',
                'manual_reconciliation' => true,
                'credential_status' => 'not_required',
                'webhook_status' => 'not_applicable',
                'capabilities' => $this->getCapabilities()->toArray(),
                'setup_note' => 'Mark the order paid from Order Detail after funds arrive.',
            ],
        );
    }

    public function initializePayment(array $pendingOrder, MercatoCart $cart): array {
        $pendingOrder['payment_status'] = MercatoPaymentStatus::PENDING;
        $pendingOrder['payment_complete'] = 0;
        $pendingOrder['payment_details'] = json_encode($this->details('initialized'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'pending_order' => $pendingOrder,
            'redirect' => $this->getSuccessUrl(),
        ];
    }

    public function completePayment(array $pendingOrder, array $data): array {
        $pendingOrder['payment_status'] = MercatoPaymentStatus::PROCESSING;
        $pendingOrder['payment_complete'] = 0;
        $pendingOrder['payment_details'] = json_encode($this->details('awaiting_transfer'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $pendingOrder;
    }

    protected function getSuccessUrl(): string {
        $path = (string) ($this->commerce->success_page ?: 'checkout/success');
        $page = wire('pages')->get('/' . ltrim($path, '/') . '/');
        $url = ($page && $page->id)
            ? $page->httpUrl()
            : $this->commerce->getHttpRoot() . '/' . ltrim($path, '/') . '/';
        return $url . (str_contains($url, '?') ? '&' : '?') . 'bank_transfer=1';
    }

    protected function getInstructions(): string {
        return trim((string) ($this->commerce->bank_transfer_instructions ?? ''));
    }

    protected function details(string $state): array {
        return [
            'gateway' => $this->getName(),
            'state' => $state,
            'external_charge' => false,
            'manual_reconciliation' => true,
            'instructions' => $this->getInstructions(),
        ];
    }
}
