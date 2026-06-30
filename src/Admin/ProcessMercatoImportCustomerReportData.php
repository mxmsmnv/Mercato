<?php
namespace ProcessWire;

trait ProcessMercatoImportCustomerReportData {

    protected function parseProductCsv(string $csv): array {
        $csv = trim($csv);
        if ($csv === '') {
            return ['rows' => [], 'errors' => [$this->_('Paste CSV content before importing.')]];
        }

        $lines = preg_split('/\R/u', $csv) ?: [];
        $header = null;
        $rows = [];
        $errors = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line);
            if ($header === null) {
                $header = array_map(fn($value) => strtolower(trim((string) $value)), $cells);
                continue;
            }

            $row = [];
            foreach ($header as $cellIndex => $name) {
                $row[$name] = trim((string) ($cells[$cellIndex] ?? ''));
            }

            $lineNumber = $index + 1;
            if (($row['title'] ?? '') === '') {
                $errors[] = sprintf($this->_('Line %d: title is required.'), $lineNumber);
            }
            if (($row['price'] ?? '') === '' || !is_numeric($row['price'])) {
                $errors[] = sprintf($this->_('Line %d: price must be numeric.'), $lineNumber);
            }
            $rows[] = $row;
        }

        if ($header === null) {
            $errors[] = $this->_('CSV header row is missing.');
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    protected function importProductRows(Mercato $commerce, array $rows, bool $dryRun = true): array {
        $pages = $this->wire('pages');
        $templates = $this->wire('templates');
        $sanitizer = $this->wire('sanitizer');
        $template = $templates->get('mrc-product');
        $parent = $pages->get('/products/');

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'images' => 0, 'errors' => []];
        if (!$template || !$parent || !$parent->id) {
            $result['errors'][] = $this->_('Products parent or mrc-product template is missing. Run the Mercato installer.');
            return $result;
        }

        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $result['skipped']++;
                continue;
            }

            $name = $sanitizer->pageName((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = $sanitizer->pageName($title);
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            $existing = $sku !== ''
                ? $pages->get("template=mrc-product, mrc_sku=" . $sanitizer->selectorValue($sku) . ", include=all")
                : null;
            if (!$existing || !$existing->id) {
                $existing = $pages->get($parent->path . $name . '/');
            }

            $isNew = !$existing || !$existing->id;
            if ($dryRun) {
                $result[$isNew ? 'created' : 'updated']++;
                continue;
            }

            $product = $isNew ? new Page() : $existing;
            $product->of(false);
            $previous = $isNew ? [] : [
                'title' => (string) $product->title,
                'sku' => $product->hasField('mrc_sku') ? (string) $product->mrc_sku : '',
                'price' => $product->hasField('mrc_price') ? (float) $product->mrc_price : null,
                'stock' => $product->hasField('mrc_stock') ? (int) $product->mrc_stock : null,
                'product_status' => $this->getProductLifecycleStatus($product),
                'product_type' => $this->getProductType($product),
                'status' => $this->getProductPublicationStatus($product),
                'policy' => $this->getProductStockPolicy($product),
            ];
            if ($isNew) {
                $product->template = $template;
                $product->parent = $parent;
                $product->name = $name;
            }

            $product->title = $title;
            if ($product->hasField('mrc_sku')) $product->mrc_sku = $sku;
            if ($product->hasField('mrc_price')) $product->mrc_price = (float) ($row['price'] ?? 0);
            if ($product->hasField('mrc_tax_rate')) {
                $taxRate = array_key_exists('tax_rate', $row) && trim((string) $row['tax_rate']) !== ''
                    ? (float) $row['tax_rate']
                    : ($isNew ? $commerce->getDefaultTaxRate() : (float) $product->mrc_tax_rate);
                $product->mrc_tax_rate = $taxRate;
            }
            if ($product->hasField('mrc_shipping_price')) $product->mrc_shipping_price = (float) ($row['shipping_price'] ?? 0);
            if ($product->hasField('mrc_stock')) $product->mrc_stock = (int) ($row['stock'] ?? 0);
            if ($product->hasField('mrc_low_stock_threshold')) $product->mrc_low_stock_threshold = (int) ($row['low_stock_threshold'] ?? 0);
            if ($product->hasField('mrc_stock_policy')) {
                $policy = strtolower(trim((string) ($row['stock_policy'] ?? 'deny')));
                $product->mrc_stock_policy = in_array($policy, ['deny', 'backorder', 'preorder'], true) ? $policy : 'deny';
            }
            if ($product->hasField('mrc_product_status')) {
                $productStatus = strtolower(trim((string) ($row['product_status'] ?? 'active')));
                $product->mrc_product_status = in_array($productStatus, ['active', 'archived', 'discontinued'], true) ? $productStatus : 'active';
            }
            if ($product->hasField('mrc_product_type')) {
                $productType = strtolower(trim((string) ($row['product_type'] ?? 'physical')));
                $product->mrc_product_type = in_array($productType, ['physical', 'digital', 'service', 'placeholder', 'recurring'], true) ? $productType : 'physical';
            }
            if ($product->hasField('mrc_stripe_price_id')) {
                $product->mrc_stripe_price_id = trim((string) ($row['stripe_price_id'] ?? ''));
            }
            if ($product->hasField('mrc_download_limit')) {
                $product->mrc_download_limit = max(0, (int) ($row['download_limit'] ?? 0));
            }
            if ($product->hasField('mrc_download_expiry_days')) {
                $product->mrc_download_expiry_days = max(0, (int) ($row['download_expiry_days'] ?? 0));
            }
            if ($product->hasField('mrc_description')) $product->mrc_description = (string) ($row['description'] ?? '');
            if ($product->hasField('mrc_collections')) {
                $this->assignProductCollections($product, (string) ($row['collections'] ?? ''));
            }

            $status = strtolower((string) ($row['status'] ?? 'published'));
            if ($status === 'unpublished') {
                $product->addStatus(Page::statusUnpublished);
            } else {
                $product->removeStatus(Page::statusUnpublished);
            }
            if ($status === 'hidden') {
                $product->addStatus(Page::statusHidden);
            } else {
                $product->removeStatus(Page::statusHidden);
            }

            $pages->save($product);
            $importedImages = 0;
            if (!empty($row['image_urls'])) {
                $imageResult = $this->importProductImages($product, (string) $row['image_urls']);
                $importedImages = (int) $imageResult['imported'];
                $result['images'] += $imageResult['imported'];
                foreach ($imageResult['errors'] as $error) {
                    $result['errors'][] = $title . ': ' . $error;
                }
            }
            $this->recordProductEvent('product_imported', $product, [
                'source' => 'csv_import',
                'import_mode' => $isNew ? 'created' : 'updated',
                'imported_images' => $importedImages,
                'from_title' => $previous['title'] ?? '',
                'to_title' => (string) $product->title,
                'from_sku' => $previous['sku'] ?? '',
                'to_sku' => $product->hasField('mrc_sku') ? (string) $product->mrc_sku : '',
                'from_price' => $previous['price'] ?? null,
                'to_price' => $product->hasField('mrc_price') ? (float) $product->mrc_price : null,
                'from_stock' => $previous['stock'] ?? null,
                'to_stock' => $product->hasField('mrc_stock') ? (int) $product->mrc_stock : null,
                'from_product_status' => $previous['product_status'] ?? '',
                'to_product_status' => $this->getProductLifecycleStatus($product),
                'from_product_type' => $previous['product_type'] ?? '',
                'to_product_type' => $this->getProductType($product),
                'from_status' => $previous['status'] ?? '',
                'to_status' => $this->getProductPublicationStatus($product),
                'from_policy' => $previous['policy'] ?? '',
                'to_policy' => $this->getProductStockPolicy($product),
            ]);
            $result[$isNew ? 'created' : 'updated']++;
        }

        return $result;
    }

    protected function importProductImages(Page $product, string $value): array {
        $result = ['imported' => 0, 'errors' => []];
        if (!$product->hasField('mrc_images')) {
            return $result;
        }

        $sources = preg_split('/[|,]+/', $value) ?: [];
        foreach ($sources as $source) {
            $source = trim((string) $source);
            if ($source === '') continue;

            $tempPath = '';
            try {
                $path = $this->resolveImportImagePath($source);
                if ($path === '') {
                    $result['errors'][] = sprintf($this->_('Image source is unreadable: %s'), $source);
                    continue;
                }
                $tempPath = str_starts_with($path, sys_get_temp_dir()) ? $path : '';
                $product->of(false);
                $product->mrc_images->add($path);
                $this->wire('pages')->save($product);
                $result['imported']++;
            } catch (\Throwable $e) {
                $result['errors'][] = $e->getMessage();
            } finally {
                if ($tempPath !== '' && is_file($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }

        return $result;
    }

    protected function resolveImportImagePath(string $source): string {
        if (preg_match('~^https?://~i', $source)) {
            return $this->downloadImportImage($source);
        }

        $path = $source;
        if (!str_starts_with($path, '/')) {
            $path = $this->wire('config')->paths->root . ltrim($path, '/');
        }

        return is_file($path) && is_readable($path) ? $path : '';
    }

    protected function downloadImportImage(string $url): string {
        $http = new WireHttp();
        $http->setTimeout(15);
        $data = $http->get($url);
        if (!is_string($data) || $data === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $extension = $this->guessImageExtension($data);
        }
        if ($extension === '') {
            return '';
        }

        $temp = tempnam(sys_get_temp_dir(), 'mercato-import-');
        if ($temp === false) {
            return '';
        }

        $target = $temp . '.' . $extension;
        if (!rename($temp, $target)) {
            @unlink($temp);
            return '';
        }
        file_put_contents($target, $data);
        return $target;
    }

    protected function guessImageExtension(string $data): string {
        $type = @exif_imagetype('data://application/octet-stream;base64,' . base64_encode($data));
        return match ($type) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => '',
        };
    }

    protected function assignProductCollections(Page $product, string $value): void {
        $product->mrc_collections->removeAll();
        foreach ($this->resolveCollectionPages($value, true) as $collection) {
            $product->mrc_collections->add($collection);
        }
    }

    protected function resolveCollectionPages(string $value, bool $create = false): array {
        $pages = $this->wire('pages');
        $templates = $this->wire('templates');
        $sanitizer = $this->wire('sanitizer');
        $collectionTemplate = $templates->get('mrc-collection');
        $parent = $pages->get('/collections/');
        if (!$collectionTemplate || !$parent || !$parent->id) {
            return [];
        }

        $names = preg_split('/[|,;]+/', $value) ?: [];
        $collections = [];
        foreach ($names as $label) {
            $label = trim((string) $label);
            if ($label === '') continue;

            $name = $sanitizer->pageName($label);
            if ($name === '') continue;

            $collection = $pages->get($parent->path . $name . '/');
            if ((!$collection || !$collection->id) && $create) {
                $collection = new Page();
                $collection->template = $collectionTemplate;
                $collection->parent = $parent;
                $collection->name = $name;
                $collection->of(false);
                $collection->title = $label;
                $pages->save($collection);
            }

            if ($collection && $collection->id) {
                $collections[(int) $collection->id] = $collection;
            }
        }

        return array_values($collections);
    }


    protected function getCustomersFromOrders(Mercato $commerce): array {
        $orders = $this->getOrders($commerce, 500);
        $customers = [];

        foreach ($orders as $order) {
            $email = strtolower(trim((string) $order->mrc_email));
            if ($email === '') {
                $email = 'order-' . (int) $order->id;
            }

            if (!isset($customers[$email])) {
                $customers[$email] = [
                    'key' => $email,
                    'name' => $this->getOrderCustomer($order),
                    'email' => (string) $order->mrc_email,
                    'first_name' => (string) $order->mrc_first_name,
                    'last_name' => (string) $order->mrc_last_name,
                    'phone' => (string) $order->mrc_phone,
                    'address' => (string) $order->mrc_address,
                    'city' => (string) $order->mrc_city,
                    'zip' => (string) $order->mrc_zip,
                    'country' => (string) $order->mrc_country,
                    'orders' => 0,
                    'paid_orders' => 0,
                    'pending_orders' => 0,
                    'processing_orders' => 0,
                    'failed_orders' => 0,
                    'canceled_orders' => 0,
                    'revenue' => 0.0,
                    'last_order' => null,
                    'last_order_created' => 0,
                    'segments' => [],
                ];
            }

            $customers[$email]['orders']++;
            $state = $this->getOrderPaymentState($order);
            $bucket = $this->getPaymentStatusBucketFromState($state);
            if ($bucket === 'paid') {
                $customers[$email]['paid_orders']++;
                $customers[$email]['revenue'] += $this->getOrderTotal($order, $commerce);
            } elseif (isset($customers[$email][$bucket . '_orders'])) {
                $customers[$email][$bucket . '_orders']++;
            }

            if ((int) $order->created > (int) $customers[$email]['last_order_created']) {
                $customers[$email]['last_order'] = $order;
                $customers[$email]['last_order_created'] = (int) $order->created;
                if ($customers[$email]['name'] === '' || str_starts_with($customers[$email]['name'], 'order-')) {
                    $customers[$email]['name'] = $this->getOrderCustomer($order);
                }
                $customers[$email]['first_name'] = (string) $order->mrc_first_name;
                $customers[$email]['last_name'] = (string) $order->mrc_last_name;
                $customers[$email]['phone'] = (string) $order->mrc_phone;
                $customers[$email]['address'] = (string) $order->mrc_address;
                $customers[$email]['city'] = (string) $order->mrc_city;
                $customers[$email]['zip'] = (string) $order->mrc_zip;
                $customers[$email]['country'] = (string) $order->mrc_country;
            }
        }

        foreach ($customers as &$customer) {
            $customer['segments'] = $this->getCustomerSegments($customer);
        }
        unset($customer);

        uasort($customers, fn(array $a, array $b) => (int) $b['last_order_created'] <=> (int) $a['last_order_created']);
        return array_values($customers);
    }

    protected function getCustomerSegments(array $customer): array {
        $segments = [];
        $paidOrders = (int) ($customer['paid_orders'] ?? 0);
        $orders = (int) ($customer['orders'] ?? 0);
        $revenue = (float) ($customer['revenue'] ?? 0.0);
        $failedOrders = (int) ($customer['failed_orders'] ?? 0);
        $pendingOrders = (int) ($customer['pending_orders'] ?? 0);
        $processingOrders = (int) ($customer['processing_orders'] ?? 0);

        if ($paidOrders >= 3 || $revenue >= 100.0) {
            $segments[] = ['key' => 'vip', 'label' => $this->_('VIP'), 'class' => 'is-paid'];
        }

        if ($orders >= 2) {
            $segments[] = ['key' => 'repeat', 'label' => $this->_('Repeat'), 'class' => 'is-pending'];
        }

        if ($failedOrders > 0 || $processingOrders > 0 || ($pendingOrders > 0 && $paidOrders === 0 && $orders > 1)) {
            $segments[] = ['key' => 'needs_attention', 'label' => $this->_('Needs attention'), 'class' => 'is-failed'];
        }

        if (!$segments && $orders === 1) {
            $segments[] = ['key' => 'new', 'label' => $this->_('New'), 'class' => 'is-pending'];
        }

        return $segments;
    }

    protected function filterCustomersBySegment(array $customers, string $segment): array {
        if ($segment === 'all') {
            return $customers;
        }

        return array_values(array_filter($customers, fn(array $customer): bool => $this->customerHasSegment($customer, $segment)));
    }

    protected function filterCustomers(array $customers, array $filters): array {
        $segment = (string) ($filters['segment'] ?? 'all');
        $query = strtolower(trim((string) ($filters['q'] ?? '')));
        $customers = $this->filterCustomersBySegment($customers, $segment);
        if ($query === '') {
            return $customers;
        }

        return array_values(array_filter($customers, static function (array $customer) use ($query): bool {
            $haystack = strtolower(implode(' ', array_filter([
                (string) ($customer['name'] ?? ''),
                (string) ($customer['email'] ?? ''),
                (string) ($customer['phone'] ?? ''),
                (string) ($customer['address'] ?? ''),
                (string) ($customer['city'] ?? ''),
                (string) ($customer['zip'] ?? ''),
                (string) ($customer['country'] ?? ''),
            ])));
            return $haystack !== '' && str_contains($haystack, $query);
        }));
    }

    protected function customerHasSegment(array $customer, string $segment): bool {
        foreach ((array) ($customer['segments'] ?? []) as $candidate) {
            if ((string) ($candidate['key'] ?? '') === $segment) {
                return true;
            }
        }
        return false;
    }

    protected function getCustomerByKey(Mercato $commerce, string $key): ?array {
        if ($key === '') {
            return null;
        }

        foreach ($this->getCustomersFromOrders($commerce) as $customer) {
            if ((string) ($customer['key'] ?? '') === $key) {
                return $customer;
            }
        }

        return null;
    }

    protected function getCustomerOrders(Mercato $commerce, array $customer): PageArray {
        $orders = new PageArray();
        $email = trim((string) ($customer['email'] ?? ''));
        if ($email !== '') {
            $value = $this->wire('sanitizer')->selectorValue($email);
            return $this->wire('pages')->find("template={$commerce->order_template}, mrc_email=$value, include=all, sort=-created, limit=100");
        }

        $lastOrder = $customer['last_order'] ?? null;
        if ($lastOrder instanceof Page && $lastOrder->id) {
            $orders->add($lastOrder);
        }
        return $orders;
    }

    protected function getReportsData(Mercato $commerce): array {
        $orders = $this->getOrders($commerce, 1000);
        $summary = [
            'orders' => 0,
            'paid_orders' => 0,
            'pending_orders' => 0,
            'processing_orders' => 0,
            'failed_orders' => 0,
            'canceled_orders' => 0,
            'revenue' => 0.0,
            'open_value' => 0.0,
            'average_order_value' => 0.0,
            'items_sold' => 0.0,
            'shipping_revenue' => 0.0,
            'expired_reservations' => 0,
            'low_stock_products' => 0,
            'oversell_debt_units' => 0,
            'oversell_debt_products' => 0,
        ];
        $statusBreakdown = [];
        $orderStatusBreakdown = [];
        $productRows = [];
        $shippingTax = $this->getTaxShippingReportData($commerce);

        foreach ($orders as $order) {
            $summary['orders']++;
            $derived = $commerce->deriveOrderStatus($order);
            $derivedRaw = (string) ($derived['raw'] ?? MercatoOrderStatus::PENDING_PAYMENT);
            $orderStatusBreakdown[$derivedRaw] = ($orderStatusBreakdown[$derivedRaw] ?? 0) + 1;
            $state = $this->getOrderPaymentState($order);
            $raw = $state['raw'] ?: 'pending';
            $statusBreakdown[$raw] = ($statusBreakdown[$raw] ?? 0) + 1;

            $bucket = $this->getPaymentStatusBucketFromState($state);
            if ($bucket === 'paid') {
                $summary['paid_orders']++;
                $summary['revenue'] += $this->getOrderTotal($order, $commerce);
            } elseif ($bucket === 'processing') {
                $summary[$bucket . '_orders']++;
                $summary['open_value'] += $this->getOrderTotal($order, $commerce);
            } elseif ($bucket === 'pending') {
                $summary[$bucket . '_orders']++;
                $summary['open_value'] += $this->getOrderTotal($order, $commerce);
            } elseif (isset($summary[$bucket . '_orders'])) {
                $summary[$bucket . '_orders']++;
            }

            $items = json_decode((string) $order->mrc_items, true);
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $quantity = (float) ($item['quantity'] ?? 1);
                $shipping = (float) ($item['sum_shipping'] ?? $item['shipping_price'] ?? 0);
                if ($bucket === 'paid') {
                    $summary['items_sold'] += $quantity;
                    $summary['shipping_revenue'] += $shipping;
                }

                $key = (string) ($item['uid'] ?? $item['key'] ?? $item['id'] ?? $item['title'] ?? 'unknown');
                if (!isset($productRows[$key])) {
                    $productRows[$key] = [
                        'title' => (string) ($item['title'] ?? $key),
                        'sku' => (string) ($item['sku'] ?? ''),
                        'quantity' => 0.0,
                        'revenue' => 0.0,
                        'orders' => 0,
                    ];
                }

                if ($bucket === 'paid') {
                    $productRows[$key]['quantity'] += $quantity;
                    $productRows[$key]['revenue'] += (float) ($item['sum'] ?? ((float) ($item['price'] ?? 0) * $quantity));
                    $productRows[$key]['orders']++;
                }
            }
        }

        if ($summary['paid_orders'] > 0) {
            $summary['average_order_value'] = round($summary['revenue'] / $summary['paid_orders'], 2);
        }
        $oversellDebt = $this->getOversellDebtSummary();
        $summary['expired_reservations'] = $commerce->orderRepository()->countExpiredReservations();
        $summary['low_stock_products'] = $this->getLowStockProducts($commerce, 1000)->count();
        $summary['oversell_debt_units'] = (int) $oversellDebt['units'];
        $summary['oversell_debt_products'] = (int) $oversellDebt['products'];

        uasort($productRows, fn(array $a, array $b) => (float) $b['revenue'] <=> (float) $a['revenue']);

        return [
            'summary' => $summary,
            'order_statuses' => $orderStatusBreakdown,
            'statuses' => $statusBreakdown,
            'products' => array_values($productRows),
            'shipping_tax' => $shippingTax,
        ];
    }

    public function getCachedDashboardData(Mercato $commerce, string $scope = 'stats'): array {
        $scope = in_array($scope, ['stats', 'reports'], true) ? $scope : 'stats';
        return $this->getCachedDashboardPayload($commerce, $scope, function () use ($commerce, $scope): array {
            return $scope === 'reports'
                ? $this->getReportsData($commerce)
                : $this->getStats($commerce);
        });
    }

    protected function getCachedDashboardPayload(Mercato $commerce, string $scope, callable $builder): array {
        $cacheKey = $this->getDashboardCacheKey($commerce, $scope);
        try {
            $cache = $this->wire('cache');
            if ($cache) {
                $cached = $cache->get($cacheKey);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        } catch (\Throwable $e) {
            $cache = null;
        }

        $payload = $builder();
        if (!is_array($payload)) {
            return [];
        }

        try {
            $cache = $cache ?? $this->wire('cache');
            if ($cache) {
                $cache->save($cacheKey, $payload, self::DASHBOARD_CACHE_TTL_SECONDS);
            }
        } catch (\Throwable $e) {
        }

        return $payload;
    }

    protected function getDashboardCacheKey(Mercato $commerce, string $scope): string {
        return 'mercato.dashboard.' . $scope . '.' . sha1(implode('|', [
            (string) ($commerce->order_template ?: 'mrc-order'),
            (string) ($commerce->product_template ?: 'mrc-product'),
            (string) ($commerce->low_stock_threshold ?? ''),
            (string) ($commerce->currency ?? ''),
        ]));
    }

    protected function getTaxShippingReportData(Mercato $commerce): array {
        $products = $this->wire('pages')->find('template=mrc-product, include=all, limit=1000');
        $taxRates = [];
        $shippingProducts = 0;
        $freeShippingProducts = 0;

        foreach ($products as $product) {
            if ($product->hasField('mrc_tax_rate')) {
                $rate = round((float) $product->mrc_tax_rate, 3);
                if ($rate > 0) {
                    $taxRates[(string) $rate] = $rate;
                }
            }

            $shipping = $product->hasField('mrc_shipping_price') ? (float) $product->mrc_shipping_price : 0.0;
            if ($shipping > 0) {
                $shippingProducts++;
            } else {
                $freeShippingProducts++;
            }
        }

        ksort($taxRates, SORT_NUMERIC);
        $fulfilmentOptions = Mercato::getFulfilmentMethodOptions();
        $enabledMethods = array_map(
            static fn(string $method): string => (string) ($fulfilmentOptions[$method] ?? $method),
            $commerce->getEnabledFulfilmentMethods()
        );
        $allowedCountries = $commerce->getAllowedDeliveryCountries();
        $localZones = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', strtoupper((string) ($commerce->local_delivery_postcodes ?? ''))) ?: []
        )));

        return [
            'product_count' => $products->count(),
            'tax_rates' => array_values($taxRates),
            'shipping_products' => $shippingProducts,
            'free_shipping_products' => $freeShippingProducts,
            'enabled_methods' => $enabledMethods,
            'free_shipping_threshold' => $commerce->getFreeShippingThreshold(),
            'tax_shipping' => $commerce->shouldTaxShipping(),
            'shipping_tax_rate' => $commerce->getShippingTaxRate(),
            'allowed_countries' => $allowedCountries,
            'local_delivery_minimum' => $commerce->getLocalDeliveryMinimumOrder(),
            'local_delivery_zones' => $localZones,
            'settings_url' => $this->wire('config')->urls->admin . 'module/edit?name=Mercato',
        ];
    }

    protected function getStats(Mercato $commerce): array {
        $template = $this->wire('sanitizer')->selectorValue($commerce->order_template ?: 'mrc-order');
        $orders = $this->wire('pages')->find("template=$template, include=all");
        $products = $this->wire('pages')->find("template=mrc-product, include=all");

        $paid = 0;
        $pending = 0;
        $processing = 0;
        $failed = 0;
        $canceled = 0;
        $openValue = 0.0;
        $revenue = 0.0;
        $latest = null;
        $lowStock = 0;

        foreach ($orders as $order) {
            $state = $this->getOrderPaymentState($order);
            $bucket = $this->getPaymentStatusBucketFromState($state);
            if ($bucket === 'paid') {
                $paid++;
                $revenue += $this->getOrderTotal($order, $commerce);
            } elseif ($bucket === 'processing') {
                $processing++;
                $openValue += $this->getOrderTotal($order, $commerce);
            } elseif ($bucket === 'failed') {
                $failed++;
            } elseif ($bucket === 'canceled') {
                $canceled++;
            } else {
                $pending++;
                $openValue += $this->getOrderTotal($order, $commerce);
            }
            if (!$latest || $order->created > $latest) {
                $latest = $order->created;
            }
        }

        foreach ($products as $product) {
            if ($this->isLowStockProduct($product, $commerce)) {
                $lowStock++;
            }
        }

        return [
            'total' => $orders->count(),
            'paid' => $paid,
            'pending' => $pending,
            'processing' => $processing,
            'failed' => $failed,
            'canceled' => $canceled,
            'revenue' => $revenue,
            'open_value' => $openValue,
            'products' => $products->count(),
            'low_stock' => $lowStock,
            'latest' => $latest,
        ];
    }
}
