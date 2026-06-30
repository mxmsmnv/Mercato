<?php
namespace ProcessWire;

/**
 * StripeGateway
 *
 * Stripe payment gateway for Mercato.
 * Supports: Credit/Debit Cards (SCA), Klarna, iDEAL, SEPA Debit.
 *
 * Payment flow:
 *   Cards:      initializePayment creates PaymentIntent (manual capture).
 *               Frontend confirms via Stripe.js. completePayment captures.
 *   Klarna/iDEAL: initializePayment creates PaymentIntent with redirect.
 *               completePayment captures after return from provider.
 *
 * Webhook (recommended for production):
 *   payment_intent.succeeded → marks order paid even if user closes browser.
 */
class StripeGateway extends MercatoGatewayBase {

    protected Mercato $commerce;

    // Payment method identifiers used as payment_method in form data
    const METHOD_CARD  = 'stripe-card';
    const METHOD_KLARNA = 'stripe-klarna';
    const METHOD_IDEAL  = 'stripe-ideal';
    const METHOD_SEPA   = 'stripe-sepa';

    public function __construct(Mercato $commerce) {
        $this->commerce = $commerce;
        // Do NOT configure Stripe here — defer to first actual API call
        // so that constructing the gateway doesn't throw if keys are empty.
    }

    public function getName(): string {
        return 'stripe';
    }

    public function getLabel(): string {
        return 'Stripe';
    }

    public function getPaymentMethods(): array {
        return [
            self::METHOD_CARD => 'Stripe Card',
            self::METHOD_IDEAL => 'Stripe iDEAL',
            self::METHOD_SEPA => 'Stripe SEPA Debit',
            self::METHOD_KLARNA => 'Stripe Klarna',
        ];
    }

    public function getCapabilities(): MercatoGatewayCapabilities {
        return new MercatoGatewayCapabilities(
            name: $this->getName(),
            label: $this->getLabel(),
            paymentMethods: $this->getPaymentMethods(),
            supportsRedirect: true,
            supportsEmbeddedConfirmation: true,
            supportsWebhooks: true,
            supportsRefunds: true,
            supportsPartialRefunds: true,
        );
    }

    public function getSetupStatus(): MercatoGatewaySetupStatus {
        $publishableKey = trim($this->getPublishableKey());
        $secretKey = trim($this->commerce->production ? (string) $this->commerce->stripe_live_sk : (string) $this->commerce->stripe_test_sk);
        $webhookSecret = trim((string) $this->commerce->stripe_webhook_secret);
        $errors = [];
        $warnings = [];

        if ($publishableKey === '') {
            $errors[] = 'Stripe publishable key is missing.';
        }
        if ($secretKey === '') {
            $errors[] = 'Stripe secret key is missing.';
        }
        if ($webhookSecret === '') {
            $warnings[] = 'Stripe webhook signing secret is not configured.';
        }

        return new MercatoGatewaySetupStatus(
            gateway: $this->getName(),
            ready: count($errors) === 0,
            errors: $errors,
            warnings: $warnings,
            details: [
                'mode' => $this->commerce->production ? 'live' : 'test',
                'webhook_url' => $this->commerce->getHttpRoot() . '/api/mercato/stripe-webhook/',
                'required_events' => [
                    'payment_intent.succeeded',
                    'payment_intent.payment_failed',
                    'payment_intent.processing',
                    'payment_intent.canceled',
                    'checkout.session.completed',
                    'checkout.session.expired',
                    'charge.refunded',
                    'customer.subscription.created',
                    'customer.subscription.updated',
                    'customer.subscription.deleted',
                    'invoice.paid',
                    'invoice.payment_failed',
                    'refund.created',
                    'refund.updated',
                    'refund.failed',
                ],
                'setup_note' => 'Create the endpoint in Stripe Dashboard > Developers > Webhooks, select these events, then copy the signing secret into Mercato settings.',
            ],
        );
    }

    public function mapExternalStatus(string $externalStatus): string {
        return MercatoPaymentStatusMapper::stripePaymentIntent($externalStatus);
    }

    // -----------------------------------------------------------------------
    // Gateway interface
    // -----------------------------------------------------------------------

