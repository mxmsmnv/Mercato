<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) { echo "Mercato MCP provider integration test skipped (set MERCATO_TEST_SITE).\n"; exit(0); }
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

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};

final class MercatoMcpPaymentFixtureGateway extends MercatoGatewayBase {
    public function getName(): string { return 'mcp-fixture'; }
    public function retrievePaymentState(Page $order): array {
        return [
            'status' => MercatoPaymentStatus::PAID,
            'amount' => (float) $order->mrc_total_amount,
            'refunded_amount' => 0,
            'currency' => (string) $order->mrc_currency,
            'reference' => 'mcp-fixture-' . (int) $order->id,
        ];
    }
}

final class MercatoMcpEmailFixtureTransport extends Wire implements MercatoEmailTransportInterface {
    public function getName(): string { return 'mcp-fixture'; }
    public function getSetupStatus(): array { return ['ready' => true, 'errors' => [], 'details' => ['mode' => 'fixture']]; }
    public function send(array $message): array { return ['accepted' => true, 'status' => 'sent', 'provider_message_id' => 'mcp-fixture-message']; }
}

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
$commerce->ensureMcpOperationsSchema();
$tools = $commerce->mcpTools();
$expect(count($tools) === 11, 'McpServer did not discover the expected Mercato tool inventory.');
foreach ($tools as $tool) {
    $expect(($tool['input_schema']['additionalProperties'] ?? null) === false, 'MCP tool schema is not closed: ' . ($tool['name'] ?? 'unknown'));
}

$product = null;
$selectedVariant = null;
foreach ($wire->pages->find('template=mrc-product, include=all') as $candidate) {
    if ((string) ($candidate->mrc_product_type ?: 'physical') !== 'physical') continue;
    $definition = $commerce->variantService()->getDefinition($candidate);
    foreach ((array) ($definition['variants'] ?? []) as $variant) {
        if ((int) ($variant['stock'] ?? 0) > 2) { $product = $candidate; $selectedVariant = $variant; break 2; }
    }
    if ((int) $candidate->mrc_stock > 2) { $product = $candidate; $selectedVariant = null; break; }
}
$expect($product instanceof Page && $product->id, 'Physical product fixture with stock was not found.');

$nonce = bin2hex(random_bytes(6));
$variantId = (string) ($selectedVariant['id'] ?? '');
$item = $commerce->variantService()->hydrateItem($product, [
    'quantity' => 1,
    'variant_id' => $variantId,
]);
$details = [
    'type' => MercatoFulfilmentMethodType::CARRIER_DELIVERY,
    'selection_key' => 'live:reference:standard',
    'label' => 'Reference delivery',
    'amount' => 5,
    'details' => 'MCP fixture delivery',
    'available' => true,
    'shipping_provider_quote' => [
        'provider' => 'reference',
        'rate' => ['id' => 'standard', 'service' => 'standard', 'label' => 'Standard', 'amount' => 5, 'currency' => 'USD'],
        'input_snapshot' => ['packages' => [['weight_kg' => 1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'quantity' => 1, 'items' => [['line_id' => (string) $item['key'], 'sku' => (string) $item['sku'], 'quantity' => 1]]]]],
    ],
];
$order = $commerce->orderRepository()->savePendingOrder([
    'first_name' => 'MCP', 'last_name' => 'Fixture', 'email' => 'mcp-' . $nonce . '@example.test',
    'payment_method' => 'mcp-fixture', 'payment_status' => MercatoPaymentStatus::PAID, 'payment_complete' => 1,
    'mrc_currency' => 'USD', 'mrc_items' => json_encode([$item]), 'mrc_subtotal_amount' => (float) $item['price'],
    'mrc_shipping_amount' => 5, 'mrc_total_amount' => (float) $item['price'] + 5,
    'fulfilment_method' => MercatoFulfilmentMethodType::CARRIER_DELIVERY,
    'mrc_fulfilment_label' => 'Reference delivery', 'mrc_fulfilment_details' => json_encode($details),
    'mrc_shipping_address' => json_encode(['address' => 'Fixture Street', 'city' => 'Test', 'zip' => '10001', 'country' => 'US']),
]);
$order->of(false);
$order->mrc_fulfilment_status = MercatoFulfilmentStatus::UNFULFILLED;
$order->mrc_inventory_adjusted = 1;
$wire->pages->save($order);

$originalProvider = $commerce->shipping_provider;
$originalSender = $commerce->notification_sender_email;
$originalTransport = $commerce->notification_transport;
$commerce->shipping_provider = 'reference';
$commerce->notification_sender_email = 'store@example.test';
$commerce->notification_transport = 'mcp-fixture';
$commerce->registerGateway('mcp-fixture', new MercatoMcpPaymentFixtureGateway());
$commerce->addHookAfter('emailTransport', static function (HookEvent $event): void {
    $transport = new MercatoMcpEmailFixtureTransport();
    $transport->setWire($event->object->wire());
    $event->return = $transport;
});

