<?php
namespace ProcessWire;
$site = getenv('MERCATO_TEST_SITE');
if (!$site) { echo "Mercato email delivery integration test skipped (set MERCATO_TEST_SITE).\n"; exit(0); }
$_SERVER['HTTP_HOST'] = 'mercato.dev'; $_SERVER['SERVER_NAME'] = 'mercato.dev'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php'; $config = ProcessWire::buildConfig($site); $config->dbHost = 'localhost'; $wire = new ProcessWire($config); $wire->users->setCurrentUser($wire->users->get('template=user, roles.name=superuser')); /** @var Mercato $commerce */ $commerce = $wire->modules->get('Mercato');

final class EmailRetryFixture implements MercatoEmailTransportInterface {
    public int $calls = 0;
    public function getName(): string { return 'retry-fixture'; }
    public function getSetupStatus(): array { return ['ready' => true, 'errors' => []]; }
    public function send(array $message): array { $this->calls++; if ($this->calls === 1) throw new WireException('Transient provider timeout containing sk_live_must_redact.'); return ['accepted' => true, 'status' => 'accepted', 'provider_message_id' => 'message-fixture']; }
}
final class EmailWebhookFixture implements MercatoEmailWebhookAdapterInterface {
    public function getName(): string { return 'email-webhook-fixture'; }
    public function verifyAndParse(string $payload, array $headers): array { if (($headers['signature'] ?? '') !== 'valid') throw new WireException('Invalid fixture signature.', 401); return [['event_id' => $payload, 'type' => 'complaint', 'provider_message_id' => 'message-fixture', 'recipient_hash' => hash('sha256', 'hidden@example.test')]]; }
}

$transport = new EmailRetryFixture(); $service = new MercatoEmailDeliveryService($commerce, $transport); $service->setWire($wire);
$commerce->notification_sender_name = 'Fixture Store'; $commerce->notification_sender_email = 'sender@example.test'; $commerce->notification_reply_to = 'reply@example.test'; $commerce->notification_retries = 2; $commerce->notification_brand_color = '#123456'; $commerce->enabled_notification_events = MercatoEmailEventCatalog::EVENTS;
$overrideDir = $wire->config->paths->templates . 'mercato/emails/zz'; if (!is_dir($overrideDir)) mkdir($overrideDir, 0775, true); $overrideFile = $overrideDir . '/payment_failed.txt'; file_put_contents($overrideFile, 'Localized {invoice} for {customer}');
$localized = $service->preview('payment_failed', ['invoice' => 'MRC-LOCAL', 'customer' => 'Localized Customer'], ['locale' => 'zz']); unlink($overrideFile); @rmdir($overrideDir);
if (($localized['text'] ?? '') !== 'Localized MRC-LOCAL for Localized Customer' || !str_contains((string) ($localized['html'] ?? ''), '#123456')) throw new \RuntimeException('Localized override or branding render failed.');
$key = 'email-integration-' . bin2hex(random_bytes(8));
$recipient = 'email-' . bin2hex(random_bytes(6)) . '@example.test';
$first = $service->deliver('payment_failed', $recipient, ['invoice' => 'MRC-TEST', 'customer' => 'Customer', 'reason' => 'Declined', 'payment_link' => 'https://mercato.dev/pay?token=signed', 'order_status_link' => 'https://mercato.dev/status?token=signed'], ['idempotency_key' => $key, 'business_event_id' => $key]);
if (($first['status'] ?? '') !== 'sent' || $transport->calls !== 2 || ($first['retry_count'] ?? -1) !== 1 || ($first['provider_message_id'] ?? '') !== 'message-fixture') throw new \RuntimeException('Transport retry/audit flow failed.');
$duplicate = $service->deliver('payment_failed', $recipient, [], ['idempotency_key' => $key]);
if (($duplicate['status'] ?? '') !== 'skipped' || $transport->calls !== 2) throw new \RuntimeException('Duplicate email event was not idempotent.');
$log = file_get_contents($wire->config->paths->logs . 'mercato-notifications.txt');
if (str_contains((string) $log, 'sk_live_must_redact') || str_contains((string) $log, $recipient)) throw new \RuntimeException('Email delivery log leaked a secret or recipient address.');
$adapter = new EmailWebhookFixture();
$commerce->addHookAfter('emailWebhookAdapters', static function (HookEvent $event) use ($adapter): void { $adapters = is_array($event->return) ? $event->return : []; $adapters[$adapter->getName()] = $adapter; $event->return = $adapters; });
$webhookId = 'complaint-' . bin2hex(random_bytes(8)); $webhookFirst = $commerce->emailWebhookService()->process($adapter->getName(), $webhookId, ['signature' => 'valid']); $webhookDuplicate = $commerce->emailWebhookService()->process($adapter->getName(), $webhookId, ['signature' => 'valid']);
if ($webhookFirst['processed'] !== 1 || $webhookDuplicate['duplicates'] !== 1) throw new \RuntimeException('Bounce/complaint webhook idempotency failed.');
echo "Mercato email delivery integration tests passed.\n";
