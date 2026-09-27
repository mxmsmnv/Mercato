<?php
namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') {
    echo "Mercato provider-emulator integration test skipped (set MERCATO_TEST_SITE).\n";
    exit(0);
}

$_SERVER['HTTP_HOST'] = 'mercato.test';
$_SERVER['SERVER_NAME'] = 'mercato.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';

require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser'));
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');

final class MercatoMollieProviderEmulator extends MollieGateway {
    public array $requests = [];
    public array $responses = [];

    protected function request(string $method, string $path, ?array $payload = null): array {
        $this->requests[] = compact('method', 'path', 'payload');
        if ($this->responses === []) throw new \RuntimeException('Unexpected Mollie network boundary call.');
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) throw $response;
        if (!is_array($response)) throw new \RuntimeException('Mollie emulator fixture must be an array.');
        return $response;
    }
}

final class MercatoPayPalProviderEmulator extends PayPalGateway {
    public array $requests = [];
    public array $responses = [];

    protected function request(string $method, string $path, mixed $payload = null, string $requestId = ''): array {
        $this->requests[] = compact('method', 'path', 'payload', 'requestId');
        if ($this->responses === []) throw new \RuntimeException('Unexpected PayPal network boundary call.');
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) throw $response;
        if (!is_array($response)) throw new \RuntimeException('PayPal emulator fixture must be an array.');
        return $response;
    }
}

final class MercatoStripeProviderEmulator extends StripeGateway {
    public array $created = [];
    public array $createResponses = [];
    public array $retrieveResponses = [];

    public function createPaymentIntent(float $amount, array $params = [], array $options = []): \Stripe\PaymentIntent {
        $this->created[] = compact('amount', 'params', 'options');
        if ($this->createResponses === []) throw new \RuntimeException('Unexpected Stripe create boundary call.');
        return array_shift($this->createResponses);
    }

    public function retrievePaymentIntent(string $id): \Stripe\PaymentIntent {
        if ($this->retrieveResponses === []) throw new \RuntimeException('Unexpected Stripe retrieve boundary call.');
        $response = array_shift($this->retrieveResponses);
        if ((string) $response->id !== $id) throw new \RuntimeException('Stripe emulator fixture ID does not match the request.');
        return $response;
    }
}

final class MercatoWebhookProviderProbe extends MercatoWebhookService {
    public function paypalStatus(string $eventType): string {
        return $this->mapPayPalWebhookStatus($eventType);
    }

    public function paypalContext(array $event): array {
        return $this->getPayPalEventContext($event);
    }

    public function stripeContext(\Stripe\Event $event): array {
        return $this->getStripeEventContext($event);
    }

    public function stripeRefundId(string $eventType, ?object $object): string {
        return $this->getStripeEventRefundId($eventType, $object);
    }

    public function applyStripe(\Stripe\Event $event): bool {
        return $this->applyStripeEvent($event);
    }
}

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new \RuntimeException($message);
};
$expectThrows = static function (callable $callable, string $message, ?string $contains = null) use (&$checks): \Throwable {
    $checks++;
    try {
        $callable();
    } catch (\Throwable $exception) {
        if ($contains !== null && !str_contains($exception->getMessage(), $contains)) {
            throw new \RuntimeException($message . ' Unexpected message: ' . $exception->getMessage(), 0, $exception);
        }
        return $exception;
    }
    throw new \RuntimeException($message);
};

$original = [
    'currency' => $commerce->currency,
    'stripe_webhook_secret' => $commerce->stripe_webhook_secret,
    'stripe_automatic_payment_methods' => $commerce->stripe_automatic_payment_methods,
    'paypal_test_webhook_id' => $commerce->paypal_test_webhook_id,
    'production' => $commerce->production,
];
register_shutdown_function(static function () use ($commerce, $original): void {
    foreach ($original as $name => $value) $commerce->set($name, $value);
});

$commerce->set('production', false);
$commerce->set('currency', 'USD');
$commerce->set('stripe_automatic_payment_methods', false);
$commerce->set('paypal_test_webhook_id', 'WH-LOCAL-EMULATOR');
$commerce->set('stripe_webhook_secret', 'whsec_local_emulator_not_a_credential');

$pending = [
    'mrc_order_page_id' => 424242,
    'mrc_invoice_number' => 'EMU-424242',
    'mrc_total_amount' => 12.34,
    'mrc_currency' => 'USD',
    'payment_method' => StripeGateway::METHOD_CARD,
    'payment_attempt_idempotency_key' => 'emu-attempt-1',
    'email' => 'provider-emulator@example.test',
    'mrc_items' => [['title' => 'Provider emulator item', 'quantity' => 1, 'price' => 12.34]],
];

