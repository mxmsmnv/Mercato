<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') {
    echo "Mercato external delivery boundaries integration test skipped (set MERCATO_TEST_SITE).\n";
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
$super = $wire->users->get('template=user, roles.name=superuser');
$wire->users->setCurrentUser($super);
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
$runId = 'external-boundary-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
$secret = 'sk_live_' . bin2hex(random_bytes(12));
$recipient = $runId . '@example.test';
$privateAddress = '47 Private Boundary Street';
$privateLabelUrl = 'https://carrier.example.invalid/private/' . rawurlencode($secret);
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new \RuntimeException($message);
    $checks++;
};

final class MercatoBoundaryMailInboxTransport implements MercatoEmailTransportInterface {
    public int $calls = 0;
    public int $timeoutsRemaining = 1;
    public array $inbox = [];

    public function __construct(private readonly string $timeoutMarker) {}
    public function getName(): string { return 'boundary-local-inbox'; }
    public function getSetupStatus(): array { return ['ready' => true, 'errors' => [], 'details' => ['mode' => 'local-inbox']]; }
    public function send(array $message): array {
        $this->calls++;
        if ($this->timeoutsRemaining > 0) {
            $this->timeoutsRemaining--;
            throw new WireException('Fixture SMTP timeout ' . $this->timeoutMarker);
        }
        $this->inbox[] = $message;
        return ['accepted' => true, 'status' => 'accepted', 'provider_message_id' => 'inbox-' . $this->calls];
    }
}

final class MercatoBoundaryPushTransport implements MercatoPushTransportInterface {
    public int $calls = 0;
    public int $timeoutsRemaining = 1;
    public array $attempts = [];

    public function __construct(private readonly string $timeoutMarker) {}
    public function send(string $deviceToken, array $payload, array $context = []): array {
        $this->calls++;
        $this->attempts[] = ['token' => $deviceToken, 'payload' => $payload, 'context' => $context];
        if ($this->timeoutsRemaining > 0) {
            $this->timeoutsRemaining--;
            throw new WireException('Fixture APNs timeout ' . $this->timeoutMarker);
        }
        return ['accepted' => true, 'status' => 'sent', 'provider_message_id' => 'push-' . $this->calls];
    }
    public function getName(): string { return 'boundary-push-sink'; }
    public function getSetupStatus(): array { return ['ready' => true, 'errors' => [], 'details' => ['mode' => 'local-sink']]; }
}

final class MercatoBoundaryCarrier implements MercatoShippingProviderInterface {
    public int $createCalls = 0;
    public int $labelCalls = 0;
    public array $createContexts = [];
    public array $labelContexts = [];

