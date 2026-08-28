<?php
namespace ProcessWire;

/**
 * Customer order confirmation email for a paid order.
 */
final class MercatoOrderConfirmationService extends Wire {

    protected string $logName = 'mercato-notifications';

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
    }

    public function send(Page $order, bool $resend = false): array {
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return ['status' => 'failed', 'message' => 'Order not found.'];
        }
        if (!$order->hasField('mrc_confirmation_sent_date') || !$order->hasField('mrc_confirmation_send_count')) {
            return $this->record($order, 'failed', 'Confirmation fields are missing. Run Mercato installer/repair.');
        }
        $status = strtolower(trim((string) $order->mrc_payment_status));
        if ($status !== MercatoPaymentStatus::PAID && $status !== MercatoPaymentStatus::PARTIALLY_REFUNDED) {
            return $this->record($order, 'failed', 'Only a paid order can receive a confirmation email.');
        }
        if (!$resend && trim((string) $order->mrc_confirmation_sent_date) !== '') {
            return [
                'event' => 'order_confirmation_email',
                'status' => 'skipped',
                'order_id' => (int) $order->id,
                'message' => 'Order confirmation was already sent.',
            ];
        }

        $recipient = (string) $this->wire('sanitizer')->email((string) $order->mrc_email);
        $sender = (string) $this->wire('sanitizer')->email((string) $this->commerce->notification_sender_email);
        if ($recipient === '') {
            return $this->record($order, 'failed', 'Order has no valid customer email.');
        }
        if ($sender === '') {
            return $this->record($order, 'failed', 'Notification sender email is not configured.', $recipient);
        }

        $values = $this->getTemplateValues($order);
        $subjectTemplate = trim((string) $this->commerce->confirmation_email_subject) ?: 'Order confirmation {invoice}';
        $bodyTemplate = trim((string) $this->commerce->confirmation_email_body)
            ?: "Hello {customer},\n\nThank you for your order {invoice}.\n\n{items}\n\n{fulfilment}: {shipping}\n{fulfilment_details}\nTotal: {total}\n\nReceipt:\n{receipt_link}\n\nYou can check order status here:\n{order_status_link}\n\nAccess recovery:\n{access_recovery_link}\n\nWe will send the next fulfilment update.\n\n{policy_links}";

        try {
            $result = $this->commerce->notificationDeliveryService()->deliver('order_confirmation', $recipient, $values, [
                'order_id' => (int) $order->id,
                'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
                'business_event_id' => 'paid',
                'force' => $resend,
            ], ['subject' => $subjectTemplate, 'text' => $bodyTemplate]);
            if (($result['status'] ?? '') !== 'sent') return $result;
            $order->of(false);
            $order->mrc_confirmation_sent_date = date('Y-m-d H:i:s');
            $order->mrc_confirmation_send_count = (int) $order->mrc_confirmation_send_count + 1;
            $this->wire('pages')->save($order);

            $result['message'] = $resend ? 'Order confirmation resent.' : 'Order confirmation sent.';
            return $result;
        } catch (\Throwable $e) {
            return $this->record($order, 'failed', $e->getMessage(), $recipient);
        }
    }

    protected function getTemplateValues(Page $order): array {
        $items = json_decode((string) $order->mrc_items, true);
        $lines = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $quantity = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
            $title = trim((string) ($item['title'] ?? $item['name'] ?? 'Product'));
            if (trim((string) ($item['variant_label'] ?? '')) !== '') $title .= ' (' . trim((string) $item['variant_label']) . ')';
            $price = (float) ($item['price'] ?? 0) * $quantity;
            $lines[] = $quantity . ' x ' . $title . ' - ' . $this->commerce->formatPrice($price);
        }

        $fulfilment = $order->hasField('mrc_fulfilment_label') && trim((string) $order->mrc_fulfilment_label) !== ''
            ? (string) $order->mrc_fulfilment_label
            : 'Shipping';
        $fulfilmentDetails = $this->commerce->getCustomerFulfilmentDetails($order);

        return [
            '{invoice}' => (string) ($order->mrc_invoice_number ?: $order->title),
            '{customer}' => trim((string) $order->mrc_first_name . ' ' . (string) $order->mrc_last_name),
            '{items}' => implode("\n", $lines),
            '{subtotal}' => $this->formatStoredAmount($order, 'mrc_subtotal_amount'),
            '{shipping}' => $this->formatStoredAmount($order, 'mrc_shipping_amount'),
            '{fulfilment}' => $fulfilment,
            '{fulfilment_details}' => $fulfilmentDetails,
            '{discount}' => $this->formatStoredAmount($order, 'mrc_discount_total'),
            '{total}' => $this->commerce->formatPrice($this->commerce->orderRepository()->getTotalAmount($order)),
            '{currency}' => (string) ($order->mrc_currency ?: $this->commerce->currency),
            '{receipt_link}' => $this->commerce->getOrderReceiptUrl($order),
            '{order_status_link}' => $this->commerce->getOrderStatusUrl($order),
            '{access_recovery_link}' => $this->commerce->getOrderAccessRecoveryUrl($order),
            '{policy_links}' => $this->commerce->getPolicyLinksText(),
        ];
    }

    protected function formatStoredAmount(Page $order, string $fieldName): string {
        return $order->hasField($fieldName) ? $this->commerce->formatPrice((float) $order->get($fieldName)) : '';
    }

    protected function record(Page $order, string $status, string $message, string $recipient = ''): array {
        $payload = [
            'event' => 'order_confirmation_email',
            'status' => $status,
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'recipient' => $recipient,
            'message' => $message,
        ];
        $log = new MercatoEventLog($this->logName);
        $log->setWire($this->wire());
        return $log->record($payload, $status);
    }
}
