<?php
require_once __DIR__ . '/../src/Payment/MercatoPaymentStatus.php';
require_once __DIR__ . '/../src/Payment/MercatoPaymentStatusMapper.php';
require_once __DIR__ . '/../src/Payment/MercatoPaymentAttempt.php';
require_once __DIR__ . '/../src/Gateway/MercatoGatewayCapabilities.php';
require_once __DIR__ . '/../src/Gateway/MercatoGatewaySetupStatus.php';
require_once __DIR__ . '/../src/Fulfilment/MercatoFulfilmentMethodType.php';
require_once __DIR__ . '/../src/Order/MercatoFulfilmentStatus.php';
require_once __DIR__ . '/../src/Webhook/MercatoWebhookEvent.php';

use ProcessWire\MercatoFulfilmentMethodType;
use ProcessWire\MercatoFulfilmentStatus;
use ProcessWire\MercatoGatewayCapabilities;
use ProcessWire\MercatoGatewaySetupStatus;
use ProcessWire\MercatoPaymentAttempt;
use ProcessWire\MercatoPaymentStatus;
use ProcessWire\MercatoPaymentStatusMapper;
use ProcessWire\MercatoWebhookEvent;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$mapperCases = [
    [MercatoPaymentStatusMapper::generic(' Completed '), MercatoPaymentStatus::PAID],
    [MercatoPaymentStatusMapper::stripePaymentIntent('requires_action'), MercatoPaymentStatus::REQUIRES_ACTION],
    [MercatoPaymentStatusMapper::stripeWebhookEvent('PAYMENT_INTENT.CANCELED'), MercatoPaymentStatus::CANCELED],
    [MercatoPaymentStatusMapper::molliePayment('pending'), MercatoPaymentStatus::PROCESSING],
    [MercatoPaymentStatusMapper::payPalOrder('PAYER_ACTION_REQUIRED'), MercatoPaymentStatus::REQUIRES_ACTION],
    [MercatoPaymentStatusMapper::payPalWebhookEvent('PAYMENT.CAPTURE.REFUNDED'), MercatoPaymentStatus::REFUNDED],
    [MercatoPaymentStatusMapper::stripeWebhookEvent('unsupported'), ''],
];
foreach ($mapperCases as [$actual, $expected]) {
    $expect($actual === $expected, "Payment status mapper returned $actual instead of $expected.");
}

$capabilities = new MercatoGatewayCapabilities(
    name: 'fixture',
    label: 'Fixture gateway',
    paymentMethods: ['card' => 'Card', 'wallet' => 'Wallet'],
    supportsRedirect: true,
    supportsWebhooks: true,
    supportsRefunds: true,
);
$expect($capabilities->supportsMethod('card'), 'Gateway capability lookup lost a declared method.');
$expect(!$capabilities->supportsMethod('Card'), 'Gateway capability lookup must remain exact.');
$expect($capabilities->toArray()['supports_refunds'] === true, 'Gateway capability serialization lost refund support.');

$setup = new MercatoGatewaySetupStatus('fixture', false, ['Missing key'], ['Sandbox only'], ['mode' => 'test']);
$expect($setup->toArray() === [
    'gateway' => 'fixture',
    'ready' => false,
    'errors' => ['Missing key'],
    'warnings' => ['Sandbox only'],
    'details' => ['mode' => 'test'],
], 'Gateway setup status serialization changed shape.');

$attempt = MercatoPaymentAttempt::fromArray([
    'id' => 'attempt-1',
    'orderPageId' => 42,
    'gateway' => 'fixture',
    'method' => 'card',
    'amount' => '19.95',
    'currency' => 'usd',
    'externalId' => 'provider-1',
    'idempotencyKey' => 'checkout-1',
    'payload' => ['safe' => true],
]);
$expect($attempt->currency === 'USD' && $attempt->orderPageId === 42, 'Payment attempt aliases or currency normalization failed.');
$expect($attempt->toArray()['payload'] === ['safe' => true], 'Payment attempt serialization lost its payload.');

$event = MercatoWebhookEvent::fromArray([
    'gateway' => ' Mollie ',
    'eventType' => 'payment.status',
    'status' => 'processed',
    'externalPaymentId' => 'PAY-123',
    'context' => ['status' => 'paid'],
]);
$expect($event->idempotencyKey() === 'mollie:fallback:payment.status:pay-123:0:paid', 'Webhook fallback idempotency key changed unexpectedly.');
$eventWithProviderId = new MercatoWebhookEvent('Stripe', 'payment_intent.succeeded', 'processed', 'EVT-123');
$expect($eventWithProviderId->idempotencyKey() === 'stripe:event:evt-123', 'Webhook provider event id must take precedence in the idempotency key.');
$expect((new MercatoWebhookEvent('', 'payment.status', 'processed'))->idempotencyKey() === '', 'Incomplete webhook identity must not create a key.');

foreach (MercatoFulfilmentMethodType::all() as $method) {
    $expect(MercatoFulfilmentMethodType::isValid($method), "Declared fulfilment method $method is not valid.");
}
foreach (MercatoFulfilmentStatus::all() as $status) {
    $expect(MercatoFulfilmentStatus::isValid($status), "Declared fulfilment status $status is not valid.");
}
$expect(!MercatoFulfilmentMethodType::isValid('drone'), 'Unknown fulfilment method was accepted.');
$expect(!MercatoFulfilmentStatus::isValid('unknown'), 'Unknown fulfilment status was accepted.');

echo "Mercato core contract tests passed.\n";
