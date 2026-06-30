<?php
namespace ProcessWire;

trait MercatoWebhookProductAuditHooks {

    public function handleStripeWebhook(HookEvent $event): void {
        header('Content-Type: application/json');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(200);
            echo json_encode($this->webhookService()->getStripeInfoResponse());
            exit;
        }

        try {
            echo json_encode($this->webhookService()->handleStripeRequest());
            exit;
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            http_response_code($code >= 400 && $code < 600 ? $code : 400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * Mollie webhook endpoint handler.
     * URL: /api/mercato/mollie-webhook
     *
     * Mollie signs webhooks by design through server-side re-fetch: it POSTs
     * only the payment id, and we retrieve the payment with our API key before
     * trusting the status.
     */
    public function handleMollieWebhook(HookEvent $event): void {
        header('Content-Type: application/json');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(200);
            echo json_encode($this->webhookService()->getMollieInfoResponse());
            exit;
        }

        try {
            echo json_encode($this->webhookService()->handleMollieRequest());
            exit;
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            http_response_code($code >= 400 && $code < 600 ? $code : 400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * PayPal webhook endpoint handler.
     * URL: /api/mercato/paypal-webhook
     */
    public function handlePayPalWebhook(HookEvent $event): void {
        header('Content-Type: application/json');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(200);
            echo json_encode($this->webhookService()->getPayPalInfoResponse());
            exit;
        }

        try {
            echo json_encode($this->webhookService()->handlePayPalRequest());
            exit;
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            http_response_code($code >= 400 && $code < 600 ? $code : 400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }

    protected function findOrderByMolliePayment(array $payment): ?Page {
        return $this->orderRepository()->findByMolliePayment($payment);
    }

    protected function orderPageToPendingData(Page $order): array {
        return $this->orderRepository()->pageToPendingData($order);
    }

    protected function getOrderPageTotalAmount(Page $order): float {
        return $this->orderRepository()->getTotalAmount($order);
    }

    /**
     * Prevent status/sort changes on completed (published) order pages.
     */
    public function hookBlockCompletedOrderChange(HookEvent $event): void {
        /** @var Page $page */
        $page = $event->arguments(0);
        if (!$page->template) return; // page has no template yet (mid-construction)
        if ($page->template->name !== $this->order_template) return;
        if ($page->isNew()) return;

        $existing = $this->wire('pages')->getById($page->id, ['cache' => false])->first();
        if (!$existing || !$existing->id) return;

        // If previously published and now being unpublished, block
        if (!$existing->isUnpublished() && $page->isUnpublished()) {
            throw new WireException($this->_('Cannot unpublish a completed order.'));
        }
    }

    public function hookCaptureProductPageSnapshot(HookEvent $event): void {
        /** @var Page $page */
        $page = $event->arguments(0);
        if (!$this->shouldAuditProductPageEdit($page)) {
            return;
        }

        $snapshot = [];
        if (!$page->isNew() && $page->id) {
            $existing = $this->wire('pages')->getById($page->id, ['cache' => false])->first();
            if ($existing && $existing->id && $existing->template && $existing->template->name === 'mrc-product') {
                $snapshot = $this->getProductAuditSnapshot($existing);
            }
        }

        $this->productSaveSnapshots[$this->productAuditSnapshotKey($page)] = $snapshot;
        if ($page->id) {
            $this->productSaveSnapshots['id:' . (int) $page->id] = $snapshot;
        }
    }

    public function hookRecordProductPageEdit(HookEvent $event): void {
        /** @var Page $page */
        $page = $event->arguments(0);
        if (!$this->shouldAuditProductPageEdit($page)) {
            return;
        }

        $key = $this->productAuditSnapshotKey($page);
        $before = $this->productSaveSnapshots[$key] ?? ($page->id ? ($this->productSaveSnapshots['id:' . (int) $page->id] ?? []) : []);
        unset($this->productSaveSnapshots[$key]);
        if ($page->id) {
            unset($this->productSaveSnapshots['id:' . (int) $page->id]);
        }

        $after = $this->getProductAuditSnapshot($page);
        $changes = $this->diffProductAuditSnapshots($before, $after);
        if (!$changes) {
            return;
        }

        $this->recordProductAuditEvent($before ? 'product_manual_edited' : 'product_manual_created', $page, [
            'source' => $this->getCurrentProcessName(),
            'changed_fields' => implode(',', array_keys($changes)),
            'changes' => $changes,
            'from_status' => (string) ($before['status'] ?? ''),
            'to_status' => (string) ($after['status'] ?? ''),
            'from_policy' => (string) ($before['stock_policy'] ?? ''),
            'to_policy' => (string) ($after['stock_policy'] ?? ''),
            'from_price' => $before['price'] ?? null,
            'to_price' => $after['price'] ?? null,
            'from_stock' => $before['stock'] ?? null,
            'to_stock' => $after['stock'] ?? null,
            'from_title' => (string) ($before['title'] ?? ''),
            'to_title' => (string) ($after['title'] ?? ''),
            'from_sku' => (string) ($before['sku'] ?? ''),
            'to_sku' => (string) ($after['sku'] ?? ''),
        ]);
    }

    protected function shouldAuditProductPageEdit(?Page $page): bool {
        if (!$page || !$page->template || $page->template->name !== 'mrc-product') {
            return false;
        }

        $processName = $this->getCurrentProcessName();
        return in_array($processName, ['ProcessPageEdit', 'ProcessPageAdd'], true);
    }

    protected function productAuditSnapshotKey(Page $page): string {
        return $page->id ? 'id:' . (int) $page->id : 'object:' . spl_object_id($page);
    }

    protected function getCurrentProcessName(): string {
        $process = $this->wire('process');
        if (!is_object($process)) {
            return '';
        }
        if (method_exists($process, 'className')) {
            return (string) $process->className();
        }
        $parts = explode('\\', get_class($process));
        return (string) end($parts);
    }

    protected function getProductAuditSnapshot(Page $product): array {
        return [
            'title' => (string) $product->title,
            'name' => (string) $product->name,
            'sku' => $product->hasField('mrc_sku') ? (string) $product->mrc_sku : '',
            'price' => $product->hasField('mrc_price') ? round((float) $product->mrc_price, 2) : null,
            'tax_rate' => $product->hasField('mrc_tax_rate') ? round((float) $product->mrc_tax_rate, 4) : null,
            'shipping_price' => $product->hasField('mrc_shipping_price') ? round((float) $product->mrc_shipping_price, 2) : null,
            'stock' => $product->hasField('mrc_stock') ? (int) $product->mrc_stock : null,
            'low_stock_threshold' => $product->hasField('mrc_low_stock_threshold') ? (int) $product->mrc_low_stock_threshold : null,
            'stock_policy' => $this->getProductAuditStockPolicy($product),
            'product_type' => $this->getProductAuditType($product),
            'product_status' => $this->getProductAuditLifecycleStatus($product),
            'status' => $this->getProductAuditPublicationStatus($product),
            'collections' => $this->getProductAuditCollections($product),
            'images_count' => $product->hasField('mrc_images') ? count($product->mrc_images) : 0,
            'description_hash' => $product->hasField('mrc_description') ? sha1((string) $product->mrc_description) : '',
        ];
    }

    protected function diffProductAuditSnapshots(array $before, array $after): array {
        $changes = [];
        foreach ($after as $field => $to) {
            $from = $before[$field] ?? null;
            if ($from === $to) {
                continue;
            }
            $changes[$field] = ['from' => $from, 'to' => $to];
        }
        return $changes;
    }

    protected function getProductAuditPublicationStatus(Page $product): string {
        if ($product->isUnpublished()) {
            return 'unpublished';
        }
        if ($product->isHidden()) {
            return 'hidden';
        }
        return 'published';
    }

    protected function getProductAuditStockPolicy(Page $product): string {
        $policy = $product->hasField('mrc_stock_policy') ? strtolower(trim((string) $product->mrc_stock_policy)) : '';
        return in_array($policy, ['deny', 'backorder', 'preorder'], true) ? $policy : 'deny';
    }

    protected function getProductAuditLifecycleStatus(Page $product): string {
        $status = $product->hasField('mrc_product_status') ? strtolower(trim((string) $product->mrc_product_status)) : '';
        return in_array($status, ['active', 'archived', 'discontinued'], true) ? $status : 'active';
    }

    protected function getProductAuditType(Page $product): string {
        $type = $product->hasField('mrc_product_type') ? strtolower(trim((string) $product->mrc_product_type)) : '';
        return in_array($type, ['physical', 'digital', 'service', 'placeholder', 'recurring'], true) ? $type : 'physical';
    }

    protected function getProductAuditCollections(Page $product): string {
        if (!$product->hasField('mrc_collections') || !$product->mrc_collections instanceof PageArray) {
            return '';
        }

        $names = [];
        foreach ($product->mrc_collections as $collection) {
            $names[] = (string) $collection->name;
        }
        sort($names);
        return implode('|', $names);
    }

    protected function recordProductAuditEvent(string $event, Page $product, array $payload = []): void {
        $user = $this->wire('user');
        $entry = array_merge([
            'event' => $event,
            'at' => date('c'),
            'product_id' => (int) $product->id,
            'title' => (string) $product->title,
            'name' => (string) $product->name,
            'sku' => $product->hasField('mrc_sku') ? (string) $product->mrc_sku : '',
            'user' => $user && $user->id ? (string) ($user->name ?: $user->id) : 'system',
        ], $payload);

        $this->wire('log')->save('mercato-products', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    // -----------------------------------------------------------------------
    // Module config UI
    // -----------------------------------------------------------------------
}
