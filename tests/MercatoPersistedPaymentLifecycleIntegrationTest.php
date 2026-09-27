<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') {
    echo "Mercato persisted payment lifecycle integration test skipped (set MERCATO_TEST_SITE).\n";
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
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for the persisted payment lifecycle profile.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Persisted payment lifecycle fixtures are forbidden in production mode.');

final class MercatoLifecycleFixtureGateway extends MercatoGatewayBase {
    public array $completionStatuses = [];
    public array $refundResponses = [];
    public array $refundLookupResponses = [];
    public array $remoteStates = [];
    public int $completionCalls = 0;
    public int $refundCalls = 0;
    public int $refundLookupCalls = 0;
    public int $remoteStateCalls = 0;

    public function getName(): string {
        return 'lifecycle-fixture';
    }

    public function getLabel(): string {
        return 'Lifecycle Fixture';
    }

    public function getPaymentMethods(): array {
        return ['lifecycle-fixture' => 'Lifecycle Fixture'];
    }

    public function getCapabilities(): MercatoGatewayCapabilities {
        return new MercatoGatewayCapabilities(
            name: $this->getName(),
            label: $this->getLabel(),
            paymentMethods: $this->getPaymentMethods(),
            supportsWebhooks: true,
            supportsRefunds: true,
            supportsPartialRefunds: true,
        );
    }

