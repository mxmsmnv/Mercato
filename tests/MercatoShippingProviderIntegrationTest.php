<?php
namespace ProcessWire;

$site = getenv('MERCATO_TEST_SITE');
if (!$site) { echo "Mercato shipping provider integration test skipped (set MERCATO_TEST_SITE).\n"; exit(0); }
$_SERVER['HTTP_HOST'] = 'mercato.test'; $_SERVER['SERVER_NAME'] = 'mercato.test'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site); $config->dbHost = '127.0.0.1'; $wire = new ProcessWire($config); $wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser'));
/** @var Mercato $commerce */ $commerce = $wire->modules->get('Mercato');
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); };
final class ShippingFailureFixture implements MercatoShippingProviderInterface {
    public int $calls = 0; public bool $slow = false; public bool $alwaysFail = false;
    public function __construct(private MercatoShippingProviderInterface $delegate) {}
    public function getShippingProviderKey(): string { return 'failure-fixture'; }
    public function quoteRates(array $context): array { $this->calls++; if ($this->slow) usleep(1100000); if ($this->alwaysFail || $this->calls === 1) throw new WireException('Fixture carrier failure.'); return $this->delegate->quoteRates($context); }
    public function createShipment(array $context): array { return $this->delegate->createShipment($context); }
    public function purchaseLabel(array $context): array { return $this->delegate->purchaseLabel($context); }
    public function getLabel(array $context): array { return $this->delegate->getLabel($context); }
    public function track(array $context): array { return $this->delegate->track($context); }
    public function voidShipment(array $context): array { return $this->delegate->voidShipment($context); }
    public function refundLabel(array $context): array { return $this->delegate->refundLabel($context); }
    public function verifyTrackingWebhook(string $payload, array $headers): bool { return $this->delegate->verifyTrackingWebhook($payload, $headers); }
    public function parseTrackingWebhook(string $payload, array $headers): array { return $this->delegate->parseTrackingWebhook($payload, $headers); }
}
$commerce->shipping_provider = 'reference'; $commerce->shipping_provider_failure_policy = 'fail_closed'; $commerce->shipping_provider_retries = 1; $commerce->shipping_provider_timeout_seconds = 2; $commerce->shipping_provider_quote_ttl_seconds = 60; $commerce->shipping_provider_package_mode = 'per_item'; $commerce->shipping_provider_handling_fixed = 1; $commerce->shipping_provider_handling_percent = 10; $commerce->shipping_provider_allowed_regions = 'US US:NY'; $commerce->shipping_provider_webhook_secret = 'integration-secret';
$items = [['id' => 'shipping-fixture', 'product_id' => 999997, 'title' => 'Shipping fixture', 'price' => 100, 'quantity' => 2, 'tax_rate' => 0, 'tax_code' => '', 'shipping_price' => 4, 'shipping_dimensions' => ['weight_kg' => 1, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10], 'product_type' => 'physical', 'template' => 'fixture', 'uid' => 'shipping-fixture']];
$cart = $commerce->productList($items); $customer = ['address' => '1 Test Street', 'city' => 'New York', 'zip' => '10001', 'country' => 'US', 'region' => 'NY'];
$methods = $commerce->shippingProviderService()->getLiveMethods($cart, $customer);
$expect(count($methods) === 2, 'Reference adapter did not return two checkout services.');
$expect(count($methods[0]['shipping_provider_quote']['input_snapshot']['packages']) === 2, 'Multiple-parcel normalization failed.');
$selection = (string) $methods[0]['selection_key']; $resolved = $commerce->fulfilmentService()->resolveSelection($selection, $cart, $customer);
$expect(($resolved['shipping_provider_quote']['revalidated_at'] ?? '') !== '', 'Final live rate was not revalidated.');
$pending = ['first_name' => 'Shipping', 'last_name' => 'Fixture', 'email' => 'shipping-fixture@example.test', 'mrc_currency' => 'USD', 'mrc_items' => json_encode($cart->toArray()), 'mrc_subtotal_amount' => 200, 'mrc_shipping_amount' => $resolved['amount'], 'mrc_total_amount' => 200 + $resolved['amount'], 'fulfilment_method' => 'carrier_delivery', 'mrc_fulfilment_label' => $resolved['label'], 'mrc_fulfilment_details' => json_encode($resolved), 'mrc_shipping_address' => json_encode($customer), 'payment_status' => MercatoPaymentStatus::PAID, 'payment_complete' => 1];
$order = $commerce->orderRepository()->savePendingOrder($pending); $label1 = $commerce->shippingProviderService()->purchaseLabel($order); $label2 = $commerce->shippingProviderService()->purchaseLabel($order);
$expect($label1['label_reference'] === $label2['label_reference'], 'Duplicate label purchase was not replay-safe.');
$stored = json_decode((string) $wire->pages->get($order->id)->mrc_fulfilment_details, true); $redacted = $commerce->shippingProviderService()->redactSnapshot($stored);
$expect(($redacted['provider_shipping']['label']['label_url'] ?? '') === '[redacted]', 'Sensitive label URL was not redacted for export.');
$send = static function (Mercato $commerce, int $orderId, string $eventId, string $status): array { $payload = json_encode(['event_id' => $eventId, 'order_id' => $orderId, 'status' => $status, 'tracking' => 'REF-TRACK']); $signature = hash_hmac('sha256', $payload, 'integration-secret'); return $commerce->shippingProviderService()->processTrackingWebhook('reference', $payload, ['x-mercato-signature' => $signature]); };
$first = $send($commerce, (int) $order->id, 'track-1', 'in_transit'); $duplicate = $send($commerce, (int) $order->id, 'track-1', 'in_transit'); $delivered = $send($commerce, (int) $order->id, 'track-2', 'delivered'); $regression = $send($commerce, (int) $order->id, 'track-3', 'in_transit');
$expect(!$first['duplicate'] && $duplicate['duplicate'], 'Duplicate tracking webhook was not idempotent.');
$expect($delivered['status'] === MercatoFulfilmentStatus::DELIVERED && $regression['status'] === MercatoFulfilmentStatus::DELIVERED, 'Tracking status regressed after delivery.');
$void1 = $commerce->shippingProviderService()->voidLabel($order); $void2 = $commerce->shippingProviderService()->voidLabel($order); $expect($void1['status'] === 'voided' && $void2['status'] === 'voided', 'Shipment void/refund replay failed.');
$unsupported = false; try { $commerce->shippingProviderService()->quoteRates($cart, array_merge($customer, ['country' => 'CA'])); } catch (WireException) { $unsupported = true; } $expect($unsupported, 'Unsupported destination was not rejected.');
$failureFixture = new ShippingFailureFixture(new MercatoReferenceShippingProvider($commerce));
$commerce->addHookAfter('shippingProviders', static function (HookEvent $event) use ($failureFixture): void { $providers = is_array($event->return) ? $event->return : []; $providers['failure-fixture'] = $failureFixture; $event->return = $providers; });
$commerce->shipping_provider = 'failure-fixture'; $commerce->shipping_provider_retries = 1; $commerce->shipping_provider_failure_policy = 'fail_closed';
$retried = $commerce->shippingProviderService()->quoteRates($cart, $customer); $expect(count($retried['rates']) === 2 && $failureFixture->calls === 2, 'Provider retry fixture failed.');
$failureFixture->slow = true; $failureFixture->calls = 1; $commerce->shipping_provider_timeout_seconds = 1; $timedOut = false; try { $commerce->shippingProviderService()->quoteRates($cart, $customer); } catch (WireException $e) { $timedOut = str_contains($e->getMessage(), 'timed out'); } $expect($timedOut, 'Provider timeout fixture failed.');
$failureFixture->slow = false; $failureFixture->alwaysFail = true; $commerce->shipping_provider_failure_policy = 'manual_fallback'; $fallback = $commerce->shippingProviderService()->quoteRates($cart, $customer); $expect(!empty($fallback['fallback']) && $fallback['rates'] === [], 'Explicit manual fallback fixture failed.');
echo 'Mercato shipping provider integration tests passed; fixture order ' . $order->id . ".\n";
