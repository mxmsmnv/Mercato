<?php
namespace ProcessWire;

/** Provider-neutral live parcel, label, and tracking contract. */
interface MercatoShippingProviderInterface {
    public function getShippingProviderKey(): string;
    public function quoteRates(array $context): array;
    public function createShipment(array $context): array;
    public function purchaseLabel(array $context): array;
    public function getLabel(array $context): array;
    public function track(array $context): array;
    public function voidShipment(array $context): array;
    public function refundLabel(array $context): array;
    public function verifyTrackingWebhook(string $payload, array $headers): bool;
    public function parseTrackingWebhook(string $payload, array $headers): array;
}
