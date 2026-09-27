<?php
namespace ProcessWire;

require_once __DIR__ . '/../src/Payment/MercatoPaymentStatus.php';
require_once __DIR__ . '/../src/Payment/MercatoPaymentStatusMapper.php';
require_once __DIR__ . '/../src/Quote/MercatoQuoteStatus.php';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};

$quoteAllowed = [
    MercatoQuoteStatus::SUBMITTED => [MercatoQuoteStatus::SUBMITTED, MercatoQuoteStatus::UNDER_REVIEW, MercatoQuoteStatus::QUOTED, MercatoQuoteStatus::DECLINED, MercatoQuoteStatus::EXPIRED],
    MercatoQuoteStatus::UNDER_REVIEW => [MercatoQuoteStatus::UNDER_REVIEW, MercatoQuoteStatus::QUOTED, MercatoQuoteStatus::DECLINED, MercatoQuoteStatus::EXPIRED],
    MercatoQuoteStatus::QUOTED => [MercatoQuoteStatus::QUOTED, MercatoQuoteStatus::ACCEPTED, MercatoQuoteStatus::DECLINED, MercatoQuoteStatus::EXPIRED],
    MercatoQuoteStatus::ACCEPTED => [MercatoQuoteStatus::ACCEPTED, MercatoQuoteStatus::CONVERTED, MercatoQuoteStatus::EXPIRED],
    MercatoQuoteStatus::DECLINED => [MercatoQuoteStatus::DECLINED],
    MercatoQuoteStatus::EXPIRED => [MercatoQuoteStatus::EXPIRED],
    MercatoQuoteStatus::CONVERTED => [MercatoQuoteStatus::CONVERTED],
];
$quoteChecks = 0;
foreach (MercatoQuoteStatus::all() as $from) {
    foreach (MercatoQuoteStatus::all() as $to) {
        $expected = in_array($to, $quoteAllowed[$from], true);
        $expect(MercatoQuoteStatus::canTransition($from, $to) === $expected, "Quote transition $from -> $to does not match the lifecycle matrix.");
        $quoteChecks++;
    }
}
foreach ([['unknown', 'unknown'], ['unknown', MercatoQuoteStatus::SUBMITTED], [MercatoQuoteStatus::SUBMITTED, 'unknown']] as [$from, $to]) {
    $expect(!MercatoQuoteStatus::canTransition($from, $to), "Unknown quote transition $from -> $to was accepted.");
}

$settledAllowed = [
    MercatoPaymentStatus::PAID => [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUNDED],
    MercatoPaymentStatus::PARTIALLY_REFUNDED => [MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUNDED],
    MercatoPaymentStatus::REFUNDED => [MercatoPaymentStatus::REFUNDED],
];
$paymentChecks = 0;
foreach (MercatoPaymentStatus::all() as $current) {
    foreach (MercatoPaymentStatus::all() as $incoming) {
        $expected = isset($settledAllowed[$current]) && !in_array($incoming, $settledAllowed[$current], true);
        $expect(MercatoPaymentStatus::wouldRegressSettled($current, $incoming) === $expected, "Payment transition $current -> $incoming has an incorrect regression result.");
        $paymentChecks++;
    }
}

$genericMap = [
    'paid' => MercatoPaymentStatus::PAID, 'succeeded' => MercatoPaymentStatus::PAID, 'complete' => MercatoPaymentStatus::PAID, 'completed' => MercatoPaymentStatus::PAID,
    'authorized' => MercatoPaymentStatus::AUTHORIZED, 'requires_capture' => MercatoPaymentStatus::AUTHORIZED, 'approved' => MercatoPaymentStatus::AUTHORIZED,
    'pending' => MercatoPaymentStatus::PENDING, 'open' => MercatoPaymentStatus::PENDING, 'created' => MercatoPaymentStatus::PENDING, 'saved' => MercatoPaymentStatus::PENDING,
    'processing' => MercatoPaymentStatus::PROCESSING,
    'requires_payment_method' => MercatoPaymentStatus::FAILED, 'failed' => MercatoPaymentStatus::FAILED, 'denied' => MercatoPaymentStatus::FAILED, 'declined' => MercatoPaymentStatus::FAILED,
    'canceled' => MercatoPaymentStatus::CANCELED, 'cancelled' => MercatoPaymentStatus::CANCELED, 'voided' => MercatoPaymentStatus::CANCELED,
    'expired' => MercatoPaymentStatus::EXPIRED, 'refunded' => MercatoPaymentStatus::REFUNDED,
];
foreach ($genericMap as $external => $canonical) {
    $expect(MercatoPaymentStatusMapper::generic(' ' . strtoupper($external) . ' ') === $canonical, "Generic payment mapping failed for $external.");
}
$expect(MercatoPaymentStatusMapper::generic('') === MercatoPaymentStatus::PENDING, 'Empty generic payment state did not fail safely to pending.');
$expect(MercatoPaymentStatusMapper::generic('provider-new-state') === MercatoPaymentStatus::PENDING, 'Unknown generic payment state did not fail safely to pending.');

