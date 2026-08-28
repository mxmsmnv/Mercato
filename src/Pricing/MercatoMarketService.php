<?php
namespace ProcessWire;

/**
 * Resolves authoritative storefront markets and explicit product price lists.
 *
 * The base `default` market always uses the existing Mercato currency and
 * product fields. Additional markets never use exchange-rate conversion: a
 * product (and each selected variant) must have an explicit market price.
 */
final class MercatoMarketService extends Wire {
    public function __construct(private readonly Mercato $commerce) { parent::__construct(); }

    public function all(): array {
        $default = [
            'id' => 'default',
            'label' => trim((string) ($this->commerce->seo_site_name ?? '')) ?: 'Default',
            'currency' => MercatoCurrency::normalizeCode((string) ($this->commerce->currency ?? 'GBP')),
            'countries' => $this->commerce->getAllowedDeliveryCountries(),
            'language' => MercatoEmailTemplateRenderer::normalizeLocale((string) ($this->commerce->notification_locale ?? 'en')),
            'is_default' => true,
        ];
        $markets = ['default' => $default];
        $decoded = json_decode(trim((string) ($this->commerce->markets_json ?? '')), true);
        foreach (is_array($decoded) ? $decoded : [] as $candidate) {
            if (!is_array($candidate) || array_key_exists('enabled', $candidate) && empty($candidate['enabled'])) continue;
            $id = self::normalizeId((string) ($candidate['id'] ?? ''));
            $currency = MercatoCurrency::normalizeCode((string) ($candidate['currency'] ?? ''));
            if ($id === '' || $id === 'default' || !MercatoCurrency::isIsoCode($currency)) continue;
            $countries = [];
            foreach ((array) ($candidate['countries'] ?? []) as $country) {
                $country = strtoupper(trim((string) $country));
                if (preg_match('/^[A-Z]{2}$/', $country)) $countries[$country] = true;
            }
            $markets[$id] = [
                'id' => $id,
                'label' => substr(trim((string) ($candidate['label'] ?? '')) ?: strtoupper($id), 0, 80),
                'currency' => $currency,
                'countries' => array_keys($countries),
                'language' => MercatoEmailTemplateRenderer::normalizeLocale((string) ($candidate['language'] ?? $default['language'])),
                'fulfilment_prices' => $this->moneyMap((array) ($candidate['fulfilment_prices'] ?? [])),
                'is_default' => false,
            ];
        }
        return array_values($markets);
    }

    public function resolve(string $id = ''): array {
        $id = self::normalizeId($id) ?: 'default';
        foreach ($this->all() as $market) if ($market['id'] === $id) return $market;
        throw new WireException(sprintf('Store market "%s" is unavailable.', $id), 422);
    }

    public function context(string $id = ''): array {
        $market = $this->resolve($id);
        return [
            'market_id' => $market['id'],
            'version' => 1,
            'currency_code' => $market['currency'],
            'country_code' => (string) ($market['countries'][0] ?? ''),
            'language_code' => $market['language'],
        ];
    }

