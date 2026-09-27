<?php
namespace ProcessWire;

final class MercatoPaymentReconciliationAudit {
    public static function classify(array $local, array $remote = [], array $attempts = []): array {
        $issues = [];
        $localStatus = strtolower((string) ($local['status'] ?? MercatoPaymentStatus::PENDING));
        $remoteStatus = strtolower((string) ($remote['status'] ?? 'unknown'));
        $localPaid = !empty($local['paid']) || in_array($localStatus, [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUNDED], true);
        $remotePaid = in_array($remoteStatus, [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUNDED], true);
        $finalized = !empty($local['inventory_adjusted']) || !empty($local['confirmation_sent']) || !empty($local['tax_committed']);
        if (($remotePaid && !$localPaid) || ($localPaid && !empty($local['requires_inventory']) && empty($local['inventory_adjusted']))) $issues[] = 'paid_unfinalized';
        if ($finalized && !$localPaid) $issues[] = 'finalized_unpaid';
        if ($localPaid && in_array($remoteStatus, [MercatoPaymentStatus::FAILED, MercatoPaymentStatus::CANCELED], true)) $issues[] = 'finalized_unpaid';
        if ($remoteStatus !== 'unknown' && $remoteStatus !== $localStatus && ($remotePaid || $localPaid)) $issues[] = 'missing_webhook';
        $keys = []; $external = [];
        foreach ($attempts as $attempt) {
            $key = trim((string) ($attempt['idempotency_key'] ?? '')); $id = trim((string) ($attempt['external_id'] ?? ''));
            if ($key !== '' && $id !== '') $keys[$key][$id] = true;
            if ($id !== '' && in_array((string) ($attempt['status'] ?? ''), [MercatoPaymentStatus::PAID, 'succeeded', 'completed'], true)) $external[$id] = ($external[$id] ?? 0) + 1;
        }
        if (count(array_filter($keys, static fn(array $ids): bool => count($ids) > 1)) > 0 || count($external) > 1) $issues[] = 'duplicate_attempt';
        $localRefundRaw = round((float) ($local['refunded_amount'] ?? 0), 2); $remoteRefundRaw = isset($remote['refunded_amount']) ? round((float) $remote['refunded_amount'], 2) : null; $totalRaw = round((float) ($local['total'] ?? 0), 2);
        $invalidAmounts = !is_finite($localRefundRaw) || !is_finite($totalRaw) || $localRefundRaw < 0 || $totalRaw < 0
            || ($remoteRefundRaw !== null && (!is_finite($remoteRefundRaw) || $remoteRefundRaw < 0));
        // Keep the public audit payload JSON-safe even when corrupt provider or
        // stored data contains NAN/INF; the mismatch issue preserves the signal.
        $localRefund = is_finite($localRefundRaw) ? $localRefundRaw : 0.0;
        $remoteRefund = $remoteRefundRaw === null ? null : (is_finite($remoteRefundRaw) ? $remoteRefundRaw : null);
        $total = is_finite($totalRaw) ? $totalRaw : 0.0;
        if ($invalidAmounts || $localRefund > $total || ($remoteRefund !== null && abs($remoteRefund - $localRefund) >= 0.01) || ($localStatus === MercatoPaymentStatus::REFUNDED && abs($localRefund - $total) >= 0.01)) $issues[] = 'refund_mismatch';
        $issues = array_values(array_unique($issues));
        return ['healthy' => !$issues, 'issues' => $issues, 'local_status' => $localStatus, 'remote_status' => $remoteStatus, 'local_refunded' => $localRefund, 'remote_refunded' => $remoteRefund];
    }
}
