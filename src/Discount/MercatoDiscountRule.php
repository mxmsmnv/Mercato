<?php
namespace ProcessWire;

/**
 * Read model for a discount/coupon page.
 */
final class MercatoDiscountRule {

    public function __construct(
        public readonly int $pageId,
        public readonly string $title,
        public readonly string $code,
        public readonly string $type,
        public readonly float $amount = 0.0,
        public readonly float $percent = 0.0,
        public readonly bool $active = false,
        public readonly ?int $startsAt = null,
        public readonly ?int $endsAt = null,
        public readonly int $usageLimit = 0,
        public readonly int $perCustomerLimit = 0,
        public readonly float $minimumOrderTotal = 0.0,
        public readonly array $productIds = [],
        public readonly array $collectionIds = [],
        public readonly array $customerTargets = [],
        public readonly int $usedCount = 0,
        public readonly string $notes = '',
    ) {
    }

    public function isCurrentlyActive(?int $now = null): bool {
        $now ??= time();
        if (!$this->active) {
            return false;
        }
        if ($this->startsAt && $this->startsAt > $now) {
            return false;
        }
        if ($this->endsAt && $this->endsAt < $now) {
            return false;
        }
        return true;
    }

    public function toArray(): array {
        return [
            'page_id' => $this->pageId,
            'title' => $this->title,
            'code' => $this->code,
            'type' => $this->type,
            'amount' => $this->amount,
            'percent' => $this->percent,
            'active' => $this->active,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'usage_limit' => $this->usageLimit,
            'per_customer_limit' => $this->perCustomerLimit,
            'minimum_order_total' => $this->minimumOrderTotal,
            'product_ids' => $this->productIds,
            'collection_ids' => $this->collectionIds,
            'customer_targets' => $this->customerTargets,
            'used_count' => $this->usedCount,
            'notes' => $this->notes,
        ];
    }
}