$stripeIntent = static function (string $id, string $status, array $extra = []): \Stripe\PaymentIntent {
    return \Stripe\PaymentIntent::constructFrom(array_merge([
        'id' => $id,
        'object' => 'payment_intent',
        'status' => $status,
        'amount' => 1234,
        'amount_received' => $status === 'succeeded' ? 1234 : 0,
        'currency' => 'usd',
        'client_secret' => $id . '_secret_local',
        'latest_charge' => null,
        'metadata' => ['mrc_order_id' => '424242'],
    ], $extra));
};

// Stripe: local SDK objects, request projection, completion states, reconciliation, and signatures.
$stripe = new MercatoStripeProviderEmulator($commerce);
$stripe->createResponses[] = $stripeIntent('pi_emu_create', 'requires_confirmation');
$initialized = $stripe->initializePayment($pending, $commerce->cart());
$expect(($initialized['pending_order']['stripe_payment_intent_id'] ?? '') === 'pi_emu_create', 'Stripe create response ID was not projected into pending order data.');
$expect(($stripe->created[0]['amount'] ?? 0) === 12.34, 'Stripe major-unit amount changed at the request boundary.');
$expect(($stripe->created[0]['params']['capture_method'] ?? '') === 'manual', 'Stripe card capture mode changed.');
$expect(($stripe->created[0]['params']['metadata']['mrc_order_id'] ?? '') === '424242', 'Stripe order metadata was not projected.');
$expect(($stripe->created[0]['options']['idempotency_key'] ?? '') === 'emu-attempt-1', 'Stripe idempotency key was not forwarded.');

foreach ([
    'requires_payment_method' => [MercatoPaymentStatus::FAILED, 0],
    'processing' => [MercatoPaymentStatus::PROCESSING, 0],
    'requires_action' => [MercatoPaymentStatus::REQUIRES_ACTION, 0],
    'succeeded' => [MercatoPaymentStatus::PAID, 1],
    'canceled' => [MercatoPaymentStatus::CANCELED, 0],
] as $external => [$canonical, $complete]) {
    $id = 'pi_emu_' . $external;
    $stripe->retrieveResponses[] = $stripeIntent($id, $external);
    $result = $stripe->completePayment(array_merge($pending, ['stripe_payment_intent_id' => $id]), []);
    $expect(($result['payment_status'] ?? '') === $canonical, "Stripe $external was not mapped to $canonical.");
    $expect((int) ($result['payment_complete'] ?? -1) === $complete, "Stripe $external produced the wrong completion flag.");
}

$stripePage = new Page();
$stripePage->setWire($wire);
$stripePage->set('mrc_stripe_payment_intent_id', 'pi_emu_reconcile');
$stripe->retrieveResponses[] = $stripeIntent('pi_emu_reconcile', 'succeeded');
$stripeState = $stripe->retrievePaymentState($stripePage);
$expect($stripeState === ['status' => MercatoPaymentStatus::PAID, 'amount' => 12.34, 'refunded_amount' => 0.0, 'currency' => 'USD', 'reference' => 'pi_emu_reconcile'], 'Stripe reconciliation payload mapping changed.');

$stripePayload = json_encode([
    'id' => 'evt_emu_success',
    'object' => 'event',
    'type' => 'payment_intent.succeeded',
    'data' => ['object' => $stripeIntent('pi_emu_webhook', 'succeeded')->toArray()],
], JSON_UNESCAPED_SLASHES);
$timestamp = time();
$signature = hash_hmac('sha256', $timestamp . '.' . $stripePayload, (string) $commerce->stripe_webhook_secret);
$verifiedEvent = $stripe->constructWebhookEvent($stripePayload, 't=' . $timestamp . ',v1=' . $signature);
$expect((string) $verifiedEvent->id === 'evt_emu_success' && (string) $verifiedEvent->type === 'payment_intent.succeeded', 'Locally signed Stripe event was not verified by the SDK.');
$expectThrows(fn() => $stripe->constructWebhookEvent($stripePayload, 't=' . $timestamp . ',v1=' . str_repeat('0', 64)), 'Stripe accepted an invalid local signature.', 'signature');
$malformedPayload = '{"id":';
$malformedSignature = hash_hmac('sha256', $timestamp . '.' . $malformedPayload, (string) $commerce->stripe_webhook_secret);
$expectThrows(fn() => $stripe->constructWebhookEvent($malformedPayload, 't=' . $timestamp . ',v1=' . $malformedSignature), 'Stripe accepted signed malformed JSON.');

