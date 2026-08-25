<?php
namespace ProcessWire;

/**
 * MollieGateway
 *
 * Off-site Mollie Checkout integration using Mollie's REST API directly.
 * No Composer dependency is required; webhook verification is done by fetching
 * the payment server-side with the configured API key.
 */
class MollieGateway extends MercatoGatewayBase {

    protected Mercato $commerce;
    protected string $apiBase = 'https://api.mollie.com/v2';

    public function __construct(Mercato $commerce) {
        $this->commerce = $commerce;
    }

    public function getName(): string {
        return 'mollie';
    }

    public function getLabel(): string {
        return 'Mollie';
    }

    public function getPaymentMethods(): array {
        return [
            'mollie' => 'Mollie Checkout',
        ];
    }

    public function getCapabilities(): MercatoGatewayCapabilities {
        return new MercatoGatewayCapabilities(
            name: $this->getName(),
            label: $this->getLabel(),
            paymentMethods: $this->getPaymentMethods(),
            supportsRedirect: true,
            supportsEmbeddedConfirmation: false,
            supportsWebhooks: true,
            supportsRefunds: true,
            supportsPartialRefunds: true,
        );
    }

    public function getSetupStatus(): MercatoGatewaySetupStatus {
        $apiKey = $this->getApiKey();
        $errors = [];

        if ($apiKey === '') {
            $errors[] = 'Mollie API key is missing.';
        }
        $expectedPrefix = !empty($this->commerce->production) ? 'live_' : 'test_';
        if ($apiKey !== '' && !str_starts_with($apiKey, $expectedPrefix)) $errors[] = 'Mollie credential does not match the selected live/test mode.';
        if (!empty($this->commerce->production) && !str_starts_with(strtolower($this->getWebhookUrl()), 'https://')) $errors[] = 'Mollie production webhook URL must use HTTPS.';

        return new MercatoGatewaySetupStatus(
            gateway: $this->getName(),
            ready: count($errors) === 0,
            errors: $errors,
            details: [
                'mode' => $this->commerce->production ? 'live' : 'test',
                'credential_status' => $errors ? 'blocked' : 'configured',
                'webhook_status' => 'provider_refetch_verification',
                'capabilities' => $this->getCapabilities()->toArray(),
                'webhook_url' => $this->getWebhookUrl(),
                'required_events' => [
                    'payment status changes',
                    'refund processing',
                    'refund failed',
                    'refund refunded',
                ],
                'payment_method_source' => 'Mollie Dashboard',
                'available_methods_note' => 'Mollie Checkout shows payment methods enabled and available for the merchant profile, currency, amount, and shopper country in the Mollie Dashboard.',
                'test_mode_note' => 'Use a Mollie test API key while Mercato production mode is off, then complete a test checkout and confirm webhook rows before switching to live mode.',
                'setup_note' => 'Mercato sends this webhook URL when creating Mollie payments. Mollie posts a payment id; Mercato verifies it by fetching the payment with the configured API key.',
            ],
        );
    }

    public function mapExternalStatus(string $externalStatus): string {
        return MercatoPaymentStatusMapper::molliePayment($externalStatus);
    }

    public function retrievePaymentState(Page $order): array {
        $id = trim((string) ($order->mrc_mollie_payment_id ?? ''));
        if ($id === '') throw new WireException('Mollie payment reference is missing.');
        $payment = $this->retrievePayment($id);
        return ['status' => $this->mapExternalStatus((string) ($payment['status'] ?? '')), 'amount' => (float) ($payment['amount']['value'] ?? 0), 'refunded_amount' => (float) ($payment['amountRefunded']['value'] ?? 0), 'currency' => (string) ($payment['amount']['currency'] ?? ''), 'reference' => (string) ($payment['id'] ?? $id)];
    }

