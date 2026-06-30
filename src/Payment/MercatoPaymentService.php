<?php
namespace ProcessWire;

/**
 * Checkout/payment orchestration.
 *
 * Public Mercato::initializePayment() and Mercato::completePayment() delegate
 * here so the module facade can stay thin while the current API remains stable.
 */
class MercatoPaymentService extends Wire {

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
    }

    public function initializePayment(array $data): string {
        $session = $this->wire('session');
        $cart = $this->commerce->cart();
        $data = $this->commerce->normalizeOrderData($data);
        $checkoutNonce = trim((string) ($data['checkout_nonce'] ?? ''));
        if ($checkoutNonce !== '' && (string) $session->get('mrc_checkout_started_nonce') === $checkoutNonce) {
            $existingPending = $session->get('mrc_pending_order');
            if (is_array($existingPending) && !empty($existingPending['mrc_order_page_id'])) {
                return $this->normalizeCheckoutRedirect((string) $session->get('mrc_checkout_redirect'));
            }
        }

        if ($cart->count() === 0) {
            throw new WireException($this->commerce->_('Cart is empty.'), 400);
        }

        if (empty($data['payment_method'])) {
            throw new WireException($this->commerce->_('No payment method provided.'), 400);
        }

        $data = $this->commerce->sanitizeFormData($data);
        $hookData = $this->commerce->beforeCreateCheckout($data, $cart);
        if (is_array($hookData)) {
            $data = $this->commerce->sanitizeFormData($this->commerce->normalizeOrderData($hookData));
        }
        $policyPages = method_exists($this->commerce, 'getPolicyPages') ? $this->commerce->getPolicyPages() : new PageArray();
        if ($policyPages->count() > 0 && (int) ($data['mrc_policy_accepted'] ?? 0) !== 1) {
            throw new WireException($this->commerce->_('Store policies must be accepted before payment.'), 422);
        }
        if ($policyPages->count() > 0) {
            $policySnapshot = [];
            foreach ($policyPages as $policyPage) {
                $policySnapshot[] = [
                    'id' => (int) $policyPage->id,
                    'title' => (string) ($policyPage->title ?: $policyPage->name),
                    'path' => (string) $policyPage->path,
                    'url' => (string) $policyPage->httpUrl(),
                ];
            }
            $data['mrc_policy_accepted'] = 1;
            $data['mrc_policy_acceptance_details'] = json_encode([
                'accepted_at' => date('c'),
                'ip' => (string) $this->wire('session')->getIP(),
                'pages' => $policySnapshot,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $data['mrc_policy_accepted'] = 0;
            $data['mrc_policy_acceptance_details'] = '';
        }
        if ($cart->hasMixedPurchaseTypes()) {
            throw new WireException($this->commerce->_('Recurring products must be checked out separately from one-off products.'), 409);
        }
        $isRecurringCheckout = $cart->isRecurringOnly();
        if ($isRecurringCheckout && !str_starts_with(strtolower((string) ($data['payment_method'] ?? '')), 'stripe')) {
            throw new WireException($this->commerce->_('Recurring checkout currently requires Stripe Billing.'), 409);
        }
        $linkedOrderId = (int) ($data['mrc_order_page_id'] ?? 0);
        if (!$isRecurringCheckout) {
            $this->commerce->orderRepository()->assertStockAvailable($cart, $linkedOrderId);
        }
        $discount = $this->commerce->discountService()->resolveCartDiscount((string) ($data['discount_code'] ?? ''), $cart, (string) ($data['email'] ?? ''), !empty($data['discount_code']), [
            'source' => 'payment_initialize',
            'email' => (string) ($data['email'] ?? ''),
        ]);
        if (empty($discount['valid'])) {
            $discount = ['code' => '', 'amount' => 0.0, 'title' => '', 'type' => ''];
        }

        $fulfilment = $this->commerce->fulfilmentService()->resolveSelection(
            (string) ($data['fulfilment_method'] ?? ''),
            $cart,
            $data
        );
        $addresses = $this->commerce->buildAddressSnapshots($data, $fulfilment);
        $subtotal = $cart->getSubtotal();
        $shipping = (float) $fulfilment['amount'];
        $discountAmount = round((float) ($discount['amount'] ?? 0), 2);
        $total = round(max(0, $subtotal + $shipping - $discountAmount), 2);

        $data['mrc_items'] = json_encode($cart->toArray());
        $data['mrc_currency'] = MercatoCurrency::normalizeCode((string) $this->commerce->currency);
        $data['mrc_subtotal_amount'] = $subtotal;
        $data['mrc_shipping_amount'] = $shipping;
        $data['fulfilment_method'] = $fulfilment['type'];
        $data['mrc_fulfilment_label'] = $fulfilment['label'];
        $data['mrc_fulfilment_details'] = json_encode($fulfilment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data['mrc_billing_address'] = json_encode($addresses['billing'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data['mrc_shipping_address'] = json_encode($addresses['shipping'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data['mrc_receipt_details'] = json_encode($this->commerce->buildReceiptDetailsSnapshot($cart, $shipping), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data = $this->applyDiscountSnapshot($data, $discount, $discountAmount);
        $data['mrc_total_amount'] = $total;
        $data['payment_status'] = Mercato::PAYMENT_STATUS_PENDING;
        $data['payment_complete'] = 0;

        if (empty(trim((string) $data['mrc_currency']))) {
            throw new WireException($this->commerce->_('Currency is not configured. Set it in the Mercato module settings.'));
        }

        $errors = array_merge(
            $this->commerce->validateOrderData($data),
            $this->validateCheckoutData($data, $cart, $fulfilment, $subtotal, $shipping, $discountAmount, $total)
        );
        if (count($errors) > 0) {
            throw new WireException(implode(' ', $errors), 422);
        }

        $gateway = $this->commerce->getGateway($data['payment_method']);
        $this->assertGatewayReadyForCheckout($gateway);
        $redirect = $this->getSuccessRedirectUrl();

        $pendingOrder = $data;
        $pendingPage = $this->commerce->orderRepository()->savePendingOrder($pendingOrder);
        $this->commerce->recordEvent('mercato-events', [
            'event' => 'checkout_created',
            'order_id' => (int) $pendingPage->id,
            'invoice' => (string) ($pendingPage->mrc_invoice_number ?: $pendingPage->title),
            'gateway' => (string) ($data['payment_method'] ?? ''),
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'discount' => $discountAmount,
            'total' => $total,
            'currency' => (string) ($data['mrc_currency'] ?? $this->commerce->currency),
        ], 'checkout_created');
        $pendingOrder['mrc_order_page_id'] = $pendingPage->id;
        $pendingOrder['mrc_invoice_number'] = $pendingPage->mrc_invoice_number;
        $attempt = $this->createPaymentAttempt($pendingOrder, $pendingPage);
        $pendingOrder['payment_attempt_id'] = $attempt->id;
        $pendingOrder['payment_attempt_idempotency_key'] = $attempt->idempotencyKey;
        $this->recordPaymentAttempt('created', $attempt, [
            'source' => !empty($data['mrc_order_page_id']) ? 'payment_link' : 'checkout',
        ]);
        if ($isRecurringCheckout) {
            return $this->initializeRecurringPayment($gateway, $cart, $data, $pendingOrder, $pendingPage, $checkoutNonce);
        }
        $this->commerce->orderRepository()->reserveStock($pendingPage, $this->commerce->getReservationTtlMinutes());
        if (!empty($data['mrc_discount_code'])) {
            $this->commerce->discountService()->recordAuditEvent('attached_to_order', $discount, [
                'source' => 'payment_initialize',
                'email' => (string) ($data['email'] ?? ''),
                'order_page_id' => (int) $pendingPage->id,
                'invoice' => (string) $pendingPage->mrc_invoice_number,
            ]);
        }

        try {
            $result = $gateway->initializePayment($pendingOrder, $cart);
        } catch (\Throwable $e) {
            $pendingOrder['payment_status'] = MercatoPaymentStatus::FAILED;
            $pendingOrder['payment_complete'] = 0;
            $pendingOrder['payment_details'] = json_encode([
                'gateway' => $this->gatewayKeyFromMethod((string) ($pendingOrder['payment_method'] ?? '')),
                'state' => 'initialization_failed',
                'error' => $e->getMessage(),
                'failed_at' => date(DATE_ATOM),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            try {
                $this->commerce->orderRepository()->savePendingOrder($pendingOrder);
                $this->commerce->orderRepository()->releaseStockReservation($pendingPage, 'payment_initialization_failed');
                $this->recordPaymentAttempt('failed', $this->attemptFromPending($pendingOrder, $pendingPage, MercatoPaymentStatus::FAILED), [
                    'source' => 'initialize_payment',
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $cleanupError) {
                $this->wire('log')->error('Mercato: failed to clean up payment initialization failure: ' . $cleanupError->getMessage());
            }
            $this->wire('session')->remove('mrc_pending_order');
            $this->wire('session')->remove('mrc_checkout_started_nonce');
            $this->wire('session')->remove('mrc_checkout_redirect');

            if ($e instanceof WireException) {
                throw $e;
            }
            throw new WireException($this->commerce->_('Payment gateway initialization failed. Please try again or choose another payment method.'), 502, $e);
        }
        if (!empty($result['redirect'])) {
            $redirect = $this->normalizeCheckoutRedirect((string) $result['redirect']);
        }
        if (!empty($result['requires_client_confirmation'])) {
            $redirect = '';
        }
        if (!empty($result['pending_order']) && is_array($result['pending_order'])) {
            $pendingOrder = $result['pending_order'];
        }

        $this->commerce->orderRepository()->savePendingOrder($pendingOrder);
        $this->recordPaymentAttempt('initialized', $this->attemptFromPending($pendingOrder, $pendingPage), [
            'redirect' => $redirect !== '',
            'requires_client_confirmation' => !empty($result['requires_client_confirmation']),
        ]);
        $this->wire('session')->set('mrc_pending_order', $pendingOrder);
        if ($checkoutNonce !== '') {
            $this->wire('session')->set('mrc_checkout_started_nonce', $checkoutNonce);
            $this->wire('session')->set('mrc_checkout_redirect', $redirect);
        }
        $this->commerce->paymentInitialized($data, $pendingOrder, $redirect);
        $this->commerce->afterCreateCheckout($pendingOrder, $pendingPage, $redirect);

        return $redirect;
    }

    protected function initializeRecurringPayment(MercatoGatewayInterface $gateway, MercatoProductList $cart, array $data, array $pendingOrder, Page $pendingPage, string $checkoutNonce): string {
        if (!$gateway instanceof StripeGateway) {
            throw new WireException($this->commerce->_('Recurring checkout currently requires Stripe Billing.'), 409);
        }

        try {
            $lineItems = $gateway->getSubscriptionLineItems($cart);
            $redirect = $this->normalizeCheckoutRedirect($gateway->createSubscriptionCheckoutUrl($pendingOrder, $lineItems));
            $pendingOrder['payment_status'] = Mercato::PAYMENT_STATUS_PENDING;
            $pendingOrder['payment_complete'] = 0;
            $pendingOrder['payment_details'] = json_encode([
                'gateway' => 'stripe',
                'state' => 'subscription_checkout_created',
                'line_items' => $lineItems,
                'created_at' => date(DATE_ATOM),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->commerce->orderRepository()->savePendingOrder($pendingOrder);
            $this->recordPaymentAttempt('initialized', $this->attemptFromPending($pendingOrder, $pendingPage), [
                'redirect' => true,
                'subscription_checkout' => true,
            ]);
            $this->wire('session')->set('mrc_pending_order', $pendingOrder);
            if ($checkoutNonce !== '') {
                $this->wire('session')->set('mrc_checkout_started_nonce', $checkoutNonce);
                $this->wire('session')->set('mrc_checkout_redirect', $redirect);
            }
            $this->commerce->paymentInitialized($data, $pendingOrder, $redirect);
            $this->commerce->afterCreateCheckout($pendingOrder, $pendingPage, $redirect);
            return $redirect;
        } catch (\Throwable $e) {
            $pendingOrder['payment_status'] = MercatoPaymentStatus::FAILED;
            $pendingOrder['payment_complete'] = 0;
            $pendingOrder['payment_details'] = json_encode([
                'gateway' => 'stripe',
                'state' => 'subscription_initialization_failed',
                'error' => $e->getMessage(),
                'failed_at' => date(DATE_ATOM),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->commerce->orderRepository()->savePendingOrder($pendingOrder);
            $this->recordPaymentAttempt('failed', $this->attemptFromPending($pendingOrder, $pendingPage, MercatoPaymentStatus::FAILED), [
                'source' => 'initialize_subscription_checkout',
                'error' => $e->getMessage(),
            ]);
            $this->wire('session')->remove('mrc_pending_order');
            $this->wire('session')->remove('mrc_checkout_started_nonce');
            $this->wire('session')->remove('mrc_checkout_redirect');

            if ($e instanceof WireException) {
                throw $e;
            }
            throw new WireException($this->commerce->_('Subscription checkout initialization failed. Please try again.'), 502, $e);
        }
    }

    protected function normalizeCheckoutRedirect(string $redirect): string {
        $redirect = trim($redirect);
        if ($redirect === '') {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $redirect)) {
            throw new WireException($this->commerce->_('Payment gateway returned an invalid redirect URL.'), 502);
        }

        $parts = parse_url($redirect);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new WireException($this->commerce->_('Payment gateway returned an invalid redirect URL.'), 502);
        }

        return $redirect;
    }

    protected function validateCheckoutData(
        array $data,
        MercatoProductList $cart,
        array $fulfilment,
        float $subtotal,
        float $shipping,
        float $discountAmount,
        float $total
    ): array {
        $errors = [];

        if ($cart->count() === 0) {
            $errors[] = $this->commerce->_('Cart is empty.');
        }

        $currency = MercatoCurrency::normalizeCode((string) ($data['mrc_currency'] ?? ''));
        if (!MercatoCurrency::isIsoCode($currency)) {
            $errors[] = $this->commerce->_('Currency must be a three-letter ISO 4217 code.');
        }

        foreach (['first_name', 'last_name'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[] = sprintf($this->commerce->_('Field "%s" is required.'), $field);
            }
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->commerce->_('A valid customer email is required.');
        }

        $enabledMethods = $this->commerce->getEnabledPaymentMethods();
        $paymentMethod = (string) ($data['payment_method'] ?? '');
        if ($paymentMethod === '' || !in_array($paymentMethod, $enabledMethods, true)) {
            $errors[] = $this->commerce->_('Choose an enabled payment method.');
        } else {
            try {
                $gateway = $this->commerce->getGateway($paymentMethod);
                if (method_exists($gateway, 'getCapabilities')) {
                    $capabilities = $gateway->getCapabilities();
                    if (method_exists($capabilities, 'supportsMethod') && !$capabilities->supportsMethod($paymentMethod)) {
                        $errors[] = sprintf($this->commerce->_('Payment gateway does not support "%s".'), $paymentMethod);
                    }
                }
            } catch (WireException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $fulfilmentType = (string) ($fulfilment['type'] ?? '');
        if ($fulfilmentType === '' || !in_array($fulfilmentType, $this->commerce->getEnabledFulfilmentMethods(), true)) {
            $errors[] = $this->commerce->_('Choose an enabled fulfilment method.');
        }
        if (isset($fulfilment['available']) && !$fulfilment['available']) {
            $errors[] = $this->commerce->_('The selected fulfilment method is not available for this cart.');
        }
        if ($fulfilmentType === MercatoFulfilmentMethodType::STORE_PICKUP && method_exists($this->commerce, 'getStorePickupLocations')) {
            $pickupLocations = $this->commerce->getStorePickupLocations();
            if (count($pickupLocations) > 1 && empty($pickupLocations[trim((string) ($data['pickup_location'] ?? ''))] ?? null)) {
                $errors[] = $this->commerce->_('Choose an available pickup location.');
            }
        }
        if (in_array($fulfilmentType, [MercatoFulfilmentMethodType::CARRIER_DELIVERY, MercatoFulfilmentMethodType::LOCAL_DELIVERY], true)) {
            foreach (['address', 'city', 'zip', 'country'] as $field) {
                if (trim((string) ($data[$field] ?? '')) === '') {
                    $errors[] = $this->commerce->_('Delivery address and postal code are required for this fulfilment method.');
                    break;
                }
            }
            $country = strtoupper(trim((string) ($data['country'] ?? '')));
            if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
                $errors[] = $this->commerce->_('Delivery country must be a two-letter ISO code.');
            }
            $regions = method_exists($this->commerce, 'getDeliveryRegionsForCountry') ? $this->commerce->getDeliveryRegionsForCountry($country) : [];
            if ($regions) {
                $region = strtoupper(trim((string) ($data['region'] ?? '')));
                if ($region === '' || !array_key_exists($region, $regions)) {
                    $errors[] = $this->commerce->_('Choose a valid delivery region for the selected country.');
                }
            }
            $windows = method_exists($this->commerce, 'getDeliveryWindowOptions') ? $this->commerce->getDeliveryWindowOptions() : [];
            if ($windows) {
                $window = trim((string) ($data['delivery_window'] ?? ''));
                if ($window === '' || !array_key_exists($window, $windows)) {
                    $errors[] = $this->commerce->_('Choose an available delivery window.');
                }
            }
        }

        foreach (['subtotal' => $subtotal, 'shipping' => $shipping, 'discount' => $discountAmount, 'total' => $total] as $label => $amount) {
            if (!is_finite($amount) || $amount < 0) {
                $errors[] = sprintf($this->commerce->_('Checkout %s amount is invalid.'), $label);
            }
        }
        $expectedTotal = round(max(0, $subtotal + $shipping - $discountAmount), 2);
        if (round($total, 2) !== $expectedTotal) {
            $errors[] = $this->commerce->_('Checkout total could not be validated.');
        }

        foreach ($this->commerce->getTaxRatesForOrder($cart, $shipping) as $taxRate) {
            if (!is_array($taxRate) || !isset($taxRate['sum']) || !is_numeric($taxRate['sum'])) {
                $errors[] = $this->commerce->_('Tax calculation could not be validated.');
                break;
            }
        }

        return array_values(array_unique($errors));
    }

    protected function applyDiscountSnapshot(array $data, array $discount, float $discountAmount): array {
        $data['mrc_discount_code'] = (string) ($discount['code'] ?? '');
        $data['mrc_discount_total'] = round(max(0.0, $discountAmount), 2);
        $data['mrc_discount_details'] = json_encode($discount, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $data;
    }

    protected function assertGatewayReadyForCheckout(MercatoGatewayInterface $gateway): void {
        if (!method_exists($gateway, 'getSetupStatus')) {
            return;
        }

        $status = $gateway->getSetupStatus();
        $data = method_exists($status, 'toArray') ? $status->toArray() : [];
        $errors = array_values(array_filter(array_map('strval', (array) ($data['errors'] ?? []))));
        $warnings = array_values(array_filter(array_map('strval', (array) ($data['warnings'] ?? []))));
        if (!empty($this->commerce->production)) {
            $errors = array_merge($errors, $warnings);
        }
        if (!empty($data['ready']) && !$errors) {
            return;
        }

        $gatewayLabel = method_exists($gateway, 'getLabel') ? $gateway->getLabel() : ucfirst((string) ($data['gateway'] ?? 'gateway'));
        $message = $errors
            ? implode(' ', $errors)
            : $this->commerce->_('Gateway setup is incomplete.');
        $this->commerce->recordEvent('mercato-events', [
            'event' => 'gateway_setup_failed',
            'gateway' => (string) ($data['gateway'] ?? ''),
            'label' => $gatewayLabel,
            'errors' => $errors,
        ], 'gateway_setup_failed');
        throw new WireException(sprintf(
            $this->commerce->_('%s is not ready for checkout. %s'),
            $gatewayLabel,
            $message
        ), 422);
    }

    public function completePayment(array $data = []): Page {
        $session = $this->wire('session');
        $pending = $session->get('mrc_pending_order');

        if (!$pending || !is_array($pending)) {
            throw new WireException($this->commerce->_('No pending order found in session.'), 400);
        }

        $pending = $this->commerce->normalizeOrderData($pending);
        $gateway = $this->commerce->getGateway($pending['payment_method']);
        $previousOrderStatus = '';
        $orderId = (int) ($pending['mrc_order_page_id'] ?? $pending['order_page_id'] ?? 0);
        if ($orderId > 0) {
            $existingOrder = $this->commerce->getOrder($orderId);
            if ($existingOrder && $existingOrder->id) {
                $previousOrderStatus = $this->commerce->getDerivedOrderStatus($existingOrder);
            }
        }

        $result = $gateway->completePayment($pending, $data);
        if (is_array($result)) {
            $pending = $result;
        }

        try {
            $orderPage = $this->commerce->orderRepository()->savePendingOrder($pending);
        } catch (\Exception $e) {
            if (!empty($pending['payment_complete'])) {
                $session->remove('mrc_pending_order');
            }
            throw $e;
        }

        $this->attachStripeOrderMetadata($pending, $orderPage);
        $this->syncSessionAfterCompletion($pending);

        $status = (string) ($pending['payment_status'] ?? Mercato::PAYMENT_STATUS_PENDING);
        $this->recordPaymentAttempt($this->paymentAttemptEventForStatus($status), $this->attemptFromPending($pending, $orderPage, $status), [
            'source' => 'complete_payment',
        ]);
        if (in_array($status, [Mercato::PAYMENT_STATUS_PAID, Mercato::PAYMENT_STATUS_PROCESSING], true)) {
            if ($status === Mercato::PAYMENT_STATUS_PAID) {
                $this->commerce->orderRepository()->decrementStockOnce($orderPage);
                $this->commerce->paymentPaid($orderPage);
            }
            $this->commerce->paymentCompleted($orderPage, $status);
        } elseif ($status === MercatoPaymentStatus::AUTHORIZED) {
            $this->commerce->paymentAuthorized($orderPage, $status);
        } elseif (MercatoPaymentStatus::isFailureOutcome($status)) {
            $this->commerce->orderRepository()->releaseStockReservation($orderPage);
            $this->commerce->paymentFailed($orderPage, $status);
        }
        $this->commerce->emitOrderStatusChanged($orderPage, $previousOrderStatus, [
            'source' => 'completePayment',
            'payment_status' => $status,
            'payment_method' => (string) ($pending['payment_method'] ?? ''),
        ]);

        return $orderPage;
    }

    protected function createPaymentAttempt(array $pending, Page $orderPage): MercatoPaymentAttempt {
        $attemptId = 'pa_' . (int) $orderPage->id . '_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $pending['payment_attempt_id'] = $attemptId;
        $pending['payment_attempt_idempotency_key'] = 'mrc_' . (int) $orderPage->id . '_' . $attemptId;
        return $this->attemptFromPending($pending, $orderPage, MercatoPaymentStatus::CREATED);
    }

    protected function attemptFromPending(array $pending, Page $orderPage, string $status = ''): MercatoPaymentAttempt {
        $status = $status !== '' ? $status : (string) ($pending['payment_status'] ?? MercatoPaymentStatus::PENDING);
        return new MercatoPaymentAttempt(
            (string) ($pending['payment_attempt_id'] ?? ('pa_' . (int) $orderPage->id)),
            (int) $orderPage->id,
            $this->gatewayKeyFromMethod((string) ($pending['payment_method'] ?? '')),
            (string) ($pending['payment_method'] ?? ''),
            round((float) ($pending['mrc_total_amount'] ?? 0), 2),
            strtoupper((string) ($pending['mrc_currency'] ?? $this->commerce->currency)),
            $status,
            $this->externalIdFromPending($pending),
            (string) ($pending['payment_attempt_idempotency_key'] ?? ''),
            [
                'invoice' => (string) ($pending['mrc_invoice_number'] ?? ($orderPage->mrc_invoice_number ?: $orderPage->title)),
            ],
        );
    }

    protected function gatewayKeyFromMethod(string $method): string {
        $method = strtolower(trim($method));
        if (str_starts_with($method, 'stripe')) return 'stripe';
        if (str_starts_with($method, 'mollie')) return 'mollie';
        if ($method === 'demo') return 'demo';
        return $method !== '' ? $method : 'unknown';
    }

    protected function externalIdFromPending(array $pending): string {
        foreach (['stripe_payment_intent_id', 'mollie_payment_id', 'payment_id'] as $key) {
            if (!empty($pending[$key])) {
                return (string) $pending[$key];
            }
        }
        return '';
    }

    protected function paymentAttemptEventForStatus(string $status): string {
        return match ($status) {
            MercatoPaymentStatus::PAID => 'completed',
            MercatoPaymentStatus::PROCESSING => 'processing',
            MercatoPaymentStatus::FAILED, MercatoPaymentStatus::EXPIRED => 'failed',
            MercatoPaymentStatus::CANCELED => 'canceled',
            default => 'updated',
        };
    }

    protected function recordPaymentAttempt(string $event, MercatoPaymentAttempt $attempt, array $context = []): void {
        try {
            (new MercatoPaymentAttemptEventLog())->record($event, $attempt, $context);
        } catch (\Exception $e) {
            $this->wire('log')->error('Mercato: failed to record payment attempt: ' . $e->getMessage());
        }
    }

    protected function getSuccessRedirectUrl(): string {
        $successPage = $this->wire('pages')->get('/' . ltrim((string) $this->commerce->success_page, '/') . '/');
        return ($successPage && $successPage->id)
            ? $successPage->httpUrl()
            : $this->commerce->getHttpRoot() . '/' . ltrim((string) $this->commerce->success_page, '/') . '/';
    }

    protected function attachStripeOrderMetadata(array $pending, Page $orderPage): void {
        if (empty($pending['stripe_payment_intent_id'])) {
            return;
        }

        try {
            $stripeGateway = $this->commerce->getGateway('stripe');
            if (method_exists($stripeGateway, 'updatePaymentIntentMetadata')) {
                $stripeGateway->updatePaymentIntentMetadata(
                    $pending['stripe_payment_intent_id'],
                    ['mrc_order_id' => $orderPage->id]
                );
            }
        } catch (\Exception $e) {
            $this->wire('log')->error('Mercato: failed to update PI metadata: ' . $e->getMessage());
        }
    }

    protected function syncSessionAfterCompletion(array $pending): void {
        $session = $this->wire('session');
        $status = (string) ($pending['payment_status'] ?? Mercato::PAYMENT_STATUS_PENDING);

        if (in_array($status, [Mercato::PAYMENT_STATUS_PAID, Mercato::PAYMENT_STATUS_PROCESSING], true)) {
            $this->commerce->cart()->delete();
            $session->remove('mrc_pending_order');
            $session->remove('mrc_checkout_nonce');
            $session->remove('mrc_checkout_started_nonce');
            $session->remove('mrc_checkout_redirect');
            $session->remove('mrc_payment_link_order_id');
            $session->remove('mrc_payment_link_token');
            return;
        }

        if (MercatoPaymentStatus::isFailureOutcome($status)) {
            $session->remove('mrc_pending_order');
            $session->remove('mrc_checkout_started_nonce');
            $session->remove('mrc_checkout_redirect');
            $session->remove('mrc_payment_link_order_id');
            $session->remove('mrc_payment_link_token');
            return;
        }

        $session->set('mrc_pending_order', $pending);
    }

    public function clearPendingCheckoutSession(bool $releaseReservation = true): void {
        $session = $this->wire('session');
        $pending = $session->get('mrc_pending_order');
        if ($releaseReservation && is_array($pending) && !empty($pending['mrc_order_page_id'])) {
            $order = $this->wire('pages')->get((int) $pending['mrc_order_page_id']);
            if ($order && $order->id && $order->template->name === (string) $this->commerce->order_template) {
                $this->commerce->orderRepository()->releaseStockReservation($order, 'cart_changed');
            }
        }

        $session->remove('mrc_pending_order');
        $session->remove('mrc_checkout_nonce');
        $session->remove('mrc_checkout_started_nonce');
        $session->remove('mrc_checkout_redirect');
        $session->remove('mrc_payment_link_order_id');
        $session->remove('mrc_payment_link_token');
    }
}