$providerMaps = [
    'Stripe PaymentIntent' => [
        [MercatoPaymentStatusMapper::class, 'stripePaymentIntent'],
        [
            'requires_payment_method' => MercatoPaymentStatus::FAILED,
            'requires_confirmation' => MercatoPaymentStatus::REQUIRES_CONFIRMATION,
            'requires_action' => MercatoPaymentStatus::REQUIRES_ACTION,
            'processing' => MercatoPaymentStatus::PROCESSING,
            'requires_capture' => MercatoPaymentStatus::AUTHORIZED,
            'succeeded' => MercatoPaymentStatus::PAID,
            'canceled' => MercatoPaymentStatus::CANCELED,
        ],
        MercatoPaymentStatus::PENDING,
    ],
    'Stripe webhook' => [
        [MercatoPaymentStatusMapper::class, 'stripeWebhookEvent'],
        [
            'payment_intent.succeeded' => MercatoPaymentStatus::PAID,
            'payment_intent.payment_failed' => MercatoPaymentStatus::FAILED,
            'payment_intent.processing' => MercatoPaymentStatus::PROCESSING,
            'payment_intent.canceled' => MercatoPaymentStatus::CANCELED,
        ],
        '',
    ],
    'Mollie payment' => [
        [MercatoPaymentStatusMapper::class, 'molliePayment'],
        [
            'paid' => MercatoPaymentStatus::PAID,
            'authorized' => MercatoPaymentStatus::AUTHORIZED,
            'pending' => MercatoPaymentStatus::PROCESSING,
            'open' => MercatoPaymentStatus::PENDING,
            'canceled' => MercatoPaymentStatus::CANCELED,
            'cancelled' => MercatoPaymentStatus::CANCELED,
            'expired' => MercatoPaymentStatus::EXPIRED,
            'failed' => MercatoPaymentStatus::FAILED,
        ],
        MercatoPaymentStatus::PENDING,
    ],
    'PayPal order' => [
        [MercatoPaymentStatusMapper::class, 'payPalOrder'],
        [
            'completed' => MercatoPaymentStatus::PAID,
            'approved' => MercatoPaymentStatus::AUTHORIZED,
            'created' => MercatoPaymentStatus::PENDING,
            'saved' => MercatoPaymentStatus::PENDING,
            'payer_action_required' => MercatoPaymentStatus::REQUIRES_ACTION,
            'voided' => MercatoPaymentStatus::CANCELED,
        ],
        MercatoPaymentStatus::PENDING,
    ],
    'PayPal webhook' => [
        [MercatoPaymentStatusMapper::class, 'payPalWebhookEvent'],
        [
            'checkout.order.approved' => MercatoPaymentStatus::AUTHORIZED,
            'payment.capture.completed' => MercatoPaymentStatus::PAID,
            'payment.capture.denied' => MercatoPaymentStatus::FAILED,
            'payment.capture.declined' => MercatoPaymentStatus::FAILED,
            'payment.capture.pending' => MercatoPaymentStatus::PROCESSING,
            'payment.capture.refunded' => MercatoPaymentStatus::REFUNDED,
            'checkout.order.cancelled' => MercatoPaymentStatus::CANCELED,
            'checkout.order.canceled' => MercatoPaymentStatus::CANCELED,
            'checkout.order.voided' => MercatoPaymentStatus::CANCELED,
        ],
        '',
    ],
];
$providerChecks = 0;
foreach ($providerMaps as $provider => [$mapper, $cases, $unknownFallback]) {
    foreach ($cases as $external => $canonical) {
        $expect($mapper(' ' . strtoupper($external) . ' ') === $canonical, "$provider mapping failed for $external.");
        $providerChecks++;
    }
    foreach (['', 'provider-new-state'] as $unknown) {
        $expect($mapper($unknown) === $unknownFallback, "$provider unknown-state fallback failed.");
        $providerChecks++;
    }
}

foreach (MercatoPaymentStatus::all() as $status) {
    $expect(MercatoPaymentStatus::isValid($status), "Declared payment state $status is not valid.");
}
$expect(!MercatoPaymentStatus::isValid(''), 'Empty payment status was accepted.');
$expect(!MercatoPaymentStatus::isValid('PAID'), 'Payment status validation unexpectedly became case-insensitive.');

echo "Mercato state-transition matrix tests passed: $quoteChecks quote transitions, $paymentChecks payment transitions, " . (count($genericMap) + $providerChecks) . " provider mappings/fallbacks.\n";
