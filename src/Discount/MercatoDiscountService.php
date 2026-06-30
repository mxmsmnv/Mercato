<?php
namespace ProcessWire;

/**
 * Discount page read service and cart discount resolver.
 */
final class MercatoDiscountService extends Wire {

    public function __construct(private readonly Mercato $commerce) {
        parent::__construct();
    }

    /**
     * @return MercatoDiscountRule[]
     */
    public function listDiscounts(int $limit = 100): array {
        $template = $this->wire('templates')->get('mrc-discount');
        if (!$template) {
            return [];
        }

        $limit = max(1, $limit);
        $pages = $this->wire('pages')->find("template=mrc-discount, include=all, sort=-modified, limit=$limit");
        $rules = [];
        foreach ($pages as $page) {
            $rules[] = $this->pageToRule($page);
        }
        return $rules;
    }

    public function findByCode(string $code): ?MercatoDiscountRule {
        $code = strtoupper(trim($code));
        if ($code === '' || !$this->wire('templates')->get('mrc-discount')) {
            return null;
        }

        $safeCode = $this->wire('sanitizer')->selectorValue($code);
        $page = $this->wire('pages')->get("template=mrc-discount, mrc_discount_code=$safeCode, include=all");
        return $page && $page->id ? $this->pageToRule($page) : null;
    }

    public function pageToRule(Page $page): MercatoDiscountRule {
        $type = (string) ($page->mrc_discount_type ?: MercatoDiscountType::PERCENTAGE);
        if (!MercatoDiscountType::isValid($type)) {
            $type = MercatoDiscountType::PERCENTAGE;
        }

        return new MercatoDiscountRule(
            pageId: (int) $page->id,
            title: (string) $page->title,
            code: strtoupper(trim((string) $page->mrc_discount_code)),
            type: $type,
            amount: (float) $page->mrc_discount_amount,
            percent: (float) $page->mrc_discount_percent,
            active: (int) $page->mrc_discount_active === 1 && !$page->isUnpublished(),
            startsAt: $this->parseTimestamp((string) $page->mrc_discount_starts),
            endsAt: $this->parseTimestamp((string) $page->mrc_discount_ends),
            usageLimit: (int) $page->mrc_discount_usage_limit,
            perCustomerLimit: (int) $page->mrc_discount_customer_limit,
            minimumOrderTotal: $page->hasField('mrc_discount_minimum_order')
                ? max(0.0, (float) $page->mrc_discount_minimum_order)
                : 0.0,
            productIds: $this->getProductTargetIds($page),
            collectionIds: $this->getCollectionTargetIds($page),
            customerTargets: $page->hasField('mrc_discount_customer_targets')
                ? $this->parseCustomerTargets((string) $page->mrc_discount_customer_targets)
                : [],
            usedCount: $this->getUsageCount(strtoupper(trim((string) $page->mrc_discount_code))),
            notes: (string) $page->mrc_discount_notes,
        );
    }

    public function calculatePreview(MercatoDiscountRule $rule, float $subtotal, float $shipping = 0.0): float {
        if (!$rule->isCurrentlyActive()) {
            return 0.0;
        }

        $discount = match ($rule->type) {
            MercatoDiscountType::PERCENTAGE => $subtotal * max(0.0, min(100.0, $rule->percent)) / 100,
            MercatoDiscountType::FIXED => $rule->amount,
            MercatoDiscountType::FREE_SHIPPING => $shipping,
            default => 0.0,
        };

        return round(max(0.0, min($subtotal + $shipping, $discount)), 2);
    }

    public function calculateCartPreview(MercatoDiscountRule $rule, MercatoProductList $cart): float {
        if (!$rule->isCurrentlyActive()) {
            return 0.0;
        }
        if ($rule->minimumOrderTotal > 0 && $cart->getSubtotal() < $rule->minimumOrderTotal) {
            return 0.0;
        }

        $subtotal = $this->getEligibleSubtotal($rule, $cart);
        $shipping = $this->getEligibleShipping($rule, $cart);

        return $this->calculatePreview($rule, $subtotal, $shipping);
    }