    public function initializePayment(array $pendingOrder, MercatoCart $cart): array {
        $amount = (float) ($pendingOrder['mrc_total_amount'] ?? $cart->getSum());
        if ($amount <= 0) {
            throw new WireException('Mollie payment amount must be greater than zero.');
        }

        $payment = $this->request('POST', '/payments', [
            'amount' => [
                'currency' => MercatoCurrency::normalizeCode((string) $this->commerce->currency),
                'value' => $this->formatAmount($amount, (string) $this->commerce->currency),
            ],
            'description' => $this->getDescription($pendingOrder),
            'redirectUrl' => $this->getSuccessUrl(),
            'webhookUrl' => $this->getWebhookUrl(),
            'metadata' => $this->getOrderMetadata($pendingOrder),
        ]);

        $checkoutUrl = $payment['_links']['checkout']['href'] ?? '';
        if ($checkoutUrl === '') {
            throw new WireException('Mollie did not return a checkout URL.');
        }

        $pendingOrder = $this->applyPaymentToOrder($pendingOrder, $payment);
        $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_PENDING;

        return [
            'pending_order' => $pendingOrder,
            'redirect' => $checkoutUrl,
        ];
    }

    public function completePayment(array $pendingOrder, array $data): array {
        $paymentId = $pendingOrder['mollie_payment_id'] ?? $data['id'] ?? null;
        if (!$paymentId) {
            throw new WireException('No Mollie payment ID found.');
        }

        $payment = $this->retrievePayment((string) $paymentId);
        $this->assertPaymentMatchesOrder($payment, $pendingOrder);

        return $this->applyPaymentToOrder($pendingOrder, $payment);
    }

