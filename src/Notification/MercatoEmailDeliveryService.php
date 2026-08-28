<?php
namespace ProcessWire;

final class MercatoEmailDeliveryService extends Wire {
    private MercatoEmailTransportInterface $transport;
    private string $logName = 'mercato-notifications';

    public function __construct(private Mercato $commerce, ?MercatoEmailTransportInterface $transport = null) {
        parent::__construct();
        $this->transport = $transport ?: new MercatoWireMailTransport();
    }

    public function setWire(ProcessWire $wire) {
        parent::setWire($wire);
        if ($this->transport instanceof Wire) $this->transport->setWire($wire);
    }

    public function preview(string $event, array $values = [], array $overrides = []): array {
        $definition = MercatoEmailEventCatalog::get($event);
        $locale = MercatoEmailTemplateRenderer::normalizeLocale((string) ($overrides['locale'] ?? $this->commerce->notification_locale ?? 'en'));
        $subject = (string) ($overrides['subject'] ?? $definition['subject']);
        $text = (string) ($overrides['text'] ?? $definition['text']);
        $html = (string) ($overrides['html'] ?? '');
        $hasHtmlOverride = false;
        foreach (['text' => 'txt', 'html' => 'html'] as $type => $extension) {
            $override = $this->findOverride($event, $locale, $extension);
            if ($override !== '') { ${$type} = $override; if ($type === 'html') $hasHtmlOverride = true; }
        }
        $savedTemplate = $this->commerce->notificationTemplate($event);
        if (!empty($savedTemplate['customized'])) {
            $subject = (string) $savedTemplate['subject'];
            $text = (string) $savedTemplate['text'];
            $html = (string) $savedTemplate['html'];
            $hasHtmlOverride = $html !== '';
        }
        $layout = $this->commerce->notificationMailLayout();
        $header = (string) ($layout['header'] ?? '');
        $footer = (string) ($layout['footer'] ?? '');
        $values += ['store_name' => (string) ($this->commerce->notification_sender_name ?: 'Mercato Store')];
        $rendered = MercatoEmailTemplateRenderer::render($subject, $text, $html, $values, $header, $footer);
        if ($header === '' && $footer === '' && !$hasHtmlOverride && $html === '') {
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($this->commerce->notification_brand_color ?? '')) ? (string) $this->commerce->notification_brand_color : '#6b4f3a';
            $logo = trim((string) ($this->commerce->notification_logo_url ?? ''));
            $brand = ($logo !== '' && filter_var($logo, FILTER_VALIDATE_URL) && str_starts_with(strtolower($logo), 'https://') ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="" style="max-height:56px;max-width:220px">' : '')
                . '<h1 style="color:' . $color . ';font-size:22px">' . htmlspecialchars((string) $values['store_name'], ENT_QUOTES, 'UTF-8') . '</h1>';
            $rendered['html'] = preg_replace('/(<div\b[^>]*>)/i', '$1' . $brand, (string) $rendered['html'], 1) ?: (string) $rendered['html'];
        }
        return ['event' => $event, 'locale' => $locale] + $rendered;
    }

