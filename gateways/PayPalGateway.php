<?php
namespace ProcessWire;

/**
 * PayPal Checkout integration using the Orders v2 API.
 *
 * The first slice supports sandbox/live order creation and return-page capture.
 * Production readiness requires PayPal webhook signature verification, because
 * redirects are not payment truth.
 */
class PayPalGateway extends MercatoGatewayBase {

    protected Mercato $commerce;
    protected string $apiBase;

    public function __construct(Mercato $commerce) {
        $this->commerce = $commerce;
        $this->apiBase = !empty($commerce->production)
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public function getName(): string {
        return 'paypal';
    }

    public function getLabel(): string {
        return 'PayPal';
    }

    public function getPaymentMethods(): array {
        return [
            'paypal' => 'PayPal Checkout',
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
        );
    }

    public function getSetupStatus(): MercatoGatewaySetupStatus {
        $errors = [];
        $warnings = [];

        if ($this->getClientId() === '') {
            $errors[] = 'PayPal client ID is missing.';
        }
        if ($this->getSecret() === '') {
            $errors[] = 'PayPal secret is missing.';
        }
        if (!empty($this->commerce->production) && $this->getWebhookId() === '') {
            $errors[] = 'PayPal webhook ID is missing.';
        }
        if (empty($this->commerce->production) && $this->getWebhookId() === '') {
            $warnings[] = 'PayPal sandbox webhook verification is skipped until a Sandbox Webhook ID is configured.';
        }

        return new MercatoGatewaySetupStatus(
            gateway: $this->getName(),
            ready: count($errors) === 0,
            errors: $errors,
            warnings: $warnings,
            details: [
                'mode' => $this->commerce->production ? 'live' : 'sandbox',
                'webhook_url' => $this->getWebhookUrl(),
                'required_events' => [
                    'CHECKOUT.ORDER.APPROVED',
                    'CHECKOUT.ORDER.CANCELLED',
                    'PAYMENT.CAPTURE.COMPLETED',
                    'PAYMENT.CAPTURE.DENIED',
                    'PAYMENT.CAPTURE.PENDING',
                    'PAYMENT.CAPTURE.REFUNDED',
                ],
                'webhook_id_configured' => $this->getWebhookId() !== '',
                'setup_note' => 'Create REST app credentials in PayPal Developer Dashboard, register the webhook URL for the required events, and save the Webhook ID here. Mercato verifies signed PayPal webhooks before processing production events.',
            ],
        );
    }

    public function verifyWebhookSignature(array $event, array $headers): bool {
        $webhookId = $this->getWebhookId();
        if ($webhookId === '') {
            throw new WireException('PayPal webhook ID is required for signature verification.');
        }

        foreach (['transmission_id', 'transmission_time', 'cert_url', 'auth_algo', 'transmission_sig'] as $name) {
            if (trim((string) ($headers[$name] ?? '')) === '') {
                throw new WireException('Missing PayPal webhook signature header: ' . $name, 400);
            }
        }

        $response = $this->request('POST', '/v1/notifications/verify-webhook-signature', [
            'webhook_id' => $webhookId,
            'transmission_id' => (string) $headers['transmission_id'],
            'transmission_time' => (string) $headers['transmission_time'],
            'cert_url' => (string) $headers['cert_url'],
            'auth_algo' => (string) $headers['auth_algo'],
            'transmission_sig' => (string) $headers['transmission_sig'],
            'webhook_event' => $event,
        ], 'mrc_paypal_webhook_verify_' . md5((string) ($headers['transmission_id'] ?? uniqid('', true))));

        return strtoupper((string) ($response['verification_status'] ?? '')) === 'SUCCESS';
    }

    public function mapExternalStatus(string $externalStatus): string {
        return MercatoPaymentStatusMapper::payPalOrder($externalStatus);
    }

    public function initializePayment(array $pendingOrder, MercatoCart $cart): array {
        $amount = (float) ($pendingOrder['mrc_total_amount'] ?? $cart->getSum());
        if ($amount <= 0) {
            throw new WireException('PayPal payment amount must be greater than zero.');
        }

        $order = $this->request('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) ($pendingOrder['mrc_order_page_id'] ?? ''),
                'invoice_id' => $this->getInvoiceId($pendingOrder),
                'custom_id' => (string) ($pendingOrder['mrc_order_page_id'] ?? ''),
                'description' => $this->getDescription($pendingOrder),
                'amount' => [
                    'currency_code' => MercatoCurrency::normalizeCode((string) $this->commerce->currency),
                    'value' => $this->formatAmount($amount, (string) $this->commerce->currency),
                ],
            ]],
            'application_context' => [
                'return_url' => $this->getSuccessUrl(),
                'cancel_url' => $this->getCancelUrl(),
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ],
        ], $this->getRequestId($pendingOrder, 'create'));

        $approvalUrl = $this->getApprovalUrl($order);
        if ($approvalUrl === '') {
            throw new WireException('PayPal did not return an approval URL.');
        }

        $pendingOrder['paypal_order_id'] = (string) ($order['id'] ?? '');
        $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_PENDING;
        $pendingOrder['payment_complete'] = 0;
        $pendingOrder['payment_details'] = json_encode($order, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'pending_order' => $pendingOrder,
            'redirect' => $approvalUrl,
        ];
    }

    public function completePayment(array $pendingOrder, array $data): array {
        $orderId = trim((string) ($pendingOrder['paypal_order_id'] ?? $data['token'] ?? ''));
        if ($orderId === '') {
            throw new WireException('No PayPal order ID found.');
        }

        $capture = $this->request(
            'POST',
            '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
            new \stdClass(),
            $this->getRequestId($pendingOrder, 'capture')
        );
        $this->assertPaymentMatchesOrder($capture, $pendingOrder);
        return $this->applyPaymentToOrder($pendingOrder, $capture);
    }

    protected function request(string $method, string $path, mixed $payload = null, string $requestId = ''): array {
        if (!function_exists('curl_init')) {
            throw new WireException('PHP cURL extension is required for PayPal payments.');
        }

        $ch = curl_init($this->apiBase . $path);
        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($requestId !== '') {
            $headers[] = 'PayPal-Request-Id: ' . $requestId;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new WireException('PayPal API request failed: ' . $curlError);
        }

        $decoded = json_decode((string) $body, true);
        if ($status >= 400) {
            $message = is_array($decoded)
                ? (string) ($decoded['message'] ?? $decoded['name'] ?? $body)
                : (string) $body;
            throw new WireException(sprintf('PayPal API error %d: %s', $status, $message));
        }

        return is_array($decoded) ? $decoded : [];
    }

    protected function getAccessToken(): string {
        $clientId = $this->getClientId();
        $secret = $this->getSecret();
        if ($clientId === '' || $secret === '') {
            throw new WireException('PayPal client ID and secret are required.');
        }

        $ch = curl_init($this->apiBase . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $clientId . ':' . $secret,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: en_US',
            ],
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new WireException('PayPal OAuth request failed: ' . $curlError);
        }

        $decoded = json_decode((string) $body, true);
        if ($status >= 400 || !is_array($decoded) || empty($decoded['access_token'])) {
            $message = is_array($decoded) ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? $body) : (string) $body;
            throw new WireException(sprintf('PayPal OAuth error %d: %s', $status, $message));
        }

        return (string) $decoded['access_token'];
    }

    protected function applyPaymentToOrder(array $pendingOrder, array $capture): array {
        $status = MercatoPaymentStatusMapper::payPalOrder((string) ($capture['status'] ?? ''));
        $pendingOrder['paypal_order_id'] = (string) ($capture['id'] ?? ($pendingOrder['paypal_order_id'] ?? ''));
        $pendingOrder['payment_details'] = json_encode($capture, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $pendingOrder['payment_complete'] = 0;

        if ($status === MercatoPaymentStatus::PAID) {
            $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_PAID;
            $pendingOrder['payment_complete'] = 1;
            $pendingOrder['paid_date'] = date('Y-m-d H:i:s');
        } elseif ($status === MercatoPaymentStatus::AUTHORIZED) {
            $pendingOrder['payment_status'] = MercatoPaymentStatus::AUTHORIZED;
        } elseif ($status === MercatoPaymentStatus::CANCELED) {
            $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_CANCELED;
        } elseif (in_array($status, [MercatoPaymentStatus::FAILED, MercatoPaymentStatus::EXPIRED], true)) {
            $pendingOrder['payment_status'] = $status;
        } else {
            $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_PROCESSING;
        }

        return $pendingOrder;
    }

    protected function assertPaymentMatchesOrder(array $capture, array $pendingOrder): void {
        $purchaseUnit = $capture['purchase_units'][0] ?? [];
        $payments = is_array($purchaseUnit) ? (array) ($purchaseUnit['payments']['captures'] ?? []) : [];
        $captureAmount = $payments[0]['amount'] ?? [];
        $expectedCurrency = MercatoCurrency::normalizeCode((string) ($pendingOrder['mrc_currency'] ?? $this->commerce->currency));
        $expectedAmount = $this->formatAmount((float) ($pendingOrder['mrc_total_amount'] ?? 0), $expectedCurrency);
        $actualAmount = (string) ($captureAmount['value'] ?? '');
        $actualCurrency = MercatoCurrency::normalizeCode((string) ($captureAmount['currency_code'] ?? ''));

        if ($actualAmount !== '' && $actualAmount !== $expectedAmount) {
            throw new WireException('PayPal amount does not match the pending order.');
        }
        if ($actualCurrency !== '' && $actualCurrency !== $expectedCurrency) {
            throw new WireException('PayPal currency does not match the pending order.');
        }
    }

    protected function getApprovalUrl(array $order): string {
        foreach ((array) ($order['links'] ?? []) as $link) {
            if (!is_array($link)) continue;
            if ((string) ($link['rel'] ?? '') === 'approve') {
                return (string) ($link['href'] ?? '');
            }
        }
        return '';
    }

    protected function getClientId(): string {
        return !empty($this->commerce->production)
            ? trim((string) ($this->commerce->paypal_live_client_id ?? ''))
            : trim((string) ($this->commerce->paypal_test_client_id ?? ''));
    }

    protected function getSecret(): string {
        return !empty($this->commerce->production)
            ? trim((string) ($this->commerce->paypal_live_secret ?? ''))
            : trim((string) ($this->commerce->paypal_test_secret ?? ''));
    }

    protected function getWebhookId(): string {
        return !empty($this->commerce->production)
            ? trim((string) ($this->commerce->paypal_live_webhook_id ?? ''))
            : trim((string) ($this->commerce->paypal_test_webhook_id ?? ''));
    }

    protected function getSuccessUrl(): string {
        $path = (string) ($this->commerce->success_page ?: 'checkout/success');
        $page = wire('pages')->get('/' . ltrim($path, '/') . '/');
        $url = ($page && $page->id)
            ? $page->httpUrl()
            : $this->commerce->getHttpRoot() . '/' . ltrim($path, '/') . '/';
        return $url . (str_contains($url, '?') ? '&' : '?') . 'paypal=1';
    }

    protected function getCancelUrl(): string {
        $path = (string) ($this->commerce->cancel_page ?: 'checkout');
        $page = wire('pages')->get('/' . ltrim($path, '/') . '/');
        return ($page && $page->id)
            ? $page->httpUrl()
            : $this->commerce->getHttpRoot() . '/' . ltrim($path, '/') . '/';
    }

    protected function getWebhookUrl(): string {
        return $this->commerce->getHttpRoot() . '/api/mercato/paypal-webhook/';
    }

    protected function getInvoiceId(array $pendingOrder): string {
        $invoice = trim((string) ($pendingOrder['mrc_invoice_number'] ?? ''));
        return $invoice !== '' ? $invoice : 'mrc-' . (string) ($pendingOrder['mrc_order_page_id'] ?? time());
    }

    protected function getDescription(array $pendingOrder): string {
        $invoice = trim((string) ($pendingOrder['mrc_invoice_number'] ?? ''));
        return $invoice !== '' ? 'Order ' . $invoice : 'Mercato order';
    }

    protected function getRequestId(array $pendingOrder, string $operation): string {
        $base = (string) ($pendingOrder['payment_attempt_idempotency_key'] ?? $pendingOrder['mrc_order_page_id'] ?? uniqid('mrc_', true));
        return substr(preg_replace('/[^A-Za-z0-9_-]/', '_', $base . '_' . $operation) ?: uniqid('mrc_', true), 0, 108);
    }

    protected function formatAmount(float $amount, ?string $currency = null): string {
        return MercatoCurrency::decimalAmount($amount, $currency ?? (string) $this->commerce->currency);
    }
}
