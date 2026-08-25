<?php
require_once __DIR__ . '/../src/Payment/MercatoPaymentStatus.php';
require_once __DIR__ . '/../src/Payment/MercatoPaymentReconciliationAudit.php';
use ProcessWire\MercatoPaymentReconciliationAudit;
$paidRemote = MercatoPaymentReconciliationAudit::classify(['status' => 'pending', 'paid' => false, 'total' => 100, 'requires_inventory' => true], ['status' => 'paid', 'refunded_amount' => 0]);
if (!in_array('paid_unfinalized', $paidRemote['issues'], true) || !in_array('missing_webhook', $paidRemote['issues'], true)) throw new RuntimeException('Interrupted finalization classification failed.');
$badFinal = MercatoPaymentReconciliationAudit::classify(['status' => 'pending', 'paid' => false, 'total' => 100, 'inventory_adjusted' => true], ['status' => 'failed']);
if (!in_array('finalized_unpaid', $badFinal['issues'], true)) throw new RuntimeException('Finalized/unpaid classification failed.');
$duplicates = MercatoPaymentReconciliationAudit::classify(['status' => 'paid', 'paid' => true, 'total' => 100], ['status' => 'paid'], [['idempotency_key' => 'same', 'external_id' => 'one', 'status' => 'paid'], ['idempotency_key' => 'same', 'external_id' => 'two', 'status' => 'paid']]);
if (!in_array('duplicate_attempt', $duplicates['issues'], true)) throw new RuntimeException('Duplicate attempt classification failed.');
$normalLifecycle = MercatoPaymentReconciliationAudit::classify(['status' => 'paid', 'paid' => true, 'total' => 100], ['status' => 'paid'], [['idempotency_key' => 'same', 'external_id' => 'one', 'status' => 'processing'], ['idempotency_key' => 'same', 'external_id' => 'one', 'status' => 'paid']]);
if (in_array('duplicate_attempt', $normalLifecycle['issues'], true)) throw new RuntimeException('Normal attempt lifecycle was misclassified as a duplicate.');
$refund = MercatoPaymentReconciliationAudit::classify(['status' => 'partially_refunded', 'paid' => true, 'total' => 100, 'refunded_amount' => 20], ['status' => 'partially_refunded', 'refunded_amount' => 25]);
if (!in_array('refund_mismatch', $refund['issues'], true)) throw new RuntimeException('Refund mismatch classification failed.');
if (!MercatoPaymentReconciliationAudit::classify(['status' => 'paid', 'paid' => true, 'total' => 100], ['status' => 'paid', 'refunded_amount' => 0])['healthy']) throw new RuntimeException('Healthy state misclassified.');
echo "Mercato payment reconciliation audit tests passed.\n";