try {
    $reference = (string) $order->mrc_invoice_number;
    $safe = $commerce->mcpGetOrder($reference);
    $encoded = json_encode($safe);
    $expect(($safe['order_id'] ?? 0) === (int) $order->id, 'MCP order lookup failed.');
    $expect(!str_contains((string) $encoded, 'mcp-' . $nonce . '@example.test') && !isset($safe['email'], $safe['shipping_address']), 'MCP order response leaked PII.');
    $inventory = $commerce->mcpGetInventory($reference);
    $expect(!empty($inventory['available']) && ($inventory['items'][0]['variant_id'] ?? '') === $variantId, 'Exact-variant inventory validation failed.');

    $payment = $commerce->mcpVerifyPayment($reference, 'verify-' . $nonce, 'Verify paid fixture order', 'VERIFY_REMOTE_PAYMENT');
    $expect(!empty($payment['verified']) && $payment['remote_status'] === MercatoPaymentStatus::PAID, 'Remote payment verification failed.');
    $paymentReplay = $commerce->mcpVerifyPayment($reference, 'verify-' . $nonce, 'Verify paid fixture order', 'VERIFY_REMOTE_PAYMENT');
    $expect(!empty($paymentReplay['idempotent_replay']), 'Payment verification was not idempotent.');

    $foreignItemBlocked = false;
    try {
        $commerce->mcpCreateShipment($reference, [['product_id' => 99999999, 'variant_id' => '', 'quantity' => 1]], 'foreign-' . $nonce, 'Attempt foreign shipment item', 'CREATE_VALIDATED_SHIPMENT');
    } catch (MercatoMcpException $error) {
        $foreignItemBlocked = ($error->payload()['code'] ?? '') === 'invalid_shipment_items';
    }
    $expect($foreignItemBlocked, 'A product outside the immutable order snapshot was accepted for shipment.');

    $shipmentItems = [['product_id' => (int) $product->id, 'variant_id' => $variantId, 'quantity' => 1]];
    $shipment = $commerce->mcpCreateShipment($reference, $shipmentItems, 'shipment-' . $nonce, 'Create validated fixture shipment', 'CREATE_VALIDATED_SHIPMENT', 'Reference', 'Standard');
    $shipmentReplay = $commerce->mcpCreateShipment($reference, $shipmentItems, 'shipment-' . $nonce, 'Create validated fixture shipment', 'CREATE_VALIDATED_SHIPMENT', 'Reference', 'Standard');
    $expect(($shipment['shipment_id'] ?? '') === ($shipmentReplay['shipment_id'] ?? '') && !empty($shipmentReplay['idempotent_replay']), 'Shipment replay created a second result.');

    $label = $commerce->mcpPurchaseShippingLabel($reference, 'label-' . $nonce, 'Purchase fixture provider label', 'PURCHASE_PROVIDER_LABEL_WITH_COST');
    $expect(($label['label']['status'] ?? '') === 'purchased' && !isset($label['label']['label_url']), 'Label purchase or redaction failed.');
    $tracking = $commerce->mcpUpdateTracking($reference, 'MCPTRACK' . $nonce, 'tracking-' . $nonce, 'Save fixture tracking reference', 'UPDATE_CARRIER_TRACKING', 'https://example.test/track/' . $nonce);
    $expect(($tracking['tracking'] ?? '') === 'MCPTRACK' . $nonce, 'Tracking update failed.');
    $advanced = $commerce->mcpAdvanceFulfilment($reference, MercatoFulfilmentStatus::SHIPPED, 'advance-' . $nonce, 'Advance fixture to shipped', 'ADVANCE_VALIDATED_FULFILMENT');
    $expect(($advanced['status'] ?? '') === MercatoFulfilmentStatus::SHIPPED, 'Fulfilment advancement failed.');
    $mail = $commerce->mcpSendOrderEmail($reference, 'shipment_tracking', 'email-' . $nonce, 'Send fixture shipment email', 'SEND_TRANSACTIONAL_ORDER_EMAIL');
    $expect(($mail['status'] ?? '') === 'sent' && !isset($mail['recipient']), 'Transactional email execution leaked PII or failed.');

    $regression = false;
    try {
        $commerce->mcpAdvanceFulfilment($reference, MercatoFulfilmentStatus::UNFULFILLED, 'regress-' . $nonce, 'Attempt invalid state regression', 'ADVANCE_VALIDATED_FULFILMENT');
    } catch (MercatoMcpException $error) {
        $payload = $error->payload();
        $regression = ($payload['code'] ?? '') === 'invalid_state_transition' && !empty($payload['human_review_required']);
    }
    $expect($regression, 'Invalid fulfilment regression did not produce a structured exception.');

    $conflict = false;
    try {
        $commerce->mcpUpdateTracking($reference, 'DIFFERENT', 'tracking-' . $nonce, 'Save different tracking reference', 'UPDATE_CARRIER_TRACKING');
    } catch (MercatoMcpException $error) {
        $conflict = ($error->payload()['code'] ?? '') === 'idempotency_conflict';
    }
    $expect($conflict, 'Idempotency key reuse with different input was not blocked.');

    $health = $commerce->mcpGetOperationalHealth(true);
    $expect(isset($health['status'], $health['checks']) && !str_contains((string) json_encode($health), 'mcp-' . $nonce . '@example.test'), 'Operational health is incomplete or contains PII.');
} finally {
    $commerce->shipping_provider = $originalProvider;
    $commerce->notification_sender_email = $originalSender;
    $commerce->notification_transport = $originalTransport;
    if ($order instanceof Page && $order->id) $wire->pages->delete($order, true);
}

echo "Mercato MCP provider integration tests passed.\n";