    public function resolveCartDiscount(string $code, MercatoProductList $cart, string $email = '', bool $audit = false, array $context = []): array {
        $code = strtoupper(trim($code));
        $hookContext = $this->commerce->beforeResolveDiscount([
            'code' => $code,
            'cart' => $cart,
            'email' => $email,
            'audit' => $audit,
            'context' => $context,
        ]);
        if (is_array($hookContext)) {
            $code = strtoupper(trim((string) ($hookContext['code'] ?? $code)));
            $email = (string) ($hookContext['email'] ?? $email);
            $audit = (bool) ($hookContext['audit'] ?? $audit);
            $context = is_array($hookContext['context'] ?? null) ? $hookContext['context'] : $context;
        }

        if ($code === '') {
            $result = [
                'valid' => false,
                'code' => '',
                'message' => 'Enter a coupon code.',
            ];
            return $this->finalizeCartDiscountResult($result, $audit, $context);
        }

        $rule = $this->findByCode($code);
        if (!$rule) {
            $result = [
                'valid' => false,
                'code' => $code,
                'message' => 'Coupon code was not found.',
            ];
            return $this->finalizeCartDiscountResult($result, $audit, $context);
        }

        if (!$rule->isCurrentlyActive()) {
            $result = [
                'valid' => false,
                'code' => $code,
                'message' => 'Coupon code is not active.',
            ];
            return $this->finalizeCartDiscountResult($result, $audit, $context);
        }

        if ($rule->usageLimit > 0 && $rule->usedCount >= $rule->usageLimit) {
            $result = [
                'valid' => false,
                'code' => $code,
                'message' => 'Coupon usage limit has been reached.',
            ];
            return $this->finalizeCartDiscountResult($result, $audit, $context);
        }

        $email = trim(strtolower($email));
        if (!$this->matchesCustomerTarget($rule, $email)) {
            $result = [
                'valid' => false,
                'code' => $code,
                'message' => $email === ''
                    ? 'Enter your email address before applying this customer-specific coupon.'
                    : 'Coupon code is not available for this customer.',
            ];
            return $this->finalizeCartDiscountResult($result, $audit, $context + ['email' => $email]);
        }

        if ($rule->perCustomerLimit > 0 && $email !== '') {
            $customerUsage = $this->getCustomerUsageCount($rule->code, $email);
            if ($customerUsage >= $rule->perCustomerLimit) {
                $result = [
                    'valid' => false,
                    'code' => $code,
                    'message' => 'Coupon has already been used by this customer.',
                    'per_customer_limit' => $rule->perCustomerLimit,
                    'customer_used_count' => $customerUsage,
                ];
                return $this->finalizeCartDiscountResult($result, $audit, $context + ['email' => $email]);
            }
        }

        if ($rule->minimumOrderTotal > 0 && $cart->getSubtotal() < $rule->minimumOrderTotal) {
            $result = [
                'valid' => false,
                'code' => $code,
                'message' => sprintf('Coupon requires a minimum order total of %s.', $this->commerce->formatPrice($rule->minimumOrderTotal)),
                'minimum_order_total' => $rule->minimumOrderTotal,
            ];
            return $this->finalizeCartDiscountResult($result, $audit, $context + ['email' => $email]);
        }

        if (!$this->matchesProductTarget($rule, $cart)) {
            $result = [
                'valid' => false,
                'code' => $code,
                'message' => 'Coupon code does not apply to the products in this cart.',
            ];
            return $this->finalizeCartDiscountResult($result, $audit, $context + ['email' => $email]);
        }

        $discount = $this->calculateCartPreview($rule, $cart);
        if ($discount <= 0) {
            $result = [
                'valid' => false,
                'code' => $code,
                'message' => 'Coupon code does not apply to this cart.',
            ];
            return $this->finalizeCartDiscountResult($result, $audit, $context + ['email' => $email]);
        }

        $result = [
            'valid' => true,
            'code' => $rule->code,
            'title' => $rule->title,
            'type' => $rule->type,
            'amount' => $discount,
            'usage_limit' => $rule->usageLimit,
            'per_customer_limit' => $rule->perCustomerLimit,
            'minimum_order_total' => $rule->minimumOrderTotal,
            'product_ids' => $rule->productIds,
            'collection_ids' => $rule->collectionIds,
            'customer_targets' => $rule->customerTargets,
            'used_count' => $rule->usedCount,
            'message' => 'Coupon applied.',
        ];
        return $this->finalizeCartDiscountResult($result, $audit, $context + ['email' => $email]);
    }

    protected function finalizeCartDiscountResult(array $result, bool $audit, array $context = []): array {
        $hooked = $this->commerce->afterResolveDiscount($result, [
            'context' => $context,
            'audit' => $audit,
        ]);
        if (is_array($hooked)) {
            $result = $hooked;
        }

        if ($audit) {
            $this->recordAuditEvent(!empty($result['valid']) ? 'accepted' : 'rejected', $result, $context);
        }
        return $result;
    }