    public function completePayment(array $pendingOrder, array $data): array {
        $this->completionCalls++;
        if ($this->completionStatuses === []) throw new \RuntimeException('Unexpected lifecycle completion boundary call.');
        $status = (string) array_shift($this->completionStatuses);
        $pendingOrder['payment_status'] = $status;
        $pendingOrder['payment_complete'] = $status === MercatoPaymentStatus::PAID ? 1 : 0;
        $pendingOrder['payment_details'] = json_encode([
            'provider' => $this->getName(),
            'status' => $status,
            'reference' => 'life_' . (int) ($pendingOrder['mrc_order_page_id'] ?? 0),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($status === MercatoPaymentStatus::PAID) $pendingOrder['paid_date'] = date('Y-m-d H:i:s');
        return $pendingOrder;
    }

    public function refund(array $orderData, float $amount, string $reason): array {
        $this->refundCalls++;
        if ($this->refundResponses === []) throw new \RuntimeException('Unexpected lifecycle refund boundary call.');
        $response = array_shift($this->refundResponses);
        return $response + ['gateway' => $this->getName(), 'amount' => $amount, 'reason' => $reason, 'payload' => ['network' => false]];
    }

    public function retrieveRefund(array $orderData, string $refundId): array {
        $this->refundLookupCalls++;
        if ($this->refundLookupResponses === []) throw new \RuntimeException('Unexpected lifecycle refund lookup boundary call.');
        $response = array_shift($this->refundLookupResponses);
        return $response + ['gateway' => $this->getName(), 'id' => $refundId, 'payload' => ['network' => false]];
    }

    public function retrievePaymentState(Page $order): array {
        $this->remoteStateCalls++;
        if ($this->remoteStates === []) throw new \RuntimeException('Unexpected lifecycle remote-state boundary call.');
        return array_shift($this->remoteStates);
    }
}

final class MercatoPersistedWebhookProbe extends MercatoWebhookService {
    public function applyStripeEventFixture(\Stripe\Event $event): bool {
        return $this->applyStripeEvent($event);
    }
}

final class MercatoLifecycleEmailTransport extends Wire implements MercatoEmailTransportInterface {
    public array $messages = [];

    public function getName(): string {
        return 'lifecycle-fixture';
    }

    public function getSetupStatus(): array {
        return ['ready' => true, 'errors' => [], 'details' => ['network' => false]];
    }

    public function send(array $message): array {
        $this->messages[] = $message;
        return [
            'accepted' => true,
            'status' => 'sent',
            'provider_message_id' => 'lifecycle-message-' . count($this->messages),
        ];
    }

    public function countEvent(string $event): int {
        return count(array_filter($this->messages, static fn(array $message): bool => ($message['event'] ?? '') === $event));
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
        if ($contains !== null && !str_contains(strtolower($exception->getMessage()), strtolower($contains))) {
            throw new \RuntimeException($message . ' Unexpected message: ' . $exception->getMessage(), 0, $exception);
        }
        return $exception;
    }
    throw new \RuntimeException($message);
};

$runId = 'payment-life-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
$createdOrderIds = [];
$createdProductIds = [];
$logsRoot = rtrim((string) $wire->config->paths->logs, '/') . '/';
$originalNotifications = $commerce->enabled_notification_events;
$originalAnalytics = $commerce->analytics_enabled;
$originalSenderEmail = $commerce->notification_sender_email;
$originalSenderName = $commerce->notification_sender_name;
$originalTransport = $commerce->notification_transport;
$originalRetries = $commerce->notification_retries;
$initialNotificationLocks = array_fill_keys(glob($logsRoot . 'mercato-notifications-*.lock') ?: [], true);
$mcpOperationKeys = [];
$cleaned = false;

$removeRunLogLines = static function () use ($logsRoot, $runId, &$createdOrderIds): void {
    $ownedIds = array_fill_keys(array_map('intval', $createdOrderIds), true);
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) {
        $lines = file($path);
        if ($lines === false) continue;
        $kept = [];
        foreach ($lines as $line) {
            $owned = str_contains($line, $runId);
            if (!$owned) {
                $json = strstr($line, '{');
                $payload = $json === false ? null : json_decode($json, true);
                $orderId = is_array($payload) ? (int) ($payload['order_id'] ?? $payload['context']['order_id'] ?? 0) : 0;
                $owned = $orderId > 0 && isset($ownedIds[$orderId]);
            }
            if (!$owned) $kept[] = $line;
        }
        if ($kept === $lines) continue;
        if ($kept === []) {
            @unlink($path);
        } else {
            file_put_contents($path, implode('', $kept), LOCK_EX);
        }
    }
};

$cleanup = static function () use (
    $wire,
    $commerce,
    &$createdOrderIds,
    &$createdProductIds,
    $runId,
    $removeRunLogLines,
    $originalNotifications,
    $originalAnalytics,
    $originalSenderEmail,
    $originalSenderName,
    $originalTransport,
    $originalRetries,
    $logsRoot,
    $initialNotificationLocks,
    &$mcpOperationKeys,
    &$cleaned
): void {
    if ($cleaned) return;
    $cleaned = true;
    $commerce->set('enabled_notification_events', $originalNotifications);
    $commerce->set('analytics_enabled', $originalAnalytics);
    $commerce->set('notification_sender_email', $originalSenderEmail);
    $commerce->set('notification_sender_name', $originalSenderName);
    $commerce->set('notification_transport', $originalTransport);
    $commerce->set('notification_retries', $originalRetries);
    $wire->session->remove('mrc_pending_order');
    foreach (array_reverse(array_unique($createdOrderIds)) as $id) {
        $order = $wire->pages->get((int) $id);
        if (!$order || !$order->id) continue;
        $email = strtolower((string) ($order->mrc_email ?: $order->mrc_customer_email));
        if (!str_contains($email, $runId)) throw new WireException("Refusing to delete unexpected order {$order->id}.");
        $wire->pages->delete($order, true);
    }
    foreach (array_reverse(array_unique($createdProductIds)) as $id) {
        $product = $wire->pages->get((int) $id);
        if (!$product || !$product->id) continue;
        if (!str_starts_with((string) $product->name, 'e2e-' . $runId . '-')) throw new WireException("Refusing to delete unexpected product {$product->id}.");
        $wire->pages->delete($product, true);
    }
    if ($mcpOperationKeys !== []) {
        $statement = $wire->database->prepare('DELETE FROM mercato_mcp_operations WHERE operation_key_hash=:key');
        foreach (array_unique($mcpOperationKeys) as $key) $statement->execute([':key' => $key]);
    }
    $removeRunLogLines();
    foreach (glob($logsRoot . 'mercato-notifications-*.lock') ?: [] as $path) {
        if (!isset($initialNotificationLocks[$path])) @unlink($path);
    }
};
register_shutdown_function($cleanup);

$commerce->set('enabled_notification_events', ['order_confirmation', 'payment_failed', 'payment_recovery', 'refund', 'cancellation', 'shipment_tracking']);
$commerce->set('analytics_enabled', false);
$commerce->set('notification_sender_email', 'store-' . $runId . '@example.test');
$commerce->set('notification_sender_name', 'Lifecycle Fixture Store');
$commerce->set('notification_transport', 'lifecycle-fixture');
$commerce->set('notification_retries', 0);
$emailTransport = new MercatoLifecycleEmailTransport();
$emailTransport->setWire($wire);
$commerce->addHookAfter('emailTransport', static function (HookEvent $event) use ($emailTransport): void {
    $event->return = $emailTransport;
});
$gateway = new MercatoLifecycleFixtureGateway();
$commerce->registerGateway('lifecycle-fixture', $gateway);
$repository = $commerce->orderRepository();
$webhooks = new MercatoPersistedWebhookProbe($commerce);
$webhooks->setWire($wire);
$manualReconciliation = new MercatoPaymentReconciliationService($commerce);
$manualReconciliation->setWire($wire);
$audit = $commerce->paymentReconciliationAuditService();
$refunds = $commerce->refundService();

$productsParent = $wire->pages->get('template=mrc-products, include=all');
if (!$productsParent || !$productsParent->id) $productsParent = $wire->pages->get('/products/');
if (!$productsParent || !$productsParent->id) throw new WireException('Mercato products parent is missing.');

$product = new Page();
$product->template = 'mrc-product';
$product->parent = $productsParent;
$product->name = 'e2e-' . $runId . '-product';
$product->of(false);
$product->title = 'E2E ' . $runId . ' lifecycle product';
$product->mrc_sku = strtoupper(substr(hash('sha256', $runId), 0, 16));
$product->mrc_price = 20;
$product->mrc_tax_rate = 0;
$product->mrc_product_type = 'physical';
$product->mrc_product_status = 'active';
$product->mrc_stock = 3;
$product->mrc_stock_policy = 'deny';
$wire->pages->save($product);
$createdProductIds[] = (int) $product->id;

$item = $commerce->variantService()->hydrateItem($product, ['quantity' => 1]);
$createOrder = static function (string $suffix) use ($commerce, $runId, $item, &$createdOrderIds): Page {
    $order = $commerce->orderRepository()->savePendingOrder([
        'first_name' => 'Lifecycle',
        'last_name' => 'Fixture',
        'email' => $runId . '-' . $suffix . '@example.test',
        'payment_method' => 'lifecycle-fixture',
        'payment_status' => MercatoPaymentStatus::PENDING,
        'payment_complete' => 0,
        'mrc_currency' => 'USD',
        'mrc_items' => json_encode([$item], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'mrc_subtotal_amount' => 20,
        'mrc_total_amount' => 20,
        'payment_attempt_id' => $runId . '-' . $suffix,
        'payment_attempt_idempotency_key' => $runId . '-' . $suffix . '-attempt',
        'checkout_nonce' => hash('sha256', $runId . '|' . $suffix),
    ]);
    $createdOrderIds[] = (int) $order->id;
    return $order;
};
$fresh = static fn(int $id): Page => $wire->pages->getById($id, ['cache' => false])->first();
$stock = static fn(): int => (int) $fresh((int) $product->id)->mrc_stock;
$completePersisted = static function (Page $order, string $status) use ($wire, $commerce, $repository, $gateway): Page {
    $pending = $repository->pageToPendingData($fresh = $wire->pages->getById((int) $order->id, ['cache' => false])->first());
    $pending['payment_method'] = 'lifecycle-fixture';
    $gateway->completionStatuses[] = $status;
    $wire->session->set('mrc_pending_order', $pending);
    return $commerce->completePayment([]);
};
$stripeEvent = static function (Page $order, string $type, string $status, string $suffix) use ($runId): \Stripe\Event {
    return \Stripe\Event::constructFrom([
        'id' => 'evt_' . $runId . '_' . $suffix,
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => [
            'id' => 'pi_' . $runId . '_' . (int) $order->id,
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => 2000,
            'amount_received' => $status === 'succeeded' ? 2000 : 0,
            'currency' => 'usd',
            'metadata' => ['mrc_order_id' => (string) $order->id],
        ]],
    ]);
};

try {
    $paymentOrder = $createOrder('payment');
    $cancelOrder = $createOrder('cancel');
    $failedOrder = $createOrder('failed');
    $repository->reserveStock($paymentOrder, 10);
    $repository->reserveStock($cancelOrder, 10);
    $repository->reserveStock($failedOrder, 10);
    $expect($repository->getReservedQuantityForProduct((int) $product->id) === 3, 'The three lifecycle orders did not persist exact reservations.');
    $expect($stock() === 3, 'Reservation unexpectedly changed physical stock.');

    $failedOrder = $completePersisted($failedOrder, MercatoPaymentStatus::FAILED);
    $failedOrder = $fresh((int) $failedOrder->id);
    $expect((string) $failedOrder->mrc_payment_status === MercatoPaymentStatus::FAILED && (int) $failedOrder->mrc_payment_complete === 0, 'Failed payment did not persist a terminal unpaid state.');
    $expect((int) $failedOrder->mrc_inventory_reserved === 0 && $repository->getReservedQuantityForProduct((int) $product->id) === 2, 'Failed payment did not release its reservation exactly once.');
    $expect($emailTransport->countEvent('payment_failed') === 1, 'Failed payment did not send exactly one failure notification.');
    $recoveryService = new MercatoPaymentLinkService($commerce);
    $recoveryService->setWire($wire);
    $firstRecovery = $recoveryService->send($failedOrder);
    $recoveryReplay = $recoveryService->send($failedOrder);
    $expect(($firstRecovery['status'] ?? '') === 'sent' && ($recoveryReplay['status'] ?? '') === 'skipped', 'Payment recovery delivery/replay did not preserve exact-once semantics.');
    $expect($emailTransport->countEvent('payment_recovery') === 1 && (string) $fresh((int) $failedOrder->id)->mrc_payment_status === MercatoPaymentStatus::FAILED, 'Recovery delivery duplicated or mutated failed payment state.');

    $paymentOrder = $completePersisted($paymentOrder, MercatoPaymentStatus::REQUIRES_ACTION);
    $paymentOrder = $fresh((int) $paymentOrder->id);
    $expect((string) $paymentOrder->mrc_payment_status === MercatoPaymentStatus::REQUIRES_ACTION, 'requires_action did not persist.');
    $expect((int) $paymentOrder->mrc_payment_complete === 0 && (int) $paymentOrder->mrc_inventory_reserved === 1, 'requires_action finalized or released the order.');
    $expect($stock() === 3, 'requires_action changed stock.');

    $paymentOrder = $completePersisted($paymentOrder, MercatoPaymentStatus::PROCESSING);
    $paymentOrder = $fresh((int) $paymentOrder->id);
    $expect((string) $paymentOrder->mrc_payment_status === MercatoPaymentStatus::PROCESSING, 'processing did not persist.');
    $expect((int) $paymentOrder->mrc_payment_complete === 0 && (int) $paymentOrder->mrc_inventory_reserved === 1, 'processing finalized or released the order.');
    $expect($stock() === 3, 'processing changed stock.');

    $successEvent = $stripeEvent($paymentOrder, 'payment_intent.succeeded', 'succeeded', 'success');
    $expect($webhooks->applyStripeEventFixture($successEvent), 'Delayed Stripe success was not applied.');
    $paymentOrder = $fresh((int) $paymentOrder->id);
    $paidDate = (string) $paymentOrder->mrc_paid_date;
    $expect((string) $paymentOrder->mrc_payment_status === MercatoPaymentStatus::PAID && (int) $paymentOrder->mrc_payment_complete === 1, 'Delayed success did not persist paid finalization.');
    $expect((int) $paymentOrder->mrc_inventory_adjusted === 1 && (int) $paymentOrder->mrc_inventory_reserved === 0, 'Delayed success did not finalize inventory exactly once.');
    $expect($stock() === 2 && $repository->getReservedQuantityForProduct((int) $product->id) === 1, 'Delayed success produced the wrong stock/reservation state.');
    $expect($emailTransport->countEvent('order_confirmation') === 1, 'Paid finalization did not send exactly one confirmation.');

    $cancellationRequest = $commerce->createCancellationRequest($cancelOrder, ['reason' => 'Run-owned cancellation request.', 'source' => 'lifecycle_test']);
    $cancellationReplay = $commerce->createCancellationRequest($fresh((int) $cancelOrder->id), ['reason' => 'Ignored replay.', 'source' => 'lifecycle_test']);
    $expect(($cancellationRequest['request_id'] ?? '') !== '' && ($cancellationReplay['request_id'] ?? '') === ($cancellationRequest['request_id'] ?? ''), 'Cancellation request replay created a second request.');

    $commerce->ensureMcpOperationsSchema();
    $advanceKey = 'advance-' . $runId;
    $regressKey = 'regress-' . $runId;
    foreach ([['advance_fulfilment', $advanceKey], ['advance_fulfilment', $regressKey]] as [$action, $key]) {
        $mcpOperationKeys[] = hash('sha256', $action . ':' . $key);
    }
    $advanced = $commerce->mcpAdvanceFulfilment((string) $paymentOrder->id, MercatoFulfilmentStatus::SHIPPED, $advanceKey, 'Advance lifecycle fixture to shipped.', 'ADVANCE_VALIDATED_FULFILMENT');
    $advancedReplay = $commerce->mcpAdvanceFulfilment((string) $paymentOrder->id, MercatoFulfilmentStatus::SHIPPED, $advanceKey, 'Advance lifecycle fixture to shipped.', 'ADVANCE_VALIDATED_FULFILMENT');
    $expect(($advanced['status'] ?? '') === MercatoFulfilmentStatus::SHIPPED && !empty($advancedReplay['idempotent_replay']), 'Fulfilment advancement/replay was not persisted idempotently.');
    $expectThrows(
        fn() => $commerce->mcpAdvanceFulfilment((string) $paymentOrder->id, MercatoFulfilmentStatus::UNFULFILLED, $regressKey, 'Attempt lifecycle fulfilment regression.', 'ADVANCE_VALIDATED_FULFILMENT'),
        'Fulfilment state regression was accepted.',
        'regression'
    );
    $paymentOrder = $fresh((int) $paymentOrder->id);
    $expect((string) $paymentOrder->mrc_fulfilment_status === MercatoFulfilmentStatus::SHIPPED && (string) $paymentOrder->mrc_payment_status === MercatoPaymentStatus::PAID, 'Fulfilment transition regressed paid state.');
    $shippingMail = $commerce->notificationDeliveryService()->sendOrderEvent($paymentOrder, 'shipment_tracking', ['event_id' => 'shipment|' . $runId]);
    $shippingReplay = $commerce->notificationDeliveryService()->sendOrderEvent($paymentOrder, 'shipment_tracking', ['event_id' => 'shipment|' . $runId]);
    $expect(($shippingMail['status'] ?? '') === 'sent' && ($shippingReplay['status'] ?? '') === 'skipped' && $emailTransport->countEvent('shipment_tracking') === 1, 'Shipment notification replay was not exact-once.');
    $returnRequest = $commerce->createReturnRequest($paymentOrder, ['reason' => 'Run-owned return request.', 'items' => [$item], 'source' => 'lifecycle_test']);
    $expect(($returnRequest['request_id'] ?? '') !== '' && count((array) ($returnRequest['items'] ?? [])) === 1, 'Return request was not created from the persisted order snapshot.');

    $processingEvent = $stripeEvent($paymentOrder, 'payment_intent.processing', 'processing', 'late-processing');
    $expect(!$webhooks->applyStripeEventFixture($processingEvent), 'Out-of-order processing regressed a settled payment.');
    $afterLate = $fresh((int) $paymentOrder->id);
    $expect((string) $afterLate->mrc_payment_status === MercatoPaymentStatus::PAID && (int) $afterLate->mrc_payment_complete === 1, 'Out-of-order processing changed persisted paid state.');
    $expect((string) $afterLate->mrc_paid_date === $paidDate && $stock() === 2, 'Out-of-order processing changed paid evidence or stock.');

    $expect($webhooks->applyStripeEventFixture($successEvent), 'Same-state success replay was not handled.');
    $afterSuccessReplay = $fresh((int) $paymentOrder->id);
    $expect((string) $afterSuccessReplay->mrc_payment_status === MercatoPaymentStatus::PAID && $stock() === 2, 'Success replay duplicated finalization or regressed state.');
    $expect($emailTransport->countEvent('order_confirmation') === 1, 'Success replay duplicated the order confirmation.');
    $duplicateDecrement = $repository->decrementStockOnce($afterSuccessReplay);
    $expect(empty($duplicateDecrement['adjusted']) && $stock() === 2, 'Finalization replay decremented stock twice.');

    $gateway->remoteStates[] = ['status' => MercatoPaymentStatus::PROCESSING, 'amount' => 20, 'refunded_amount' => 0, 'currency' => 'USD', 'reference' => 'remote-processing'];
    $processingAudit = $audit->inspect($afterSuccessReplay, [], true);
    $expect(in_array('missing_webhook', $processingAudit['issues'], true), 'Remote processing/local paid mismatch was not reported.');
    $afterProcessingAudit = $fresh((int) $paymentOrder->id);
    $expect((string) $afterProcessingAudit->mrc_payment_status === MercatoPaymentStatus::PAID && (int) $afterProcessingAudit->mrc_payment_complete === 1 && $stock() === 2, 'Remote verification regressed local paid state.');

    $gateway->remoteStates[] = ['status' => MercatoPaymentStatus::FAILED, 'amount' => 20, 'refunded_amount' => 0, 'currency' => 'USD', 'reference' => 'remote-failed'];
    $failedAudit = $audit->inspect($afterProcessingAudit, [], true);
    $expect(in_array('finalized_unpaid', $failedAudit['issues'], true) && in_array('missing_webhook', $failedAudit['issues'], true), 'Remote failure/local paid mismatch classification changed.');
    $expect((string) $fresh((int) $paymentOrder->id)->mrc_payment_status === MercatoPaymentStatus::PAID && $stock() === 2, 'Remote failed verification mutated local payment state.');

    $gateway->remoteStates[] = ['status' => MercatoPaymentStatus::PAID, 'amount' => 20, 'refunded_amount' => 0, 'currency' => 'USD', 'reference' => 'remote-paid'];
    $replayRepair = $audit->repair($fresh((int) $paymentOrder->id), 'replay_finalization', 'Replay run-owned finalization safely.', $runId);
    $expect(empty($replayRepair['result']['inventory']['adjusted']), 'Reconciliation finalization replay adjusted inventory twice.');
    $expect((string) $fresh((int) $paymentOrder->id)->mrc_payment_status === MercatoPaymentStatus::PAID && $stock() === 2, 'Reconciliation finalization replay changed paid state or stock.');

    $gateway->refundResponses[] = ['id' => 're_' . $runId . '_partial', 'status' => 'succeeded'];
    $partial = $refunds->refund($fresh((int) $paymentOrder->id), 5.00, 'Run-owned confirmed partial refund.', $runId);
    $paymentOrder = $fresh((int) $paymentOrder->id);
    $expect(($partial['status'] ?? '') === MercatoPaymentStatus::PARTIALLY_REFUNDED, 'Confirmed partial refund did not reach partially_refunded.');
    $expect((float) $paymentOrder->mrc_refunded_amount === 5.0 && (float) $paymentOrder->mrc_refund_pending_amount === 0.0, 'Confirmed partial refund totals did not persist.');
    $expect((int) $paymentOrder->mrc_payment_complete === 1 && $stock() === 2, 'Partial refund incorrectly reversed payment finalization or stock.');

    $gateway->refundResponses[] = ['id' => 're_' . $runId . '_full_pending', 'status' => 'pending'];
    $pendingFull = $refunds->refund($paymentOrder, 15.00, 'Run-owned [pending] final refund.', $runId);
    $paymentOrder = $fresh((int) $paymentOrder->id);
    $expect(($pendingFull['status'] ?? '') === MercatoPaymentStatus::REFUND_PENDING, 'Pending remainder did not reach refund_pending.');
    $expect((float) $paymentOrder->mrc_refunded_amount === 5.0 && (float) $paymentOrder->mrc_refund_pending_amount === 15.0, 'Pending full-refund totals did not persist.');
    $expect((int) $paymentOrder->mrc_payment_complete === 1 && $stock() === 2, 'Pending full refund restored inventory before provider confirmation.');
    $expectThrows(fn() => $refunds->refund($paymentOrder, 1.00, 'Blocked overlapping refund.', $runId), 'Overlapping refund was accepted while confirmation was pending.');

    $gateway->refundLookupResponses[] = ['id' => 're_' . $runId . '_full_pending', 'status' => 'pending'];
    $stillPending = $refunds->reconcilePending($fresh((int) $paymentOrder->id), $runId);
    $paymentOrder = $fresh((int) $paymentOrder->id);
    $expect(!empty($stillPending['pending']) && ($stillPending['status'] ?? '') === MercatoPaymentStatus::REFUND_PENDING, 'Pending provider lookup did not preserve refund_pending.');
    $expect((float) $paymentOrder->mrc_refund_pending_amount === 15.0 && $stock() === 2, 'Pending reconciliation changed amount or stock.');

    $gateway->refundLookupResponses[] = ['id' => 're_' . $runId . '_full_pending', 'status' => 'succeeded'];
    $confirmedFull = $refunds->reconcilePending($paymentOrder, $runId);
    $paymentOrder = $fresh((int) $paymentOrder->id);
    $expect(($confirmedFull['status'] ?? '') === MercatoPaymentStatus::REFUNDED, 'Confirmed pending remainder did not reach refunded.');
    $expect((float) $paymentOrder->mrc_refunded_amount === 20.0 && (float) $paymentOrder->mrc_refund_pending_amount === 0.0, 'Full-refund totals did not persist.');
    $expect((int) $paymentOrder->mrc_payment_complete === 0 && (int) $paymentOrder->mrc_inventory_refund_restored === 1, 'Full refund did not persist terminal payment/inventory flags.');
    $expect($stock() === 3, 'Confirmed full refund did not restore stock exactly once.');
    $duplicateRestore = $repository->restoreStockAfterFullRefundOnce($paymentOrder);
    $expect(empty($duplicateRestore['restored']) && $stock() === 3, 'Full-refund replay restored stock twice.');

    $expect(!$webhooks->applyStripeEventFixture($successEvent), 'Delayed success regressed a fully refunded order.');
    $afterRefundedSuccess = $fresh((int) $paymentOrder->id);
    $expect((string) $afterRefundedSuccess->mrc_payment_status === MercatoPaymentStatus::REFUNDED && (float) $afterRefundedSuccess->mrc_refunded_amount === 20.0 && $stock() === 3, 'Delayed success changed full-refund persistence.');

    $gateway->remoteStates[] = ['status' => MercatoPaymentStatus::PAID, 'amount' => 20, 'refunded_amount' => 0, 'currency' => 'USD', 'reference' => 'remote-stale-paid'];
    $refundAudit = $audit->inspect($afterRefundedSuccess, [], true);
    $expect(in_array('missing_webhook', $refundAudit['issues'], true) && in_array('refund_mismatch', $refundAudit['issues'], true), 'Stale remote paid state did not expose refund reconciliation mismatches.');
    $afterRefundAudit = $fresh((int) $paymentOrder->id);
    $expect((string) $afterRefundAudit->mrc_payment_status === MercatoPaymentStatus::REFUNDED && (float) $afterRefundAudit->mrc_refunded_amount === 20.0 && $stock() === 3, 'Refund reconciliation audit regressed terminal local state.');

    $cancelResult = $manualReconciliation->reconcile($fresh((int) $cancelOrder->id), MercatoPaymentStatus::CANCELED, 'Run-owned cancellation finalization.', $runId);
    $cancelOrder = $fresh((int) $cancelOrder->id);
    $expect(($cancelResult['to'] ?? '') === MercatoPaymentStatus::CANCELED && (string) $cancelOrder->mrc_payment_status === MercatoPaymentStatus::CANCELED, 'Cancellation finalization did not persist.');
    $expect((int) $cancelOrder->mrc_payment_complete === 0 && (int) $cancelOrder->mrc_inventory_reserved === 0, 'Cancellation finalization retained completion/reservation state.');
    $expect($stock() === 3 && $repository->getReservedQuantityForProduct((int) $product->id) === 0, 'Cancellation finalization changed stock or retained a reservation.');
    $expectThrows(fn() => $manualReconciliation->reconcile($cancelOrder, MercatoPaymentStatus::CANCELED, 'Run-owned cancellation replay.', $runId), 'Cancellation replay was accepted.', 'already');
    $expect((string) $fresh((int) $cancelOrder->id)->mrc_payment_status === MercatoPaymentStatus::CANCELED && $stock() === 3, 'Rejected cancellation replay changed persisted state.');
    $expect($emailTransport->countEvent('cancellation') === 1, 'Cancellation finalization/replay did not produce exactly one notification.');

    $runLogs = '';
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) $runLogs .= (string) file_get_contents($path);
    $expect(!str_contains($runLogs, strtolower((string) $paymentOrder->mrc_email)), 'Lifecycle audit logs exposed the raw return-request email address.');

