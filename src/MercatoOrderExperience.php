<?php
namespace ProcessWire;

trait MercatoOrderExperience {

    public function createReturnRequest(Page $order, array $data = []): array {
        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            throw new WireException($this->_('Order not found.'));
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new WireException($this->_('Enter a return reason.'));
        }

        $sanitizer = $this->wire('sanitizer');
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
            $sku = trim((string) ($item['sku'] ?? ''));
            $productId = max(0, (int) ($item['product_id'] ?? $item['id'] ?? 0));
            if ($title === '' && $productId <= 0 && $sku === '') {
                continue;
            }
            $items[] = [
                'product_id' => $productId,
                'title' => $sanitizer->text($title),
                'sku' => $sanitizer->text($sku),
                'quantity' => $quantity,
            ];
        }

        if (!$items) {
            $orderItems = json_decode((string) ($order->mrc_items ?? ''), true);
            foreach (is_array($orderItems) ? $orderItems : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $items[] = [
                    'product_id' => max(0, (int) ($item['product_id'] ?? $item['id'] ?? 0)),
                    'title' => $sanitizer->text((string) ($item['title'] ?? $item['name'] ?? '')),
                    'sku' => $sanitizer->text((string) ($item['sku'] ?? '')),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ];
            }
        }

        $request = [
            'request_id' => $this->generateReturnRequestId($order),
            'status' => 'requested',
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'email' => strtolower((string) $sanitizer->email((string) ($data['email'] ?? $order->mrc_email ?? ''))),
            'reason' => $sanitizer->textarea($reason),
            'items' => $items,
            'created_at' => date(DATE_ATOM),
            'source' => $sanitizer->text(trim((string) ($data['source'] ?? 'api'))) ?: 'api',
        ];

        $hooked = $this->returnRequested($order, $request);
        if (is_array($hooked)) {
            $request = $hooked + $request;
        }

        $this->recordEvent('mercato-returns', [
            'event' => 'return_requested',
            'request_id' => (string) ($request['request_id'] ?? ''),
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'email' => (string) ($request['email'] ?? ''),
            'status' => (string) ($request['status'] ?? 'requested'),
            'reason' => (string) ($request['reason'] ?? ''),
            'items' => $request['items'] ?? [],
            'source' => (string) ($request['source'] ?? 'api'),
        ], 'return_requested');

        return $request;
    }

    protected function generateReturnRequestId(Page $order): string {
        return 'RMA-' . (int) $order->id . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    public function createPartialFulfilment(Page $order, array $data = []): array {
        $this->requireArchitectureClasses();

        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            throw new WireException($this->_('Order not found.'));
        }

        $sanitizer = $this->wire('sanitizer');
        $items = $this->normalizePartialFulfilmentItems((array) ($data['items'] ?? []), $order);
        if (!$items) {
            throw new WireException($this->_('Select at least one item to fulfil.'));
        }

        $batch = [
            'batch_id' => $this->generatePartialFulfilmentId($order),
            'status' => $sanitizer->text(trim((string) ($data['status'] ?? MercatoFulfilmentStatus::PARTIALLY_FULFILLED))) ?: MercatoFulfilmentStatus::PARTIALLY_FULFILLED,
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'method' => $sanitizer->text(trim((string) ($data['method'] ?? $order->mrc_fulfilment_method ?? ''))),
            'tracking' => $sanitizer->text(trim((string) ($data['tracking'] ?? ''))),
            'tracking_url' => $sanitizer->url((string) ($data['tracking_url'] ?? '')),
            'notes' => $sanitizer->textarea((string) ($data['notes'] ?? '')),
            'items' => $items,
            'created_at' => date(DATE_ATOM),
            'source' => $sanitizer->text(trim((string) ($data['source'] ?? 'api'))) ?: 'api',
        ];

        $hooked = $this->partialFulfilmentRecorded($order, $batch);
        if (is_array($hooked)) {
            $batch = $hooked + $batch;
        }

        $this->recordEvent('mercato-fulfilment', [
            'event' => 'partial_fulfilment_recorded',
            'batch_id' => (string) ($batch['batch_id'] ?? ''),
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'status' => (string) ($batch['status'] ?? MercatoFulfilmentStatus::PARTIALLY_FULFILLED),
            'method' => (string) ($batch['method'] ?? ''),
            'tracking' => (string) ($batch['tracking'] ?? ''),
            'tracking_url' => (string) ($batch['tracking_url'] ?? ''),
            'notes' => (string) ($batch['notes'] ?? ''),
            'items' => $batch['items'] ?? [],
            'source' => (string) ($batch['source'] ?? 'api'),
        ], 'partial_fulfilment_recorded');

        return $batch;
    }

    protected function normalizePartialFulfilmentItems(array $items, Page $order): array {
        $sanitizer = $this->wire('sanitizer');
        $orderItems = json_decode((string) ($order->mrc_items ?? ''), true);
        $orderItems = is_array($orderItems) ? $orderItems : [];
        $available = [];
        foreach ($orderItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = $this->partialFulfilmentItemKey($item);
            if ($key !== '') {
                $available[$key] = max(1, (int) ($item['quantity'] ?? 1));
            }
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = $this->partialFulfilmentItemKey($item);
            if ($key === '') {
                continue;
            }
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            if (isset($available[$key])) {
                $quantity = min($quantity, $available[$key]);
            }
            $normalized[] = [
                'product_id' => max(0, (int) ($item['product_id'] ?? $item['id'] ?? 0)),
                'title' => $sanitizer->text((string) ($item['title'] ?? $item['name'] ?? '')),
                'sku' => $sanitizer->text((string) ($item['sku'] ?? '')),
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    protected function partialFulfilmentItemKey(array $item): string {
        $productId = max(0, (int) ($item['product_id'] ?? $item['id'] ?? 0));
        if ($productId > 0) {
            return 'id:' . $productId;
        }
        $sku = trim((string) ($item['sku'] ?? ''));
        if ($sku !== '') {
            return 'sku:' . strtolower($sku);
        }
        $title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
        return $title !== '' ? 'title:' . strtolower($title) : '';
    }

    protected function generatePartialFulfilmentId(Page $order): string {
        return 'FUL-' . (int) $order->id . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    public function createShipment(Page $order, array $data = []): array {
        $this->requireArchitectureClasses();

        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            throw new WireException($this->_('Order not found.'));
        }

        $sanitizer = $this->wire('sanitizer');
        $items = $this->normalizePartialFulfilmentItems((array) ($data['items'] ?? []), $order);
        if (!$items) {
            throw new WireException($this->_('Select at least one item to ship.'));
        }

        $shipment = [
            'shipment_id' => $this->generateShipmentId($order),
            'status' => $sanitizer->text(trim((string) ($data['status'] ?? MercatoFulfilmentStatus::SHIPPED))) ?: MercatoFulfilmentStatus::SHIPPED,
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'method' => $sanitizer->text(trim((string) ($data['method'] ?? $order->mrc_fulfilment_method ?? ''))),
            'carrier' => $sanitizer->text(trim((string) ($data['carrier'] ?? ''))),
            'service' => $sanitizer->text(trim((string) ($data['service'] ?? ''))),
            'tracking' => $sanitizer->text(trim((string) ($data['tracking'] ?? ''))),
            'tracking_url' => $sanitizer->url((string) ($data['tracking_url'] ?? '')),
            'label_url' => $sanitizer->url((string) ($data['label_url'] ?? '')),
            'notes' => $sanitizer->textarea((string) ($data['notes'] ?? '')),
            'items' => $items,
            'created_at' => date(DATE_ATOM),
            'source' => $sanitizer->text(trim((string) ($data['source'] ?? 'api'))) ?: 'api',
        ];

        $hooked = $this->shipmentRecorded($order, $shipment);
        if (is_array($hooked)) {
            $shipment = $hooked + $shipment;
        }

        $this->recordEvent('mercato-fulfilment', [
            'event' => 'shipment_recorded',
            'shipment_id' => (string) ($shipment['shipment_id'] ?? ''),
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'status' => (string) ($shipment['status'] ?? MercatoFulfilmentStatus::SHIPPED),
            'method' => (string) ($shipment['method'] ?? ''),
            'carrier' => (string) ($shipment['carrier'] ?? ''),
            'service' => (string) ($shipment['service'] ?? ''),
            'tracking' => (string) ($shipment['tracking'] ?? ''),
            'tracking_url' => (string) ($shipment['tracking_url'] ?? ''),
            'notes' => (string) ($shipment['notes'] ?? ''),
            'items' => $shipment['items'] ?? [],
            'source' => (string) ($shipment['source'] ?? 'api'),
        ], 'shipment_recorded');

        return $shipment;
    }

    protected function generateShipmentId(Page $order): string {
        return 'SHP-' . (int) $order->id . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    public function issueStoreCredit(float $amount, array $data = []): array {
        $amount = round(max(0.0, $amount), 2);
        if ($amount <= 0) {
            throw new WireException($this->_('Store credit amount must be greater than zero.'));
        }

        $sanitizer = $this->wire('sanitizer');
        $credit = [
            'credit_id' => 'CR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'code' => $this->normalizeStoreCreditCode((string) ($data['code'] ?? '')),
            'amount' => $amount,
            'currency' => strtoupper(trim((string) ($data['currency'] ?? $this->currency ?? 'GBP'))) ?: 'GBP',
            'email' => strtolower((string) $sanitizer->email((string) ($data['email'] ?? ''))),
            'order_id' => max(0, (int) ($data['order_id'] ?? 0)),
            'reason' => $sanitizer->textarea((string) ($data['reason'] ?? '')),
            'expires_at' => trim((string) ($data['expires_at'] ?? '')),
            'created_at' => date(DATE_ATOM),
            'source' => $sanitizer->text(trim((string) ($data['source'] ?? 'api'))) ?: 'api',
        ];
        if ($credit['code'] === '') {
            $credit['code'] = $this->generateStoreCreditCode();
        }

        $hooked = $this->storeCreditIssued($credit);
        if (is_array($hooked)) {
            $credit = $hooked + $credit;
        }

        $this->recordEvent('mercato-store-credit', [
            'event' => 'store_credit_issued',
            'credit_id' => (string) ($credit['credit_id'] ?? ''),
            'code' => (string) ($credit['code'] ?? ''),
            'amount' => (float) ($credit['amount'] ?? $amount),
            'currency' => (string) ($credit['currency'] ?? ''),
            'email' => (string) ($credit['email'] ?? ''),
            'order_id' => (int) ($credit['order_id'] ?? 0),
            'reason' => (string) ($credit['reason'] ?? ''),
            'expires_at' => (string) ($credit['expires_at'] ?? ''),
            'source' => (string) ($credit['source'] ?? 'api'),
        ], 'store_credit_issued');

        return $credit;
    }

    public function redeemStoreCredit(string $code, float $amount, array $data = []): array {
        $code = $this->normalizeStoreCreditCode($code);
        $amount = round(max(0.0, $amount), 2);
        if ($code === '') {
            throw new WireException($this->_('Enter a store credit code.'));
        }
        if ($amount <= 0) {
            throw new WireException($this->_('Store credit redemption amount must be greater than zero.'));
        }

        $sanitizer = $this->wire('sanitizer');
        $redemption = [
            'redemption_id' => 'RD-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'code' => $code,
            'amount' => $amount,
            'currency' => strtoupper(trim((string) ($data['currency'] ?? $this->currency ?? 'GBP'))) ?: 'GBP',
            'email' => strtolower((string) $sanitizer->email((string) ($data['email'] ?? ''))),
            'order_id' => max(0, (int) ($data['order_id'] ?? 0)),
            'created_at' => date(DATE_ATOM),
            'source' => $sanitizer->text(trim((string) ($data['source'] ?? 'api'))) ?: 'api',
        ];

        $hooked = $this->storeCreditRedeemed($redemption);
        if (is_array($hooked)) {
            $redemption = $hooked + $redemption;
        }

        $this->recordEvent('mercato-store-credit', [
            'event' => 'store_credit_redeemed',
            'redemption_id' => (string) ($redemption['redemption_id'] ?? ''),
            'code' => (string) ($redemption['code'] ?? $code),
            'amount' => (float) ($redemption['amount'] ?? $amount),
            'currency' => (string) ($redemption['currency'] ?? ''),
            'email' => (string) ($redemption['email'] ?? ''),
            'order_id' => (int) ($redemption['order_id'] ?? 0),
            'source' => (string) ($redemption['source'] ?? 'api'),
        ], 'store_credit_redeemed');

        return $redemption;
    }

    protected function normalizeStoreCreditCode(string $code): string {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', trim($code)));
    }

    protected function generateStoreCreditCode(): string {
        return 'GC' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
    }

    public function getOrderAnalyticsEvent(Page $order, string $event = 'purchase'): array {
        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) return [];
        return $this->analyticsService()->consumeOrderEvent($order, $event, 'data_layer');
    }

    public function getProductReviewSummary(Page $product): array {
        if (!$product || !$product->id || $product->template->name !== (string) $this->product_template) {
            return [];
        }

        $summary = $this->productReviewSummary($product);
        if (!is_array($summary)) {
            return [];
        }

        $rating = round(max(0.0, min(5.0, (float) ($summary['rating'] ?? 0))), 1);
        $count = max(0, (int) ($summary['count'] ?? 0));
        $label = trim((string) ($summary['label'] ?? ''));
        $url = trim((string) ($summary['url'] ?? ''));
        if ($url !== '') {
            $url = (string) $this->wire('sanitizer')->url($url, ['allowRelative' => true]);
        }

        if ($rating <= 0.0 && $count <= 0 && $label === '') {
            return [];
        }

        if ($label === '') {
            $ratingLabel = rtrim(rtrim(number_format($rating, 1, '.', ''), '0'), '.');
            if ($rating > 0.0 && $count > 0) {
                $label = sprintf($this->_('%s/5 from %d %s'), $ratingLabel, $count, $count === 1 ? $this->_('review') : $this->_('reviews'));
            } elseif ($rating > 0.0) {
                $label = sprintf($this->_('%s/5 rating'), $ratingLabel);
            } else {
                $label = sprintf($this->_('%d %s'), $count, $count === 1 ? $this->_('review') : $this->_('reviews'));
            }
        }

        return [
            'rating' => $rating,
            'count' => $count,
            'label' => $label,
            'url' => $url,
        ];
    }

    public function getProductRelatedProducts(Page $product, int $limit = 4): array {
        if (!$product || !$product->id || $product->template->name !== (string) $this->product_template) {
            return [];
        }

        $limit = max(1, min(12, $limit));
        $related = $this->productRelatedProducts($product, $limit);
        if (!is_iterable($related)) {
            return [];
        }

        $products = [];
        $seen = [(int) $product->id => true];
        foreach ($related as $candidate) {
            if (!$candidate instanceof Page || !$candidate->id || isset($seen[(int) $candidate->id])) {
                continue;
            }
            if ($candidate->template->name !== (string) $this->product_template) {
                continue;
            }
            $seen[(int) $candidate->id] = true;
            $products[] = $candidate;
            if (count($products) >= $limit) {
                break;
            }
        }

        return $products;
    }

    public function getOrderReceiptPdfUrl(Page $order): string {
        if (!$this->isOrderReceiptAvailable($order)) {
            return '';
        }
        $external = $this->getExternalOrderReceiptPdfUrl($order);
        if ($external !== '') {
            return $external;
        }
        return $this->getHttpRoot() . '/api/mercato/order-receipt-pdf?' . http_build_query([
            'order' => (int) $order->id,
            'token' => $this->getOrderReceiptToken($order),
        ]);
    }

    protected function getExternalOrderReceiptPdfUrl(Page $order): string {
        $template = self::normalizeReceiptPdfUrlTemplate($this->receipt_pdf_url_template ?? '');
        if ($template === '') {
            return '';
        }
        $receiptUrl = $this->getOrderReceiptUrl($order);
        $replacements = [
            '{order_id}' => rawurlencode((string) (int) $order->id),
            '{invoice}' => rawurlencode((string) ($order->mrc_invoice_number ?: $order->title)),
            '{token}' => rawurlencode($this->getOrderReceiptToken($order)),
            '{receipt_link}' => rawurlencode($receiptUrl),
        ];
        $url = strtr($template, $replacements);
        if (str_starts_with($url, '/')) {
            $url = $this->getHttpRoot() . '/' . ltrim($url, '/');
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    protected function getReceiptTemplatePath(): string {
        $file = self::normalizeReceiptTemplateFile($this->receipt_template_file ?? '');
        if ($file === '') {
            return '';
        }
        $templatesRoot = realpath((string) $this->wire('config')->paths->templates);
        if (!$templatesRoot) {
            return '';
        }
        $path = realpath($templatesRoot . DIRECTORY_SEPARATOR . $file);
        if (!$path || !is_file($path) || !str_starts_with($path, $templatesRoot . DIRECTORY_SEPARATOR)) {
            return '';
        }
        return $path;
    }

    protected function renderCustomOrderReceipt(Page $order, array $context): string {
        $templatePath = $this->getReceiptTemplatePath();
        if ($templatePath === '') {
            return '';
        }
        $commerce = $this;
        $bufferLevel = ob_get_level();
        try {
            ob_start();
            extract($context, EXTR_SKIP);
            include $templatePath;
            return trim((string) ob_get_clean());
        } catch (\Throwable $e) {
            if (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $this->wire('log')->save('mercato', 'Receipt template failed: ' . $e->getMessage());
            return '';
        }
    }

    protected function getOrderStatusTemplatePath(): string {
        $file = self::normalizeReceiptTemplateFile($this->order_status_template_file ?? '');
        if ($file === '') {
            return '';
        }
        $templatesRoot = realpath((string) $this->wire('config')->paths->templates);
        if (!$templatesRoot) {
            return '';
        }
        $path = realpath($templatesRoot . DIRECTORY_SEPARATOR . $file);
        if (!$path || !is_file($path) || !str_starts_with($path, $templatesRoot . DIRECTORY_SEPARATOR)) {
            return '';
        }
        return $path;
    }

    protected function renderCustomOrderStatus(Page $order, array $context): string {
        $templatePath = $this->getOrderStatusTemplatePath();
        if ($templatePath === '') {
            return '';
        }
        $commerce = $this;
        $bufferLevel = ob_get_level();
        try {
            ob_start();
            extract($context, EXTR_SKIP);
            include $templatePath;
            return trim((string) ob_get_clean());
        } catch (\Throwable $e) {
            if (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $this->wire('log')->save('mercato', 'Order status template failed: ' . $e->getMessage());
            return '';
        }
    }

    public function getOrderReceiptToken(Page $order): string {
        $secret = (string) ($this->wire('config')->userAuthSalt ?: $this->wire('config')->userAuthHashType ?: __FILE__);
        $payload = implode('|', [
            'mercato-order-receipt',
            (int) $order->id,
            (string) ($order->mrc_invoice_number ?: $order->title),
            strtolower((string) $order->mrc_email),
            (int) $order->created,
        ]);
        return hash_hmac('sha256', $payload, $secret);
    }

    public function verifyOrderReceiptToken(Page $order, string $token): bool {
        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            return false;
        }
        return !$this->areOrderSignedLinksExpired($order) && $this->isOrderReceiptAvailable($order) && hash_equals($this->getOrderReceiptToken($order), trim($token));
    }

    public function getOrderDownloadToken(Page $order, int $productId, int $fileIndex): string {
        $secret = (string) ($this->wire('config')->userAuthSalt ?: $this->wire('config')->userAuthHashType ?: __FILE__);
        $payload = implode('|', [
            'mercato-order-download',
            (int) $order->id,
            (string) ($order->mrc_invoice_number ?: $order->title),
            strtolower((string) $order->mrc_email),
            (int) $order->created,
            max(0, $productId),
            max(0, $fileIndex),
        ]);
        return hash_hmac('sha256', $payload, $secret);
    }

    public function getOrderDownloadUrl(Page $order, int $productId, int $fileIndex): string {
        return $this->getHttpRoot() . '/api/mercato/download?' . http_build_query([
            'order' => (int) $order->id,
            'product' => max(0, $productId),
            'file' => max(0, $fileIndex),
            'token' => $this->getOrderDownloadToken($order, $productId, $fileIndex),
        ]);
    }

    public function verifyOrderDownloadToken(Page $order, int $productId, int $fileIndex, string $token): bool {
        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            return false;
        }
        return !$this->areOrderSignedLinksExpired($order) && hash_equals($this->getOrderDownloadToken($order, $productId, $fileIndex), trim($token));
    }

    public function isOrderReceiptAvailable(Page $order): bool {
        $this->requireArchitectureClasses();
        $status = strtolower(trim((string) ($order->mrc_payment_status ?? '')));
        return (int) ($order->mrc_payment_complete ?? 0) === 1
            || in_array($status, [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED, MercatoPaymentStatus::REFUNDED], true);
    }

    public function getOrderDigitalDownloads(Page $order): array {
        if (!$this->isOrderReceiptAvailable($order)) {
            return [];
        }

        $items = json_decode((string) $order->mrc_items, true);
        $items = is_array($items) ? array_filter($items, 'is_array') : [];
        $purchasedProductIds = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);
            if ($productId > 0) {
                $purchasedProductIds[$productId] = true;
            }
        }

        $downloads = [];
        foreach (array_keys($purchasedProductIds) as $productId) {
            $product = $this->wire('pages')->get((int) $productId);
            if (!$this->isProductDownloadableForOrder($order, $product)) {
                continue;
            }
            $expiresAt = $this->getDigitalDownloadExpiryTimestamp($order, $product);
            foreach ($product->mrc_digital_files as $index => $file) {
                if ($this->isDigitalDownloadLimitReached($order, $product, (int) $index)) {
                    continue;
                }
                $filename = $this->getDigitalDownloadFilename($file);
                if ($filename === '') {
                    continue;
                }
                $downloads[] = [
                    'product_id' => (int) $product->id,
                    'product_title' => (string) $product->title,
                    'file_index' => (int) $index,
                    'filename' => $filename,
                    'url' => $this->getOrderDownloadUrl($order, (int) $product->id, (int) $index),
                    'expires_at' => $expiresAt > 0 ? date('c', $expiresAt) : '',
                ];
            }
        }

        return $downloads;
    }

    public function isOrderPaymentRetryAvailable(Page $order): bool {
        $this->requireArchitectureClasses();
        if (!$order || !$order->id || $order->template->name !== (string) $this->order_template) {
            return false;
        }
        if ((int) ($order->mrc_payment_complete ?? 0) === 1) {
            return false;
        }

        $status = strtolower(trim((string) ($order->mrc_payment_status ?? '')));
        if ($status === '') {
            $status = MercatoPaymentStatus::PENDING;
        }
        if (!in_array($status, [
            MercatoPaymentStatus::CREATED,
            MercatoPaymentStatus::REQUIRES_PAYMENT,
            MercatoPaymentStatus::REQUIRES_CONFIRMATION,
            MercatoPaymentStatus::REQUIRES_ACTION,
            MercatoPaymentStatus::PENDING,
            MercatoPaymentStatus::FAILED,
            MercatoPaymentStatus::EXPIRED,
        ], true)) {
            return false;
        }

        $items = json_decode((string) $order->mrc_items, true);
        return is_array($items) && count(array_filter($items, 'is_array')) > 0;
    }

    protected function getPublicRefundSummary(Page $order): array {
        $total = $this->orderRepository()->getTotalAmount($order);
        $refunded = $order->hasField('mrc_refunded_amount') ? round(max(0, (float) $order->mrc_refunded_amount), 2) : 0.0;
        $pending = $order->hasField('mrc_refund_pending_amount') ? round(max(0, (float) $order->mrc_refund_pending_amount), 2) : 0.0;
        $netPaid = round(max(0, $total - $refunded - $pending), 2);

        return [
            'total' => round($total, 2),
            'refunded' => $refunded,
            'pending' => $pending,
            'net_paid' => $netPaid,
            'has_refund' => $refunded > 0 || $pending > 0,
        ];
    }

    public function deriveOrderStatus(Page $order): array {
        $this->requireArchitectureClasses();
        $status = MercatoOrderStatus::derive($order);
        return [
            'raw' => $status,
            'label' => MercatoOrderStatus::label($status),
            'class' => MercatoOrderStatus::statusClass($status),
        ];
    }

    public function getDerivedOrderStatus(Page $order): string {
        $this->requireArchitectureClasses();
        return MercatoOrderStatus::derive($order);
    }

    public function emitOrderStatusChanged(Page $order, string $previousStatus, array $context = []): bool {
        if (!$order->id || $order->template->name !== (string) $this->order_template) {
            return false;
        }

        $previousStatus = strtolower(trim($previousStatus));
        $currentStatus = $this->getDerivedOrderStatus($order);
        if ($previousStatus === '' || $previousStatus === $currentStatus) {
            return false;
        }

        $this->orderStatusChanged($order, $previousStatus, $currentStatus, $context);
        $this->recordEvent('mercato-events', [
            'event' => 'order_status_changed',
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'from' => $previousStatus,
            'to' => $currentStatus,
            'context' => $context,
        ], 'order_status_changed');
        return true;
    }

    // -----------------------------------------------------------------------
    // Flash messages
    // -----------------------------------------------------------------------

    public function setMessage(mixed $message): void {
        $this->wire('session')->set('mrc_message', $message);
    }

    public function getMessage(): mixed {
        $msg = $this->wire('session')->get('mrc_message');
        if ($msg !== null) {
            $this->wire('session')->remove('mrc_message');
        }
        return $msg;
    }

    // -----------------------------------------------------------------------
    // Order page persistence
    // -----------------------------------------------------------------------

    /**
     * Save the pending order array as a ProcessWire page under the orders parent.
     */
}
