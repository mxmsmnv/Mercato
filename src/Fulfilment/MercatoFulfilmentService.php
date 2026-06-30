<?php
namespace ProcessWire;

/**
 * Resolves the selected customer fulfilment method into an immutable order
 * snapshot before a gateway receives the payable total.
 */
final class MercatoFulfilmentService extends Wire {

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
    }

    /**
     * @return array<int, array{type:string,label:string,amount:float,details:string,available:bool}>
     */
    public function getCheckoutMethods(MercatoProductList $cart, array $customerData = []): array {
        $methods = [];
        $enabled = $this->commerce->getEnabledFulfilmentMethods();
        $default = method_exists($this->commerce, 'getDefaultFulfilmentMethod')
            ? $this->commerce->getDefaultFulfilmentMethod()
            : ($enabled[0] ?? MercatoFulfilmentMethodType::CARRIER_DELIVERY);
        if (in_array($default, $enabled, true)) {
            $enabled = array_values(array_unique(array_merge([$default], $enabled)));
        }
        foreach ($enabled as $type) {
            $methods[] = $this->buildMethod($type, $cart, $customerData, false);
        }
        return $methods;
    }

    /**
     * @return array{type:string,label:string,amount:float,details:string,available:bool}
     */
    public function resolveSelection(string $method, MercatoProductList $cart, array $customerData = []): array {
        $method = trim($method);
        if ($method === '') {
            $method = method_exists($this->commerce, 'getDefaultFulfilmentMethod')
                ? $this->commerce->getDefaultFulfilmentMethod()
                : MercatoFulfilmentMethodType::CARRIER_DELIVERY;
        }
        if (!in_array($method, $this->commerce->getEnabledFulfilmentMethods(), true)) {
            throw new WireException($this->commerce->_('The selected fulfilment method is not available.'));
        }

        return $this->buildMethod($method, $cart, $customerData, true);
    }

    protected function buildMethod(string $type, MercatoProductList $cart, array $customerData, bool $validate): array {
        $hook = $this->commerce->beforeCalculateShipping($type, $cart, $customerData, $validate);
        if (is_array($hook)) {
            $type = trim((string) ($hook['type'] ?? $type)) ?: $type;
            $customerData = is_array($hook['customer_data'] ?? null) ? $hook['customer_data'] : $customerData;
            $validate = array_key_exists('validate', $hook) ? (bool) $hook['validate'] : $validate;
        }

        if ($type === MercatoFulfilmentMethodType::STORE_PICKUP) {
            $locations = method_exists($this->commerce, 'getStorePickupLocations') ? $this->commerce->getStorePickupLocations() : [];
            $locationKey = trim((string) ($customerData['pickup_location'] ?? ''));
            $location = $locations[$locationKey] ?? reset($locations) ?: [];
            $details = trim(implode("\n", array_filter([
                trim((string) ($location['label'] ?? '')),
                trim((string) ($location['address'] ?? $this->commerce->store_pickup_address ?? '')),
                trim((string) ($location['instructions'] ?? $this->commerce->store_pickup_instructions ?? '')),
                trim((string) ($location['hours'] ?? '')),
            ])));
            return $this->finalizeMethod([
                'type' => $type,
                'label' => trim((string) ($this->commerce->store_pickup_label ?? '')) ?: $this->commerce->_('Store pickup'),
                'amount' => 0.0,
                'details' => $details,
                'available' => true,
                'pickup_location_key' => (string) ($location['key'] ?? $locationKey),
                'pickup_location' => (string) ($location['label'] ?? ''),
                'pickup_address' => (string) ($location['address'] ?? $this->commerce->store_pickup_address ?? ''),
                'pickup_instructions' => (string) ($location['instructions'] ?? $this->commerce->store_pickup_instructions ?? ''),
                'pickup_hours' => (string) ($location['hours'] ?? ''),
                'pickup_locations' => $locations,
            ], $cart, $customerData, $validate);
        }

        if ($type === MercatoFulfilmentMethodType::LOCAL_DELIVERY) {
            if ($validate) {
                $this->assertDeliveryAddress($customerData);
                $this->assertDeliveryCountryAllowed($customerData);
            }
            $postcode = strtoupper(trim((string) ($customerData['zip'] ?? '')));
            $zones = preg_split('/[\r\n,]+/', strtoupper((string) ($this->commerce->local_delivery_postcodes ?? ''))) ?: [];
            $zones = array_values(array_filter(array_map('trim', $zones)));
            $matchedZone = $this->matchLocalDeliveryZone($postcode, $zones);
            $available = !$zones || $matchedZone !== '';
            if ($validate && !$available) {
                throw new WireException($this->commerce->_('Local delivery is not available for this postal code.'));
            }
            $minimumOrder = $this->commerce->getLocalDeliveryMinimumOrder();
            $subtotal = round((float) $cart->getSubtotal(), 2);
            $meetsMinimum = $minimumOrder <= 0 || $subtotal >= $minimumOrder;
            if ($validate && !$meetsMinimum) {
                throw new WireException(sprintf(
                    $this->commerce->_('Local delivery requires a minimum order of %s.'),
                    $this->commerce->formatPrice($minimumOrder)
                ));
            }
            $details = trim((string) ($this->commerce->local_delivery_instructions ?? ''));
            if ($minimumOrder > 0) {
                $details = trim($details . "\n" . sprintf(
                    $this->commerce->_('Minimum order: %s.'),
                    $this->commerce->formatPrice($minimumOrder)
                ));
            }
            return $this->finalizeMethod([
                'type' => $type,
                'label' => trim((string) ($this->commerce->local_delivery_label ?? '')) ?: $this->commerce->_('Local delivery'),
                'amount' => round(max(0, (float) ($this->commerce->local_delivery_fee ?? 0)), 2),
                'details' => $details,
                'available' => $available && $meetsMinimum,
                'minimum_order' => $minimumOrder,
                'local_delivery_zone' => $matchedZone,
            ], $cart, $customerData, $validate);
        }

        if ($validate) {
            $this->assertDeliveryAddress($customerData);
            $this->assertDeliveryCountryAllowed($customerData);
        }
        $originalAmount = round(max(0, (float) $cart->getShipping()), 2);
        $threshold = $this->commerce->getFreeShippingThreshold();
        $subtotal = round((float) $cart->getSubtotal(), 2);
        $freeShippingApplied = $threshold > 0 && $subtotal >= $threshold;
        $amount = $freeShippingApplied ? 0.0 : $originalAmount;
        $details = $freeShippingApplied
            ? sprintf(
                $this->commerce->_('Free shipping applied. Cart subtotal reached %s.'),
                $this->commerce->formatPrice($threshold)
            )
            : '';

        return $this->finalizeMethod([
            'type' => MercatoFulfilmentMethodType::CARRIER_DELIVERY,
            'label' => trim((string) ($this->commerce->carrier_delivery_label ?? '')) ?: $this->commerce->_('Delivery'),
            'amount' => $amount,
            'details' => $details,
            'available' => true,
            'original_amount' => $originalAmount,
            'free_shipping_threshold' => $threshold,
            'free_shipping_applied' => $freeShippingApplied,
        ], $cart, $customerData, $validate);
    }

    protected function matchLocalDeliveryZone(string $postcode, array $zones): string {
        if ($postcode === '' || !$zones) {
            return '';
        }

        $matches = [];
        foreach ($zones as $zone) {
            $zone = trim((string) $zone);
            if ($zone !== '' && str_starts_with($postcode, $zone)) {
                $matches[] = $zone;
            }
        }
        usort($matches, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $matches[0] ?? '';
    }

    protected function finalizeMethod(array $method, MercatoProductList $cart, array $customerData, bool $validate): array {
        $hooked = $this->commerce->afterCalculateShipping($method, $cart, $customerData, $validate);
        if (is_array($hooked)) {
            $method = $hooked;
        }
        $method['type'] = trim((string) ($method['type'] ?? MercatoFulfilmentMethodType::CARRIER_DELIVERY)) ?: MercatoFulfilmentMethodType::CARRIER_DELIVERY;
        $method['label'] = trim((string) ($method['label'] ?? $method['type'])) ?: $method['type'];
        $method['amount'] = round(max(0.0, (float) ($method['amount'] ?? 0)), 2);
        $method['details'] = (string) ($method['details'] ?? '');
        $method['available'] = array_key_exists('available', $method) ? (bool) $method['available'] : true;

        return $method;
    }

    protected function assertDeliveryAddress(array $customerData): void {
        foreach (['address', 'city', 'zip', 'country'] as $field) {
            if (trim((string) ($customerData[$field] ?? '')) === '') {
                throw new WireException($this->commerce->_('Delivery address and postal code are required for this fulfilment method.'));
            }
        }
    }

    protected function assertDeliveryCountryAllowed(array $customerData): void {
        $country = strtoupper(trim((string) ($customerData['country'] ?? '')));
        if (!$this->commerce->isDeliveryCountryAllowed($country)) {
            throw new WireException($this->commerce->_('Delivery is not available for this country.'));
        }
    }
}