    /**
     * Initialize Stripe payment.
     *
     * For card payments: creates a PaymentIntent and returns its client_secret
     * so the frontend can confirm via Stripe.js / Payment Element.
     *
     * For redirect-based methods (Klarna, iDEAL): creates a PaymentIntent
     * with confirm=true and returns the redirect URL.
     */
    public function initializePayment(array $pendingOrder, MercatoCart $cart): array {
        $method = $pendingOrder['payment_method'];
        $sum    = (float) ($pendingOrder['mrc_total_amount'] ?? $cart->getSum());

        switch ($method) {
            case self::METHOD_CARD:
                return $this->initializeCardPayment($pendingOrder, $sum);

            case self::METHOD_KLARNA:
                return $this->initializeKlarnaPayment($pendingOrder, $sum);

            case self::METHOD_IDEAL:
                return $this->initializeiDEALPayment($pendingOrder, $sum);

            case self::METHOD_SEPA:
                return $this->initializeSepaPayment($pendingOrder, $sum);

            default:
                throw new WireException(
                    sprintf('StripeGateway: unsupported payment method "%s".', $method)
                );
        }
    }

    /**
     * Complete Stripe payment.
     *
     * Retrieves the PaymentIntent, captures it (manual capture mode),
     * and marks the pending order as paid.
     */
    public function completePayment(array $pendingOrder, array $data): array {
        // Check for user-canceled redirect-based payment
        if (($data['redirect_status'] ?? '') === 'failed') {
            $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_FAILED;
            $pendingOrder['payment_complete'] = 0;
        }

        // Prefer PI ID from the session (set during initializePayment) over the GET
        // parameter that Stripe appends to the return URL. The GET param is not
        // authenticated — anyone could craft a URL with a foreign payment_intent value.
        // Using the session value as the primary source closes that attack vector.
        $piId = $pendingOrder['stripe_payment_intent_id']
            ?? $data['payment_intent']
            ?? null;

        if (!$piId) {
            if (($pendingOrder['payment_status'] ?? '') === Mercato::PAYMENT_STATUS_FAILED) {
                return $pendingOrder;
            }
            throw new WireException('No Stripe PaymentIntent ID found.');
        }

        $pi = $this->retrievePaymentIntent($piId);
        $this->assertPaymentIntentMatchesOrder($pi, $pendingOrder);

        // Capture if in requires_capture state (manual capture for card payments).
        // Note: mrc_order_id metadata is attached separately after the order page
        // is saved in Mercato::completePayment() via updatePaymentIntentMetadata().
        if ($pi->status === 'requires_capture') {
            $pi = $pi->capture();
        } elseif (in_array($pi->status, ['requires_action', 'processing'])) {
            // Not yet captured — return without marking paid (webhook will handle)
            $pendingOrder['stripe_payment_intent_id'] = $pi->id;
            $pendingOrder['payment_details']           = json_encode($pi->toArray());
            $pendingOrder['payment_complete']          = 0;
            $pendingOrder['payment_status']            = $pi->status === 'processing'
                ? Mercato::PAYMENT_STATUS_PROCESSING
                : Mercato::PAYMENT_STATUS_REQUIRES_ACTION;
            return $pendingOrder;
        }

        if ($pi->status === 'succeeded') {
            $pendingOrder['payment_complete']          = 1;
            $pendingOrder['payment_status']            = Mercato::PAYMENT_STATUS_PAID;
            $pendingOrder['paid_date']                 = date('Y-m-d H:i:s');
        } elseif ($pi->status === 'canceled') {
            $pendingOrder['payment_complete']          = 0;
            $pendingOrder['payment_status']            = Mercato::PAYMENT_STATUS_CANCELED;
        } elseif ($pi->status === 'requires_payment_method') {
            $pendingOrder['payment_complete']          = 0;
            $pendingOrder['payment_status']            = Mercato::PAYMENT_STATUS_FAILED;
        } else {
            throw new WireException(sprintf('Unsupported Stripe PaymentIntent status "%s".', $pi->status));
        }

        $pendingOrder['stripe_payment_intent_id'] = $pi->id;
        $pendingOrder['payment_details']           = json_encode($pi->toArray());

        return $pendingOrder;
    }

    // -----------------------------------------------------------------------
    // Method-specific initializers
    // -----------------------------------------------------------------------

