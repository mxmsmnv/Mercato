<?php
namespace ProcessWire;

/**
 * MercatoCart
 *
 * Session-persisted shopping cart. Extends MercatoProductList.
 * All mutations (add/remove/update) are automatically saved to session.
 */
class MercatoCart extends MercatoProductList {

    const SESSION_KEY = 'mrc_cart_items';
    const DISCOUNT_SESSION_KEY = 'mrc_discount_code';
    const UPDATED_AT_SESSION_KEY = 'mrc_cart_updated_at';

    public function __construct(array $items = []) {
        // Restore from session if no items were passed explicitly
        if (count($items) === 0) {
            $saved = wire('session')->get(self::SESSION_KEY);
            if (is_array($saved)) {
                $updatedAt = (int) wire('session')->get(self::UPDATED_AT_SESSION_KEY);
                $retentionDays = (int) wire('modules')->get('Mercato')->getCartRetentionDays();
                if ($updatedAt > 0 && $retentionDays > 0 && $updatedAt <= time() - ($retentionDays * 86400)) {
                    wire('session')->remove(self::SESSION_KEY);
                    wire('session')->remove(self::UPDATED_AT_SESSION_KEY);
                    wire('session')->remove(self::DISCOUNT_SESSION_KEY);
                } else $items = $saved;
            }
        }

        // Wrap parent construction in try/catch: corrupt session data (e.g. a deleted
        // product page) would otherwise throw and leave the cart in a broken state.
        // On failure we clear the bad session data and start with an empty cart.
        try {
            $items = $this->rehydrateProductItems($items);
            parent::__construct($items);
        } catch (\Exception $e) {
            wire('session')->remove(self::SESSION_KEY);
            $this->items = []; // reset — Wire::__construct() already ran via parent chain
        }

        $this->save(false);
        wire('modules')->get('Mercato')->cartLoaded($this);
    }

    // -----------------------------------------------------------------------
    // Mutating methods — all auto-save
    // -----------------------------------------------------------------------

    /**
     * Add item to cart. Accepts a page ID/path string or an item array.
     *
     * Usage:
     *   $cart->add('products/cool-shirt');
     *   $cart->add(['id' => 'products/cool-shirt', 'quantity' => 2]);
     *
     * @throws WireException
     */
    public function add(string|array $arg): static {
        $commerce = wire('modules')->get('Mercato');
        $item = is_string($arg) ? ['id' => $arg, 'quantity' => 1] : $arg;
        $hookItem = $commerce->beforeAddToCart($item, $this);
        if (is_array($hookItem)) {
            $item = $hookItem;
        }

        try {
            if (empty($item['id'])) {
                throw new WireException('Item array must contain an "id" key.');
            }
            $product = $this->findCartProduct($item);
            if ($product) {
                $item = $commerce->variantService()->hydrateItem($product, $item);
            }
            $this->append($item);
        } catch (WireException $e) {
            // Re-throw PW exceptions as-is — they already have the right message
            throw $e;
        } catch (\Exception $e) {
            // Wrap unexpected non-PW exceptions with context
            $id = $item['id'] ?? '(no id)';
            throw new WireException(
                sprintf('Could not add item "%s" to cart: %s', $id, $e->getMessage())
            );
        }

        // Save outside the try block — if append() threw, we never reach here,
        // so we only persist a successfully updated cart to the session.
        $this->save();
        $commerce->afterAddToCart($item, $this);
        return $this;
    }

    /**
     * Remove item from cart by key.
     */
    public function remove(string $key): static {
        parent::remove($key);
        $this->save();
        return $this;
    }

    /**
     * Update one or more items in the cart.
     * Accepts a single item array or a list of item arrays.
     */
    public function update(array $items): static {
        // Single item: has 'id' or 'key' at top level
        if (isset($items['id']) || isset($items['key'])) {
            $items = [$items];
        }
        foreach ($items as $item) {
            parent::updateItem($this->canonicalizeUpdateItem($item));
        }
        $this->save();
        return $this;
    }

    /**
     * updateItem override — auto-save.
     */
    public function updateItem(array $item): static {
        parent::updateItem($this->canonicalizeUpdateItem($item));
        $this->save();
        return $this;
    }

    /**
     * Empty and delete the cart from session.
     */
    public function delete(): void {
        $this->items = [];
        wire('session')->remove(self::SESSION_KEY);
        wire('session')->remove(self::UPDATED_AT_SESSION_KEY);
        $this->removeCoupon();
        wire('modules')->get('Mercato')->cartDeleted($this);
    }

