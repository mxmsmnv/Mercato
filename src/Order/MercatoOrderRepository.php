<?php
namespace ProcessWire;

/**
 * ProcessWire page persistence for Mercato orders.
 *
 * This keeps order-page storage separate from the Mercato facade while
 * preserving the current mrc-order field schema.
 */
class MercatoOrderRepository extends Wire {

    public function __construct(
        protected Mercato $commerce,
    ) {
        parent::__construct();
    }

    public function savePendingOrder(array $pending): Page {
        $pages = $this->wire('pages');
        $wire = $this->wire();
        $hookPending = $this->commerce->beforeCreateOrder($pending);
        if (is_array($hookPending)) {
            $pending = $hookPending;
        }

        $ordersParent = $pages->get('/' . ltrim((string) $this->commerce->orders_parent, '/') . '/');
        if (!$ordersParent || !$ordersParent->id) {
            throw new WireException(
                sprintf($this->commerce->_('Orders parent page "%s" not found.'), $this->commerce->orders_parent)
            );
        }

        $template = $this->wire('templates')->get((string) $this->commerce->order_template);
        if (!$template) {
            throw new WireException(
                sprintf($this->commerce->_('Order template "%s" not found.'), $this->commerce->order_template)
            );
        }

        $savedUser = $wire->user;
        $superuser = $wire->users->get('template=user, roles.name=superuser');
        if (!$superuser || !$superuser->id) {
            throw new WireException($this->commerce->_('Mercato: no superuser found — cannot save order page.'));
        }

        $wire->users->setCurrentUser($superuser);

        try {
            $page = $this->findExistingOrderPage($pending);
            $created = false;
            if (!$page) {
                $page = $this->createOrderPage($template, $ordersParent);
                $created = true;
            }

            $page->of(false);

            foreach ($this->getFieldMap() as $formKey => $fieldName) {
                if (isset($pending[$formKey]) && $page->template->hasField($fieldName)) {
                    $page->set($fieldName, $pending[$formKey]);
                }
            }

            if (isset($pending['mrc_items']) && $page->template->hasField('mrc_items')) {
                $page->mrc_items = $pending['mrc_items'];
            }

            if ($page->template->hasField('mrc_customer_user_id')) {
                $customerUserId = (int) ($pending['mrc_customer_user_id'] ?? 0);
                if ($customerUserId === 0 && $savedUser->id && !$savedUser->isGuest() && $savedUser->hasRole('mercato-customer') && (int) ($savedUser->mrc_customer_verified ?? 0) === 1) {
                    $customerUserId = (int) $savedUser->id;
                }
                $existingOwnerId = (int) ($page->mrc_customer_user_id ?? 0);
                if (MercatoAccountPolicy::mergeConflict($existingOwnerId, $customerUserId)) throw new WireException($this->commerce->_('Order ownership conflict.'), 409);
                if ($customerUserId > 0) $page->mrc_customer_user_id = $customerUserId;
            }

            if ($page->template->hasField('mrc_payment_status')) {
                $page->mrc_payment_status = $pending['payment_status'] ?? Mercato::PAYMENT_STATUS_PENDING;
            }

            if (!$page->mrc_invoice_date) {
                $page->mrc_invoice_date = date('Y-m-d H:i:s');
            }

            $pages->save($page);

            $invoiceNumber = $page->mrc_invoice_number ?: $this->commerce->formatInvoiceNumber((int) $page->id);
            $page->title = $invoiceNumber;
            $page->mrc_invoice_number = $invoiceNumber;

            if (!empty($pending['payment_complete']) || (($pending['payment_status'] ?? '') === Mercato::PAYMENT_STATUS_PAID)) {
                $page->removeStatus(Page::statusHidden);
            }

            $pages->save($page);
            $this->commerce->afterCreateOrder($page, $pending, $created);
        } finally {
            $wire->users->setCurrentUser($savedUser);
        }

        return $page;
    }

    public function findByMolliePayment(array $payment): ?Page {
        $pages = $this->wire('pages');
        $orderId = (int) ($payment['metadata']['mrc_order_id'] ?? 0);

        if ($orderId > 0) {
            $order = $pages->get($orderId);
            if ($order && $order->id && $order->template->name === $this->commerce->order_template) {
                return $order;
            }
        }

        $paymentId = $this->wire('sanitizer')->selectorValue((string) ($payment['id'] ?? ''));
        if ($paymentId !== '') {
            $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
            $order = $pages->get("template=$template, mrc_mollie_payment_id=$paymentId, include=all");
            if ($order && $order->id) {
                return $order;
            }
        }

        return null;
    }