    public function retrievePayment(string $paymentId): array {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            throw new WireException('Mollie payment ID is empty.');
        }
        return $this->request('GET', '/payments/' . rawurlencode($paymentId));
    }

    public function refund(array $orderData, float $amount, string $reason): array {
        $paymentId = trim((string) ($orderData['mollie_payment_id'] ?? ''));
        if ($paymentId === '') {
            throw new WireException('This order has no Mollie payment ID.');
        }
        if ($amount <= 0) {
            throw new WireException('Refund amount must be greater than zero.');
        }

        $refund = $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/refunds', [
            'amount' => [
                'currency' => MercatoCurrency::normalizeCode((string) ($orderData['mrc_currency'] ?? $this->commerce->currency)),
                'value' => $this->formatAmount($amount, (string) ($orderData['mrc_currency'] ?? $this->commerce->currency)),
            ],
            'description' => substr($reason, 0, 255),
            'metadata' => [
                'mrc_order_id' => (string) ($orderData['mrc_order_page_id'] ?? ''),
                'mrc_invoice_number' => (string) ($orderData['mrc_invoice_number'] ?? ''),
            ],
        ]);

        return [
            'gateway' => $this->getName(),
            'id' => (string) ($refund['id'] ?? ''),
            'status' => (string) ($refund['status'] ?? 'pending'),
            'amount' => $amount,
            'payload' => $refund,
        ];
    }

    public function retrieveRefund(array $orderData, string $refundId): array {
        $paymentId = trim((string) ($orderData['mollie_payment_id'] ?? ''));
        $refundId = trim($refundId);
        if ($paymentId === '' || $refundId === '') {
            throw new WireException('Mollie payment or refund ID is empty.');
        }

        $refund = $this->request(
            'GET',
            '/payments/' . rawurlencode($paymentId) . '/refunds/' . rawurlencode($refundId)
        );

        return [
            'gateway' => $this->getName(),
            'id' => (string) ($refund['id'] ?? $refundId),
            'status' => (string) ($refund['status'] ?? 'pending'),
            'payload' => $refund,
        ];
    }

    public function applyPaymentToOrder(array $pendingOrder, array $payment): array {
        $status = MercatoPaymentStatusMapper::molliePayment((string) ($payment['status'] ?? 'open'));

        $pendingOrder['mollie_payment_id'] = (string) ($payment['id'] ?? ($pendingOrder['mollie_payment_id'] ?? ''));
        $pendingOrder['payment_details'] = json_encode($payment);
        $pendingOrder['payment_complete'] = 0;

        switch ($status) {
            case MercatoPaymentStatus::PAID:
                $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_PAID;
                $pendingOrder['payment_complete'] = 1;
                $pendingOrder['paid_date'] = date('Y-m-d H:i:s');
                break;

            case MercatoPaymentStatus::AUTHORIZED:
            case MercatoPaymentStatus::PROCESSING:
                $pendingOrder['payment_status'] = $status;
                break;

            case MercatoPaymentStatus::CANCELED:
                $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_CANCELED;
                break;

            case MercatoPaymentStatus::EXPIRED:
            case MercatoPaymentStatus::FAILED:
                $pendingOrder['payment_status'] = $status;
                break;

            default:
                $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_PENDING;
                break;
        }

        return $pendingOrder;
    }

    protected function request(string $method, string $path, ?array $payload = null): array {
        return MercatoGatewayRequestPolicy::run(fn(): array => $this->requestOnce($method, $path, $payload), (int) ($this->commerce->gateway_retries ?? 2), (float) ($this->commerce->gateway_timeout_seconds ?? 30));
    }

    protected function requestOnce(string $method, string $path, ?array $payload = null): array {
        if (!function_exists('curl_init')) {
            throw new WireException('PHP cURL extension is required for Mollie payments.');
        }

        $key = $this->getApiKey();
        if ($key === '') {
            throw new WireException('Mollie API key is not configured in Mercato module settings.');
        }

        $ch = curl_init($this->apiBase . $path);
        $headers = [
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(3, (int) ($this->commerce->gateway_timeout_seconds ?? 30)),
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new WireException('Mollie API request failed: ' . $curlError);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new WireException('Mollie API returned an unreadable response.');
        }

        if ($status < 200 || $status >= 300) {
            $message = $decoded['detail'] ?? $decoded['title'] ?? 'Mollie API request failed.';
            throw new WireException($message);
        }

        return $decoded;
    }

    protected function getApiKey(): string {
        return $this->commerce->production
            ? trim((string) $this->commerce->mollie_live_key)
            : trim((string) $this->commerce->mollie_test_key);
    }

    protected function getSuccessUrl(): string {
        $successPath = $this->commerce->success_page ?: 'checkout/success';
        $page = wire('pages')->get('/' . ltrim($successPath, '/') . '/');
        $url = ($page && $page->id)
            ? $page->httpUrl()
            : rtrim(wire('config')->urls->httpRoot, '/') . '/' . ltrim($successPath, '/') . '/';

        return $url . (str_contains($url, '?') ? '&' : '?') . 'mollie=1';
    }

    public function getWebhookUrl(): string {
        return $this->commerce->getHttpRoot() . '/api/mercato/mollie-webhook/';
    }

    protected function getDescription(array $pendingOrder): string {
        $invoice = (string) ($pendingOrder['mrc_invoice_number'] ?? $pendingOrder['mrc_order_page_id'] ?? '');
        return $invoice !== '' ? 'Order ' . $invoice : 'Mercato order';
    }

    protected function getOrderMetadata(array $pendingOrder): array {
        $metadata = [];
        if (!empty($pendingOrder['mrc_order_page_id'])) {
            $metadata['mrc_order_id'] = (string) $pendingOrder['mrc_order_page_id'];
        }
        if (!empty($pendingOrder['mrc_invoice_number'])) {
            $metadata['mrc_invoice_number'] = (string) $pendingOrder['mrc_invoice_number'];
        }
        return $metadata;
    }

    protected function formatAmount(float $amount, ?string $currency = null): string {
        return MercatoCurrency::decimalAmount($amount, $currency ?? (string) $this->commerce->currency);
    }

    protected function assertPaymentMatchesOrder(array $payment, array $pendingOrder): void {
        $expectedCurrency = MercatoCurrency::normalizeCode((string) ($pendingOrder['mrc_currency'] ?? $this->commerce->currency));
        $expectedAmount = $this->formatAmount((float) ($pendingOrder['mrc_total_amount'] ?? 0), $expectedCurrency);
        $actualAmount = (string) ($payment['amount']['value'] ?? '');
        if ($expectedAmount !== $actualAmount) {
            throw new WireException('Mollie payment amount does not match the pending order.');
        }

        $actualCurrency = MercatoCurrency::normalizeCode((string) ($payment['amount']['currency'] ?? ''));
        if ($actualCurrency !== $expectedCurrency) {
            throw new WireException('Mollie payment currency does not match the pending order.');
        }

        $expectedOrderId = (string) ($pendingOrder['mrc_order_page_id'] ?? '');
        $actualOrderId = (string) ($payment['metadata']['mrc_order_id'] ?? '');
        if ($expectedOrderId !== '' && $actualOrderId !== '' && $expectedOrderId !== $actualOrderId) {
            throw new WireException('Mollie payment order metadata does not match the pending order.');
        }
    }
}