    public function getUsageCount(string $code): int {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return 0;
        }

        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        $safeCode = $this->wire('sanitizer')->selectorValue($code);
        $selector = "template=$template, include=all, mrc_discount_code=$safeCode, mrc_payment_complete=1";
        return (int) $this->wire('pages')->count($selector);
    }

    public function getCustomerUsageCount(string $code, string $email): int {
        $code = strtoupper(trim($code));
        $email = trim(strtolower($email));
        if ($code === '' || $email === '') {
            return 0;
        }

        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        $safeCode = $this->wire('sanitizer')->selectorValue($code);
        $safeEmail = $this->wire('sanitizer')->selectorValue($email);
        $selector = "template=$template, include=all, mrc_discount_code=$safeCode, mrc_email=$safeEmail, mrc_payment_complete=1";
        return (int) $this->wire('pages')->count($selector);
    }

    public function recordAuditEvent(string $event, array $discount, array $context = []): void {
        $payload = [
            'event' => $event,
            'code' => (string) ($discount['code'] ?? ''),
            'valid' => !empty($discount['valid']),
            'amount' => round((float) ($discount['amount'] ?? 0), 2),
            'message' => (string) ($discount['message'] ?? ''),
            'email' => (string) ($context['email'] ?? ''),
            'source' => (string) ($context['source'] ?? ''),
            'order_page_id' => (int) ($context['order_page_id'] ?? 0),
            'invoice' => (string) ($context['invoice'] ?? ''),
            'customer_used_count' => (int) ($discount['customer_used_count'] ?? 0),
            'per_customer_limit' => (int) ($discount['per_customer_limit'] ?? 0),
        ];

        $this->wire('log')->save('mercato-discounts', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function getProductTargetIds(Page $page): array {
        if (!$page->hasField('mrc_discount_products')) {
            return [];
        }

        $value = $page->get('mrc_discount_products');
        if ($value instanceof PageArray) {
            $ids = [];
            foreach ($value as $product) {
                if ($product instanceof Page && $product->id) {
                    $ids[] = (int) $product->id;
                }
            }
            return array_values(array_unique($ids));
        }

        if ($value instanceof Page && $value->id) {
            return [(int) $value->id];
        }

        return [];
    }

    protected function getCollectionTargetIds(Page $page): array {
        if (!$page->hasField('mrc_discount_collections')) {
            return [];
        }

        $value = $page->get('mrc_discount_collections');
        if ($value instanceof PageArray) {
            $ids = [];
            foreach ($value as $collection) {
                if ($collection instanceof Page && $collection->id) {
                    $ids[] = (int) $collection->id;
                }
            }
            return array_values(array_unique($ids));
        }

        if ($value instanceof Page && $value->id) {
            return [(int) $value->id];
        }

        return [];
    }

    protected function parseCustomerTargets(string $value): array {
        $parts = preg_split('/[\s,;]+/', strtolower(trim($value))) ?: [];
        $targets = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $targets[] = $part;
        }
        return array_values(array_unique($targets));
    }

    protected function matchesCustomerTarget(MercatoDiscountRule $rule, string $email): bool {
        if (!$rule->customerTargets) {
            return true;
        }

        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        foreach ($rule->customerTargets as $target) {
            $target = strtolower(trim((string) $target));
            if ($target === '') continue;
            if ($target[0] === '@' && str_ends_with($email, $target)) {
                return true;
            }
            if (str_contains($target, '@') && $email === $target) {
                return true;
            }
            if (!str_contains($target, '@') && str_ends_with($email, '@' . ltrim($target, '@'))) {
                return true;
            }
        }

        return false;
    }

    protected function matchesProductTarget(MercatoDiscountRule $rule, MercatoProductList $cart): bool {
        if (!$rule->productIds && !$rule->collectionIds) {
            return true;
        }

        foreach ($cart->toArray() as $item) {
            if ($this->itemMatchesTargets($rule, $item)) {
                return true;
            }
        }

        return false;
    }

    protected function getEligibleSubtotal(MercatoDiscountRule $rule, MercatoProductList $cart): float {
        if (!$rule->productIds && !$rule->collectionIds) {
            return $cart->getSubtotal();
        }

        $subtotal = 0.0;
        foreach ($cart->toArray() as $item) {
            if (!$this->itemMatchesTargets($rule, $item)) {
                continue;
            }
            $subtotal += (float) ($item['sum'] ?? 0);
        }
        return round(max(0.0, $subtotal), 2);
    }

    protected function getEligibleShipping(MercatoDiscountRule $rule, MercatoProductList $cart): float {
        if (!$rule->productIds && !$rule->collectionIds) {
            return $cart->getShipping();
        }

        $shipping = 0.0;
        foreach ($cart->toArray() as $item) {
            if (!$this->itemMatchesTargets($rule, $item)) {
                continue;
            }
            $shipping += (float) ($item['sum_shipping'] ?? 0);
        }
        return round(max(0.0, $shipping), 2);
    }

    protected function itemMatchesTargets(MercatoDiscountRule $rule, array $item): bool {
        if ($rule->productIds) {
            foreach (['product_id', 'id'] as $key) {
                $productId = (int) ($item[$key] ?? 0);
                if ($productId > 0 && in_array($productId, $rule->productIds, true)) {
                    return true;
                }
            }
        }

        if (!$rule->collectionIds) {
            return false;
        }

        foreach ((array) ($item['collection_ids'] ?? []) as $collectionId) {
            if (in_array((int) $collectionId, $rule->collectionIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function parseTimestamp(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? $timestamp : null;
    }
}
