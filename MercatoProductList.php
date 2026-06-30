<?php
namespace ProcessWire;

/**
 * MercatoProductList
 *
 * Ordered collection of product items with price/tax calculation.
 * Each item is an array with at minimum: id, title, price, quantity, tax_rate.
 *
 * When an item's 'id' corresponds to a ProcessWire page, title, price and
 * tax_rate are auto-populated from that page's fields.
 */
class MercatoProductList extends Wire {

    protected array $items = [];

    /** @var Mercato */
    protected Mercato $commerce;

    public function __construct(array $items = []) {
        parent::__construct();
        $this->commerce = wire('modules')->get('Mercato');

        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $this->setItem(is_string($key) ? $key : ($item['key'] ?? $item['id'] ?? $key), $item);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Item management
    // -----------------------------------------------------------------------

    /**
     * Add or replace an item.
     * Autofills title/price/tax_rate from PW page if id matches a page path/id.
     *
     * @param string $key  Unique key (usually the page path or custom key)
     * @param array $item  Must contain 'price' or match a PW product page
     */
    public function setItem(string $key, array $item): static {
        if (isset($item['taxRate']) && !isset($item['tax_rate'])) {
            $item['tax_rate'] = $item['taxRate'];
        }
        if (isset($item['sumTax']) && !isset($item['sum_tax'])) {
            $item['sum_tax'] = $item['sumTax'];
        }

        $item['key']      = $key;
        $item['id']       = $item['id'] ?? $key;
        $item['quantity'] = (float) ($item['quantity'] ?? 1);

        // Reject id='0' — pages->get(0) returns the root/homepage in ProcessWire
        if ((string) $item['id'] === '' || (string) $item['id'] === '0') {
            throw new WireException(sprintf('Invalid product id "%s".', $item['id']));
        }

        if ($item['quantity'] < 0) {
            throw new WireException('Item quantity cannot be negative.');
        }

        // Skip DB lookup if all auto-fill fields are already present (e.g. session restore).
        // Only look up the page when we actually need data from it.
        $needsPageLookup = !isset($item['price'])
            || !isset($item['title'])
            || !isset($item['tax_rate'])
            || !isset($item['shipping_price'])
            || !isset($item['template'])
            || !isset($item['uid'])
            || !isset($item['product_id']);

        $page = $needsPageLookup ? $this->findProductPage($item['id']) : null;

        if (!isset($item['price'])) {
            if (!$page || !$page->id) {
                throw new WireException(
                    sprintf('No price set and no product page found for id "%s".', $item['id'])
                );
            }
        }

        if ($page && $page->id) {
            $item['product_id'] = (int) $page->id;
            if (!isset($item['title'])) {
                $item['title'] = $page->title;
            }
            if (!isset($item['price'])) {
                $item['price'] = (float) $page->mrc_price;
            }
            if (!isset($item['tax_rate'])) {
                $item['tax_rate'] = $page->hasField('mrc_tax_rate')
                    ? (float) $page->mrc_tax_rate
                    : 0.0;
            }
            if (!isset($item['shipping_price'])) {
                $item['shipping_price'] = $page->hasField('mrc_shipping_price')
                    ? (float) $page->mrc_shipping_price
                    : 0.0;
            }
            if (!isset($item['template'])) {
                $item['template'] = $page->template->name;
            }
            if (!isset($item['uid'])) {
                $item['uid'] = $page->name;
            }
            if (!isset($item['collection_ids'])) {
                $item['collection_ids'] = [];
                if ($page->hasField('mrc_collections') && $page->mrc_collections instanceof PageArray) {
                    foreach ($page->mrc_collections as $collection) {
                        if ($collection instanceof Page && $collection->id) {
                            $item['collection_ids'][] = (int) $collection->id;
                        }
                    }
                }
            }
            if (!isset($item['stock_policy'])) {
                $policy = $page->hasField('mrc_stock_policy') ? strtolower(trim((string) $page->mrc_stock_policy)) : '';
                $item['stock_policy'] = in_array($policy, ['deny', 'backorder', 'preorder'], true) ? $policy : 'deny';
            }
            if (!isset($item['stock'])) {
                $item['stock'] = $page->hasField('mrc_stock') ? (int) $page->mrc_stock : null;
            }
            if (!isset($item['product_type'])) {
                $type = $page->hasField('mrc_product_type') ? strtolower(trim((string) $page->mrc_product_type)) : '';
                $item['product_type'] = in_array($type, ['physical', 'digital', 'service', 'placeholder', 'recurring', 'bundle'], true) ? $type : 'physical';
            }
            if (!isset($item['stripe_price_id'])) {
                $item['stripe_price_id'] = $page->hasField('mrc_stripe_price_id') ? trim((string) $page->mrc_stripe_price_id) : '';
            }

            // Pull in any extra fields configured on the module
            $extraFields = wire('modules')->get('Mercato')->get('cart_extra_fields') ?? [];
            foreach ($extraFields as $fieldName) {
                if (!array_key_exists($fieldName, $item) && $page->hasField($fieldName)) {
                    $item[$fieldName] = (string) $page->get($fieldName);
                }
            }
        }

        $item['price']    = (float) ($item['price'] ?? 0);
        $item['tax_rate'] = (float) ($item['tax_rate'] ?? 0);
        $item['shipping_price'] = (float) ($item['shipping_price'] ?? 0);
        $item['tax']      = $this->commerce->calculateTax($item['price'], $item['tax_rate']);
        $item['sum']      = round($item['price'] * $item['quantity'], 10);
        $item['sum_shipping'] = round($item['shipping_price'] * ($item['quantity'] > 0 ? 1 : 0), 10);
        $item['sum_tax']  = round($item['tax']   * $item['quantity'], 10);
        $item['taxRate']  = $item['tax_rate'];
        $item['sumTax']   = $item['sum_tax'];

        // Capture previous item (may be null if this is a new key) for rollback
        $previousItem = $this->items[$key] ?? null;
        $this->items[$key] = $item;

        // Guard: total tax and sum must not go negative (e.g. discount overcorrection).
        // If the guard fails, restore the previous item value (or remove if it was new).
        if ($this->getTax() < -0.001 || $this->getSum() < -0.001) {
            if ($previousItem !== null) {
                $this->items[$key] = $previousItem; // restore
            } else {
                unset($this->items[$key]); // was a new item — remove
            }
            if ($this->getTax() < -0.001) {
                throw new WireException('Cart tax cannot be negative.');
            }
            throw new WireException('Cart total cannot be negative.');
        }

        return $this;
    }

    /**
     * Update an existing item (merge, not replace).
     * If quantity reaches 0, the item is removed.
     */
    public function updateItem(array $item): static {
        $key = $item['key'] ?? $item['id'] ?? null;
        if (!$key) throw new WireException('Item must have a key or id.');

        $existing = $this->items[$key] ?? null;
        $quantity = (float) ($item['quantity'] ?? $existing['quantity'] ?? 1);

        if ($quantity <= 0) {
            return $this->remove($key);
        }

        if ($existing) {
            $merged = array_merge($existing, $item);
            $this->setItem($key, $merged);
        } else {
            $this->setItem($key, $item);
        }

        return $this;
    }

    /**
     * Remove item by key.
     */
    public function remove(string $key): static {
        unset($this->items[$key]);
        return $this;
    }

    public function getProductTypes(): array {
        $types = [];
        foreach ($this->items as $item) {
            $type = strtolower(trim((string) ($item['product_type'] ?? 'physical')));
            if (!in_array($type, ['physical', 'digital', 'service', 'placeholder', 'recurring', 'bundle'], true)) {
                $type = 'physical';
            }
            $types[$type] = true;
        }
        return array_keys($types);
    }

    public function containsRecurringProducts(): bool {
        return in_array('recurring', $this->getProductTypes(), true);
    }

    public function containsOneOffProducts(): bool {
        foreach ($this->getProductTypes() as $type) {
            if ($type !== 'recurring') {
                return true;
            }
        }
        return false;
    }

    public function isRecurringOnly(): bool {
        return $this->count() > 0 && $this->containsRecurringProducts() && !$this->containsOneOffProducts();
    }

    public function hasMixedPurchaseTypes(): bool {
        return $this->containsRecurringProducts() && $this->containsOneOffProducts();
    }

    /**
     * Append item (add or increment quantity if key exists).
     */
    public function append(array $item): static {
        $key = $item['key'] ?? $item['id'] ?? null;
        if (!$key) throw new WireException('Item must have a key or id.');

        if (isset($this->items[$key])) {
            $existing = $this->items[$key];
            $item['quantity'] = ($existing['quantity'] ?? 1) + ($item['quantity'] ?? 1);
            $this->setItem($key, array_merge($existing, $item));
        } else {
            $this->setItem($key, $item);
        }

        return $this;
    }

    // -----------------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------------

    /**
     * Get a single item by key. Returns null if not found.
     * Named getItem() to avoid conflict with Wire::get(mixed $key): mixed.
     */
    public function getItem(string $key): ?array {
        return $this->items[$key] ?? null;
    }

    public function count(): int {
        return count($this->items);
    }

    public function toArray(): array {
        return array_values($this->items);
    }

    public function values(): array {
        return array_values($this->items);
    }

    public function keys(): array {
        return array_keys($this->items);
    }

    // -----------------------------------------------------------------------
    // Calculations
    // -----------------------------------------------------------------------

    /**
     * Total including tax across all items.
     */
    public function getSum(): float {
        return round($this->getSubtotal() + $this->getShipping(), 2);
    }

    /**
     * Product total excluding shipping.
     */
    public function getSubtotal(): float {
        $sum = 0.0;
        foreach ($this->items as $item) {
            $sum += $item['sum'];
        }
        return round($sum, 2);
    }

    /**
     * Shipping total across items. Shipping is charged once per cart line.
     */
    public function getShipping(): float {
        $sum = 0.0;
        foreach ($this->items as $item) {
            $sum += (float) ($item['sum_shipping'] ?? 0);
        }
        return round($sum, 2);
    }

    /**
     * Total tax across all items.
     */
    public function getTax(): float {
        $mode = $this->getTaxRoundingMode();
        if ($mode === 'tax_rate') {
            $tax = 0.0;
            foreach ($this->getTaxRates() as $rate) {
                $tax += (float) ($rate['sum'] ?? 0);
            }
            return round($tax, 2);
        }

        $tax = 0.0;
        foreach ($this->items as $item) {
            $lineTax = (float) ($item['sum_tax'] ?? 0);
            $tax += $mode === 'line' ? round($lineTax, 2) : $lineTax;
        }
        return round($tax, 2);
    }

    /**
     * Taxes grouped by tax rate.
     * Returns: [['tax_rate' => 19.0, 'sum' => 3.18], ...]
     */
    public function getTaxRates(): array {
        $mode = $this->getTaxRoundingMode();
        $rates = [];
        foreach ($this->items as $item) {
            $rate = (float) $item['tax_rate'];
            if ($rate == 0) continue;
            $key = (string) $rate;
            if (!isset($rates[$key])) {
                $rates[$key] = ['tax_rate' => $rate, 'taxRate' => $rate, 'sum' => 0.0];
            }
            $lineTax = (float) ($item['sum_tax'] ?? 0);
            $rates[$key]['sum'] += $mode === 'line' ? round($lineTax, 2) : $lineTax;
        }
        foreach ($rates as &$group) {
            $group['sum'] = round((float) $group['sum'], 2);
        }
        unset($group);
        ksort($rates);
        return array_values($rates);
    }

    /**
     * Return items with price/tax/sum formatted as strings.
     */
    public function getFormattedItems(): array {
        return array_map(function (array $item): array {
            $item['price']   = $this->commerce->formatPrice($item['price']);
            $item['tax']     = $this->commerce->formatPrice($item['tax']);
            $item['sum']     = $this->commerce->formatPrice($item['sum']);
            $item['sum_tax'] = $this->commerce->formatPrice($item['sum_tax']);
            $item['shipping_price'] = $this->commerce->formatPrice((float) ($item['shipping_price'] ?? 0));
            $item['sum_shipping'] = $this->commerce->formatPrice((float) ($item['sum_shipping'] ?? 0));
            $item['sumTax']  = $item['sum_tax'];
            return $item;
        }, $this->items);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Find a product PW page by path, name, or integer ID.
     */
    protected function findProductPage(string $id): ?Page {
        $pages    = wire('pages');
        $sanitize = wire('sanitizer');

        // Numeric ID
        if (ctype_digit($id)) {
            $p = $pages->get((int) $id);
            return $p && $p->id ? $p : null;
        }
        // Path starting with /
        if (str_starts_with($id, '/')) {
            $p = $pages->get($sanitize->path($id));
            return $p && $p->id ? $p : null;
        }
        // Sanitize before using in selector to prevent injection
        $safeName = $sanitize->pageName($id);
        if ($safeName) {
            $p = $pages->get("name=$safeName, include=all");
            if ($p && $p->id) return $p;
        }
        // Try as relative path
        $p = $pages->get('/' . ltrim($id, '/') . '/');
        return $p && $p->id ? $p : null;
    }

    protected function getTaxRoundingMode(): string {
        return method_exists($this->commerce, 'getTaxRoundingMode')
            ? $this->commerce->getTaxRoundingMode()
            : 'line';
    }
}
