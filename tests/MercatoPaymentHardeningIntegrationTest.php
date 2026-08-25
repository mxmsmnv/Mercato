<?php
namespace ProcessWire;
$site = getenv('MERCATO_TEST_SITE');
if (!$site) { echo "Mercato payment hardening integration test skipped (set MERCATO_TEST_SITE).\n"; exit(0); }
$_SERVER['HTTP_HOST'] = 'mercato.dev'; $_SERVER['SERVER_NAME'] = 'mercato.dev'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php'; $config = ProcessWire::buildConfig($site); $config->dbHost = 'localhost'; $wire = new ProcessWire($config); $wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser')); /** @var Mercato $commerce */ $commerce = $wire->modules->get('Mercato');
$items = [['id' => 'payment-hardening-fixture', 'product_id' => 999996, 'title' => 'Payment hardening fixture', 'price' => 10, 'quantity' => 1, 'tax_rate' => 0, 'tax_code' => '', 'shipping_price' => 0, 'product_type' => 'service', 'template' => 'fixture', 'uid' => 'payment-hardening-fixture']];
$order = $commerce->orderRepository()->savePendingOrder(['first_name' => 'Payment', 'last_name' => 'Fixture', 'email' => 'payment-hardening@example.test', 'payment_method' => 'stripe-card', 'payment_status' => MercatoPaymentStatus::PENDING, 'payment_complete' => 0, 'mrc_currency' => 'USD', 'mrc_items' => json_encode($items), 'mrc_subtotal_amount' => 10, 'mrc_total_amount' => 10]);
$before = [(string) $order->mrc_payment_status, (int) $order->mrc_payment_complete, (int) ($order->mrc_inventory_adjusted ?? 0), (int) ($order->mrc_confirmation_send_count ?? 0)];
$first = $commerce->webhookService()->simulateDuplicateCallback($order, 'stripe', 'integration'); $second = $commerce->webhookService()->simulateDuplicateCallback($order, 'stripe', 'integration');
$fresh = $wire->pages->getById((int) $order->id, ['cache' => false])->first(); $after = [(string) $fresh->mrc_payment_status, (int) $fresh->mrc_payment_complete, (int) ($fresh->mrc_inventory_adjusted ?? 0), (int) ($fresh->mrc_confirmation_send_count ?? 0)];
if (!empty($first['duplicate']) || empty($second['duplicate']) || $before !== $after) throw new \RuntimeException('Duplicate callback produced side effects.');
$audit = $commerce->paymentReconciliationAuditService()->inspect($fresh, [['idempotency_key' => 'same', 'external_id' => 'charge-a', 'status' => 'paid'], ['idempotency_key' => 'same', 'external_id' => 'charge-b', 'status' => 'paid']]);
if (!in_array('duplicate_attempt', $audit['issues'], true)) throw new \RuntimeException('Duplicate attempt was not visible to reconciliation.');
$blocked = false; $invalidProduction = array_merge(Mercato::getDefaultConfig(), (array) $wire->modules->getConfig('Mercato'), ['production' => true, 'production_activation_confirmed' => true, 'enabled_payment_methods' => ['demo']]);
try { $commerce->setConfigData($invalidProduction); } catch (WireException $e) { $blocked = str_contains($e->getMessage(), 'Production activation blocked'); }
if (!$blocked || !empty($commerce->production) || !empty($wire->modules->getConfig('Mercato')['production'])) throw new \RuntimeException('Invalid production activation was not safely blocked.');
echo 'Mercato payment hardening integration tests passed; fixture order ' . $order->id . ".\n";