    public function findByStripePaymentIntent(string $paymentIntentId): ?Page {
        $paymentIntentId = $this->wire('sanitizer')->selectorValue(trim($paymentIntentId));
        if ($paymentIntentId === '') {
            return null;
        }
        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        $order = $this->wire('pages')->get("template=$template, mrc_stripe_payment_intent_id=$paymentIntentId, include=all");
        return $order && $order->id ? $order : null;
    }

    public function findByPayPalReference(string $paypalOrderId = '', int $orderId = 0, string $invoice = ''): ?Page {
        $pages = $this->wire('pages');
        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);

        if ($orderId > 0) {
            $order = $pages->get($orderId);
            if ($order && $order->id && $order->template->name === $this->commerce->order_template) {
                return $order;
            }
        }

        $invoice = $this->wire('sanitizer')->selectorValue(trim($invoice));
        if ($invoice !== '') {
            $order = $pages->get("template=$template, include=all, mrc_invoice_number|title=$invoice");
            if ($order && $order->id) {
                return $order;
            }
        }

        $paypalOrderId = trim($paypalOrderId);
        if ($paypalOrderId === '') {
            return null;
        }

        $selectorOrderId = $this->wire('sanitizer')->selectorValue($paypalOrderId);
        if ($selectorOrderId === '') {
            return null;
        }

