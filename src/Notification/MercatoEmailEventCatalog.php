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

    public static function metadata(): array {
        return [
            'order_confirmation' => ['label' => 'Order confirmation', 'recipient' => 'Customer', 'purpose' => 'Confirms payment and gives the customer receipt and order-status links.'],
            'payment_failed' => ['label' => 'Payment failed', 'recipient' => 'Customer', 'purpose' => 'Explains that payment was not completed and provides a secure recovery route.'],
            'payment_recovery' => ['label' => 'Payment recovery', 'recipient' => 'Customer', 'purpose' => 'Reminds a customer to complete an eligible unpaid order.'],
            'refund' => ['label' => 'Refund update', 'recipient' => 'Customer', 'purpose' => 'Confirms a full or partial refund state recorded against an order.'],
            'cancellation' => ['label' => 'Order cancellation', 'recipient' => 'Customer', 'purpose' => 'Confirms that an order was canceled and provides the reason when available.'],
            'shipment_tracking' => ['label' => 'Shipment tracking', 'recipient' => 'Customer', 'purpose' => 'Shares shipment tracking details and the signed order-status route.'],
            'pickup_ready' => ['label' => 'Pickup ready', 'recipient' => 'Customer', 'purpose' => 'Tells a customer that an order is ready at the selected pickup location.'],
            'local_delivery' => ['label' => 'Local delivery', 'recipient' => 'Customer', 'purpose' => 'Shares the current local-delivery update and fulfilment instructions.'],
            'account_created' => ['label' => 'Account created', 'recipient' => 'Customer', 'purpose' => 'Welcomes a customer and links to the new account.'],
            'account_security' => ['label' => 'Account security', 'recipient' => 'Customer', 'purpose' => 'Sends an account-security notice without exposing credentials.'],
        ];
    }

    public static function variables(string $event): array {
        $definition = self::get($event);
        preg_match_all('/\{([a-z_]+)\}/', (string) $definition['subject'] . "\n" . (string) $definition['text'], $matches);
        $available = match ($event) {
            'order_confirmation' => ['store_name', 'invoice', 'customer', 'items', 'subtotal', 'shipping', 'fulfilment', 'fulfilment_details', 'discount', 'total', 'currency', 'receipt_link', 'order_status_link', 'policy_links'],
            'payment_failed' => ['store_name', 'invoice', 'customer', 'total', 'reason', 'payment_link', 'order_status_link'],
            'payment_recovery' => ['store_name', 'invoice', 'customer', 'total', 'payment_link', 'recovery_discount_code', 'recovery_discount_line', 'recovery_unsubscribe_link'],
            'refund' => ['store_name', 'invoice', 'customer', 'refund_amount', 'refund_status', 'order_status_link'],
            'cancellation' => ['store_name', 'invoice', 'customer', 'reason', 'order_status_link'],
            'shipment_tracking' => ['store_name', 'invoice', 'customer', 'tracking', 'tracking_url', 'order_status_link'],
            'pickup_ready', 'local_delivery' => ['store_name', 'invoice', 'customer', 'fulfilment_details', 'order_status_link'],
            'account_created' => ['store_name', 'customer', 'account_link'],
            'account_security' => ['store_name', 'customer', 'security_message', 'account_link'],
        };
        return array_values(array_unique(array_merge($available, array_map('strval', (array) ($matches[1] ?? [])))));
    }

    public static function samples(): array {
        return [
            'store_name' => 'Example Store',
            'invoice' => 'MRC-00123',
            'customer' => 'Alex Customer',
            'items' => "1 × Example course\n1 × Reference guide",
            'subtotal' => '$ 149.00',
            'shipping' => '$ 0.00',
            'fulfilment' => 'Digital access',
            'fulfilment_details' => 'Access instructions will be sent separately.',
            'discount' => '$ 0.00',
            'total' => '$ 149.00',
            'currency' => 'USD',
            'receipt_link' => 'https://store.example/receipt?signed=preview',
            'order_status_link' => 'https://store.example/status?signed=preview',
            'payment_link' => 'https://store.example/pay?signed=preview',
            'policy_links' => 'Privacy policy · Terms of service',
            'reason' => 'The payment provider declined the attempt.',
            'refund_amount' => '$ 25.00',
            'refund_status' => 'Partially refunded',
            'tracking' => 'TRACK123',
            'tracking_url' => 'https://carrier.example/TRACK123',
            'recovery_discount_code' => 'RETURN10',
            'recovery_discount_line' => 'Use RETURN10 before checkout.',
            'recovery_unsubscribe_link' => 'https://store.example/unsubscribe?signed=preview',
            'account_link' => 'https://store.example/account',
            'security_message' => 'Your password was changed.',
        ];
    }
}
