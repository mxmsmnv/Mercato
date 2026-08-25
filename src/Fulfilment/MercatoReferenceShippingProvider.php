<?php
namespace ProcessWire;

/** Credential-free reference adapter for development, fixtures, and adapter authors. */
final class MercatoReferenceShippingProvider implements MercatoShippingProviderInterface {
    public function __construct(protected Mercato $commerce) {}
    public function getShippingProviderKey(): string { return 'reference'; }
    public function quoteRates(array $context): array {
        if (empty($context['destination']['country']) || empty($context['destination']['postal_code'])) throw new WireException('Reference carrier requires a country and postal code.');
        $weight = array_sum(array_map(static fn(array $package): float => (float) ($package['weight_kg'] ?? 0), (array) $context['packages']));
        $base = round(4.5 + max(0, $weight) * 1.25, 2);
        $expires = date(DATE_ATOM, time() + (int) $context['quote_ttl_seconds']);
        return [
            ['id' => 'reference-standard', 'service' => 'standard', 'label' => 'Reference Standard', 'amount' => $base, 'currency' => $context['currency'], 'delivery_days_min' => 3, 'delivery_days_max' => 5, 'expires_at' => $expires, 'provider_reference' => 'ref-rate-standard'],
            ['id' => 'reference-express', 'service' => 'express', 'label' => 'Reference Express', 'amount' => round($base + 7.5, 2), 'currency' => $context['currency'], 'delivery_days_min' => 1, 'delivery_days_max' => 2, 'expires_at' => $expires, 'provider_reference' => 'ref-rate-express'],
        ];
    }
    public function createShipment(array $context): array { return ['status' => 'created', 'shipment_reference' => 'ref-shipment-' . $context['order']['id']]; }
    public function purchaseLabel(array $context): array { return ['status' => 'purchased', 'shipment_reference' => (string) ($context['shipment_reference'] ?? ''), 'label_reference' => 'ref-label-' . $context['order']['id'], 'tracking' => 'REF' . str_pad((string) $context['order']['id'], 10, '0', STR_PAD_LEFT), 'tracking_url' => 'https://example.invalid/track/' . $context['order']['id'], 'label_url' => 'https://example.invalid/private-label/' . $context['order']['id']]; }
    public function getLabel(array $context): array { return ['status' => 'available', 'label_reference' => (string) ($context['label_reference'] ?? ''), 'label_url' => 'https://example.invalid/private-label/' . $context['order']['id']]; }
    public function track(array $context): array { return ['status' => 'in_transit', 'tracking' => (string) ($context['tracking'] ?? '')]; }
    public function voidShipment(array $context): array { return ['status' => 'voided', 'shipment_reference' => (string) ($context['shipment_reference'] ?? '')]; }
    public function refundLabel(array $context): array { return ['status' => 'refunded', 'label_reference' => (string) ($context['label_reference'] ?? '')]; }
    public function verifyTrackingWebhook(string $payload, array $headers): bool { $secret = trim((string) ($this->commerce->shipping_provider_webhook_secret ?? '')); return $secret !== '' && hash_equals(hash_hmac('sha256', $payload, $secret), (string) ($headers['x-mercato-signature'] ?? '')); }
    public function parseTrackingWebhook(string $payload, array $headers): array { $data = json_decode($payload, true); return is_array($data) ? $data : []; }
}
