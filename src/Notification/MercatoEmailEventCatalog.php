<?php
namespace ProcessWire;

final class MercatoEmailEventCatalog {
    public const EVENTS = [
        'order_confirmation', 'payment_failed', 'payment_recovery', 'refund', 'cancellation',
        'shipment_tracking', 'pickup_ready', 'local_delivery', 'account_created', 'account_security',
    ];

    public static function all(): array {
        return [
            'order_confirmation' => ['subject' => 'Order confirmation {invoice}', 'text' => "Hello {customer},\n\nThank you for your order {invoice}.\n\n{items}\n\nTotal: {total}\n\nReceipt: {receipt_link}\nOrder status: {order_status_link}\n\n{policy_links}"],
            'payment_failed' => ['subject' => 'Payment issue for order {invoice}', 'text' => "Hello {customer},\n\nWe could not complete payment for order {invoice}.\n{reason}\n\nTry again: {payment_link}\nOrder status: {order_status_link}"],
            'payment_recovery' => ['subject' => 'Complete payment for order {invoice}', 'text' => "Hello {customer},\n\nComplete payment for order {invoice}: {payment_link}\n\n{recovery_discount_line}\nUnsubscribe: {recovery_unsubscribe_link}"],
            'refund' => ['subject' => 'Refund update for order {invoice}', 'text' => "Hello {customer},\n\nA refund of {refund_amount} was recorded for order {invoice}.\nRefund status: {refund_status}\n\nOrder status: {order_status_link}"],
            'cancellation' => ['subject' => 'Order {invoice} canceled', 'text' => "Hello {customer},\n\nOrder {invoice} has been canceled.\n{reason}\n\nOrder status: {order_status_link}"],
            'shipment_tracking' => ['subject' => 'Your order {invoice} has shipped', 'text' => "Hello {customer},\n\nYour order {invoice} has shipped.\nTracking: {tracking}\n{tracking_url}\n\nOrder status: {order_status_link}"],
            'pickup_ready' => ['subject' => 'Your order {invoice} is ready for pickup', 'text' => "Hello {customer},\n\nYour order {invoice} is ready for pickup.\n\n{fulfilment_details}\n\nOrder status: {order_status_link}"],
            'local_delivery' => ['subject' => 'Your order {invoice} is out for delivery', 'text' => "Hello {customer},\n\nYour order {invoice} is out for local delivery.\n\n{fulfilment_details}\n\nOrder status: {order_status_link}"],
            'account_created' => ['subject' => 'Welcome to {store_name}', 'text' => "Hello {customer},\n\nYour account at {store_name} is ready.\nAccount: {account_link}"],
            'account_security' => ['subject' => 'Account security notice from {store_name}', 'text' => "Hello {customer},\n\n{security_message}\n\nReview your account: {account_link}"],
        ];
    }

    public static function get(string $event): array {
        $all = self::all();
        if (!isset($all[$event])) throw new \InvalidArgumentException('Unsupported email event: ' . $event);
        return $all[$event];
    }
}
