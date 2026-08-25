<?php
namespace ProcessWire;

/**
 * Manual customer notifications for operational fulfilment milestones.
 *
 * Class name is retained for compatibility with the original shipping-only API.
 */
final class MercatoShippingNotificationService extends Wire {

    protected string $logName = 'mercato-notifications';

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
    }

    public function sendShippingNotification(Page $order): array {
        return $this->sendNotification(
            $order,
            'shipping_email',
            trim((string) $this->commerce->shipping_email_subject) ?: 'Your order {invoice} has shipped',
            trim((string) $this->commerce->shipping_email_body) ?: "Hello {customer},\n\nYour order {invoice} has shipped.\nTracking: {tracking}\n{tracking_url}\n\nOrder status:\n{order_status_link}\n\nThank you.\n\n{policy_links}",
            'Shipping notification sent.'
        );
    }

    public function previewShippingNotification(Page $order): array {
        return $this->buildNotificationPreview(
            $order,
            'shipping_email',
            trim((string) $this->commerce->shipping_email_subject) ?: 'Your order {invoice} has shipped',
            trim((string) $this->commerce->shipping_email_body) ?: "Hello {customer},\n\nYour order {invoice} has shipped.\nTracking: {tracking}\n{tracking_url}\n\nOrder status:\n{order_status_link}\n\nThank you.\n\n{policy_links}"
        );
    }

    public function sendPickupReadyNotification(Page $order): array {
        return $this->sendNotification(
            $order,
            'pickup_ready_email',
            trim((string) $this->commerce->pickup_ready_email_subject) ?: 'Your order {invoice} is ready for pickup',
            trim((string) $this->commerce->pickup_ready_email_body) ?: "Hello {customer},\n\nYour order {invoice} is ready for pickup.\n\n{fulfilment_details}\n\nOrder status:\n{order_status_link}\n\nThank you.\n\n{policy_links}",
            'Pickup-ready notification sent.'
        );
    }

    public function previewPickupReadyNotification(Page $order): array {
        return $this->buildNotificationPreview(
            $order,
            'pickup_ready_email',
            trim((string) $this->commerce->pickup_ready_email_subject) ?: 'Your order {invoice} is ready for pickup',
            trim((string) $this->commerce->pickup_ready_email_body) ?: "Hello {customer},\n\nYour order {invoice} is ready for pickup.\n\n{fulfilment_details}\n\nOrder status:\n{order_status_link}\n\nThank you.\n\n{policy_links}"
        );
    }

    public function sendLocalDeliveryNotification(Page $order): array {
        return $this->sendNotification(
            $order,
            'local_delivery_email',
            trim((string) $this->commerce->local_delivery_email_subject) ?: 'Your order {invoice} is out for delivery',
            trim((string) $this->commerce->local_delivery_email_body) ?: "Hello {customer},\n\nYour order {invoice} is out for local delivery.\n\n{fulfilment_details}\n\nOrder status:\n{order_status_link}\n\nThank you.\n\n{policy_links}",
            'Local-delivery notification sent.'
        );
    }

    public function previewLocalDeliveryNotification(Page $order): array {
        return $this->buildNotificationPreview(
            $order,
            'local_delivery_email',
            trim((string) $this->commerce->local_delivery_email_subject) ?: 'Your order {invoice} is out for delivery',
            trim((string) $this->commerce->local_delivery_email_body) ?: "Hello {customer},\n\nYour order {invoice} is out for local delivery.\n\n{fulfilment_details}\n\nOrder status:\n{order_status_link}\n\nThank you.\n\n{policy_links}"
        );
    }

    protected function sendNotification(Page $order, string $event, string $subjectTemplate, string $bodyTemplate, string $successMessage): array {
        $recipient = (string) $this->wire('sanitizer')->email((string) $order->mrc_email);
        $sender = (string) $this->wire('sanitizer')->email((string) $this->commerce->notification_sender_email);
        if ($recipient === '') {
            return $this->record($order, $event, 'failed', 'Order has no valid customer email.');
        }
        if ($sender === '') {
            return $this->record($order, $event, 'failed', 'Notification sender email is not configured.');
        }

        $preview = $this->buildNotificationPreview($order, $event, $subjectTemplate, $bodyTemplate);
        $subject = (string) $preview['subject'];
        $body = (string) $preview['body'];

        try {
            $emailEvent = match ($event) {
                'shipping_email' => 'shipment_tracking',
                'pickup_ready_email' => 'pickup_ready',
                'local_delivery_email' => 'local_delivery',
                default => throw new WireException('Unsupported fulfilment email event.'),
            };
            $result = $this->commerce->notificationDeliveryService()->deliver($emailEvent, $recipient, (array) $preview['values'], [
                'order_id' => (int) $order->id,
                'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
                'business_event_id' => $event . '|' . (string) ($order->mrc_fulfilment_tracking ?? '') . '|' . (string) ($order->mrc_fulfilment_status ?? ''),
                'log_event' => $event,
            ], ['subject' => $subjectTemplate, 'text' => $bodyTemplate]);
            if (($result['status'] ?? '') === 'sent') $result['message'] = $successMessage;
            return $result;
        } catch (\Throwable $e) {
            return $this->record($order, $event, 'failed', $e->getMessage(), $recipient);
        }
    }

    protected function buildNotificationPreview(Page $order, string $event, string $subjectTemplate, string $bodyTemplate): array {
        $values = [
            '{invoice}' => (string) ($order->mrc_invoice_number ?: $order->title),
            '{customer}' => trim((string) $order->mrc_first_name . ' ' . (string) $order->mrc_last_name),
            '{tracking}' => $order->hasField('mrc_fulfilment_tracking') ? (string) $order->mrc_fulfilment_tracking : '',
            '{tracking_url}' => $order->hasField('mrc_fulfilment_tracking_url') ? (string) $order->mrc_fulfilment_tracking_url : '',
            '{fulfilment}' => $order->hasField('mrc_fulfilment_label') ? (string) $order->mrc_fulfilment_label : '',
            '{fulfilment_details}' => $this->commerce->getCustomerFulfilmentDetails($order),
            '{order_status_link}' => $this->commerce->getOrderStatusUrl($order),
            '{policy_links}' => $this->commerce->getPolicyLinksText(),
        ];

        return [
            'event' => $event,
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'recipient' => (string) $this->wire('sanitizer')->email((string) $order->mrc_email),
            'subject' => strtr($subjectTemplate, $values),
            'body' => strtr($bodyTemplate, $values),
            'values' => $values,
        ];
    }

    protected function record(Page $order, string $event, string $status, string $message, string $recipient = ''): array {
        $payload = [
            'event' => $event,
            'status' => $status,
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'recipient' => $recipient,
            'message' => $message,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->wire('log')->save($this->logName, $encoded ?: $status);
        return $payload;
    }
}
