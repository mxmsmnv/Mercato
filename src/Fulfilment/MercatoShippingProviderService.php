<?php
namespace ProcessWire;

final class MercatoShippingProviderService extends Wire {
    protected string $logName = 'mercato-shipping-provider';
    public function __construct(protected Mercato $commerce) { parent::__construct(); }

    public function getProviders(): array {
        $providers = ['reference' => new MercatoReferenceShippingProvider($this->commerce)];
        $hooked = $this->commerce->shippingProviders($providers);
        foreach (is_array($hooked) ? $hooked : [] as $key => $provider) if ($provider instanceof MercatoShippingProviderInterface) $providers[(string) $key] = $provider;
        return $providers;
    }

    public function isEnabled(): bool { return trim((string) ($this->commerce->shipping_provider ?? 'manual')) !== 'manual'; }
    public function isLiveSelection(string $selection): bool { return str_starts_with($selection, 'live:'); }

    public function getLiveMethods(MercatoProductList $cart, array $customer): array {
        if (!$this->isEnabled()) return [];
        foreach (['address', 'city', 'zip', 'country'] as $required) if (trim((string) ($customer[$required] ?? '')) === '') return [];
        try {
            $quote = $this->quoteRates($cart, $customer);
        } catch (\Throwable $e) {
            if ((string) ($this->commerce->shipping_provider_failure_policy ?? 'manual_fallback') === 'manual_fallback') return [];
            return [[
                'type' => MercatoFulfilmentMethodType::CARRIER_DELIVERY,
                'selection_key' => 'live:unavailable:unavailable',
                'label' => $this->commerce->_('Live delivery unavailable'),
                'amount' => 0.0,
                'details' => $this->commerce->_('The carrier could not quote this address. Check the address or try again later.'),
                'available' => false,
            ]];
        }
        $methods = [];
        foreach ($quote['rates'] as $rate) {
            $methods[] = [
                'type' => MercatoFulfilmentMethodType::CARRIER_DELIVERY,
                'selection_key' => $this->selectionKey($quote['provider'], $rate['id']),
                'label' => $rate['label'], 'amount' => $rate['amount'], 'details' => $this->deliveryEstimate($rate), 'available' => true,
                'shipping_provider_quote' => ['provider' => $quote['provider'], 'rate' => $rate, 'quoted_at' => $quote['quoted_at'], 'context_hash' => $quote['context_hash'], 'input_snapshot' => $quote['input_snapshot']],
            ];
        }
        return $methods;
    }

    public function resolveSelection(string $selection, MercatoProductList $cart, array $customer): array {
        [$providerKey, $rateId] = $this->parseSelection($selection);
        $quote = $this->quoteRates($cart, $customer, $providerKey);
        foreach ($quote['rates'] as $rate) {
            if ($rate['id'] !== $rateId) continue;
            return [
                'type' => MercatoFulfilmentMethodType::CARRIER_DELIVERY, 'selection_key' => $selection,
                'label' => $rate['label'], 'amount' => $rate['amount'], 'details' => $this->deliveryEstimate($rate), 'available' => true,
                'shipping_provider_quote' => ['provider' => $providerKey, 'rate' => $rate, 'quoted_at' => $quote['quoted_at'], 'revalidated_at' => date(DATE_ATOM), 'context_hash' => $quote['context_hash'], 'input_snapshot' => $quote['input_snapshot']],
            ];
        }
        throw new WireException($this->commerce->_('The selected live shipping service expired or is no longer available. Choose another delivery service.'), 409);
    }

    public function quoteRates(MercatoProductList $cart, array $customer, string $providerKey = ''): array {
        $providerKey = $providerKey ?: trim((string) ($this->commerce->shipping_provider ?? 'manual'));
        $providers = $this->getProviders();
        if (!isset($providers[$providerKey])) return $this->rateFailure(new WireException('Shipping provider is unavailable.'), $providerKey);
        $context = $this->buildRateContext($cart, $customer, $providerKey);
        $this->assertRegionAllowed($context['destination']);
        try {
            $started = microtime(true);
            $rates = $this->retry(fn(): array => $providers[$providerKey]->quoteRates($context));
            if ((microtime(true) - $started) > (float) $context['timeout_seconds']) throw new WireException('Shipping provider timed out.');
            $rates = MercatoShippingQuote::normalizeRates($rates, $context);
            $rates = $this->mapAndAdjustRates($rates);
            if (!$rates) throw new WireException('Shipping provider returned no supported services.');
            $result = ['provider' => $providerKey, 'currency' => $context['currency'], 'rates' => $rates, 'quoted_at' => date(DATE_ATOM), 'context_hash' => hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 'input_snapshot' => $this->publicRateContext($context)];
            $this->audit('rates_quoted', ['provider' => $providerKey, 'rate_count' => count($rates), 'context_hash' => $result['context_hash']]);
            return $result;
        } catch (\Throwable $e) {
            return $this->rateFailure($e, $providerKey);
        }
    }