    public function __construct(
        private readonly string $key,
        private readonly string $webhookSecret,
        private readonly string $privateLabelUrl,
    ) {}
    public function getShippingProviderKey(): string { return $this->key; }
    public function quoteRates(array $context): array { return []; }
    public function createShipment(array $context): array {
        $this->createCalls++;
        $this->createContexts[] = $context;
        if ($this->createCalls === 1) throw new WireException('Fixture carrier create timeout.');
        return ['status' => 'created', 'shipment_reference' => 'shipment-' . $context['order']['id']];
    }
    public function purchaseLabel(array $context): array {
        $this->labelCalls++;
        $this->labelContexts[] = $context;
        if ($this->labelCalls === 1) throw new WireException('Fixture carrier label timeout.');
        return [
            'status' => 'purchased',
            'shipment_reference' => (string) $context['shipment_reference'],
            'label_reference' => 'label-' . $context['order']['id'],
            'tracking' => 'BOUNDARY' . $context['order']['id'],
            'tracking_url' => 'https://carrier.example.invalid/track/' . $context['order']['id'],
            'label_url' => $this->privateLabelUrl,
            'label_data' => 'private-document-bytes',
        ];
    }
    public function getLabel(array $context): array { return ['status' => 'available', 'label_url' => $this->privateLabelUrl]; }
    public function track(array $context): array { return ['status' => 'in_transit']; }
    public function voidShipment(array $context): array { return ['status' => 'voided']; }
    public function refundLabel(array $context): array { return ['status' => 'refunded']; }
    public function verifyTrackingWebhook(string $payload, array $headers): bool {
        return hash_equals(hash_hmac('sha256', $payload, $this->webhookSecret), (string) ($headers['x-mercato-signature'] ?? ''));
    }
    public function parseTrackingWebhook(string $payload, array $headers): array {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}

final class MercatoBoundaryAnalyticsSink implements MercatoAnalyticsAdapterInterface {
    public array $events = [];
    public function getAnalyticsAdapterKey(): string { return 'boundary-sink'; }
    public function getConsentCategory(): string { return 'analytics'; }
    public function dispatch(array $event): array {
        $this->events[] = $event;
        return ['accepted' => true, 'external_id' => 'sink-' . count($this->events)];
    }
}

final class MercatoBoundaryAnalyticsTimeout implements MercatoAnalyticsAdapterInterface {
    public int $calls = 0;
    public function getAnalyticsAdapterKey(): string { return 'boundary-timeout'; }
    public function getConsentCategory(): string { return 'analytics'; }
    public function dispatch(array $event): array {
        $this->calls++;
        throw new WireException('Fixture analytics sink timeout.');
    }
}

$runtimeKeys = [
    'notification_sender_name', 'notification_sender_email', 'notification_reply_to',
    'notification_retries', 'enabled_notification_events',
    'push_notifications_enabled', 'apns_environment', 'apns_bundle_id',
    'shipping_provider', 'shipping_provider_retries', 'shipping_provider_failure_policy',
    'shipping_provider_webhook_secret', 'analytics_enabled', 'analytics_adapters',
    'analytics_default_consent', 'analytics_order_identifier',
];
$originalRuntime = [];
foreach ($runtimeKeys as $key) $originalRuntime[$key] = $commerce->get($key);
$originalConsent = $wire->session->get('mrc_analytics_consent');
$logDirectory = rtrim((string) $wire->config->paths->logs, '/');
$logOffsets = [];
foreach (glob($logDirectory . '/*.txt') ?: [] as $path) $logOffsets[$path] = (int) filesize($path);
$logNames = ['mercato-notifications', 'mercato-shipping-provider', 'mercato-analytics'];
$order = null;
$pushRegistration = '';
$mailLock = '';
$mailLockExisted = false;
$pdfDirectory = rtrim((string) $wire->config->paths->cache, '/') . '/MercatoExternalBoundary/';
$pdfDirectoryExisted = is_dir($pdfDirectory);
$pdfPath = $pdfDirectory . $runId . '.pdf';
$cleanupDone = false;

$removeRunLogLines = static function (string $path, string $needle): void {
    if (!is_file($path)) return;
    $lines = file($path) ?: [];
    $kept = array_values(array_filter($lines, static fn(string $line): bool => !str_contains($line, $needle)));
    if ($kept === []) @unlink($path);
    elseif (count($kept) !== count($lines)) file_put_contents($path, implode('', $kept), LOCK_EX);
};

$cleanup = static function () use (
    &$cleanupDone,
    $wire,
    $commerce,
    $originalRuntime,
    $originalConsent,
    &$order,
    &$pushRegistration,
    &$mailLock,
    &$mailLockExisted,
    $pdfPath,
    $pdfDirectory,
    $pdfDirectoryExisted,
    $logDirectory,
    $logNames,
    $removeRunLogLines,
    $runId,
): void {
    if ($cleanupDone) return;
    foreach ($originalRuntime as $key => $value) $commerce->set($key, $value);
    if ($originalConsent === null) $wire->session->remove('mrc_analytics_consent');
    else $wire->session->set('mrc_analytics_consent', $originalConsent);
    if ($pushRegistration !== '') {
        $delete = $wire->database->prepare('DELETE FROM mercato_push_deliveries WHERE registration_id=:id');
        $delete->execute([':id' => $pushRegistration]);
        $delete = $wire->database->prepare('DELETE FROM mercato_push_devices WHERE registration_id=:id');
        $delete->execute([':id' => $pushRegistration]);
    }
    if ($order instanceof Page && $order->id) {
        $fresh = $wire->pages->get((int) $order->id);
        if ($fresh->id) $wire->pages->delete($fresh, true);
    }
    if ($mailLock !== '' && !$mailLockExisted && is_file($mailLock)) @unlink($mailLock);
    if (is_file($pdfPath)) @unlink($pdfPath);
    if (!$pdfDirectoryExisted && is_dir($pdfDirectory)) @rmdir($pdfDirectory);
    foreach ($logNames as $name) $removeRunLogLines($logDirectory . '/' . $name . '.txt', $runId);
    $cleanupDone = true;
};
register_shutdown_function($cleanup);

try {
    $items = [[
        'id' => $runId, 'uid' => $runId, 'product_id' => 999991,
        'title' => 'External boundary product', 'sku' => 'EXT-BOUNDARY',
        'price' => 37.5, 'quantity' => 1, 'tax_rate' => 0,
        'product_type' => 'physical', 'stock_policy' => 'allow',
    ]];
    $carrierKey = 'boundary-' . substr(hash('sha256', $runId), 0, 12);
    $fulfilment = [
        'type' => MercatoFulfilmentMethodType::CARRIER_DELIVERY,
        'selection_key' => 'live:' . $carrierKey . ':standard',
        'label' => 'Boundary carrier', 'amount' => 5, 'available' => true,
        'shipping_provider_quote' => [
            'provider' => $carrierKey,
            'rate' => ['id' => 'standard', 'service' => 'standard', 'label' => 'Boundary Standard', 'amount' => 5, 'currency' => 'USD'],
            'input_snapshot' => ['packages' => [['weight_kg' => 1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'quantity' => 1]]],
        ],
    ];
    $order = $commerce->orderRepository()->savePendingOrder([
        'first_name' => 'Private', 'last_name' => 'Boundary', 'email' => $recipient,
        'address' => $privateAddress, 'city' => 'Test City', 'zip' => '10001', 'country' => 'US',
        'payment_method' => 'demo', 'payment_status' => MercatoPaymentStatus::PAID, 'payment_complete' => 1,
        'mrc_items' => json_encode($items, JSON_THROW_ON_ERROR),
        'mrc_subtotal_amount' => 37.5, 'mrc_shipping_amount' => 5, 'mrc_total_amount' => 42.5, 'mrc_currency' => 'USD',
        'fulfilment_method' => MercatoFulfilmentMethodType::CARRIER_DELIVERY,
        'mrc_fulfilment_label' => 'Boundary carrier', 'mrc_fulfilment_details' => json_encode($fulfilment, JSON_THROW_ON_ERROR),
        'mrc_shipping_address' => json_encode(['address' => $privateAddress, 'city' => 'Test City', 'zip' => '10001', 'country' => 'US'], JSON_THROW_ON_ERROR),
        'mrc_fulfilment_status' => MercatoFulfilmentStatus::UNFULFILLED,
    ]);
    $order = $wire->pages->getById((int) $order->id, ['cache' => false])->first();
    $expect($order instanceof Page && $order->id > 0, 'Run-owned external-boundary order was not persisted.');

    $mailTransport = new MercatoBoundaryMailInboxTransport($secret);
    $mailService = new MercatoEmailDeliveryService($commerce, $mailTransport);
    $mailService->setWire($wire);
    $commerce->set('notification_sender_name', 'Boundary Store');
    $commerce->set('notification_sender_email', 'sender@example.test');
    $commerce->set('notification_reply_to', 'reply@example.test');
    $commerce->set('notification_retries', 1);
    $commerce->set('enabled_notification_events', ['payment_failed']);
    $mailKey = 'mail-' . $runId;
    $mailLock = $logDirectory . '/mercato-notifications-' . substr(hash('sha256', $mailKey), 0, 2) . '.lock';
    $mailLockExisted = is_file($mailLock);
    $mail = $mailService->deliver('payment_failed', $recipient, [
        'invoice' => (string) $order->mrc_invoice_number,
        'customer' => 'Private Boundary', 'reason' => 'Declined',
        'payment_link' => 'https://mercato.test/pay?token=' . rawurlencode($secret),
        'order_status_link' => 'https://mercato.test/status/' . rawurlencode($runId),
    ], ['idempotency_key' => $mailKey, 'business_event_id' => $runId, 'headers' => ['X-Mercato-Test' => $runId]]);
    $mailReplay = $mailService->deliver('payment_failed', $recipient, [], ['idempotency_key' => $mailKey]);
    $expect(($mail['status'] ?? '') === 'sent' && ($mail['retry_count'] ?? -1) === 1, 'Mail transport timeout was not retried exactly once.');
    $expect($mailTransport->calls === 2 && count($mailTransport->inbox) === 1 && ($mailReplay['status'] ?? '') === 'skipped', 'Local mail inbox did not enforce exact-once delivery.');
    $inboxMessage = $mailTransport->inbox[0];
    $expect(($inboxMessage['to'] ?? '') === $recipient && ($inboxMessage['from_email'] ?? '') === 'sender@example.test', 'Mail envelope lost the intended local recipient or sender.');
    $expect(str_contains((string) ($inboxMessage['text'] ?? ''), 'token=') && str_contains((string) ($inboxMessage['html'] ?? ''), 'token='), 'Mail inbox lost the signed recovery link in text or HTML.');
    $expect(($inboxMessage['headers']['X-Mercato-Test'] ?? '') === $runId, 'Mail transport lost bounded custom headers.');

    $pushTransport = new MercatoBoundaryPushTransport($secret);
    $pushService = new MercatoPushNotificationService($commerce, $pushTransport);
    $pushService->setWire($wire);
    $pushService->ensureSchema();
    $commerce->set('push_notifications_enabled', true);
    $commerce->set('apns_environment', 'sandbox');
    $commerce->set('apns_bundle_id', '');
    $deviceToken = hash('sha256', 'push-' . $secret . '-' . $runId);
    $registered = $pushService->register([
        'token' => $deviceToken, 'installation_id' => 'fixture-' . $runId,
        'environment' => 'sandbox', 'bundle_id' => 'org.smnv.mercato.boundary',
        'locale' => 'en', 'topics' => ['payment_updates'],
    ], 'order', (int) $order->id);
    $pushRegistration = (string) ($registered['id'] ?? '');
    $pushFailed = $pushService->sendOrderEvent($order, 'payment_failed', 'push-' . $runId);
    $pushSent = $pushService->sendOrderEvent($order, 'payment_failed', 'push-' . $runId);
    $pushReplay = $pushService->sendOrderEvent($order, 'payment_failed', 'push-' . $runId);
    $expect(($pushFailed['failed'] ?? 0) === 1 && ($pushSent['sent'] ?? 0) === 1 && ($pushReplay['sent'] ?? -1) === 0, 'Push timeout/retry/replay sequence was not exact-once.');
    $expect($pushTransport->calls === 2 && count($pushTransport->attempts) === 2, 'Push transport received an unexpected number of attempts.');
    $pushPublic = json_encode(array_column($pushTransport->attempts, 'payload'), JSON_THROW_ON_ERROR);
    $pushContext = json_encode(array_column($pushTransport->attempts, 'context'), JSON_THROW_ON_ERROR);
    $expect(!str_contains($pushPublic, $recipient) && !str_contains($pushPublic, $privateAddress) && !str_contains($pushPublic, $secret), 'Push payload leaked customer PII or a secret.');
    $expect(!str_contains($pushContext, $deviceToken) && !str_contains($pushContext, $recipient), 'Push transport context leaked a token or customer PII.');
    $pushRows = $wire->database->prepare('SELECT d.token_hash,d.token_cipher,v.status,v.provider_message_id FROM mercato_push_devices d LEFT JOIN mercato_push_deliveries v ON v.registration_id=d.registration_id WHERE d.registration_id=:id');
    $pushRows->execute([':id' => $pushRegistration]);
    $pushStored = json_encode($pushRows->fetchAll(\PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
    $expect(!str_contains($pushStored, $deviceToken) && !str_contains($pushStored, $recipient) && str_contains($pushStored, 'push-2'), 'Persisted push delivery state leaked raw identity/token data or lost the provider result.');
    $commerce->set('push_notifications_enabled', false);

    $carrier = new MercatoBoundaryCarrier($carrierKey, $secret, $privateLabelUrl);
    $commerce->addHookAfter('shippingProviders', static function (HookEvent $event) use ($carrier, $carrierKey): void {
        $providers = is_array($event->return) ? $event->return : [];
        $providers[$carrierKey] = $carrier;
        $event->return = $providers;
    });
    $commerce->set('shipping_provider', $carrierKey);
    $commerce->set('shipping_provider_retries', 1);
    $commerce->set('shipping_provider_failure_policy', 'fail_closed');
    $commerce->set('shipping_provider_webhook_secret', $secret);
    $label = $commerce->shippingProviderService()->purchaseLabel($order);
    $expect($carrier->createCalls === 2 && $carrier->labelCalls === 2, 'Carrier shipment and label transient failures were not retried exactly once.');
    $expect(($label['status'] ?? '') === 'purchased' && ($label['label_url'] ?? '') === $privateLabelUrl, 'Carrier label result was not persisted from the local adapter.');
    $carrierContext = json_encode([$carrier->createContexts, $carrier->labelContexts], JSON_THROW_ON_ERROR);
    $expect(str_contains($carrierContext, $privateAddress) && str_contains($carrierContext, 'shipping_create_'), 'Carrier adapter did not receive the required address or stable idempotency context.');
    $storedFulfilment = json_decode((string) $wire->pages->getById((int) $order->id, ['cache' => false])->first()->mrc_fulfilment_details, true);
    $redactedFulfilment = $commerce->shippingProviderService()->redactSnapshot((array) $storedFulfilment);
    $expect(
        ($redactedFulfilment['provider_shipping']['label']['label_url'] ?? '') === '[redacted]'
        && ($redactedFulfilment['provider_shipping']['label']['label_data'] ?? '') === '[redacted]',
        'Carrier label URL or private document bytes were not redacted from the export snapshot.'
    );

    $payload = json_encode(['event_id' => 'tracking-' . $runId, 'order_id' => (int) $order->id, 'status' => 'in_transit', 'tracking' => 'TRACK-' . $runId], JSON_THROW_ON_ERROR);
    $invalidSignature = false;
    try {
        $commerce->shippingProviderService()->processTrackingWebhook($carrierKey, $payload, ['x-mercato-signature' => 'invalid']);
    } catch (WireException $error) {
        $invalidSignature = $error->getCode() === 401;
    }
    $expect($invalidSignature, 'Carrier tracking webhook accepted an invalid signature.');
    $signature = hash_hmac('sha256', $payload, $secret);
    $tracking = $commerce->shippingProviderService()->processTrackingWebhook($carrierKey, $payload, ['x-mercato-signature' => $signature]);
    $trackingReplay = $commerce->shippingProviderService()->processTrackingWebhook($carrierKey, $payload, ['x-mercato-signature' => $signature]);
    $expect(empty($tracking['duplicate']) && !empty($trackingReplay['duplicate']), 'Carrier tracking webhook replay was not idempotent.');

    $analyticsSink = new MercatoBoundaryAnalyticsSink();
    $analyticsTimeout = new MercatoBoundaryAnalyticsTimeout();
    $commerce->addHookAfter('analyticsAdapters', static function (HookEvent $event) use ($analyticsSink, $analyticsTimeout): void {
        $event->return = ['boundary-timeout' => $analyticsTimeout, 'boundary-sink' => $analyticsSink];
    });
    $commerce->set('analytics_enabled', true);
    $commerce->set('analytics_adapters', ['boundary-timeout', 'boundary-sink']);
    $commerce->set('analytics_default_consent', 'denied');
    $commerce->set('analytics_order_identifier', 'hash');
    $wire->session->set('mrc_analytics_consent', ['essential' => true, 'analytics' => true, 'marketing' => false, 'updated_at' => date(DATE_ATOM)]);
    $analytics = $commerce->analyticsService()->track('search', [
        'event_id' => 'analytics-' . $runId, 'query_length' => 7, 'result_count' => 3,
        'email' => $recipient, 'shipping_address' => $privateAddress, 'provider_secret' => $secret,
    ]);
    $expect(($analytics['status'] ?? '') === 'processed' && empty($analytics['adapters']['boundary-timeout']['accepted']) && !empty($analytics['adapters']['boundary-sink']['accepted']), 'Analytics timeout interrupted the independent local sink.');
    $expect($analyticsTimeout->calls === 1 && count($analyticsSink->events) === 1, 'Analytics adapters did not receive exactly one attempt each.');
    $sinkPayload = json_encode($analyticsSink->events, JSON_THROW_ON_ERROR);
    $expect(!str_contains($sinkPayload, $recipient) && !str_contains($sinkPayload, $privateAddress) && !str_contains($sinkPayload, $secret), 'Analytics sink payload leaked PII or a secret.');

    if (!is_dir($pdfDirectory) && !$wire->files->mkdir($pdfDirectory, true)) throw new \RuntimeException('Could not create run-owned PDF directory.');
    $pdf = MercatoReceiptPdfRenderer::render([
        'invoice' => (string) $order->mrc_invoice_number,
        'date' => date('F j, Y'), 'date_short' => date('M j, Y'), 'payment_status' => 'Paid',
        'total' => '$ 42.50', 'customer_name' => 'Private Boundary', 'customer_email' => $recipient,
        'fulfilment_label' => 'Boundary carrier', 'billing_address' => ['Private Boundary', $privateAddress],
        'shipping_address' => [$privateAddress],
        'items' => [['title' => 'External boundary product', 'sku' => 'EXT-BOUNDARY', 'quantity' => 1, 'amount' => '$ 37.50']],
        'summary' => [['label' => 'Subtotal', 'value' => '$ 37.50'], ['label' => 'Total paid', 'value' => '$ 42.50', 'total' => true]],
    ], ['brand_name' => 'Boundary Store', 'primary' => '#173A37', 'accent' => '#2E898E']);
    $expect(str_starts_with($pdf, '%PDF-1.4') && !str_contains($pdf, $secret), 'Generated local receipt PDF is invalid or contains an integration secret.');
    $written = file_put_contents($pdfPath, $pdf, LOCK_EX);
    $expect($written === strlen($pdf) && hash_file('sha256', $pdfPath) === hash('sha256', $pdf), 'Run-owned local PDF did not round-trip byte-exactly.');
    $expect(str_starts_with(realpath($pdfPath) ?: '', realpath($pdfDirectory) ?: '/nonexistent'), 'Generated PDF escaped its run-owned local storage directory.');

    $appendedLogs = '';
    foreach (glob($logDirectory . '/*.txt') ?: [] as $path) {
        $offset = (int) ($logOffsets[$path] ?? 0);
        if (filesize($path) <= $offset) continue;
        $handle = fopen($path, 'rb');
        if (!$handle) continue;
        fseek($handle, $offset);
        $appendedLogs .= (string) stream_get_contents($handle);
        fclose($handle);
    }
    foreach ([$secret, $recipient, $privateAddress, $privateLabelUrl, $deviceToken] as $forbidden) {
        $expect(!str_contains($appendedLogs, $forbidden), 'External-boundary logs leaked a secret, token, private label URL, or customer PII.');
    }

    $orderId = (int) $order->id;
    $registrationId = $pushRegistration;
    $cleanup();
    foreach ($originalRuntime as $key => $value) $expect($commerce->get($key) === $value, "Runtime setting {$key} was not restored exactly.");
    $residualOrder = $wire->database->prepare('SELECT COUNT(*) FROM pages WHERE id=:id');
    $residualOrder->execute([':id' => $orderId]);
    $expect((int) $residualOrder->fetchColumn() === 0, 'Run-owned external-boundary order remained after cleanup.');
    $residualPush = $wire->database->prepare('SELECT (SELECT COUNT(*) FROM mercato_push_devices WHERE registration_id=:device_id) + (SELECT COUNT(*) FROM mercato_push_deliveries WHERE registration_id=:delivery_id)');
    $residualPush->execute([':device_id' => $registrationId, ':delivery_id' => $registrationId]);
    $expect((int) $residualPush->fetchColumn() === 0, 'Run-owned push device or delivery rows remained after cleanup.');
    $expect(!is_file($pdfPath) && ($mailLockExisted || !is_file($mailLock)), 'Run-owned PDF or notification lock remained after cleanup.');
    foreach ($logNames as $name) {
        $path = $logDirectory . '/' . $name . '.txt';
        $expect(!is_file($path) || !str_contains((string) file_get_contents($path), $runId), "Run-owned {$name} log rows remained after cleanup.");
    }
} finally {
    $cleanup();
}

echo "Mercato external delivery boundaries integration tests passed: {$checks} assertions; exact file/DB/config/log cleanup complete.\n";
