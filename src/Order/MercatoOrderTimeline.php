<?php
namespace ProcessWire;

/**
 * Builds a single order timeline from persisted order state and event logs.
 */
final class MercatoOrderTimeline {

    public static function build(Page $order, array $webhooks, array $inventoryEvents, array $fulfilmentEvents, array $notificationEvents = [], array $paymentEvents = [], array $refundEvents = [], array $orderEditEvents = [], array $orderNoteEvents = [], array $recoveryEvents = [], array $paymentAttemptEvents = []): array {
        $events = [];
        $orderId = (int) $order->id;

        $events[] = self::event(
            date('Y-m-d H:i:s', (int) $order->created),
            'order',
            'created',
            'Order created.',
            'is-pending'
        );

        $paidDate = $order->hasField('mrc_paid_date') ? trim((string) $order->mrc_paid_date) : '';
        if ($paidDate !== '') {
            $events[] = self::event(
                $paidDate,
                'payment',
                'paid',
                'Payment marked complete.',
                'is-paid'
            );
        }

        foreach ($webhooks as $event) {
            if ((int) ($event['order_page_id'] ?? 0) !== $orderId) continue;
            $status = (string) ($event['status'] ?? 'received');
            $details = trim(implode(' / ', array_filter([
                (string) ($event['gateway'] ?? ''),
                (string) ($event['event_type'] ?? ''),
                (string) ($event['message'] ?? ''),
            ])));
            $events[] = self::event(
                (string) ($event['_time'] ?? ''),
                'webhook',
                $status,
                $details,
                $status === 'processed' ? 'is-paid' : ($status === 'failed' ? 'is-failed' : 'is-pending'),
                [
                    'event_id' => (string) ($event['event_id'] ?? ''),
                    'external_payment_id' => (string) ($event['external_payment_id'] ?? ''),
                    'context' => is_array($event['context'] ?? null) ? (array) $event['context'] : [],
                ]
            );
        }

        foreach ($inventoryEvents as $event) {
            if ((int) ($event['order_id'] ?? 0) !== $orderId) continue;
            $eventName = (string) ($event['event'] ?? 'movement');
            $details = trim(implode(' / ', array_filter([
                (string) ($event['title'] ?? ''),
                isset($event['quantity']) ? 'Qty ' . (int) $event['quantity'] : '',
                (string) ($event['note'] ?? ''),
            ])));
            $events[] = self::event(
                (string) ($event['_time'] ?? $event['at'] ?? ''),
                'inventory',
                $eventName,
                $details,
                $eventName === 'sold' ? 'is-paid' : ($eventName === 'expired' ? 'is-failed' : 'is-pending')
            );
        }

        foreach ($fulfilmentEvents as $event) {
            if ((int) ($event['order_id'] ?? 0) !== $orderId) continue;
            $to = (string) ($event['to'] ?? 'unfulfilled');
            $details = trim(implode(' / ', array_filter([
                (string) ($event['from'] ?? 'unfulfilled') . ' -> ' . $to,
                !empty($event['tracking']) ? 'Tracking ' . (string) $event['tracking'] : '',
                !empty($event['tracking_url']) ? (string) $event['tracking_url'] : '',
                (string) ($event['note'] ?? ''),
            ])));
            $events[] = self::event(
                (string) ($event['_time'] ?? ''),
                'fulfilment',
                $to,
                $details,
                in_array($to, [MercatoFulfilmentStatus::FULFILLED, MercatoFulfilmentStatus::SHIPPED, MercatoFulfilmentStatus::DELIVERED], true)
                    ? 'is-paid'
                    : ($to === MercatoFulfilmentStatus::RETURNED ? 'is-failed' : 'is-pending')
            );
        }

        foreach ($notificationEvents as $event) {
            if ((int) ($event['order_id'] ?? 0) !== $orderId) continue;
            $status = (string) ($event['status'] ?? 'failed');
            $eventName = (string) ($event['event'] ?? 'email');
            $discountCode = trim((string) ($event['recovery_discount_code'] ?? ''));
            $events[] = self::event(
                (string) ($event['_time'] ?? ''),
                'email',
                $eventName . '_' . $status,
                trim(implode(' / ', array_filter([
                    (string) ($event['recipient'] ?? ''),
                    $discountCode !== '' ? 'Coupon ' . $discountCode : '',
                    (string) ($event['message'] ?? ''),
                ]))),
                $status === 'sent' ? 'is-paid' : 'is-failed',
                [
                    'recovery_discount_code' => $discountCode,
                ]
            );
        }

        foreach ($recoveryEvents as $event) {
            if ((int) ($event['order_id'] ?? 0) !== $orderId) continue;
            $status = (string) ($event['status'] ?? 'skipped');
            $discountCode = trim((string) ($event['recovery_discount_code'] ?? ''));
            $details = trim(implode(' / ', array_filter([
                (string) ($event['email'] ?? $event['recipient'] ?? ''),
                $discountCode !== '' ? 'Coupon ' . $discountCode : '',
                (string) ($event['message'] ?? ''),
                !empty($event['user']) ? 'By ' . (string) $event['user'] : '',
            ])));
            $events[] = self::event(
                (string) ($event['_time'] ?? ''),
                'recovery',
                'recovery_' . $status,
                $details,
                $status === 'sent' ? 'is-paid' : ($status === 'blocked' || $status === 'failed' ? 'is-failed' : 'is-pending'),
                [
                    'cooldown_minutes' => (int) ($event['cooldown_minutes'] ?? 0),
                    'recovery_discount_code' => $discountCode,
                ]
            );
        }

        foreach ($paymentEvents as $event) {
            if ((int) ($event['order_id'] ?? 0) !== $orderId) continue;
            $to = (string) ($event['to'] ?? 'pending');
            $details = trim(implode(' / ', array_filter([
                (string) ($event['from'] ?? 'pending') . ' -> ' . $to,
                (string) ($event['reason'] ?? ''),
                !empty($event['user']) ? 'By ' . (string) $event['user'] : '',
            ])));
            $events[] = self::event(
                (string) ($event['_time'] ?? ''),
                'payment',
                'manual_' . $to,
                $details,
                $to === MercatoPaymentStatus::PAID ? 'is-paid' : 'is-failed'
            );
        }

        foreach ($paymentAttemptEvents as $event) {
            if ((int) ($event['order_page_id'] ?? 0) !== $orderId) continue;
            $eventName = (string) ($event['event'] ?? 'attempt');
            $status = (string) ($event['status'] ?? 'pending');
            $context = is_array($event['context'] ?? null) ? (array) $event['context'] : [];
            $source = trim((string) ($context['source'] ?? ''));
            $details = trim(implode(' / ', array_filter([
                (string) ($event['gateway'] ?? ''),
                (string) ($event['method'] ?? ''),
                $source !== '' ? 'source: ' . $source : '',
                !empty($event['external_id']) ? 'external: ' . (string) $event['external_id'] : '',
                !empty($event['amount']) ? 'amount: ' . (string) $event['amount'] . ' ' . (string) ($event['currency'] ?? '') : '',
            ])));
            $events[] = self::event(
                (string) ($event['_time'] ?? ''),
                'payment_attempt',
                $eventName,
                $details,
                in_array($eventName, ['completed', 'processing'], true) || in_array($status, [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PROCESSING], true)
                    ? 'is-paid'
                    : (in_array($eventName, ['failed', 'canceled'], true) || MercatoPaymentStatus::isFailureOutcome($status) ? 'is-failed' : 'is-pending'),
                [
                    'attempt_id' => (string) ($event['id'] ?? ''),
                    'idempotency_key' => (string) ($event['idempotency_key'] ?? ''),
                    'context' => $context,
                ]
            );
        }

        foreach ($refundEvents as $event) {
            if ((int) ($event['order_id'] ?? 0) !== $orderId) continue;
            $status = (string) ($event['payment_status'] ?? MercatoPaymentStatus::REFUNDED);
            $details = trim(implode(' / ', array_filter([
                (string) ($event['gateway'] ?? ''),
                !empty($event['amount']) ? 'Amount ' . (string) $event['amount'] : '',
                (string) ($event['reason'] ?? ''),
                !empty($event['refund_id']) ? 'Refund ' . (string) $event['refund_id'] : '',
            ])));
            $events[] = self::event(
                (string) ($event['_time'] ?? ''),
                'payment',
                $status,
                $details,
                $status === MercatoPaymentStatus::REFUNDED ? 'is-failed' : 'is-pending'
            );
        }

        foreach ($orderEditEvents as $event) {
            if ((int) ($event['order_id'] ?? 0) !== $orderId) continue;
            $details = trim(implode(' / ', array_filter([
                (string) ($event['summary'] ?? 'Order edited.'),
                isset($event['from_total'], $event['to_total']) ? 'Total ' . (string) $event['from_total'] . ' -> ' . (string) $event['to_total'] : '',
                !empty($event['user']) ? 'By ' . (string) $event['user'] : '',
            ])));
            $events[] = self::event(
                (string) ($event['_time'] ?? ''),
                'order',
                'edited',
                $details,
                'is-pending',
                [
                    'context' => [
                        'items_before' => $event['items_before'] ?? null,
                        'items_after' => $event['items_after'] ?? null,
                        'shipping_before' => $event['shipping_before'] ?? null,
                        'shipping_after' => $event['shipping_after'] ?? null,
                        'discount_before' => $event['discount_before'] ?? null,
                        'discount_after' => $event['discount_after'] ?? null,
                    ],
                ]
            );
        }

        foreach ($orderNoteEvents as $event) {
            if ((int) ($event['order_id'] ?? 0) !== $orderId) continue;
            $events[] = self::event(
                (string) ($event['_time'] ?? ''),
                'order',
                'note',
                trim(implode(' / ', array_filter([
                    (string) ($event['note'] ?? ''),
                    !empty($event['user']) ? 'By ' . (string) $event['user'] : '',
                ]))),
                'is-pending'
            );
        }

        usort($events, static fn(array $left, array $right): int => $right['timestamp'] <=> $left['timestamp']);
        return $events;
    }

    protected static function event(string $time, string $type, string $label, string $details, string $class, array $meta = []): array {
        return [
            'time' => $time !== '' ? $time : '-',
            'timestamp' => strtotime($time) ?: 0,
            'type' => $type,
            'label' => $label,
            'details' => $details,
            'class' => $class,
            'meta' => $meta,
        ];
    }
}
