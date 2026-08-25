<?php
namespace ProcessWire;

/**
 * Sends a payable checkout link for an unpaid order.
 */
final class MercatoPaymentLinkService extends Wire {

    protected string $logName = 'mercato-notifications';

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
    }

    public function send(Page $order): array {
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return ['status' => 'failed', 'message' => 'Order not found.'];
        }

        $status = strtolower(trim((string) $order->mrc_payment_status));
        if ((int) $order->mrc_payment_complete === 1 || $status === MercatoPaymentStatus::PAID) {
            return $this->record($order, 'failed', 'Payment link was not sent because the order is already paid.');
        }

        $recipient = (string) $this->wire('sanitizer')->email((string) $order->mrc_email);
        $sender = (string) $this->wire('sanitizer')->email((string) $this->commerce->notification_sender_email);
        if ($recipient === '') {
            return $this->record($order, 'failed', 'Order has no valid customer email.');
        }
        if ($sender === '') {
            return $this->record($order, 'failed', 'Notification sender email is not configured.', $recipient);
        }

        $url = $this->commerce->getPaymentLinkUrl($order);
        $recoveryDiscountCode = strtoupper(trim((string) ($this->commerce->recovery_discount_code ?? '')));
        $recoveryDiscountCode = substr(preg_replace('/[^A-Z0-9_-]+/', '', $recoveryDiscountCode) ?: '', 0, 64);
        $recoveryDiscountLine = $recoveryDiscountCode !== ''
            ? 'Use coupon code ' . $recoveryDiscountCode . ' at checkout.'
            : '';
        $values = [
            '{invoice}' => (string) ($order->mrc_invoice_number ?: $order->title),
            '{customer}' => trim((string) $order->mrc_first_name . ' ' . (string) $order->mrc_last_name),
            '{total}' => $this->commerce->formatPrice($this->commerce->orderRepository()->getTotalAmount($order)),
            '{payment_link}' => $url,
            '{recovery_discount_code}' => $recoveryDiscountCode,
            '{recovery_discount_line}' => $recoveryDiscountLine,
            '{recovery_unsubscribe_link}' => $this->commerce->getRecoveryUnsubscribeUrl($recipient),
        ];
        $subjectTemplate = trim((string) $this->commerce->payment_link_email_subject) ?: 'Payment link for order {invoice}';
        $bodyTemplate = trim((string) $this->commerce->payment_link_email_body)
            ?: "Hello {customer},\n\nYou can pay order {invoice} for {total} here:\n{payment_link}\n\n{recovery_discount_line}\n\nTo stop recovery payment-link reminders, use this link:\n{recovery_unsubscribe_link}\n\nThank you.";
        $subject = strtr($subjectTemplate, $values);
        $body = strtr($bodyTemplate, $values);
        if ($recoveryDiscountLine !== ''
            && !str_contains($bodyTemplate, '{recovery_discount_code}')
            && !str_contains($bodyTemplate, '{recovery_discount_line}')) {
            $body .= "\n\n" . $recoveryDiscountLine;
        }
        if (!str_contains($bodyTemplate, '{recovery_unsubscribe_link}')) {
            $body .= "\n\nTo stop recovery payment-link reminders, use this link:\n" . $values['{recovery_unsubscribe_link}'];
        }

        try {
            $result = $this->commerce->notificationDeliveryService()->deliver('payment_recovery', $recipient, $values, [
                'order_id' => (int) $order->id,
                'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
                'business_event_id' => 'payment_link|' . (string) $order->mrc_payment_status,
                'log_event' => 'payment_link_email',
                'headers' => ['List-Unsubscribe' => '<' . $values['{recovery_unsubscribe_link}'] . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
            ], ['subject' => $subject, 'text' => $body]);
            if (($result['status'] ?? '') === 'sent') $result['message'] = 'Payment link sent.';
            return $result;
        } catch (\Throwable $e) {
            return $this->record($order, 'failed', $e->getMessage(), $recipient);
        }
    }

    protected function record(Page $order, string $status, string $message, string $recipient = ''): array {
        $payload = [
            'event' => 'payment_link_email',
            'status' => $status,
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'recipient' => $recipient,
            'message' => $message,
            'recovery_discount_code' => $this->getRecoveryDiscountCode(),
        ];
        $log = new MercatoEventLog($this->logName);
        $log->setWire($this->wire());
        return $log->record($payload, $status);
    }

    protected function getRecoveryDiscountCode(): string {
        $code = strtoupper(trim((string) ($this->commerce->recovery_discount_code ?? '')));
        return substr(preg_replace('/[^A-Z0-9_-]+/', '', $code) ?: '', 0, 64);
    }
}