    /**
     * Apply a coupon code to the session cart.
     */
    public function applyCoupon(string $code, string $email = '', bool $audit = false, array $context = []): array {
        $result = $this->validateCoupon($code, $email, $audit, $context);
        if (!empty($result['valid'])) {
            wire('session')->set(self::DISCOUNT_SESSION_KEY, (string) ($result['code'] ?? ''));
        } else {
            wire('session')->remove(self::DISCOUNT_SESSION_KEY);
        }
        return $result;
    }

    /**
     * Remove any coupon code attached to the session cart.
     */
    public function removeCoupon(string $code = ''): static {
        wire('session')->remove(self::DISCOUNT_SESSION_KEY);
        return $this;
    }

    /**
     * Validate a coupon code against the current cart without mutating line items.
     */
    public function validateCoupon(string $code, string $email = '', bool $audit = false, array $context = []): array {
        return wire('modules')->get('Mercato')->discountService()->resolveCartDiscount($code, $this, $email, $audit, $context);
    }

    /**
     * Return the currently attached coupon discount amount.
     */
    public function getDiscountTotal(string $email = ''): float {
        $code = (string) wire('session')->get(self::DISCOUNT_SESSION_KEY);
        if ($code === '') {
            return 0.0;
        }

        $result = $this->validateCoupon($code, $email);
        return !empty($result['valid']) ? round((float) ($result['amount'] ?? 0), 2) : 0.0;
    }

    // -----------------------------------------------------------------------
    // Stripe helpers
    // -----------------------------------------------------------------------

    /**
     * Create a Stripe PaymentIntent for the current cart total.
     * Additional Stripe params can be passed via $params.
     *
     * @deprecated Gateway-specific payment creation belongs in Mercato payment
     *             services/gateways. Kept for backward compatibility.
     *
     * @param array $params  Extra params merged into PaymentIntent::create()
     * @param array $options Stripe request options
     * @return \Stripe\PaymentIntent
     * @throws WireException if cart is empty
     */
    public function getStripePaymentIntent(array $params = [], array $options = []): \Stripe\PaymentIntent {
        if ($this->getSum() <= 0) {
            throw new WireException('Cannot create PaymentIntent: cart is empty or total is zero.');
        }
        /** @var StripeGateway $gateway */
        $gateway = wire('modules')->get('Mercato')->getGateway('stripe');
        return $gateway->createPaymentIntent($this->getSum(), $params, $options);
    }

    // -----------------------------------------------------------------------
    // Session persistence
    // -----------------------------------------------------------------------

    protected function save(bool $touch = true): static {
        if ($this->count() === 0) {
            wire('session')->remove(self::SESSION_KEY);
            wire('session')->remove(self::UPDATED_AT_SESSION_KEY);
        } else {
            wire('session')->set(self::SESSION_KEY, $this->toArray());
            if ($touch || !(int) wire('session')->get(self::UPDATED_AT_SESSION_KEY)) wire('session')->set(self::UPDATED_AT_SESSION_KEY, time());
        }
        return $this;
    }

    protected function rehydrateProductItems(array $items): array {
        $commerce = wire('modules')->get('Mercato');
        foreach ($items as $key => $item) {
            if (!is_array($item)) continue;
            $product = $this->findCartProduct($item);
            if (!$product) continue;
            $hydrated = $commerce->variantService()->hydrateItem($product, $item);
            $marketId = (string) ($item['market_id'] ?? 'default');
            $items[$key] = $marketId !== 'default' ? $commerce->marketService()->applyToItem($product, $hydrated, $marketId) : $hydrated;
        }
        return $items;
    }

    protected function findCartProduct(array $item): ?Page {
        $reference = $item['product_id'] ?? $item['id'] ?? '';
        if ($reference === '' || $reference === 0 || $reference === '0') return null;
        $product = wire('pages')->get($reference);
        return $product && $product->id && $product->template && $product->template->name === 'mrc-product' ? $product : null;
    }

    protected function canonicalizeUpdateItem(array $item): array {
        $key = (string) ($item['key'] ?? $item['id'] ?? '');
        $existing = $key !== '' ? $this->getItem($key) : null;
        if (!$existing) return $item;
        $quantity = (float) ($item['quantity'] ?? $existing['quantity'] ?? 1);
        if ($quantity <= 0) return ['key' => $key, 'id' => $existing['id'] ?? $key, 'quantity' => 0];
        $canonical = array_merge($existing, ['quantity' => $quantity]);
        $product = $this->findCartProduct($canonical);
        if (!$product) return $canonical;
        $commerce = wire('modules')->get('Mercato');
        $hydrated = $commerce->variantService()->hydrateItem($product, $canonical);
        $marketId = (string) ($canonical['market_id'] ?? 'default');
        return $marketId !== 'default' ? $commerce->marketService()->applyToItem($product, $hydrated, $marketId) : $hydrated;
    }
}