    protected function initializeCardPayment(array $pendingOrder, float $sum): array {
        $pi = $this->createPaymentIntent($sum, $this->getCardPaymentIntentParams($pendingOrder), $this->getPaymentIntentRequestOptions($pendingOrder));

        $pendingOrder['stripe_payment_intent_id']     = $pi->id;
        $pendingOrder['stripe_client_secret']          = $pi->client_secret;
        $pendingOrder['payment_status']                = Mercato::PAYMENT_STATUS_REQUIRES_CONFIRMATION;

        return [
            'pending_order' => $pendingOrder,
            'requires_client_confirmation' => true,
            // No redirect for card — frontend handles confirmation via Stripe.js
        ];
    }

    protected function getCardPaymentIntentParams(array $pendingOrder): array {
        $params = [
            'capture_method' => 'manual',
            'metadata'       => $this->getOrderMetadata($pendingOrder),
        ];

        if (!empty($this->commerce->stripe_automatic_payment_methods)) {
            $params['automatic_payment_methods'] = ['enabled' => true];
        } else {
            $params['payment_method_types'] = ['card'];
        }

        return $params;
    }

    protected function initializeKlarnaPayment(array $pendingOrder, float $sum): array {
        $email   = $pendingOrder['email'] ?? '';
        $country = strtoupper($pendingOrder['country'] ?? '');

        if (empty($country)) {
            throw new WireException(
                'Klarna payment requires a billing country. Add "country" to your checkout form.'
            );
        }

        $successUrl = $this->getSuccessUrl();

        $pi = $this->createPaymentIntent($sum, [
            'payment_method_types' => ['klarna'],
            'confirm'              => true,
            'capture_method'       => 'automatic',
            'metadata'             => $this->getOrderMetadata($pendingOrder),
            'payment_method_data'  => [
                'type'    => 'klarna',
                'billing_details' => [
                    'email'   => $email,
                    'address' => ['country' => $country],
                ],
            ],
            'return_url' => $successUrl,
        ], $this->getPaymentIntentRequestOptions($pendingOrder));

        $redirect = $pi->next_action->redirect_to_url->url ?? $successUrl;

        $pendingOrder['stripe_payment_intent_id'] = $pi->id;
        $pendingOrder['payment_status']           = Mercato::PAYMENT_STATUS_PENDING;

        return [
            'pending_order' => $pendingOrder,
            'redirect'      => $redirect,
        ];
    }

    protected function initializeiDEALPayment(array $pendingOrder, float $sum): array {
        $successUrl = $this->getSuccessUrl();

        // iDEAL requires the customer to select their bank on Stripe's hosted page.
        // We create a PaymentIntent without confirm=true and let Stripe.js / Payment Element
        // handle the bank selection and redirect. The client_secret is returned for front-end use.
        // For a pure server-side redirect flow, use a Stripe Checkout Session instead.
        $pi = $this->createPaymentIntent($sum, [
            'payment_method_types' => ['ideal'],
            'capture_method'       => 'automatic',
            'metadata'             => $this->getOrderMetadata($pendingOrder),
        ], $this->getPaymentIntentRequestOptions($pendingOrder));

        $pendingOrder['stripe_payment_intent_id'] = $pi->id;
        $pendingOrder['stripe_client_secret']      = $pi->client_secret;
        $pendingOrder['payment_status']            = Mercato::PAYMENT_STATUS_REQUIRES_CONFIRMATION;

        return [
            'pending_order' => $pendingOrder,
            'requires_client_confirmation' => true,
            // No server-side redirect — frontend confirms via Stripe.js Payment Element
        ];
    }

