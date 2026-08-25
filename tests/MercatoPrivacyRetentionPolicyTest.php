<?php
require_once __DIR__ . '/../src/Privacy/MercatoPrivacyRetentionPolicy.php';
use ProcessWire\MercatoPrivacyRetentionPolicy;
$now = 2000000000; $boundary = $now - 30 * 86400;
if (!MercatoPrivacyRetentionPolicy::isOlderThan($boundary, 30, $now) || MercatoPrivacyRetentionPolicy::isOlderThan($boundary + 1, 30, $now)) throw new RuntimeException('Retention age boundary failed.');
foreach ([['legal_hold' => true], ['payment_status' => 'refund_pending'], ['dispute_open' => true], ['inventory_reserved' => true], ['paid' => true, 'fulfilment_status' => 'shipped']] as $state) if (!MercatoPrivacyRetentionPolicy::orderBlockers($state)) throw new RuntimeException('Unsafe linked order was not blocked.');
if (MercatoPrivacyRetentionPolicy::orderBlockers(['paid' => true, 'payment_status' => 'paid', 'fulfilment_status' => 'delivered'])) throw new RuntimeException('Settled order was incorrectly blocked.');
echo "Mercato privacy retention policy tests passed.\n";