    public function applyToItem(Page $product, array $item, string $marketId): array {
        $market = $this->resolve($marketId);
        if ($market['is_default']) {
            $item['market_id'] = 'default';
            $item['currency'] = $market['currency'];
            return $item;
        }
        $entry = $this->productEntry($product, $market['id']);
        if (!is_array($entry) || !array_key_exists('price', $entry) || !is_numeric($entry['price'])) {
            throw new WireException(sprintf('Product %d has no explicit price for market "%s".', (int) $product->id, $market['id']), 409);
        }
        $price = (float) $entry['price'];
        $shipping = array_key_exists('shipping_price', $entry) && is_numeric($entry['shipping_price']) ? (float) $entry['shipping_price'] : 0.0;
        $variantId = trim((string) ($item['variant_id'] ?? ''));
        if ($variantId !== '') {
            $variantEntry = (array) (($entry['variants'] ?? [])[$variantId] ?? []);
            if (!array_key_exists('price', $variantEntry) || !is_numeric($variantEntry['price'])) {
                throw new WireException(sprintf('Variant "%s" has no explicit price for market "%s".', $variantId, $market['id']), 409);
            }
            $price = (float) $variantEntry['price'];
            if (array_key_exists('shipping_price', $variantEntry) && is_numeric($variantEntry['shipping_price'])) $shipping = (float) $variantEntry['shipping_price'];
            if (array_key_exists('stripe_price_id', $variantEntry)) $item['stripe_price_id'] = trim((string) $variantEntry['stripe_price_id']);
        } elseif (array_key_exists('stripe_price_id', $entry)) {
            $item['stripe_price_id'] = trim((string) $entry['stripe_price_id']);
        }
        if ((string) ($item['product_type'] ?? '') === 'recurring' && trim((string) ($item['stripe_price_id'] ?? '')) === '') {
            throw new WireException(sprintf('Recurring product %d has no Stripe price for market "%s".', (int) $product->id, $market['id']), 409);
        }
        $item['price'] = round(max(0, $price), MercatoCurrency::decimalPlaces($market['currency']));
        $item['shipping_price'] = round(max(0, $shipping), MercatoCurrency::decimalPlaces($market['currency']));
        $item['market_id'] = $market['id'];
        $item['currency'] = $market['currency'];
        $item['market_price_snapshot_version'] = 1;
        return $item;
    }

    public function assertCart(MercatoProductList $cart, array $market): void {
        foreach ($cart->toArray() as $item) {
            $itemMarket = (string) ($item['market_id'] ?? 'default');
            $itemCurrency = MercatoCurrency::normalizeCode((string) ($item['currency'] ?? ''));
            if ($itemMarket !== $market['id'] || (!$market['is_default'] && $itemCurrency !== $market['currency']) || ($itemCurrency !== '' && $itemCurrency !== $market['currency'])) {
                throw new WireException('Cart market context is stale. Reload prices before checkout.', 409);
            }
        }
    }

    public function assertDiscountSupported(array $discount, array $market): void {
        if ($market['is_default'] || empty($discount['valid'])) return;
        if (($discount['type'] ?? '') !== MercatoDiscountType::PERCENTAGE || (float) ($discount['minimum_order_total'] ?? 0) > 0) {
            throw new WireException('This coupon is not configured for the selected market.', 422);
        }
    }

    public function applyToFulfilmentMethods(array $methods, array $market, bool $strict = false): array {
        if ($market['is_default']) return $methods;
        $prices = (array) ($market['fulfilment_prices'] ?? []);
        foreach ($methods as &$method) {
            if (!is_array($method)) continue;
            $selection = self::normalizeId((string) ($method['selection_key'] ?? $method['type'] ?? ''));
            $type = self::normalizeId((string) ($method['type'] ?? ''));
            $configured = array_key_exists($selection, $prices) ? $prices[$selection] : ($prices[$type] ?? null);
            if ($configured !== null) {
                $method['amount'] = $configured;
                $method['market_price_override'] = true;
                continue;
            }
            if ((float) ($method['amount'] ?? 0) > 0) {
                $method['available'] = false;
                $method['market_price_missing'] = true;
                if ($strict) throw new WireException('The selected fulfilment method has no price in this market.', 422);
            }
        }
        unset($method);
        return $methods;
    }

    public static function normalizeId(string $id): string {
        return trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($id))) ?: '', '-_');
    }

    private function productEntry(Page $product, string $marketId): ?array {
        if (!$product->hasField('mrc_market_prices')) return null;
        $decoded = json_decode(trim((string) $product->mrc_market_prices), true);
        $entry = is_array($decoded) ? ($decoded[$marketId] ?? null) : null;
        return is_array($entry) ? $entry : null;
    }

    private function moneyMap(array $values): array {
        $out = [];
        foreach ($values as $key => $value) {
            $key = self::normalizeId((string) $key);
            if ($key !== '' && is_numeric($value) && (float) $value >= 0) $out[$key] = round((float) $value, 3);
        }
        return $out;
    }
}