        $order = $pages->get("template=$template, include=all, mrc_payment_method=paypal, mrc_payment_details%=$selectorOrderId");
        return $order && $order->id ? $order : null;
    }

    public function findByOrderReference(string $reference): ?Page {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        $selectorReference = $this->wire('sanitizer')->selectorValue(ltrim($reference, "# \t\n\r\0\x0B"));
        if ($selectorReference !== '') {
            $order = $this->wire('pages')->get("template=$template, include=all, mrc_invoice_number|title=$selectorReference");
            if ($order && $order->id) {
                return $order;
            }
        }

        $idReference = ltrim($reference, "# \t\n\r\0\x0B");
        if (ctype_digit($idReference)) {
            $order = $this->wire('pages')->get((int) $idReference);
            if ($order && $order->id && $order->template->name === (string) $this->commerce->order_template) {
                return $order;
            }
        }

        return null;
    }

    public function pageToPendingData(Page $order): array {
        return [
            'mrc_order_page_id' => $order->id,
            'mrc_invoice_number' => (string) $order->mrc_invoice_number,
            'first_name' => (string) $order->mrc_first_name,
            'last_name' => (string) $order->mrc_last_name,
            'email' => (string) $order->mrc_email,
            'phone' => (string) $order->mrc_phone,
            'address' => (string) $order->mrc_address,
            'address_2' => $order->hasField('mrc_address_2') ? (string) $order->mrc_address_2 : '',
            'city' => (string) $order->mrc_city,
            'zip' => (string) $order->mrc_zip,
            'country' => (string) $order->mrc_country,
            'mrc_billing_address' => $order->hasField('mrc_billing_address') ? (string) $order->mrc_billing_address : '',
            'mrc_shipping_address' => $order->hasField('mrc_shipping_address') ? (string) $order->mrc_shipping_address : '',
            'mrc_receipt_details' => $order->hasField('mrc_receipt_details') ? (string) $order->mrc_receipt_details : '',
            'notes' => (string) $order->mrc_notes,
            'payment_method' => (string) $order->mrc_payment_method,
            'payment_status' => (string) ($order->mrc_payment_status ?: Mercato::PAYMENT_STATUS_PENDING),
            'payment_complete' => (int) $order->mrc_payment_complete,
            'paid_date' => (string) $order->mrc_paid_date,
            'payment_details' => (string) $order->mrc_payment_details,
            'mrc_items' => (string) $order->mrc_items,
            'mrc_currency' => (string) ($order->mrc_currency ?: $this->commerce->currency),
            'mrc_subtotal_amount' => $order->hasField('mrc_subtotal_amount') ? round((float) $order->mrc_subtotal_amount, 2) : 0.0,
            'mrc_shipping_amount' => $order->hasField('mrc_shipping_amount') ? round((float) $order->mrc_shipping_amount, 2) : 0.0,
            'mrc_discount_code' => $order->hasField('mrc_discount_code') ? (string) $order->mrc_discount_code : '',
            'mrc_discount_total' => $order->hasField('mrc_discount_total') ? round((float) $order->mrc_discount_total, 2) : 0.0,
            'mrc_discount_details' => $order->hasField('mrc_discount_details') ? (string) $order->mrc_discount_details : '',
            'mrc_total_amount' => $this->getTotalAmount($order),
            'mrc_tax_amount' => $order->hasField('mrc_tax_amount') ? round((float) $order->mrc_tax_amount, 2) : 0.0,
            'mrc_tax_details' => $order->hasField('mrc_tax_details') ? (string) $order->mrc_tax_details : '',
            'mrc_tax_provider_reference' => $order->hasField('mrc_tax_provider_reference') ? (string) $order->mrc_tax_provider_reference : '',
            'mrc_tax_committed' => $order->hasField('mrc_tax_committed') ? (int) $order->mrc_tax_committed : 0,
            'fulfilment_method' => $order->hasField('mrc_fulfilment_method') ? (string) $order->mrc_fulfilment_method : '',
            'mrc_fulfilment_label' => $order->hasField('mrc_fulfilment_label') ? (string) $order->mrc_fulfilment_label : '',
            'mrc_fulfilment_details' => $order->hasField('mrc_fulfilment_details') ? (string) $order->mrc_fulfilment_details : '',
            'mollie_payment_id' => (string) $order->mrc_mollie_payment_id,
            'stripe_payment_intent_id' => (string) $order->mrc_stripe_payment_intent_id,
        ];
    }

    public function getTotalAmount(Page $order): float {
        $hasSnapshot = $order->hasField('mrc_fulfilment_method') && trim((string) $order->mrc_fulfilment_method) !== '';
        if ($order->hasField('mrc_total_amount') && ((float) $order->mrc_total_amount > 0 || $hasSnapshot)) {
            return round((float) $order->mrc_total_amount, 2);
        }

        $items = json_decode((string) $order->mrc_items, true);
        if (!is_array($items)) {
            return 0.0;
        }
        return $this->commerce->productList($items)->getSum();
    }

    public function getFieldMap(): array {
        return [
            'first_name' => 'mrc_first_name',
            'last_name' => 'mrc_last_name',
            'email' => 'mrc_email',
            'phone' => 'mrc_phone',
            'address' => 'mrc_address',
            'address_2' => 'mrc_address_2',
            'city' => 'mrc_city',
            'zip' => 'mrc_zip',
            'country' => 'mrc_country',
            'mrc_billing_address' => 'mrc_billing_address',
            'mrc_shipping_address' => 'mrc_shipping_address',
            'mrc_receipt_details' => 'mrc_receipt_details',
            'payment_method' => 'mrc_payment_method',
            'payment_complete' => 'mrc_payment_complete',
            'payment_status' => 'mrc_payment_status',
            'payment_details' => 'mrc_payment_details',
            'mrc_policy_accepted' => 'mrc_policy_accepted',
            'mrc_policy_acceptance_details' => 'mrc_policy_acceptance_details',
            'paid_date' => 'mrc_paid_date',
            'mrc_currency' => 'mrc_currency',
            'mrc_subtotal_amount' => 'mrc_subtotal_amount',
            'mrc_shipping_amount' => 'mrc_shipping_amount',
            'mrc_discount_code' => 'mrc_discount_code',
            'mrc_discount_total' => 'mrc_discount_total',
            'mrc_discount_details' => 'mrc_discount_details',
            'mrc_total_amount' => 'mrc_total_amount',
            'mrc_tax_amount' => 'mrc_tax_amount',
            'mrc_tax_details' => 'mrc_tax_details',
            'mrc_tax_provider_reference' => 'mrc_tax_provider_reference',
            'mrc_tax_committed' => 'mrc_tax_committed',
            'mrc_customer_user_id' => 'mrc_customer_user_id',
            'fulfilment_method' => 'mrc_fulfilment_method',
            'mrc_fulfilment_label' => 'mrc_fulfilment_label',
            'mrc_fulfilment_details' => 'mrc_fulfilment_details',
            'notes' => 'mrc_notes',
            'stripe_payment_intent_id' => 'mrc_stripe_payment_intent_id',
            'mollie_payment_id' => 'mrc_mollie_payment_id',
        ];
    }

    public function assertStockAvailable(MercatoProductList $cart, int $excludeOrderId = 0): void {
        foreach ($cart->toArray() as $item) {
            $productId = $this->getItemProductId($item);
            $quantity = (int) ceil((float) ($item['quantity'] ?? 1));
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $product = $this->wire('pages')->get($productId);
            if (!$product || !$product->id || $product->template->name !== 'mrc-product') {
                continue;
            }
            $variantId = (string) ($item['variant_id'] ?? '');
            $purchasability = $this->commerce->getProductPurchasability($product, $quantity, 0, $excludeOrderId, $variantId !== '' ? $variantId : null);
            if (empty($purchasability['ok'])) {
                throw new WireException(sprintf(
                    $this->commerce->_('"%s" cannot be purchased: %s'),
                    (string) $product->title,
                    (string) ($purchasability['first_error'] ?? $this->commerce->_('Product is not available.'))
                ), 409);
            }
            if ($variantId !== '') {
                continue;
            }
            if (!$product->hasField('mrc_stock')) {
                continue;
            }

            if ($this->allowsOversell($product)) {
                continue;
            }

            $stock = (int) $product->mrc_stock;
            $reserved = $this->getReservedQuantityForProduct($productId, $excludeOrderId);
            $available = max(0, $stock - $reserved);
            if ($available < $quantity) {
                throw new WireException(sprintf(
                    $this->commerce->_('Only %d item(s) of "%s" are available.'),
                    $available,
                    (string) $product->title
                ), 409);
            }
        }
    }

    public function getProductStockPolicy(Page $product): string {
        $policy = $product->hasField('mrc_stock_policy') ? strtolower(trim((string) $product->mrc_stock_policy)) : '';
        return in_array($policy, ['deny', 'backorder', 'preorder'], true) ? $policy : 'deny';
    }

    public function allowsOversell(Page $product): bool {
        return in_array($this->getProductStockPolicy($product), ['backorder', 'preorder'], true);
    }

    public function reserveStock(Page $order, int $minutes = 30): void {
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) return;
        if (!$order->hasField('mrc_inventory_reserved')) return;

        $items = $this->getOrderReservableInventoryItems($order);
        $order->of(false);
        if (!$items) {
            $order->mrc_inventory_reserved = 0;
            if ($order->hasField('mrc_inventory_reserved_until')) {
                $order->mrc_inventory_reserved_until = '';
            }
            $this->wire('pages')->save($order);
            return;
        }
        $order->mrc_inventory_reserved = 1;
        if ($order->hasField('mrc_inventory_reserved_until')) {
            $order->mrc_inventory_reserved_until = date('Y-m-d H:i:s', time() + max(1, $minutes) * 60);
        }
        $this->wire('pages')->save($order);
        $this->recordInventoryMovements('reserved', $order, $items, [
            'reserved_until' => $order->hasField('mrc_inventory_reserved_until') ? (string) $order->mrc_inventory_reserved_until : '',
        ]);
    }

    public function releaseStockReservation(Page $order, string $reason = 'released'): void {
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) return;
        if (!$order->hasField('mrc_inventory_reserved')) return;
        if ((int) $order->mrc_inventory_reserved !== 1) return;

        $items = $this->getOrderReservableInventoryItems($order);
        $order->of(false);
        $order->mrc_inventory_reserved = 0;
        if ($order->hasField('mrc_inventory_reserved_until')) {
            $order->mrc_inventory_reserved_until = '';
        }
        $this->wire('pages')->save($order);
        $this->recordInventoryMovements($reason, $order, $items);
    }

    public function getReservedQuantityForProduct(int $productId, int $excludeOrderId = 0): int {
        return $this->getReservedQuantity($productId, null, $excludeOrderId);
    }

    public function getReservedQuantityForVariant(int $productId, string $variantId, int $excludeOrderId = 0): int {
        $variantId = MercatoVariantDefinition::slug($variantId);
        return $variantId === '' ? 0 : $this->getReservedQuantity($productId, $variantId, $excludeOrderId);
    }

    protected function getReservedQuantity(int $productId, ?string $variantId, int $excludeOrderId = 0): int {
        if ($productId <= 0) return 0;
        $product = $this->wire('pages')->get($productId);
        $resolvedVariant = $product && $product->id && $variantId !== null
            ? $this->commerce->variantService()->resolve($product, $variantId, [], false)
            : null;
        $policy = $resolvedVariant ? (string) $resolvedVariant['stock_policy'] : ($product && $product->id ? $this->getProductStockPolicy($product) : 'deny');
        if (in_array($policy, ['backorder', 'preorder'], true)) {
            return 0;
        }

        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        $orders = $this->wire('pages')->find("template=$template, include=all, mrc_inventory_reserved=1");
        $reserved = 0;
        $now = time();

        foreach ($orders as $order) {
            if ((int) $order->id === $excludeOrderId) continue;
            if ((int) $order->mrc_payment_complete === 1 || (string) $order->mrc_payment_status === Mercato::PAYMENT_STATUS_PAID) continue;

            $until = strtotime((string) $order->mrc_inventory_reserved_until);
            if ($until !== false && $until < $now) continue;

            $items = json_decode((string) $order->mrc_items, true);
            if (!is_array($items)) continue;

            foreach ($items as $item) {
                if (!is_array($item)) continue;
                if ($this->getItemProductId($item) !== $productId) continue;
                if ($variantId !== null && (string) ($item['variant_id'] ?? '') !== $variantId) continue;
                $reserved += (int) ceil((float) ($item['quantity'] ?? 1));
            }
        }

        if ((string) ($this->commerce->quote_inventory_policy ?? 'none') === 'on_acceptance') {
            $quoteTemplate = $this->wire('sanitizer')->selectorValue((string) ($this->commerce->quote_template ?? 'mrc-quote'));
            $quotes = $this->wire('pages')->find("template=$quoteTemplate, include=all, mrc_inventory_reserved=1");
            foreach ($quotes as $quote) {
                $until = strtotime((string) $quote->mrc_inventory_reserved_until);
                if ($until !== false && $until < $now) continue;
                $items = json_decode((string) $quote->mrc_items, true);
                foreach (is_array($items) ? $items : [] as $item) {
                    if (!is_array($item) || $this->getItemProductId($item) !== $productId) continue;
                    if ($variantId !== null && (string) ($item['variant_id'] ?? '') !== $variantId) continue;
                    $reserved += (int) ceil((float) ($item['quantity'] ?? 1));
                }
            }
        }

        return $reserved;
    }

    public function cleanupExpiredReservations(): int {
        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        $orders = $this->wire('pages')->find("template=$template, include=all, mrc_inventory_reserved=1");
        $count = 0;
        $now = time();

        foreach ($orders as $order) {
            $until = strtotime((string) $order->mrc_inventory_reserved_until);
            if ($until === false || $until >= $now) {
                continue;
            }

            $this->releaseStockReservation($order, 'expired');
            $count++;
        }

        return $count;
    }

    public function countExpiredReservations(): int {
        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        $orders = $this->wire('pages')->find("template=$template, include=all, mrc_inventory_reserved=1");
        $count = 0;
        $now = time();

        foreach ($orders as $order) {
            $until = strtotime((string) $order->mrc_inventory_reserved_until);
            if ($until !== false && $until < $now) {
                $count++;
            }
        }

        return $count;
    }

    public function markStaleDraftOrdersExpired(int $olderThanDays, array $candidateOrderIds = []): int {
        $olderThanDays = max(0, $olderThanDays);
        $cutoff = time() - ($olderThanDays * 86400);
        $orders = $this->findStaleDraftOrders($cutoff, $candidateOrderIds);
        $count = 0;

        foreach ($orders as $order) {
            if (!$this->isDraftOrderExpirable($order)) {
                continue;
            }
            $order->of(false);
            if ($order->hasField('mrc_payment_status')) {
                $order->mrc_payment_status = MercatoPaymentStatus::EXPIRED;
            }
            if ($order->hasField('mrc_payment_complete')) {
                $order->mrc_payment_complete = 0;
            }
            if ($order->hasField('mrc_payment_details')) {
                $details = json_decode((string) $order->mrc_payment_details, true);
                $details = is_array($details) ? $details : [];
                $details['state'] = 'expired';
                $details['expired_at'] = date(DATE_ATOM);
                $details['expired_reason'] = 'stale_draft_retention';
                $order->mrc_payment_details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $this->wire('pages')->save($order);
            $this->releaseStockReservation($order, 'stale_draft_expired');
            $count++;
        }

        return $count;
    }

    public function countStaleDraftOrders(int $olderThanDays, array $candidateOrderIds = []): int {
        $olderThanDays = max(0, $olderThanDays);
        $cutoff = time() - ($olderThanDays * 86400);
        $count = 0;
        foreach ($this->findStaleDraftOrders($cutoff, $candidateOrderIds) as $order) {
            if ($this->isDraftOrderExpirable($order)) {
                $count++;
            }
        }
        return $count;
    }

    protected function findStaleDraftOrders(int $cutoff, array $candidateOrderIds = []): PageArray {
        $template = $this->wire('sanitizer')->selectorValue((string) $this->commerce->order_template);
        $candidateOrderIds = array_values(array_filter(array_map('intval', $candidateOrderIds), static fn(int $id): bool => $id > 0));
        if ($candidateOrderIds !== []) {
            $ids = implode('|', $candidateOrderIds);
            return $this->wire('pages')->find("template=$template, include=all, id=$ids");
        }
        $cutoff = max(0, $cutoff);
        return $this->wire('pages')->find("template=$template, include=all, status=hidden, created<$cutoff");
    }

    protected function isDraftOrderExpirable(Page $order): bool {
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return false;
        }
        if (!$order->isHidden()) {
            return false;
        }
        if ((int) $order->mrc_payment_complete === 1) {
            return false;
        }
        $status = strtolower(trim((string) ($order->mrc_payment_status ?: MercatoPaymentStatus::PENDING)));
        return in_array($status, [
            MercatoPaymentStatus::CREATED,
            MercatoPaymentStatus::REQUIRES_PAYMENT,
            MercatoPaymentStatus::REQUIRES_CONFIRMATION,
            MercatoPaymentStatus::REQUIRES_ACTION,
            MercatoPaymentStatus::PENDING,
        ], true);
    }

    public function decrementStockOnce(Page $order): array {
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return ['adjusted' => false, 'items' => [], 'errors' => ['Invalid order page.']];
        }

        if ($order->hasField('mrc_inventory_adjusted') && (int) $order->mrc_inventory_adjusted === 1) {
            return ['adjusted' => false, 'items' => [], 'errors' => [], 'message' => 'Inventory already adjusted.'];
        }

        $items = json_decode((string) $order->mrc_items, true);
        if (!is_array($items)) {
            return ['adjusted' => false, 'items' => [], 'errors' => ['Order item JSON is invalid.']];
        }

        $wire = $this->wire();
        $pages = $this->wire('pages');
        $savedUser = $wire->user;
        $superuser = $wire->users->get('template=user, roles.name=superuser');
        if (!$superuser || !$superuser->id) {
            throw new WireException($this->commerce->_('Mercato: no superuser found — cannot adjust inventory.'));
        }

        $adjusted = [];
        $errors = [];
        $wire->users->setCurrentUser($superuser);

        try {
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $productId = $this->getItemProductId($item);
                $quantity = (int) ceil((float) ($item['quantity'] ?? 1));
                if ($quantity <= 0) continue;
                if ($productId <= 0) {
                    continue;
                }

                $product = $pages->get($productId);
                if (!$product || !$product->id || !$product->hasField('mrc_stock')) {
                    $errors[] = sprintf('Stock cannot be adjusted for product id %d.', $productId);
                    continue;
                }

                $variantId = (string) ($item['variant_id'] ?? '');
                if ($variantId !== '') {
                    try {
                        $stockResult = $this->commerce->variantService()->updateStock($product, $variantId, -$quantity);
                        $movement = [
                            'product_id' => (int) $product->id, 'title' => (string) $product->title,
                            'variant_id' => $variantId, 'variant_label' => (string) ($item['variant_label'] ?? ''),
                            'sku' => (string) ($item['sku'] ?? $stockResult['sku'] ?? ''), 'quantity' => $quantity,
                            'before' => (int) $stockResult['before'], 'after' => (int) $stockResult['after'],
                            'stock_policy' => (string) ($item['stock_policy'] ?? 'deny'),
                        ];
                        $adjusted[] = $movement;
                        $this->recordInventoryMovement('sold', $order, $movement);
                    } catch (WireException $e) {
                        $errors[] = $e->getMessage();
                    }
                    continue;
                }

                $before = (int) $product->mrc_stock;
                $allowsOversell = $this->allowsOversell($product);
                if (!$allowsOversell && $before < $quantity) {
                    $errors[] = sprintf('Insufficient stock for %s: %d available, %d requested.', (string) $product->title, $before, $quantity);
                    continue;
                }

                $product->of(false);
                $product->mrc_stock = $before - $quantity;
                $pages->save($product);
                $movement = [
                    'product_id' => (int) $product->id,
                    'title' => (string) $product->title,
                    'quantity' => $quantity,
                    'before' => $before,
                    'after' => (int) $product->mrc_stock,
                    'stock_policy' => $this->getProductStockPolicy($product),
                ];
                $adjusted[] = $movement;
                $this->recordInventoryMovement('sold', $order, $movement);
            }

            $order->of(false);
            if ($order->hasField('mrc_inventory_adjusted')) {
                $order->mrc_inventory_adjusted = $errors ? 0 : 1;
            }
            if (!$errors && $order->hasField('mrc_inventory_reserved')) {
                $order->mrc_inventory_reserved = 0;
            }
            if (!$errors && $order->hasField('mrc_inventory_reserved_until')) {
                $order->mrc_inventory_reserved_until = '';
            }
            if ($order->hasField('mrc_inventory_details')) {
                $order->mrc_inventory_details = json_encode([
                    'adjusted_at' => date('Y-m-d H:i:s'),
                    'items' => $adjusted,
                    'errors' => $errors,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $pages->save($order);
        } finally {
            $wire->users->setCurrentUser($savedUser);
        }

        return ['adjusted' => !$errors, 'items' => $adjusted, 'errors' => $errors];
    }

    public function restoreStockAfterFullRefundOnce(Page $order): array {
        if (!$order || !$order->id || $order->template->name !== $this->commerce->order_template) {
            return ['restored' => false, 'items' => [], 'errors' => ['Invalid order page.']];
        }
        if (!$order->hasField('mrc_inventory_refund_restored')) {
            return ['restored' => false, 'items' => [], 'errors' => ['Refund inventory field is missing.']];
        }
        if ((int) $order->mrc_inventory_refund_restored === 1) {
            return ['restored' => false, 'items' => [], 'errors' => [], 'message' => 'Refund inventory already restored.'];
        }
        if (!$order->hasField('mrc_inventory_adjusted') || (int) $order->mrc_inventory_adjusted !== 1) {
            $order->of(false);
            $order->mrc_inventory_refund_restored = 1;
            $this->wire('pages')->save($order);
            return ['restored' => true, 'items' => [], 'errors' => []];
        }

        $items = $this->getOrderInventoryItems($order);
        $restored = [];
        $errors = [];
        foreach ($items as $item) {
            $product = $this->wire('pages')->get((int) ($item['product_id'] ?? 0));
            if (!$product || !$product->id || !$product->hasField('mrc_stock')) {
                $errors[] = sprintf('Product %s cannot be restored.', (string) ($item['title'] ?? ''));
                continue;
            }
            $quantity = (int) ($item['quantity'] ?? 0);
            $variantId = (string) ($item['variant_id'] ?? '');
            if ($variantId !== '') {
                try {
                    $stockResult = $this->commerce->variantService()->updateStock($product, $variantId, $quantity);
                    $movement = array_merge($item, ['before' => (int) $stockResult['before'], 'after' => (int) $stockResult['after']]);
                    $restored[] = $movement;
                    $this->recordInventoryMovement('refund_restored', $order, $movement);
                } catch (WireException $e) {
                    $errors[] = $e->getMessage();
                }
                continue;
            }
            $before = (int) $product->mrc_stock;
            $product->of(false);
            $product->mrc_stock = $before + $quantity;
            $this->wire('pages')->save($product);
            $movement = array_merge($item, ['before' => $before, 'after' => (int) $product->mrc_stock]);
            $restored[] = $movement;
            $this->recordInventoryMovement('refund_restored', $order, $movement);
        }

        // Mark the compensation attempt complete even when one referenced
        // product is missing; retrying automatically could double-restock the
        // products that were already restored.
        $order->of(false);
        $order->mrc_inventory_refund_restored = 1;
        $this->wire('pages')->save($order);
        return ['restored' => !$errors, 'items' => $restored, 'errors' => $errors];
    }

    public function adjustProductStock(Page $product, int $delta, string $note = ''): array {
        if (!$product || !$product->id || !$product->hasField('mrc_stock')) {
            return ['adjusted' => false, 'errors' => ['Invalid product page.']];
        }
        if ($delta === 0) {
            return ['adjusted' => false, 'errors' => ['Stock adjustment cannot be zero.']];
        }

        $before = (int) $product->mrc_stock;
        $after = $before + $delta;
        if ($after < 0 && !$this->allowsOversell($product)) {
            return ['adjusted' => false, 'errors' => [sprintf('Adjustment would make stock negative: %d.', $after)]];
        }

        $product->of(false);
        $product->mrc_stock = $after;
        $this->wire('pages')->save($product);

        $movement = [
            'product_id' => (int) $product->id,
            'title' => (string) $product->title,
            'quantity' => abs($delta),
            'before' => $before,
            'after' => $after,
            'stock_policy' => $this->getProductStockPolicy($product),
        ];
        $this->recordInventoryMovement($delta > 0 ? 'manual_increase' : 'manual_decrease', null, $movement, [
            'delta' => $delta,
            'note' => $note,
        ]);

        return ['adjusted' => true, 'item' => $movement, 'before' => $before, 'after' => $after, 'errors' => []];
    }

    protected function getOrderInventoryItems(Page $order): array {
        $items = json_decode((string) $order->mrc_items, true);
        if (!is_array($items)) return [];

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $productId = $this->getItemProductId($item);
            $quantity = (int) ceil((float) ($item['quantity'] ?? 1));
            if ($productId <= 0 || $quantity <= 0) continue;

            $result[] = [
                'product_id' => $productId,
                'title' => (string) ($item['title'] ?? $item['name'] ?? ''),
                'quantity' => $quantity,
                'variant_id' => (string) ($item['variant_id'] ?? ''),
                'variant_label' => (string) ($item['variant_label'] ?? ''),
                'sku' => (string) ($item['sku'] ?? ''),
                'stock_policy' => (string) ($item['stock_policy'] ?? ''),
            ];
        }

        return $result;
    }

    protected function getOrderReservableInventoryItems(Page $order): array {
        $result = [];
        foreach ($this->getOrderInventoryItems($order) as $item) {
            $product = $this->wire('pages')->get((int) ($item['product_id'] ?? 0));
            $variantId = (string) ($item['variant_id'] ?? '');
            $variant = $product && $product->id && $variantId !== ''
                ? $this->commerce->variantService()->resolve($product, $variantId, [], false)
                : null;
            $allowsOversell = $variant
                ? in_array((string) $variant['stock_policy'], ['backorder', 'preorder'], true)
                : ($product && $product->id && $this->allowsOversell($product));
            if (!$product || !$product->id || $allowsOversell) {
                continue;
            }
            $result[] = $item;
        }
        return $result;
    }

    protected function getItemProductId(array $item): int {
        $productId = (int) ($item['product_id'] ?? 0);
        if ($productId > 0) {
            return $productId;
        }

        $reference = $item['id'] ?? '';
        if (is_numeric($reference) && (int) $reference > 0) {
            return (int) $reference;
        }
        if (is_string($reference) && trim($reference) !== '') {
            $product = $this->wire('pages')->get($reference);
            if ($product && $product->id && $product->template->name === 'mrc-product') {
                return (int) $product->id;
            }
        }
        return 0;
    }

    protected function recordInventoryMovements(string $event, Page $order, array $items, array $context = []): void {
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $this->recordInventoryMovement($event, $order, $item, $context);
        }
    }

    protected function recordInventoryMovement(string $event, ?Page $order, array $item, array $context = []): void {
        $payload = array_merge([
            'event' => $event,
            'order_id' => $order ? (int) $order->id : 0,
            'invoice' => ($order && $order->hasField('mrc_invoice_number')) ? (string) $order->mrc_invoice_number : '',
            'product_id' => (int) ($item['product_id'] ?? 0),
            'title' => (string) ($item['title'] ?? ''),
            'variant_id' => (string) ($item['variant_id'] ?? ''),
            'variant_label' => (string) ($item['variant_label'] ?? ''),
            'sku' => (string) ($item['sku'] ?? ''),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'before' => array_key_exists('before', $item) ? (int) $item['before'] : null,
            'after' => array_key_exists('after', $item) ? (int) $item['after'] : null,
            'at' => date('Y-m-d H:i:s'),
        ], $context);

        $this->wire('log')->save('mercato-inventory', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function findExistingOrderPage(array $pending): ?Page {
        if (empty($pending['mrc_order_page_id'])) {
            return null;
        }

        $existing = $this->wire('pages')->get((int) $pending['mrc_order_page_id']);
        if ($existing && $existing->id && $existing->template->name === $this->commerce->order_template) {
            return $existing;
        }

        return null;
    }

    protected function createOrderPage(Template $template, Page $ordersParent): Page {
        $slug = 'order-' . uniqid();
        $page = new Page();
        $page->template = $template;
        $page->parent = $ordersParent;
        $page->name = $slug;
        $page->title = $slug;
        $page->addStatus(Page::statusHidden);
        return $page;
    }
}