$probe = new MercatoWebhookProviderProbe($commerce);
$probe->setWire($wire);
$unknownPayload = json_encode(['id' => 'evt_emu_unknown', 'object' => 'event', 'type' => 'provider.future.event', 'data' => ['object' => ['id' => 'future_1', 'object' => 'payment_intent']]], JSON_UNESCAPED_SLASHES);
$unknownSignature = hash_hmac('sha256', $timestamp . '.' . $unknownPayload, (string) $commerce->stripe_webhook_secret);
$unknownEvent = $stripe->constructWebhookEvent($unknownPayload, 't=' . $timestamp . ',v1=' . $unknownSignature);
$expect($probe->applyStripe($unknownEvent) === false, 'Unknown signed Stripe event was treated as an applied transition.');

$refundEvent = \Stripe\Event::constructFrom([
    'id' => 'evt_emu_refund', 'object' => 'event', 'type' => 'refund.updated',
    'data' => ['object' => ['id' => 're_emu_1', 'object' => 'refund', 'payment_intent' => 'pi_emu_success']],
]);
$refundContext = $probe->stripeContext($refundEvent);
$expect(($refundContext['external_payment_id'] ?? '') === 'pi_emu_success' && ($refundContext['external_refund_id'] ?? '') === 're_emu_1', 'Stripe refund webhook identifiers were not projected for reconciliation.');
$expect($probe->stripeRefundId('charge.refunded', (object) ['latest_refund' => 're_latest']) === 're_latest', 'Stripe charge refund ID mapping changed.');

// Mollie: request/response fixtures exercise initialization, provider states, refunds, and reconciliation.
$mollie = new MercatoMollieProviderEmulator($commerce);
$mollie->responses[] = ['id' => 'tr_emu_create', 'status' => 'open', 'amount' => ['value' => '12.34', 'currency' => 'USD'], 'metadata' => ['mrc_order_id' => '424242'], '_links' => ['checkout' => ['href' => 'https://checkout.example.test/emu']]];
$mollieInitialized = $mollie->initializePayment($pending, $commerce->cart());
$expect(($mollieInitialized['redirect'] ?? '') === 'https://checkout.example.test/emu', 'Mollie approval URL was not returned.');
$expect(($mollie->requests[0]['method'] ?? '') === 'POST' && ($mollie->requests[0]['path'] ?? '') === '/payments', 'Mollie create request method/path changed.');
$expect(($mollie->requests[0]['payload']['amount'] ?? []) === ['currency' => 'USD', 'value' => '12.34'], 'Mollie create amount projection changed.');
$expect(($mollie->requests[0]['payload']['metadata']['mrc_order_id'] ?? '') === '424242', 'Mollie order metadata was not forwarded.');

foreach ([
    'paid' => [MercatoPaymentStatus::PAID, 1],
    'pending' => [MercatoPaymentStatus::PROCESSING, 0],
    'open' => [MercatoPaymentStatus::PENDING, 0],
    'failed' => [MercatoPaymentStatus::FAILED, 0],
    'expired' => [MercatoPaymentStatus::EXPIRED, 0],
] as $external => [$canonical, $complete]) {
    $id = 'tr_emu_' . $external;
    $mollie->responses[] = ['id' => $id, 'status' => $external, 'amount' => ['value' => '12.34', 'currency' => 'USD'], 'metadata' => ['mrc_order_id' => '424242']];
    $result = $mollie->completePayment(array_merge($pending, ['mollie_payment_id' => $id]), []);
    $expect(($result['payment_status'] ?? '') === $canonical, "Mollie $external was not mapped to $canonical.");
    $expect((int) ($result['payment_complete'] ?? -1) === $complete, "Mollie $external produced the wrong completion flag.");
}

