<?php
namespace ProcessWire;

final class MercatoPrivacyRetentionPolicy {
    public static function isOlderThan(int $created, int $days, int $now): bool {
        return $days > 0 && $created > 0 && $created <= $now - ($days * 86400);
    }

    public static function orderBlockers(array $order): array {
        $blockers = [];
        if (!empty($order['legal_hold'])) $blockers[] = 'legal_hold';
        if (in_array(strtolower((string) ($order['payment_status'] ?? '')), ['refund_pending', 'partial_refund_pending', 'processing', 'requires_action'], true)) $blockers[] = 'active_payment_or_refund';
        if (!empty($order['dispute_open'])) $blockers[] = 'active_dispute';
        if (!empty($order['inventory_reserved'])) $blockers[] = 'active_inventory_reservation';
        $fulfilment = strtolower((string) ($order['fulfilment_status'] ?? ''));
        if (!empty($order['paid']) && !in_array($fulfilment, ['', 'fulfilled', 'delivered', 'collected', 'returned'], true)) $blockers[] = 'active_fulfilment';
        return array_values(array_unique($blockers));
    }
}