    $expect($gateway->completionCalls === 3, 'Lifecycle completion fake saw an unexpected call count.');
    $expect($gateway->refundCalls === 2 && $gateway->refundLookupCalls === 2, 'Lifecycle refund fake saw an unexpected call count.');
    $expect($gateway->remoteStateCalls === 4, 'Lifecycle reconciliation fake saw an unexpected call count.');
    $expect($gateway->completionStatuses === [] && $gateway->refundResponses === [] && $gateway->refundLookupResponses === [] && $gateway->remoteStates === [], 'Lifecycle fake fixtures were not consumed exactly.');

    $orderCount = count($createdOrderIds);
    $productCount = count($createdProductIds);
    $cleanup();
    $pageExists = static function (int $id) use ($wire): bool {
        $statement = $wire->database->prepare('SELECT COUNT(*) FROM pages WHERE id=:id');
        $statement->execute([':id' => $id]);
        return (int) $statement->fetchColumn() > 0;
    };
    foreach ($createdOrderIds as $id) $expect(!$pageExists((int) $id), "Order {$id} remained after cleanup.");
    foreach ($createdProductIds as $id) $expect(!$pageExists((int) $id), "Product {$id} remained after cleanup.");
    $residualLogs = '';
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) $residualLogs .= (string) file_get_contents($path);
    $expect(!str_contains($residualLogs, $runId), 'Run-owned Mercato log rows remained after cleanup.');

    echo "Mercato persisted payment lifecycle integration tests passed: {$checks} assertions, {$orderCount} orders, {$productCount} product; exact cleanup complete.\n";
} finally {
    $cleanup();
}