$mollie->responses[] = ['id' => 're_emu_mollie', 'status' => 'pending'];
$mollieRefund = $mollie->refund(array_merge($pending, ['mollie_payment_id' => 'tr_emu_paid']), 3.21, str_repeat('R', 300));
$mollieRefundRequest = $mollie->requests[array_key_last($mollie->requests)];
$expect(($mollieRefund['id'] ?? '') === 're_emu_mollie' && ($mollieRefund['amount'] ?? 0) === 3.21, 'Mollie refund response mapping changed.');
$expect(($mollieRefundRequest['path'] ?? '') === '/payments/tr_emu_paid/refunds' && ($mollieRefundRequest['payload']['amount']['value'] ?? '') === '3.21', 'Mollie refund request path/amount changed.');
$expect(strlen((string) ($mollieRefundRequest['payload']['description'] ?? '')) === 255, 'Mollie refund reason was not provider-bounded.');

$molliePage = new Page();
$molliePage->setWire($wire);
$molliePage->set('mrc_mollie_payment_id', 'tr_emu_reconcile');
$mollie->responses[] = ['id' => 'tr_emu_reconcile', 'status' => 'paid', 'amount' => ['value' => '12.34', 'currency' => 'USD'], 'amountRefunded' => ['value' => '3.21']];
$mollieState = $mollie->retrievePaymentState($molliePage);
$expect($mollieState === ['status' => MercatoPaymentStatus::PAID, 'amount' => 12.34, 'refunded_amount' => 3.21, 'currency' => 'USD', 'reference' => 'tr_emu_reconcile'], 'Mollie reconciliation payload mapping changed.');

$mollieMalformed = new MercatoMollieProviderEmulator($commerce);
$mollieMalformed->responses[] = ['id' => 'tr_no_checkout'];
$expectThrows(fn() => $mollieMalformed->initializePayment($pending, $commerce->cart()), 'Mollie accepted a create response without checkout URL.', 'checkout URL');

// PayPal: request IDs, capture states, signature-verification contract, refunds, and reconciliation.
$paypal = new MercatoPayPalProviderEmulator($commerce);
$paypal->responses[] = ['id' => 'PAYPAL-EMU-CREATE', 'status' => 'CREATED', 'links' => [['rel' => 'approve', 'href' => 'https://paypal.example.test/approve']]];
$paypalInitialized = $paypal->initializePayment($pending, $commerce->cart());
$expect(($paypalInitialized['redirect'] ?? '') === 'https://paypal.example.test/approve', 'PayPal approval URL was not returned.');
$expect(($paypal->requests[0]['path'] ?? '') === '/v2/checkout/orders' && ($paypal->requests[0]['method'] ?? '') === 'POST', 'PayPal create request method/path changed.');
$expect(($paypal->requests[0]['payload']['purchase_units'][0]['amount'] ?? []) === ['currency_code' => 'USD', 'value' => '12.34'], 'PayPal create amount projection changed.');
$expect(($paypal->requests[0]['requestId'] ?? '') === 'emu-attempt-1_create', 'PayPal create idempotency key changed.');

foreach ([
    'COMPLETED' => [MercatoPaymentStatus::PAID, 1],
    'APPROVED' => [MercatoPaymentStatus::AUTHORIZED, 0],
    'PROCESSING' => [MercatoPaymentStatus::PROCESSING, 0],
    'DENIED' => [MercatoPaymentStatus::FAILED, 0],
    'VOIDED' => [MercatoPaymentStatus::CANCELED, 0],
] as $external => [$canonical, $complete]) {
    $id = 'PAYPAL-EMU-' . $external;
    $paypal->responses[] = [
        'id' => $id,
        'status' => $external,
        'purchase_units' => [['payments' => ['captures' => [['amount' => ['value' => '12.34', 'currency_code' => 'USD']]]]]],
    ];
    $result = $paypal->completePayment(array_merge($pending, ['paypal_order_id' => $id]), []);
    $expect(($result['payment_status'] ?? '') === $canonical, "PayPal $external was not mapped to $canonical.");
    $expect((int) ($result['payment_complete'] ?? -1) === $complete, "PayPal $external produced the wrong completion flag.");
}

$duplicatePending = array_merge($pending, ['paypal_order_id' => 'PAYPAL-EMU-DUPLICATE']);
for ($index = 0; $index < 2; $index++) {
    $paypal->responses[] = ['id' => 'PAYPAL-EMU-DUPLICATE', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['amount' => ['value' => '12.34', 'currency_code' => 'USD']]]]]]];
    $paypal->completePayment($duplicatePending, []);
}
$lastTwo = array_slice($paypal->requests, -2);
$expect(($lastTwo[0]['requestId'] ?? '') === 'emu-attempt-1_capture' && ($lastTwo[1]['requestId'] ?? '') === 'emu-attempt-1_capture', 'Duplicate PayPal capture did not retain the same provider idempotency key.');