    protected function initializeSepaPayment(array $pendingOrder, float $sum): array {
        $pi = $this->createPaymentIntent($sum, [
            'payment_method_types' => ['sepa_debit'],
            'capture_method'       => 'automatic',
            'metadata'             => $this->getOrderMetadata($pendingOrder),
        ], $this->getPaymentIntentRequestOptions($pendingOrder));

        $pendingOrder['stripe_payment_intent_id'] = $pi->id;
        $pendingOrder['stripe_client_secret']      = $pi->client_secret;
        $pendingOrder['payment_status']            = Mercato::PAYMENT_STATUS_REQUIRES_CONFIRMATION;

        return [
            'pending_order' => $pendingOrder,
            'requires_client_confirmation' => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Stripe API wrappers
    // -----------------------------------------------------------------------

    /**
     * Create a Stripe PaymentIntent.
     *
     * @param float $amount  Amount in major currency units (e.g. 12.99 EUR)
     * @param array $params  Extra params merged into PaymentIntent::create()
     * @param array $options Stripe request options
     */
    public function createPaymentIntent(float $amount, array $params = [], array $options = []): \Stripe\PaymentIntent {
        $this->configureStripe();

        $defaults = [
            'amount'   => $this->toStripeAmount($amount),
            'currency' => strtolower(MercatoCurrency::normalizeCode((string) $this->commerce->currency)),
        ];

        return \Stripe\PaymentIntent::create(
            array_merge($defaults, $params),
            $options
        );
    }

    /**
     * Retrieve an existing PaymentIntent.
     */
    public function retrievePaymentIntent(string $id): \Stripe\PaymentIntent {
        $this->configureStripe();
        return \Stripe\PaymentIntent::retrieve($id);
    }

    /**
     * Update metadata on a PaymentIntent (e.g. attach order ID after save).
     */
    public function updatePaymentIntentMetadata(string $piId, array $metadata): \Stripe\PaymentIntent {
        $this->configureStripe();
        return \Stripe\PaymentIntent::update($piId, ['metadata' => $metadata]);
    }

    public function createCustomerPortalUrl(string $customerId, string $returnUrl): string {
        $customerId = trim($customerId);
        $returnUrl = trim($returnUrl);
        if ($customerId === '' || $returnUrl === '') {
            return '';
        }
        $scheme = strtolower((string) parse_url($returnUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !filter_var($returnUrl, FILTER_VALIDATE_URL)) {
            return '';
        }

        $this->configureStripe();
        $session = \Stripe\BillingPortal\Session::create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return (string) ($session->url ?? '');
    }

    public function createSubscriptionCheckoutUrl(array $pendingOrder, array $lineItems): string {
        if ($lineItems === []) {
            throw new WireException('Stripe subscription checkout requires at least one line item.');
        }

        $this->configureStripe();
        $session = \Stripe\Checkout\Session::create([
            'mode' => 'subscription',
            'line_items' => array_values($lineItems),
            'customer_email' => (string) ($pendingOrder['email'] ?? ''),
            'client_reference_id' => (string) ($pendingOrder['mrc_order_page_id'] ?? ''),
            'success_url' => $this->getSuccessUrl(),
            'cancel_url' => $this->getCancelUrl(),
            'metadata' => $this->getOrderMetadata($pendingOrder),
            'subscription_data' => [
                'metadata' => $this->getOrderMetadata($pendingOrder),
            ],
        ], $this->getCheckoutSessionRequestOptions($pendingOrder));

        return (string) ($session->url ?? '');
    }

    public function getSubscriptionLineItems(MercatoProductList $cart): array {
        $lineItems = [];
        foreach ($cart->toArray() as $item) {
            $priceId = trim((string) ($item['stripe_price_id'] ?? ''));
            if ($priceId === '') {
                throw new WireException('Recurring products require a Stripe Price ID.');
            }
            $lineItems[] = [
                'price' => $priceId,
                'quantity' => max(1, (int) ceil((float) ($item['quantity'] ?? 1))),
            ];
        }
        return $lineItems;
    }

    public function refund(array $orderData, float $amount, string $reason): array {
        $paymentIntentId = trim((string) ($orderData['stripe_payment_intent_id'] ?? ''));
        if ($paymentIntentId === '') {
            throw new WireException('This order has no Stripe PaymentIntent ID.');
        }
        if ($amount <= 0) {
            throw new WireException('Refund amount must be greater than zero.');
        }

        $this->configureStripe();
        $refund = \Stripe\Refund::create([
            'payment_intent' => $paymentIntentId,
            'amount' => $this->toStripeAmount($amount),
            'reason' => 'requested_by_customer',
            'metadata' => [
                'mrc_order_id' => (string) ($orderData['mrc_order_page_id'] ?? ''),
                'mrc_invoice_number' => (string) ($orderData['mrc_invoice_number'] ?? ''),
                'mrc_reason' => substr($reason, 0, 450),
            ],
        ]);

        return [
            'gateway' => $this->getName(),
            'id' => (string) $refund->id,
            'status' => (string) ($refund->status ?? 'pending'),
            'amount' => $amount,
            'payload' => $refund->toArray(),
        ];
    }

    public function retrieveRefund(array $orderData, string $refundId): array {
        $refundId = trim($refundId);
        if ($refundId === '') {
            throw new WireException('Stripe refund ID is empty.');
        }

        $this->configureStripe();
        $refund = \Stripe\Refund::retrieve($refundId);

        return [
            'gateway' => $this->getName(),
            'id' => (string) $refund->id,
            'status' => (string) ($refund->status ?? 'pending'),
            'payload' => $refund->toArray(),
        ];
    }

    /**
     * Construct and verify a Stripe webhook event.
     *
     * @param string $payload    Raw request body
     * @param string $sigHeader  HTTP_STRIPE_SIGNATURE header value
     * @return \Stripe\Event
     * @throws \Stripe\Exception\SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event {
        $secret = $this->commerce->stripe_webhook_secret;
        if (empty($secret)) {
            throw new WireException('Stripe webhook signing secret is not configured.');
        }
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
    }

    /**
     * Return the Stripe publishable key for the current mode.
     * Used by frontend JS to initialise Stripe.js.
     */
    public function getPublishableKey(): string {
        return $this->commerce->production
            ? (string) $this->commerce->stripe_live_pk
            : (string) $this->commerce->stripe_test_pk;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Convert float amount to Stripe integer (cents/pence/öre).
     * For zero-decimal currencies (JPY, KRW) no multiplication is needed.
     */
    protected function toStripeAmount(float $amount): int {
        return MercatoCurrency::minorUnitAmount($amount, (string) $this->commerce->currency);
    }

    protected function getSuccessUrl(): string {
        $successPath = $this->commerce->success_page ?: 'checkout/success';
        $page        = wire('pages')->get('/' . ltrim($successPath, '/') . '/');
        return ($page && $page->id)
            ? $page->httpUrl()
            : $this->commerce->getHttpRoot() . '/' . ltrim($successPath, '/') . '/';
    }

    protected function getCancelUrl(): string {
        $cancelPath = $this->commerce->cancel_page ?: 'checkout';
        $page       = wire('pages')->get('/' . ltrim($cancelPath, '/') . '/');
        return ($page && $page->id)
            ? $page->httpUrl()
            : $this->commerce->getHttpRoot() . '/' . ltrim($cancelPath, '/') . '/';
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

    protected function getPaymentIntentRequestOptions(array $pendingOrder): array {
        $key = trim((string) ($pendingOrder['payment_attempt_idempotency_key'] ?? ''));
        return $key !== '' ? ['idempotency_key' => $key] : [];
    }

    protected function getCheckoutSessionRequestOptions(array $pendingOrder): array {
        $key = trim((string) ($pendingOrder['payment_attempt_idempotency_key'] ?? ''));
        return $key !== '' ? ['idempotency_key' => $key . ':subscription'] : [];
    }

    protected function assertPaymentIntentMatchesOrder(\Stripe\PaymentIntent $pi, array $pendingOrder): void {
        $expectedAmount = $this->toStripeAmount((float) ($pendingOrder['mrc_total_amount'] ?? 0));
        if ($expectedAmount <= 0) {
            throw new WireException('Cannot verify Stripe payment: pending order total is missing.');
        }

        if ((int) $pi->amount !== $expectedAmount) {
            throw new WireException('Stripe payment amount does not match the pending order.');
        }

        $expectedCurrency = strtolower((string) ($pendingOrder['mrc_currency'] ?? $this->commerce->currency));
        if (strtolower((string) $pi->currency) !== $expectedCurrency) {
            throw new WireException('Stripe payment currency does not match the pending order.');
        }

        $expectedOrderId = (string) ($pendingOrder['mrc_order_page_id'] ?? '');
        $actualOrderId = (string) ($pi->metadata->mrc_order_id ?? '');
        if ($expectedOrderId !== '' && $actualOrderId !== '' && $expectedOrderId !== $actualOrderId) {
            throw new WireException('Stripe payment order metadata does not match the pending order.');
        }
    }

    protected function configureStripe(): void {
        if (!class_exists('\Stripe\Stripe')) {
            throw new WireException(
                'Stripe PHP library not found. Run: composer require stripe/stripe-php in the Mercato module directory.'
            );
        }

        $key = $this->commerce->production
            ? $this->commerce->stripe_live_sk
            : $this->commerce->stripe_test_sk;

        if (empty($key)) {
            throw new WireException('Stripe secret key is not configured in Mercato module settings.');
        }

        \Stripe\Stripe::setApiKey($key);
        \Stripe\Stripe::setAppInfo('Mercato', '1.0.0');
    }
}