    public function purchaseLabel(Page $order): array {
        $paid = (int) ($order->mrc_payment_complete ?? 0) === 1 || in_array((string) ($order->mrc_payment_status ?? ''), [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED], true);
        if (!$paid) throw new WireException('A shipping label can only be purchased for a paid order.', 409);
        return $this->withOrderLock($order, function (Page $fresh): array {
            $details = $this->fulfilmentDetails($fresh); $state = (array) ($details['provider_shipping'] ?? []);
            if (($state['label']['status'] ?? '') === 'purchased') return $state['label'];
            $provider = $this->providerForOrder($details); $quote = (array) ($details['shipping_provider_quote'] ?? []);
            $context = ['order' => $this->orderContext($fresh), 'quote' => $quote, 'packages' => (array) ($quote['input_snapshot']['packages'] ?? []), 'idempotency_key' => 'shipping_create_' . (int) $fresh->id];
            if (empty($state['shipment']['shipment_reference'])) {
                $shipment = $this->invoke($provider, 'createShipment', $context);
                $state['shipment'] = ['status' => 'created', 'at' => date(DATE_ATOM), 'idempotency_key' => $context['idempotency_key']] + $shipment;
            }
            $labelContext = array_merge($context, ['shipment_reference' => (string) ($state['shipment']['shipment_reference'] ?? ''), 'idempotency_key' => 'shipping_label_' . (int) $fresh->id]);
            $label = $this->invoke($provider, 'purchaseLabel', $labelContext);
            $state['label'] = ['status' => 'purchased', 'at' => date(DATE_ATOM), 'idempotency_key' => $labelContext['idempotency_key']] + $label;
            $details['provider_shipping'] = $state; $this->saveFulfilmentDetails($fresh, $details, $state['label']);
            $this->audit('label_purchased', $this->safeAudit(['provider' => $provider->getShippingProviderKey(), 'order_id' => (int) $fresh->id] + $state['label']));
            return $state['label'];
        });
    }

    public function getLabel(Page $order): array {
        $details = $this->fulfilmentDetails($order); $state = (array) ($details['provider_shipping'] ?? []); $label = (array) ($state['label'] ?? []);
        if (!$label) throw new WireException('No provider label exists for this order.', 404);
        return $this->invoke($this->providerForOrder($details), 'getLabel', ['order' => $this->orderContext($order), 'shipment_reference' => (string) ($state['shipment']['shipment_reference'] ?? ''), 'label_reference' => (string) ($label['label_reference'] ?? ''), 'idempotency_key' => 'shipping_label_read_' . (int) $order->id]);
    }

    public function voidLabel(Page $order): array {
        return $this->withOrderLock($order, function (Page $fresh): array {
            $details = $this->fulfilmentDetails($fresh); $state = (array) ($details['provider_shipping'] ?? []);
            if (($state['void']['status'] ?? '') === 'voided') return $state['void'];
            $provider = $this->providerForOrder($details); $base = ['order' => $this->orderContext($fresh), 'shipment_reference' => (string) ($state['shipment']['shipment_reference'] ?? ''), 'label_reference' => (string) ($state['label']['label_reference'] ?? '')];
            $refund = !empty($base['label_reference']) ? $this->invoke($provider, 'refundLabel', $base + ['idempotency_key' => 'shipping_label_refund_' . (int) $fresh->id]) : [];
            $void = $this->invoke($provider, 'voidShipment', $base + ['idempotency_key' => 'shipping_void_' . (int) $fresh->id]);
            $state['void'] = ['status' => 'voided', 'at' => date(DATE_ATOM), 'refund' => $refund] + $void;
            $details['provider_shipping'] = $state; $this->saveFulfilmentDetails($fresh, $details);
            $this->audit('shipment_voided', $this->safeAudit(['provider' => $provider->getShippingProviderKey(), 'order_id' => (int) $fresh->id] + $state['void']));
            return $state['void'];
        });
    }

