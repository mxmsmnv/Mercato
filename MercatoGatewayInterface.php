<?php
namespace ProcessWire;

/**
 * MercatoGatewayBase
 *
 * Abstract base class for Mercato payment gateways.
 * Custom gateways should extend this class and override only the methods they need.
 *
 * Both initializePayment() and completePayment() have safe no-op defaults,
 * so a bank-transfer gateway that only implements completePayment() works fine.
 */
abstract class MercatoGatewayBase implements MercatoGatewayInterface {

    public function getName(): string {
        $class = (new \ReflectionClass($this))->getShortName();
        return strtolower(preg_replace('/Gateway$/', '', $class) ?: $class);
    }

    public function getLabel(): string {
        return ucfirst($this->getName());
    }

    public function getPaymentMethods(): array {
        return [];
    }

    public function supportsMethod(string $method): bool {
        return array_key_exists($method, $this->getPaymentMethods());
    }

    public function getCapabilities(): MercatoGatewayCapabilities {
        return new MercatoGatewayCapabilities(
            name: $this->getName(),
            label: $this->getLabel(),
            paymentMethods: $this->getPaymentMethods(),
        );
    }

    public function getSetupStatus(): MercatoGatewaySetupStatus {
        return new MercatoGatewaySetupStatus(
            gateway: $this->getName(),
            ready: true,
        );
    }

    public function mapExternalStatus(string $externalStatus): string {
        return MercatoPaymentStatusMapper::generic($externalStatus);
    }

    /**
     * Default initializePayment: no external API call needed.
     * Return the pending order unchanged; no redirect.
     */
    public function initializePayment(array $pendingOrder, MercatoCart $cart): array {
        return ['pending_order' => $pendingOrder];
    }

    /**
     * Default completePayment: mark as pending (e.g. bank transfer awaiting confirmation).
     */
    public function completePayment(array $pendingOrder, array $data): array {
        return $pendingOrder;
    }

    /**
     * Optional provider-backed refund operation.
     *
     * Gateways that advertise refund support should override this method.
     */
    public function refund(array $orderData, float $amount, string $reason): array {
        throw new WireException(sprintf('%s does not implement refunds.', $this->getLabel()));
    }

    /**
     * Optional provider-backed status lookup for an asynchronous refund.
     */
    public function retrieveRefund(array $orderData, string $refundId): array {
        throw new WireException(sprintf('%s does not implement refund reconciliation.', $this->getLabel()));
    }
}

/**
 * MercatoGatewayInterface
 *
 * Contract for Mercato payment gateways.
 * Implement this interface directly for full control, or extend MercatoGatewayBase
 * for a simpler approach where you only override the methods you need.
 */
interface MercatoGatewayInterface {

    /**
     * Step 1: Initialize payment.
     *
     * Called during checkout form submission. Use this to:
     * - Create a PaymentIntent (Stripe)
     * - Create an order on the payment provider's side (PayPal)
     * - Generate a redirect URL for off-site payment flows
     *
     * @param array $pendingOrder  Sanitized form data + mrc_items JSON
     * @param MercatoCart $cart    Current cart
     *
     * @return array {
     *   'pending_order' => array,  // updated pending order (e.g. with payment intent ID)
     *   'redirect'      => string, // optional redirect URL (overrides success page)
     * }
     */
    public function initializePayment(array $pendingOrder, MercatoCart $cart): array;

    /**
     * Step 2: Complete payment.
     *
     * Called on the success/return page. Use this to:
     * - Capture a PaymentIntent
     * - Mark the order as paid
     * - Retrieve final payment details from provider
     *
     * @param array $pendingOrder  Pending order from session
     * @param array $data          Extra data from GET/POST on return URL (e.g. payment_intent)
     *
     * @return array Updated pending order with payment_complete, paid_date, payment_details set
     */
    public function completePayment(array $pendingOrder, array $data): array;
}