$headers = ['transmission_id' => 'local-transmission', 'transmission_time' => gmdate('c'), 'cert_url' => 'https://paypal.example.test/cert', 'auth_algo' => 'SHA256withRSA', 'transmission_sig' => 'local-signature'];
$event = ['id' => 'WH-EVT-EMU', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAPTURE-EMU']];
$paypal->responses[] = ['verification_status' => 'SUCCESS'];
$expect($paypal->verifyWebhookSignature($event, $headers), 'PayPal emulator SUCCESS verification was rejected.');
$verifyRequest = $paypal->requests[array_key_last($paypal->requests)];
$expect(($verifyRequest['path'] ?? '') === '/v1/notifications/verify-webhook-signature' && ($verifyRequest['payload']['webhook_event']['id'] ?? '') === 'WH-EVT-EMU', 'PayPal webhook verification payload changed.');
$paypal->responses[] = ['verification_status' => 'FAILURE'];
$expect(!$paypal->verifyWebhookSignature($event, $headers), 'PayPal emulator FAILURE verification was accepted.');
$expectThrows(fn() => $paypal->verifyWebhookSignature($event, array_diff_key($headers, ['transmission_sig' => true])), 'PayPal accepted incomplete signature headers.', 'transmission_sig');

$paypalEvent = [
    'id' => 'WH-EMU-REFUND',
    'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
    'resource' => [
        'id' => 'REFUND-EMU',
        'status' => 'COMPLETED',
        'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPAL-EMU-PAID']],
        'purchase_units' => [['custom_id' => '424242', 'invoice_id' => 'EMU-424242']],
    ],
];
$paypalContext = $probe->paypalContext($paypalEvent);
$expect($probe->paypalStatus('PAYMENT.CAPTURE.REFUNDED') === MercatoPaymentStatus::REFUNDED, 'PayPal refund webhook status mapping changed.');
$expect(($paypalContext['paypal_order_id'] ?? '') === 'PAYPAL-EMU-PAID' && ($paypalContext['order_page_id'] ?? 0) === 424242 && ($paypalContext['invoice'] ?? '') === 'EMU-424242', 'PayPal webhook reconciliation identifiers were not projected.');
$expect($probe->paypalStatus('PROVIDER.FUTURE.EVENT') === '', 'Unknown PayPal event was treated as a payment transition.');

$paypalPage = new Page();
$paypalPage->setWire($wire);
$paypalPage->set('mrc_payment_details', json_encode(['id' => 'PAYPAL-EMU-RECONCILE']));
$paypal->responses[] = [
    'id' => 'PAYPAL-EMU-RECONCILE', 'status' => 'COMPLETED',
    'purchase_units' => [[
        'amount' => ['value' => '12.34', 'currency_code' => 'USD'],
        'payments' => ['refunds' => [['amount' => ['value' => '3.21']]]],
    ]],
];
$paypalState = $paypal->retrievePaymentState($paypalPage);
$expect($paypalState === ['status' => MercatoPaymentStatus::PAID, 'amount' => 12.34, 'refunded_amount' => 3.21, 'currency' => 'USD', 'reference' => 'PAYPAL-EMU-RECONCILE'], 'PayPal reconciliation payload mapping changed.');

$paypalMalformed = new MercatoPayPalProviderEmulator($commerce);
$paypalMalformed->responses[] = ['id' => 'PAYPAL-NO-APPROVAL', 'links' => []];
$expectThrows(fn() => $paypalMalformed->initializePayment($pending, $commerce->cart()), 'PayPal accepted a create response without approval URL.', 'approval URL');

foreach ([
    [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PROCESSING, true],
    [MercatoPaymentStatus::PAID, MercatoPaymentStatus::FAILED, true],
    [MercatoPaymentStatus::PAID, MercatoPaymentStatus::REFUNDED, false],
    [MercatoPaymentStatus::REFUNDED, MercatoPaymentStatus::PAID, true],
] as [$current, $incoming, $regresses]) {
    $expect(MercatoPaymentStatus::wouldRegressSettled($current, $incoming) === $regresses, "Out-of-order provider transition $current -> $incoming changed.");
}

$expect($mollie->responses === [] && $paypal->responses === [] && $stripe->createResponses === [] && $stripe->retrieveResponses === [], 'Provider emulator fixtures were not consumed exactly.');

echo "Mercato no-network provider emulator tests passed: $checks assertions.\n";
