<?php
require_once __DIR__ . '/../src/Payment/MercatoStripeOrderData.php';

use ProcessWire\MercatoStripeOrderData;

$orderData = MercatoStripeOrderData::fromPendingOrder([
    'mrc_order_page_id' => 1167,
    'mrc_invoice_number' => '01167',
    'mrc_items' => json_encode([
        ['title' => 'Inspection course', 'sku' => 'COURSE-A', 'product_type' => 'digital', 'quantity' => 2],
        ['title' => 'Field guide', 'sku' => 'GUIDE-B', 'product_type' => 'physical', 'quantity' => 1],
    ]),
    'email' => 'must-not-enter-metadata@example.test',
    'phone' => '+1 949 555 0100',
]);

if (($orderData['description'] ?? '') !== 'Order 01167 · 3 items · Inspection course x2, Field guide') throw new RuntimeException('Stripe order description was not built from the generic cart snapshot.');
if (($orderData['metadata']['mrc_order_id'] ?? '') !== '1167') throw new RuntimeException('Stripe order ID metadata is missing.');
if (($orderData['metadata']['mrc_invoice_number'] ?? '') !== '01167') throw new RuntimeException('Stripe invoice metadata is missing.');
if (($orderData['metadata']['mrc_line_item_count'] ?? '') !== '2') throw new RuntimeException('Stripe line-item count is incorrect.');
if (($orderData['metadata']['mrc_quantity_total'] ?? '') !== '3') throw new RuntimeException('Stripe quantity total is incorrect.');
if (($orderData['metadata']['mrc_skus'] ?? '') !== 'COURSE-A, GUIDE-B') throw new RuntimeException('Stripe SKU summary is incorrect.');
if (($orderData['metadata']['mrc_product_types'] ?? '') !== 'digital, physical') throw new RuntimeException('Stripe product-type summary is incorrect.');
if (str_contains(json_encode($orderData['metadata']), 'example.test') || str_contains(json_encode($orderData['metadata']), '555')) throw new RuntimeException('Customer PII leaked into Stripe metadata.');

$fallback = MercatoStripeOrderData::fromPendingOrder([
    'mrc_order_page_id' => 9,
    'items' => [['name' => 'Service review', 'quantity' => 0.5]],
]);
if (($fallback['description'] ?? '') !== 'Order 9 · 0.5 items · Service review x0.5') throw new RuntimeException('Array item fallback or fractional quantity projection failed.');

$bounded = MercatoStripeOrderData::fromPendingOrder([
    'mrc_invoice_number' => str_repeat('I', 600),
    'mrc_items' => [['title' => str_repeat('T', 600), 'sku' => str_repeat('S', 600), 'product_type' => str_repeat('P', 600)]],
]);
if (strlen($bounded['description']) > 500 || strlen($bounded['metadata']['mrc_skus']) > 450 || strlen($bounded['metadata']['mrc_product_types']) > 450) throw new RuntimeException('Stripe projection exceeded provider-safe bounds.');

echo "Mercato Stripe order data tests passed.\n";