    public function deliver(string $event, string $recipient, array $values = [], array $context = [], array $overrides = []): array {
        // Push is an independent transactional channel. It must still run when
        // email is disabled or temporarily misconfigured.
        try {
            if ((int) ($context['order_id'] ?? 0) > 0) {
                $order = $this->wire('pages')->get((int) $context['order_id']);
                if ($order instanceof Page && $order->id) $this->commerce->pushNotificationService()->sendOrderEvent($order, $event, (string) ($context['business_event_id'] ?? ''));
            } elseif ((int) ($context['user_id'] ?? 0) > 0) {
                $this->commerce->pushNotificationService()->sendUserEvent((int) $context['user_id'], $event, (string) ($context['business_event_id'] ?? ''));
            }
        } catch (\Throwable $error) {
            $this->wire('log')->error('Mercato push dispatch: ' . $error->getMessage());
        }
        $recipient = (string) $this->wire('sanitizer')->email($recipient);
        $sender = (string) $this->wire('sanitizer')->email((string) $this->commerce->notification_sender_email);
        $enabled = array_values(array_filter(array_map('trim', (array) ($this->commerce->enabled_notification_events ?? MercatoEmailEventCatalog::EVENTS))));
        if (!in_array($event, $enabled, true)) return $this->record($event, 'disabled', $recipient, $context, ['message' => 'Email event is disabled.']);
        if ($recipient === '' || $sender === '') return $this->record($event, 'failed', $recipient, $context, ['message' => $recipient === '' ? 'Recipient email is invalid.' : 'Sender email is invalid.']);

        $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') $idempotencyKey = hash('sha256', implode('|', [$event, (int) ($context['order_id'] ?? 0), (string) ($context['business_event_id'] ?? '')]));
        $lock = $this->acquireDeliveryLock($idempotencyKey);
        if (!is_resource($lock)) return $this->record($event, 'failed', $recipient, $context, ['message' => 'Notification delivery lock could not be acquired.', 'idempotency_key' => $idempotencyKey]);

        try {
            if (empty($context['force']) && $this->wasDelivered($idempotencyKey)) return $this->record($event, 'skipped', $recipient, $context, ['message' => 'Email was already delivered for this business event.', 'idempotency_key' => $idempotencyKey]);

            $rendered = $this->preview($event, $values, $overrides);
            $message = $rendered + ['to' => $recipient, 'from_email' => $sender, 'from_name' => (string) $this->commerce->notification_sender_name, 'reply_to' => (string) $this->commerce->notification_reply_to, 'headers' => (array) ($context['headers'] ?? [])];
            $maxRetries = max(0, min(5, (int) ($this->commerce->notification_retries ?? 2)));
            $last = [];
            for ($retry = 0; $retry <= $maxRetries; $retry++) {
                try { $last = $this->transport->send($message); }
                catch (\Throwable $e) { $last = ['accepted' => false, 'status' => 'failed', 'message' => $e->getMessage()]; }
                $status = !empty($last['accepted']) ? 'sent' : ($retry < $maxRetries ? 'retrying' : 'failed');
                $result = $this->record($event, $status, $recipient, $context, ['message' => (string) ($last['message'] ?? ''), 'idempotency_key' => $idempotencyKey, 'retry_count' => $retry, 'provider' => $this->transport->getName(), 'provider_message_id' => (string) ($last['provider_message_id'] ?? ''), 'provider_status' => (string) ($last['status'] ?? '')]);
                if (!empty($last['accepted'])) return $result + ['rendered' => $rendered];
            }
            return $result + ['rendered' => $rendered];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function getSetupStatus(): array {
        $transport = $this->transport->getSetupStatus();
        $errors = (array) ($transport['errors'] ?? []);
        $configuredTransport = trim((string) ($this->commerce->notification_transport ?? 'wiremail')) ?: 'wiremail';
        if ($configuredTransport !== $this->transport->getName()) $errors[] = 'Configured transactional email transport is not registered: ' . $configuredTransport . '.';
        if ((string) $this->wire('sanitizer')->email((string) $this->commerce->notification_sender_email) === '') $errors[] = 'A valid notification sender email is required.';
        if (trim((string) $this->commerce->notification_sender_name) === '') $errors[] = 'Notification sender name is required.';
        if (!MercatoEmailEventCatalog::EVENTS || !(array) ($this->commerce->enabled_notification_events ?? [])) $errors[] = 'Enable at least one transactional email event.';
        return ['ready' => !$errors && !empty($transport['ready']), 'errors' => array_values(array_unique($errors)), 'transport' => $this->transport->getName(), 'details' => (array) ($transport['details'] ?? [])];
    }

    public function sendOrderEvent(Page $order, string $event, array $eventData = [], bool $force = false): array {
        if (!$order->id || $order->template->name !== $this->commerce->order_template) return ['event' => $event . '_email', 'status' => 'failed', 'message' => 'Order not found.'];
        $recipient = (string) $order->mrc_email;
        $items = json_decode((string) $order->mrc_items, true);
        $itemLines = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $quantity = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
            $itemLines[] = $quantity . ' x ' . trim((string) ($item['title'] ?? $item['name'] ?? 'Product'));
        }
        $values = [
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'customer' => trim((string) $order->mrc_first_name . ' ' . (string) $order->mrc_last_name),
            'items' => implode("\n", $itemLines),
            'total' => $this->commerce->formatPrice($this->commerce->orderRepository()->getTotalAmount($order)),
            'receipt_link' => $this->commerce->getOrderReceiptUrl($order),
            'order_status_link' => $this->commerce->getOrderStatusUrl($order),
            'access_recovery_link' => $this->commerce->getOrderAccessRecoveryUrl($order),
            'payment_link' => $this->commerce->getPaymentLinkUrl($order),
            'policy_links' => $this->commerce->getPolicyLinksText(),
            'tracking' => (string) ($order->mrc_fulfilment_tracking ?? ''),
            'tracking_url' => (string) ($order->mrc_fulfilment_tracking_url ?? ''),
            'fulfilment_details' => $this->commerce->getCustomerFulfilmentDetails($order),
            'reason' => (string) ($eventData['reason'] ?? ''),
            'refund_amount' => $this->commerce->formatPrice((float) ($eventData['amount'] ?? $eventData['refunded_amount'] ?? 0)),
            'refund_status' => (string) ($eventData['status'] ?? $order->mrc_payment_status),
        ] + $eventData;
        $businessId = (string) ($eventData['event_id'] ?? $eventData['id'] ?? $eventData['provider_refund_id'] ?? $eventData['external_refund_id'] ?? $eventData['status'] ?? $order->mrc_payment_status);
        return $this->deliver($event, $recipient, $values, ['order_id' => (int) $order->id, 'invoice' => $values['invoice'], 'business_event_id' => $businessId, 'force' => $force]);
    }

    private function findOverride(string $event, string $locale, string $extension): string {
        $base = rtrim((string) $this->wire('config')->paths->templates, '/') . '/mercato/emails/';
        foreach ([$base . $locale . '/' . $event . '.' . $extension, $base . $event . '.' . $extension] as $path) {
            if (is_file($path) && is_readable($path)) return (string) file_get_contents($path);
        }
        return '';
    }

    private function wasDelivered(string $key): bool {
        $path = rtrim((string) $this->wire('config')->paths->logs, '/') . '/' . $this->logName . '.txt';
        if (!is_readable($path)) return false;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_reverse(array_slice($lines, -5000)) as $line) {
            $json = strstr((string) $line, '{'); $row = $json ? json_decode($json, true) : null;
            if (is_array($row) && hash_equals((string) ($row['idempotency_key'] ?? ''), $key) && (string) ($row['status'] ?? '') === 'sent') return true;
        }
        return false;
    }

    /** @return resource|null */
    private function acquireDeliveryLock(string $key) {
        $bucket = substr(hash('sha256', $key), 0, 2);
        $path = rtrim((string) $this->wire('config')->paths->logs, '/') . '/mercato-notifications-' . $bucket . '.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) return null;
        if (@flock($handle, LOCK_EX)) return $handle;
        fclose($handle);
        return null;
    }

    private function record(string $event, string $status, string $recipient, array $context, array $data): array {
        $at = strrpos($recipient, '@');
        $masked = $at === false ? '' : substr($recipient, 0, 1) . '***' . substr($recipient, $at);
        $payload = ['event' => (string) ($context['log_event'] ?? ($event . '_email')), 'status' => $status, 'order_id' => (int) ($context['order_id'] ?? 0), 'invoice' => (string) ($context['invoice'] ?? ''), 'recipient' => $masked, 'recipient_hash' => $recipient !== '' ? hash('sha256', strtolower($recipient)) : ''] + $data;
        $log = new MercatoEventLog($this->logName); $log->setWire($this->wire());
        return $log->record($payload, $status);
    }
}
