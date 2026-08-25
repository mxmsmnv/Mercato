<?php
namespace ProcessWire;

trait ProcessMercatoProductActions {

    protected function handleProductVariants(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_save_variants') !== '1') return [];
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_PRODUCTS)) {
            return $this->permissionError(self::PERMISSION_MANAGE_PRODUCTS, $this->_('Variant update was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return ['summary' => $this->_('Variant update was blocked.'), 'errors' => [$this->_('CSRF token validation failed.')]];
        }
        $product = $this->wire('pages')->get((int) $this->wire('input')->post->int('product_id'));
        if (!$product || !$product->id || $product->template->name !== 'mrc-product') {
            return ['summary' => $this->_('Variant update failed.'), 'errors' => [$this->_('Product not found.')]];
        }
        $options = json_decode((string) $this->wire('input')->post('variant_options_json'), true);
        $variants = json_decode((string) $this->wire('input')->post('variants_json'), true);
        if (!is_array($options) || !is_array($variants)) {
            return ['summary' => $this->_('Variant update failed.'), 'errors' => [$this->_('Options and variants must be valid JSON arrays.')]];
        }
        try {
            $result = $commerce->variantService()->saveDefinition($product, $options, $variants);
            $summary = sprintf($this->_('Saved %d option group(s) and %d variant(s).'), count($result['options']), count($result['variants']));
            $this->recordProductEvent('product_variants_updated', $product, [
                'option_groups' => count($result['options']), 'variants' => count($result['variants']),
            ]);
            $this->wire('session')->message($summary);
            return ['summary' => $summary, 'errors' => []];
        } catch (WireException $e) {
            return ['summary' => $this->_('Variant update failed.'), 'errors' => [$e->getMessage()]];
        }
    }

    protected function handleProductImport(Mercato $commerce): array {
        $action = (string) $this->wire('input')->post->text('mrc_import_products');
        if (!in_array($action, ['preview', 'import'], true)) {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_PRODUCTS)) {
            return $this->permissionError(self::PERMISSION_MANAGE_PRODUCTS, $this->_('Product import was blocked.'));
        }

        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Import blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $csv = (string) $this->wire('input')->post->textarea('mrc_products_csv');
        $rows = $this->parseProductCsv($csv);
        if (!empty($rows['errors'])) {
            return [
                'summary' => $this->_('CSV could not be imported.'),
                'errors' => $rows['errors'],
            ];
        }

        $dryRun = $action === 'preview';
        $result = $this->importProductRows($commerce, $rows['rows'], $dryRun);
        $result['summary'] = $dryRun
            ? sprintf($this->_('Preview: %d create, %d update, %d skipped.'), $result['created'], $result['updated'], $result['skipped'])
            : sprintf($this->_('Imported: %d created, %d updated, %d skipped, %d image(s).'), $result['created'], $result['updated'], $result['skipped'], $result['images'] ?? 0);

        if (!$dryRun && empty($result['errors'])) {
            $this->wire('session')->message($result['summary']);
        }

        return $result;
    }

    protected function handleProductBulkAction(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_bulk_product_action') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_PRODUCTS)) {
            return $this->permissionError(self::PERMISSION_MANAGE_PRODUCTS, $this->_('Bulk product action was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Bulk product action was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $action = strtolower(trim((string) $this->wire('input')->post->text('bulk_action')));
        $priceValueRaw = trim((string) $this->wire('input')->post('bulk_price_value'));
        $priceValueNormalized = str_replace(',', '.', $priceValueRaw);
        $priceValue = is_numeric($priceValueNormalized) ? (float) $priceValueNormalized : null;
        $ids = array_values(array_unique(array_map('intval', (array) $this->wire('input')->post('product_ids'))));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if (!$ids) {
            return [
                'summary' => $this->_('Bulk product action failed.'),
                'errors' => [$this->_('Select at least one product.')],
            ];
        }
        if (count($ids) > 100) {
            return [
                'summary' => $this->_('Bulk product action failed.'),
                'errors' => [$this->_('Bulk product actions are limited to 100 products per request.')],
            ];
        }
        if (str_starts_with($action, 'price:') && $priceValue === null) {
            return [
                'summary' => $this->_('Bulk product action failed.'),
                'errors' => [$this->_('Enter a numeric price value before applying a price action.')],
            ];
        }

        $updated = 0;
        $errors = [];
        foreach ($ids as $id) {
            $product = $this->wire('pages')->get($id);
            if (!$product || !$product->id || $product->template->name !== 'mrc-product') {
                $errors[] = sprintf($this->_('Product %d was not found.'), $id);
                continue;
            }

            try {
                $product->of(false);
                $previousStatus = $this->getProductPublicationStatus($product);
                $previousPolicy = $this->getProductStockPolicy($product);
                $previousProductStatus = $this->getProductLifecycleStatus($product);
                $previousProductType = $this->getProductType($product);
                $previousPrice = $product->hasField('mrc_price') ? (float) $product->mrc_price : null;
                $eventName = 'product_bulk_updated';
                $eventPayload = ['action' => $action];
                if ($action === 'publish') {
                    $product->removeStatus(Page::statusUnpublished);
                    $product->removeStatus(Page::statusHidden);
                    $eventName = 'product_bulk_status';
                } elseif ($action === 'hide') {
                    $product->removeStatus(Page::statusUnpublished);
                    $product->addStatus(Page::statusHidden);
                    $eventName = 'product_bulk_status';
                } elseif ($action === 'unpublish') {
                    $product->addStatus(Page::statusUnpublished);
                    $eventName = 'product_bulk_status';
                } elseif (str_starts_with($action, 'policy:')) {
                    $policy = substr($action, 7);
                    if (!in_array($policy, ['deny', 'backorder', 'preorder'], true)) {
                        $errors[] = $this->_('Invalid stock policy action.');
                        continue;
                    }
                    if (!$product->hasField('mrc_stock_policy')) {
                        $errors[] = sprintf($this->_('%s has no stock policy field.'), (string) $product->title);
                        continue;
                    }
                    $product->mrc_stock_policy = $policy;
                    $eventName = 'product_bulk_policy';
                } elseif (str_starts_with($action, 'lifecycle:')) {
                    $lifecycleStatus = substr($action, 10);
                    if (!in_array($lifecycleStatus, ['active', 'archived', 'discontinued'], true)) {
                        $errors[] = $this->_('Invalid product status action.');
                        continue;
                    }
                    if (!$product->hasField('mrc_product_status')) {
                        $errors[] = sprintf($this->_('%s has no product status field.'), (string) $product->title);
                        continue;
                    }
                    $product->mrc_product_status = $lifecycleStatus;
                    $eventName = 'product_bulk_lifecycle';
                } elseif (str_starts_with($action, 'type:')) {
                    $productType = substr($action, 5);
                    if (!in_array($productType, ['physical', 'digital', 'service', 'placeholder', 'recurring'], true)) {
                        $errors[] = $this->_('Invalid product type action.');
                        continue;
                    }
                    if (!$product->hasField('mrc_product_type')) {
                        $errors[] = sprintf($this->_('%s has no product type field.'), (string) $product->title);
                        continue;
                    }
                    $product->mrc_product_type = $productType;
                    $eventName = 'product_bulk_type';
                } elseif (str_starts_with($action, 'price:')) {
                    if (!$product->hasField('mrc_price')) {
                        $errors[] = sprintf($this->_('%s has no price field.'), (string) $product->title);
                        continue;
                    }
                    $priceAction = substr($action, 6);
                    $oldPrice = (float) $product->mrc_price;
                    $newPrice = match ($priceAction) {
                        'set' => $priceValue,
                        'add' => $oldPrice + $priceValue,
                        'percent' => $oldPrice * (1 + ($priceValue / 100)),
                        default => -1,
                    };
                    if (!is_finite($newPrice) || $newPrice < 0) {
                        $errors[] = sprintf($this->_('%s would get an invalid price.'), (string) $product->title);
                        continue;
                    }
                    $product->mrc_price = round($newPrice, 2);
                    $eventName = 'product_bulk_price';
                    $eventPayload['value'] = $priceValue;
                } else {
                    return [
                        'summary' => $this->_('Bulk product action failed.'),
                        'errors' => [$this->_('Choose a valid bulk action.')],
                    ];
                }
                $this->wire('pages')->save($product);
                $eventPayload += [
                    'from_status' => $previousStatus,
                    'to_status' => $this->getProductPublicationStatus($product),
                    'from_policy' => $previousPolicy,
                    'to_policy' => $this->getProductStockPolicy($product),
                    'from_product_status' => $previousProductStatus,
                    'to_product_status' => $this->getProductLifecycleStatus($product),
                    'from_product_type' => $previousProductType,
                    'to_product_type' => $this->getProductType($product),
                    'from_price' => $previousPrice,
                    'to_price' => $product->hasField('mrc_price') ? (float) $product->mrc_price : null,
                ];
                $this->recordProductEvent($eventName, $product, $eventPayload);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: %s', (string) ($product->title ?: $id), $e->getMessage());
            }
        }

        $summary = sprintf($this->_('Updated %d product(s).'), $updated);
        if ($updated > 0) {
            $this->wire('session')->message($summary);
        }
        return [
            'summary' => $summary,
            'errors' => $errors,
            'warning' => (bool) $errors,
        ];
    }

    protected function handleProductQuickUpdate(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_quick_update_product') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_PRODUCTS)) {
            return $this->permissionError(self::PERMISSION_MANAGE_PRODUCTS, $this->_('Product quick update was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Product quick update was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $productId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('product_id'));
        $product = $this->wire('pages')->get($productId);
        if (!$product || !$product->id || !$product->template || $product->template->name !== 'mrc-product') {
            return [
                'summary' => $this->_('Product quick update failed.'),
                'errors' => [$this->_('Product not found.')],
            ];
        }

        $price = $this->normalizeDecimalInput((string) $this->wire('input')->post('quick_price'));
        $shipping = $this->normalizeDecimalInput((string) $this->wire('input')->post('quick_shipping_price'));
        if ($price === null || $price < 0 || $shipping === null || $shipping < 0) {
            return [
                'summary' => $this->_('Product quick update failed.'),
                'errors' => [$this->_('Price and shipping must be non-negative numeric values.')],
            ];
        }

        $policy = strtolower(trim((string) $this->wire('input')->post->text('quick_stock_policy')));
        if (!in_array($policy, ['deny', 'backorder', 'preorder'], true)) {
            return [
                'summary' => $this->_('Product quick update failed.'),
                'errors' => [$this->_('Choose a valid stock policy.')],
            ];
        }
        $lifecycleStatus = strtolower(trim((string) $this->wire('input')->post->text('quick_product_status')));
        if (!in_array($lifecycleStatus, ['active', 'archived', 'discontinued'], true)) {
            return [
                'summary' => $this->_('Product quick update failed.'),
                'errors' => [$this->_('Choose a valid product status.')],
            ];
        }
        $productType = strtolower(trim((string) $this->wire('input')->post->text('quick_product_type')));
        if (!in_array($productType, ['physical', 'digital', 'service', 'placeholder', 'recurring'], true)) {
            return [
                'summary' => $this->_('Product quick update failed.'),
                'errors' => [$this->_('Choose a valid product type.')],
            ];
        }
        $status = strtolower(trim((string) $this->wire('input')->post->text('quick_status')));
        if (!in_array($status, ['published', 'hidden', 'unpublished'], true)) {
            return [
                'summary' => $this->_('Product quick update failed.'),
                'errors' => [$this->_('Choose a valid publication status.')],
            ];
        }

        $threshold = max(0, (int) $this->wire('sanitizer')->int($this->wire('input')->post('quick_low_stock_threshold')));
        $previous = [
            'price' => $product->hasField('mrc_price') ? (float) $product->mrc_price : null,
            'shipping_price' => $product->hasField('mrc_shipping_price') ? (float) $product->mrc_shipping_price : null,
            'low_stock_threshold' => $product->hasField('mrc_low_stock_threshold') ? (int) $product->mrc_low_stock_threshold : null,
            'stock_policy' => $this->getProductStockPolicy($product),
            'product_status' => $this->getProductLifecycleStatus($product),
            'product_type' => $this->getProductType($product),
            'status' => $this->getProductPublicationStatus($product),
        ];

        $product->of(false);
        if ($product->hasField('mrc_price')) $product->mrc_price = round($price, 2);
        if ($product->hasField('mrc_shipping_price')) $product->mrc_shipping_price = round($shipping, 2);
        if ($product->hasField('mrc_low_stock_threshold')) $product->mrc_low_stock_threshold = $threshold;
        if ($product->hasField('mrc_stock_policy')) $product->mrc_stock_policy = $policy;
        if ($product->hasField('mrc_product_status')) $product->mrc_product_status = $lifecycleStatus;
        if ($product->hasField('mrc_product_type')) $product->mrc_product_type = $productType;
        if ($status === 'published') {
            $product->removeStatus(Page::statusUnpublished);
            $product->removeStatus(Page::statusHidden);
        } elseif ($status === 'hidden') {
            $product->removeStatus(Page::statusUnpublished);
            $product->addStatus(Page::statusHidden);
        } else {
            $product->addStatus(Page::statusUnpublished);
        }

        $this->wire('pages')->save($product);
        $this->recordProductEvent('product_quick_updated', $product, [
            'source' => 'product_detail',
            'changed_fields' => implode(',', array_keys(array_filter([
                'price' => $previous['price'] !== ($product->hasField('mrc_price') ? (float) $product->mrc_price : null),
                'shipping_price' => $previous['shipping_price'] !== ($product->hasField('mrc_shipping_price') ? (float) $product->mrc_shipping_price : null),
                'low_stock_threshold' => $previous['low_stock_threshold'] !== ($product->hasField('mrc_low_stock_threshold') ? (int) $product->mrc_low_stock_threshold : null),
                'stock_policy' => $previous['stock_policy'] !== $this->getProductStockPolicy($product),
                'product_status' => $previous['product_status'] !== $this->getProductLifecycleStatus($product),
                'product_type' => $previous['product_type'] !== $this->getProductType($product),
                'status' => $previous['status'] !== $this->getProductPublicationStatus($product),
            ]))),
            'from_price' => $previous['price'],
            'to_price' => $product->hasField('mrc_price') ? (float) $product->mrc_price : null,
            'from_shipping_price' => $previous['shipping_price'],
            'to_shipping_price' => $product->hasField('mrc_shipping_price') ? (float) $product->mrc_shipping_price : null,
            'from_low_stock_threshold' => $previous['low_stock_threshold'],
            'to_low_stock_threshold' => $product->hasField('mrc_low_stock_threshold') ? (int) $product->mrc_low_stock_threshold : null,
            'from_policy' => $previous['stock_policy'],
            'to_policy' => $this->getProductStockPolicy($product),
            'from_product_status' => $previous['product_status'],
            'to_product_status' => $this->getProductLifecycleStatus($product),
            'from_product_type' => $previous['product_type'],
            'to_product_type' => $this->getProductType($product),
            'from_status' => $previous['status'],
            'to_status' => $this->getProductPublicationStatus($product),
        ]);

        $summary = sprintf($this->_('Updated quick commerce fields for "%s".'), (string) $product->title);
        $this->wire('session')->message($summary);
        return [
            'summary' => $summary,
            'errors' => [],
        ];
    }

    protected function normalizeDecimalInput(string $value): ?float {
        $value = trim(str_replace(',', '.', $value));
        return is_numeric($value) ? (float) $value : null;
    }

    protected function handleProductDuplicate(Mercato $commerce): array {
        if ((string) $this->wire('input')->post->text('mrc_duplicate_product') !== '1') {
            return [];
        }
        if (!$this->hasCommercePermission(self::PERMISSION_MANAGE_PRODUCTS)) {
            return $this->permissionError(self::PERMISSION_MANAGE_PRODUCTS, $this->_('Product duplicate was blocked.'));
        }
        if (!$this->validateCsrf()) {
            return [
                'summary' => $this->_('Product duplicate was blocked. Refresh the page and try again.'),
                'errors' => [$this->_('CSRF token validation failed.')],
            ];
        }

        $sourceId = (int) $this->wire('sanitizer')->int($this->wire('input')->post('product_id'));
        $source = $this->wire('pages')->get($sourceId);
        $parent = $this->wire('pages')->get('/products/');
        $template = $this->wire('templates')->get('mrc-product');
        if (!$source || !$source->id || !$source->template || $source->template->name !== 'mrc-product' || !$parent || !$parent->id || !$template) {
            return [
                'summary' => $this->_('Product duplicate failed.'),
                'errors' => [$this->_('Source product or product template is missing.')],
            ];
        }

        $title = trim((string) $this->wire('input')->post->text('duplicate_title'));
        if ($title === '') {
            $title = sprintf($this->_('%s copy'), (string) $source->title);
        }
        $sku = trim((string) $this->wire('input')->post->text('duplicate_sku'));
        if ($sku === '') {
            $sku = (string) ($source->hasField('mrc_sku') && trim((string) $source->mrc_sku) !== '' ? $source->mrc_sku . '-COPY' : 'MRC-COPY-' . (int) $source->id);
        }
        $sku = $this->getUniqueProductSku($sku);
        $copyImages = (int) $this->wire('input')->post('duplicate_copy_images') === 1;

        try {
            $product = new Page();
            $product->template = $template;
            $product->parent = $parent;
            $product->name = $this->getUniqueProductName($title, $parent);
            $product->title = $title;
            if ($product->hasField('mrc_sku')) $product->mrc_sku = $sku;
            if ($product->hasField('mrc_price') && $source->hasField('mrc_price')) $product->mrc_price = (float) $source->mrc_price;
            if ($product->hasField('mrc_tax_rate') && $source->hasField('mrc_tax_rate')) $product->mrc_tax_rate = (float) $source->mrc_tax_rate;
            if ($product->hasField('mrc_shipping_price') && $source->hasField('mrc_shipping_price')) $product->mrc_shipping_price = (float) $source->mrc_shipping_price;
            if ($product->hasField('mrc_shipping_note') && $source->hasField('mrc_shipping_note')) $product->mrc_shipping_note = (string) $source->mrc_shipping_note;
            if ($product->hasField('mrc_stock') && $source->hasField('mrc_stock')) $product->mrc_stock = (int) $source->mrc_stock;
            if ($product->hasField('mrc_low_stock_threshold') && $source->hasField('mrc_low_stock_threshold')) $product->mrc_low_stock_threshold = (int) $source->mrc_low_stock_threshold;
            if ($product->hasField('mrc_stock_policy') && $source->hasField('mrc_stock_policy')) $product->mrc_stock_policy = $this->getProductStockPolicy($source);
            if ($product->hasField('mrc_description') && $source->hasField('mrc_description')) $product->mrc_description = (string) $source->mrc_description;
            if ($product->hasField('mrc_collections') && $source->hasField('mrc_collections')) {
                foreach ($source->mrc_collections as $collection) {
                    if ($collection instanceof Page && $collection->id) {
                        $product->mrc_collections->add($collection);
                    }
                }
            }

            $this->wire('pages')->save($product);
            $copiedImages = 0;
            if ($copyImages && $product->hasField('mrc_images') && $source->hasField('mrc_images')) {
                foreach ($source->mrc_images as $image) {
                    if (!empty($image->filename) && is_file((string) $image->filename)) {
                        $product->mrc_images->add((string) $image->filename);
                        $copiedImages++;
                    }
                }
                if ($copiedImages > 0) {
                    $this->wire('pages')->save($product);
                }
            }

            $this->recordProductEvent('product_duplicated', $product, [
                'source' => 'product_detail',
                'source_product_id' => (int) $source->id,
                'source_product_title' => (string) $source->title,
                'copied_images' => $copiedImages,
                'to_sku' => $sku,
            ]);

            $summary = sprintf($this->_('Duplicated "%s" as "%s".'), (string) $source->title, (string) $product->title);
            $this->wire('session')->message($summary);
            return [
                'summary' => $summary,
                'errors' => [],
                'product_id' => (int) $product->id,
                'product_title' => (string) $product->title,
                'product_url' => $this->productDetailUrl($product),
            ];
        } catch (\Throwable $e) {
            return [
                'summary' => $this->_('Product duplicate failed.'),
                'errors' => [$e->getMessage()],
            ];
        }
    }

    protected function getUniqueProductName(string $title, Page $parent): string {
        $base = $this->wire('sanitizer')->pageName($title);
        if ($base === '') {
            $base = 'product-copy';
        }
        $name = $base;
        $i = 2;
        while ($this->wire('pages')->get($parent->path . $name . '/')->id) {
            $name = $base . '-' . $i;
            $i++;
        }
        return $name;
    }

    protected function getUniqueProductSku(string $base): string {
        $sku = trim($base);
        if ($sku === '') {
            $sku = 'MRC-COPY';
        }
        $candidate = $sku;
        $i = 2;
        while ($this->wire('pages')->get('template=mrc-product, include=all, mrc_sku=' . $this->wire('sanitizer')->selectorValue($candidate))->id) {
            $candidate = $sku . '-' . $i;
            $i++;
        }
        return $candidate;
    }

}