    public function processTrackingWebhook(string $providerKey, string $payload, array $headers): array {
        $providers = $this->getProviders(); $provider = $providers[$providerKey] ?? null;
        if (!$provider || !$provider->verifyTrackingWebhook($payload, array_change_key_case($headers, CASE_LOWER))) throw new WireException('Invalid shipping webhook signature.', 401);
        $event = $provider->parseTrackingWebhook($payload, array_change_key_case($headers, CASE_LOWER));
        $eventId = substr(trim((string) ($event['event_id'] ?? '')), 0, 200); $orderId = (int) ($event['order_id'] ?? 0);
        if ($eventId === '' || $orderId <= 0) throw new WireException('Shipping webhook event ID and order ID are required.', 422);
        $order = $this->wire('pages')->get($orderId);
        if (!$order || !$order->id || $order->template->name !== (string) $this->commerce->order_template) throw new WireException('Shipping webhook order was not found.', 404);
        return $this->withOrderLock($order, function (Page $fresh) use ($event, $eventId, $providerKey): array {
            $details = $this->fulfilmentDetails($fresh); $state = (array) ($details['provider_shipping'] ?? []);
            foreach ((array) ($state['tracking_events'] ?? []) as $stored) if (($stored['event_id'] ?? '') === $eventId) return ['duplicate' => true, 'status' => (string) ($stored['status'] ?? '')];
            $status = $this->mapTrackingStatus((string) ($event['status'] ?? '')); $current = (string) ($fresh->mrc_fulfilment_status ?: MercatoFulfilmentStatus::UNFULFILLED);
            $previousOrderStatus = $this->commerce->getDerivedOrderStatus($fresh);
            if ($this->statusRank($status) < $this->statusRank($current)) $status = $current;
            $entry = ['event_id' => $eventId, 'status' => $status, 'provider_status' => substr(trim((string) ($event['status'] ?? '')), 0, 120), 'at' => (string) ($event['occurred_at'] ?? date(DATE_ATOM))];
            $state['tracking_events'][] = $entry; $details['provider_shipping'] = $state;
            $fresh->of(false); $fresh->mrc_fulfilment_status = $status;
            if ($fresh->hasField('mrc_fulfilment_tracking') && !empty($event['tracking'])) $fresh->mrc_fulfilment_tracking = substr(trim((string) $event['tracking']), 0, 240);
            $fresh->mrc_fulfilment_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $this->wire('pages')->save($fresh);
            $this->commerce->fulfilmentUpdated($fresh, ['source' => 'shipping_tracking_webhook', 'provider' => $providerKey, 'event_id' => $eventId, 'from' => $current, 'to' => $status]);
            $this->commerce->emitOrderStatusChanged($fresh, $previousOrderStatus, ['source' => 'shipping_tracking_webhook', 'fulfilment_status_from' => $current, 'fulfilment_status_to' => $status]);
            $this->audit('tracking_webhook', ['provider' => $providerKey, 'order_id' => (int) $fresh->id, 'event_id' => $eventId, 'status' => $status]);
            return ['duplicate' => false, 'status' => $status];
        });
    }

    public function redactSnapshot(array $details): array {
        if (isset($details['provider_shipping']['label']['label_url'])) $details['provider_shipping']['label']['label_url'] = '[redacted]';
        if (isset($details['manual_label_url'])) $details['manual_label_url'] = '[redacted]';
        return $details;
    }

    protected function buildRateContext(MercatoProductList $cart, array $customer, string $provider): array {
        $packages = $this->buildPackages($cart->toArray());
        return ['operation' => 'rates', 'provider' => $provider, 'currency' => MercatoCurrency::normalizeCode((string) $this->commerce->currency), 'origin' => $this->decodeJson((string) ($this->commerce->shipping_provider_origin ?? '')), 'destination' => ['address' => trim((string) ($customer['address'] ?? '')), 'address_2' => trim((string) ($customer['address_2'] ?? $customer['address_line_2'] ?? '')), 'city' => trim((string) ($customer['city'] ?? '')), 'postal_code' => strtoupper(trim((string) ($customer['zip'] ?? ''))), 'country' => strtoupper(trim((string) ($customer['country'] ?? ''))), 'region' => strtoupper(trim((string) ($customer['region'] ?? '')))], 'packages' => $packages, 'declared_value' => round($cart->getSubtotal(), 2), 'timeout_seconds' => max(1, (int) ($this->commerce->shipping_provider_timeout_seconds ?? 5)), 'quote_ttl_seconds' => max(60, (int) ($this->commerce->shipping_provider_quote_ttl_seconds ?? 900))];
    }
    protected function buildPackages(array $items): array {
        $mode = (string) ($this->commerce->shipping_provider_package_mode ?? 'combined'); $packages = [];
        foreach ($items as $item) {
            if (($item['product_type'] ?? 'physical') !== 'physical') continue;
            $d = (array) ($item['shipping_dimensions'] ?? []); $qty = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
            $parcel = ['weight_kg' => max(0, (float) ($d['weight_kg'] ?? 0)), 'length_cm' => max(0, (float) ($d['length_cm'] ?? 0)), 'width_cm' => max(0, (float) ($d['width_cm'] ?? 0)), 'height_cm' => max(0, (float) ($d['height_cm'] ?? 0)), 'quantity' => $qty, 'items' => [['line_id' => (string) ($item['key'] ?? $item['id'] ?? ''), 'sku' => (string) ($item['sku'] ?? ''), 'quantity' => $qty]]];
            if ($mode === 'per_item') for ($i = 0; $i < $qty; $i++) { $copy = $parcel; $copy['quantity'] = 1; $copy['items'][0]['quantity'] = 1; $packages[] = $copy; } else $packages[] = $parcel;
        }
        if ($mode === 'combined' && count($packages) > 1) {
            $combined = ['weight_kg' => 0.0, 'length_cm' => 0.0, 'width_cm' => 0.0, 'height_cm' => 0.0, 'quantity' => 1, 'items' => []];
            foreach ($packages as $p) { $combined['weight_kg'] += $p['weight_kg'] * $p['quantity']; $combined['length_cm'] = max($combined['length_cm'], $p['length_cm']); $combined['width_cm'] = max($combined['width_cm'], $p['width_cm']); $combined['height_cm'] += $p['height_cm'] * $p['quantity']; $combined['items'] = array_merge($combined['items'], $p['items']); }
            $packages = [$combined];
        }
        if (!$packages) throw new WireException('No physical parcels are available for live shipping.');
        return $packages;
    }
    protected function mapAndAdjustRates(array $rates): array {
        $map = $this->decodeJson((string) ($this->commerce->shipping_provider_service_map ?? '')); $fixed = (float) ($this->commerce->shipping_provider_handling_fixed ?? 0); $percent = (float) ($this->commerce->shipping_provider_handling_percent ?? 0);
        foreach ($rates as $index => &$rate) { $rule = (array) ($map[$rate['service']] ?? []); if (array_key_exists('enabled', $rule) && !$rule['enabled']) { unset($rates[$index]); continue; } $rate['provider_amount'] = $rate['amount']; $rate['amount'] = round(max(0, $rate['amount'] + $fixed + ($rate['amount'] * $percent / 100)), 2); if (!empty($rule['label'])) $rate['label'] = substr(trim((string) $rule['label']), 0, 160); }
        unset($rate); return array_values($rates);
    }
    protected function invoke(MercatoShippingProviderInterface $provider, string $method, array $context): array { $result = $this->retry(fn(): array => $provider->$method($context)); if (!is_array($result)) throw new WireException('Shipping provider returned an invalid response.'); return $result; }
    protected function retry(callable $operation): array { $attempts = max(1, min(4, (int) ($this->commerce->shipping_provider_retries ?? 1) + 1)); $last = null; for ($i = 0; $i < $attempts; $i++) try { return $operation(); } catch (\Throwable $e) { $last = $e; } throw new WireException($last?->getMessage() ?: 'Shipping provider failed.', 502, $last); }
    protected function rateFailure(\Throwable $e, string $provider): array { $policy = (string) ($this->commerce->shipping_provider_failure_policy ?? 'manual_fallback'); $this->audit('rate_failure', ['provider' => $provider, 'policy' => $policy, 'error' => $e->getMessage()]); if ($policy === 'manual_fallback') return ['provider' => $provider, 'rates' => [], 'fallback' => true, 'error' => $e->getMessage()]; throw new WireException('Live shipping rates are unavailable: ' . $e->getMessage(), 503, $e); }
    protected function providerForOrder(array $details): MercatoShippingProviderInterface { $key = (string) ($details['shipping_provider_quote']['provider'] ?? ''); $provider = $this->getProviders()[$key] ?? null; if (!$provider) throw new WireException('The order shipping provider is unavailable.', 503); return $provider; }
    protected function fulfilmentDetails(Page $order): array { $details = json_decode((string) $order->mrc_fulfilment_details, true); return is_array($details) ? $details : []; }
    protected function saveFulfilmentDetails(Page $order, array $details, array $label = []): void { $order->of(false); $order->mrc_fulfilment_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); if ($order->hasField('mrc_fulfilment_tracking') && !empty($label['tracking'])) $order->mrc_fulfilment_tracking = (string) $label['tracking']; if ($order->hasField('mrc_fulfilment_tracking_url') && !empty($label['tracking_url'])) $order->mrc_fulfilment_tracking_url = (string) $label['tracking_url']; $this->wire('pages')->save($order); }
    protected function withOrderLock(Page $order, callable $operation): array { $database = $this->wire('database'); $name = 'mercato_shipping_' . (int) $order->id; $lock = $database->prepare('SELECT GET_LOCK(:name, 10)'); $lock->execute([':name' => $name]); if ((int) $lock->fetchColumn() !== 1) throw new WireException('Could not acquire the shipping transaction lock.', 503); try { $fresh = $this->wire('pages')->getById((int) $order->id, ['cache' => false])->first(); if (!$fresh || !$fresh->id) throw new WireException('Order no longer exists.', 404); return $operation($fresh); } finally { $release = $database->prepare('SELECT RELEASE_LOCK(:name)'); $release->execute([':name' => $name]); } }
    protected function orderContext(Page $order): array { return ['id' => (int) $order->id, 'invoice' => (string) ($order->mrc_invoice_number ?: $order->title), 'currency' => (string) $order->mrc_currency, 'total' => (float) $order->mrc_total_amount, 'shipping_address' => json_decode((string) $order->mrc_shipping_address, true) ?: []]; }
    protected function assertRegionAllowed(array $destination): void { $rules = preg_split('/[\s,]+/', strtoupper((string) ($this->commerce->shipping_provider_allowed_regions ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []; if (!$rules) return; $country = (string) $destination['country']; $region = (string) $destination['region']; if (!in_array($country, $rules, true) && !in_array($country . ':' . $region, $rules, true)) throw new WireException('Live shipping is not enabled for this destination.'); }
    protected function parseSelection(string $selection): array { $parts = explode(':', $selection, 3); if (count($parts) !== 3 || $parts[0] !== 'live') throw new WireException('Invalid live shipping selection.', 422); return [rawurldecode($parts[1]), rawurldecode($parts[2])]; }
    protected function selectionKey(string $provider, string $rate): string { return 'live:' . rawurlencode($provider) . ':' . rawurlencode($rate); }
    protected function deliveryEstimate(array $rate): string { $min = (int) $rate['delivery_days_min']; $max = (int) $rate['delivery_days_max']; return $max > 0 ? sprintf('Estimated delivery: %s business days.', $min === $max ? (string) $max : $min . '–' . $max) : ''; }
    protected function publicRateContext(array $context): array { unset($context['timeout_seconds']); return $context; }
    protected function decodeJson(string $json): array { $value = json_decode(trim($json), true); return is_array($value) ? $value : []; }
    protected function mapTrackingStatus(string $status): string { return match (strtolower(trim($status))) { 'pre_transit', 'label_created', 'created' => MercatoFulfilmentStatus::UNFULFILLED, 'in_transit', 'transit', 'shipped' => MercatoFulfilmentStatus::SHIPPED, 'out_for_delivery' => MercatoFulfilmentStatus::OUT_FOR_DELIVERY, 'delivered' => MercatoFulfilmentStatus::DELIVERED, 'returned', 'return_to_sender' => MercatoFulfilmentStatus::RETURNED, default => MercatoFulfilmentStatus::UNFULFILLED }; }
    protected function statusRank(string $status): int { return [MercatoFulfilmentStatus::UNFULFILLED => 0, MercatoFulfilmentStatus::PARTIALLY_FULFILLED => 1, MercatoFulfilmentStatus::FULFILLED => 2, MercatoFulfilmentStatus::SHIPPED => 3, MercatoFulfilmentStatus::OUT_FOR_DELIVERY => 4, MercatoFulfilmentStatus::DELIVERED => 5, MercatoFulfilmentStatus::RETURNED => 6][$status] ?? 0; }
    protected function safeAudit(array $data): array { unset($data['label_url'], $data['label_data'], $data['document']); return $data; }
    protected function audit(string $event, array $data): void { $this->wire('log')->save($this->logName, json_encode(['event' => $event, 'at' => date(DATE_ATOM)] + $this->safeAudit($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
}
