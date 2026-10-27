<?php
namespace ProcessWire;

/**
 * Server-authoritative catalog boundary for MCP, CLI and feed integrations.
 *
 * Callers remain responsible for transport scopes, credentials, rate limits,
 * confirmations and the durable MCP idempotency ledger. This service owns the
 * domain validation, uniqueness checks and bounded ProcessWire mutations.
 */
final class MercatoCatalogService extends Wire {

    private $imageResolver;
    private $imageTransport;

    public function __construct(protected Mercato $commerce, ?callable $imageResolver = null, ?callable $imageTransport = null) {
        parent::__construct();
        $this->imageResolver = $imageResolver ?? fn(string $host): array => $this->resolveImageHost($host);
        $this->imageTransport = $imageTransport ?? fn(string $url, string $ip, int $maxBytes, int $timeout): array => $this->fetchImageResponse($url, $ip, $maxBytes, $timeout);
    }

    /** @return array<string,mixed> */
    public function listForAutomation(array $query = []): array {
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $afterId = max(0, (int) ($query['after_id'] ?? 0));
        $status = (string) ($query['product_status'] ?? 'all');
        $parts = ['template=' . $this->safe($this->productTemplate()), 'include=all', 'id>' . $afterId];
        if ($status !== 'all') {
            if (!in_array($status, MercatoCatalogRules::LIFECYCLE_STATUSES, true)) throw new WireException('Invalid product status filter.', 422);
            $parts[] = 'mrc_product_status=' . $this->safe($status);
        }
        $search = substr(trim((string) ($query['query'] ?? '')), 0, 240);
        if ($search !== '') $parts[] = 'title|mrc_sku|mrc_description%=' . $this->safe($search);
        $pages = $this->wire('pages')->find(implode(', ', $parts) . ', sort=id, limit=' . ($limit + 1));
        $items = [];
        foreach ($pages as $index => $product) {
            if ($index >= $limit) break;
            $items[] = $this->serialize($product);
        }
        $last = end($items);
        return ['count' => count($items), 'items' => $items, 'next_after_id' => $last ? (int) $last['id'] : $afterId, 'has_more' => count($pages) > $limit];
    }

    /** @return array<string,mixed> */
    /** @param int|string|array<string,mixed> $reference */
    public function getForAutomation(int|string|array $reference): array {
        return $this->get($reference);
    }

    /** @return array<string,mixed> */
    public function previewUpsert(array $input): array {
        $identity = MercatoCatalogRules::normalizeUpsert($input, false);
        $bySku = ($identity['sku'] ?? '') !== '' ? $this->findBySku((string) $identity['sku']) : null;
        $byExternal = null;
        if (($identity['external_source'] ?? '') !== '') {
            $this->requireExternalIdentitySchema();
            $byExternal = $this->findByExternal((string) $identity['external_source'], (string) $identity['external_id']);
        }
        if ($bySku && $byExternal && (int) $bySku->id !== (int) $byExternal->id) throw new WireException('SKU and external identity resolve to different products.', 409);
        $product = $byExternal ?: $bySku;
        $creating = !$product || !$product->id;
        $normalized = MercatoCatalogRules::normalizeUpsert($input, $creating);
        if (array_key_exists('sku', $normalized) && $normalized['sku'] !== '') $this->assertSkuAvailable((string) $normalized['sku'], $creating ? 0 : (int) $product->id);
        if (array_key_exists('variants', $normalized)) {
            if ($creating) {
                $validationProduct = new Page();
                $validationProduct->template = $this->productTemplate();
            } else {
                $validationProduct = clone $product;
            }
            $validationProduct->of(false);
            if (array_key_exists('sku', $normalized) && $validationProduct->hasField('mrc_sku')) $validationProduct->mrc_sku = $normalized['sku'];
            $variantDefinition = $this->commerce->variantService()->validateDefinition($validationProduct, $normalized['variant_options'], $normalized['variants']);
            if (!$variantDefinition['valid']) throw new WireException(implode(' ', $variantDefinition['errors']), 422);
            $normalized['variant_options'] = $variantDefinition['options'];
            $normalized['variants'] = $variantDefinition['variants'];
        }
        $current = $creating ? null : $this->serialize($product);
        $digestPayload = ['action' => $creating ? 'create' : 'update', 'current_version' => $current['version'] ?? '', 'product' => $normalized];
        return [
            'valid' => true,
            'action' => $creating ? 'create' : 'update',
            'product_id' => $creating ? 0 : (int) $product->id,
            'current' => $current,
            'normalized' => $normalized,
            'preview_digest' => MercatoCatalogRules::version($digestPayload),
        ];
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int,limit:int,offset:int} */
    public function search(array $query = []): array {
        $query = MercatoCatalogRules::normalizeSearch($query);
        $parts = ['template=' . $this->safe($this->productTemplate()), 'include=all'];
        if (!$query['include_inactive']) {
            $parts[] = 'status<' . Page::statusHidden;
            $parts[] = 'mrc_product_status=active';
        }
        if ($query['q'] !== '') $parts[] = 'title|mrc_sku|mrc_description%=' . $this->safe($query['q']);
        if ($query['sku'] !== '') $parts[] = 'mrc_sku=' . $this->safe($query['sku']);
        if ($query['status'] !== '') $parts[] = 'mrc_product_status=' . $this->safe($query['status']);
        if ($query['type'] !== '') $parts[] = 'mrc_product_type=' . $this->safe($query['type']);
        if ($query['external_source'] !== '') {
            $this->requireExternalIdentitySchema();
            $parts[] = 'mrc_external_source=' . $this->safe($query['external_source']);
            $parts[] = 'mrc_external_id=' . $this->safe($query['external_id']);
        }
        $base = implode(', ', $parts);
        $total = (int) $this->wire('pages')->count($base);
        $selector = $base . ', sort=title, sort=id, start=' . $query['offset'] . ', limit=' . $query['limit'];
        $items = [];
        foreach ($this->wire('pages')->find($selector) as $product) $items[] = $this->serialize($product);
        return ['items' => $items, 'total' => $total, 'limit' => $query['limit'], 'offset' => $query['offset']];
    }

    /** @param int|string|array<string,mixed> $reference */
    public function get(int|string|array $reference): array {
        return $this->serialize($this->findProduct($reference, true));
    }

    /**
     * Creates or updates one product. At least one stable identity (SKU or the
     * external source/id pair) is required for an unattended upsert.
     *
     * @return array{action:string,product:array<string,mixed>}
     */
    public function upsert(array $input, array $context = []): array {
        $identity = MercatoCatalogRules::normalizeUpsert($input, false);
        $hasSku = ($identity['sku'] ?? '') !== '';
        $hasExternal = ($identity['external_source'] ?? '') !== '';
        if (!$hasSku && !$hasExternal) throw new WireException('Catalog upsert requires sku or external_source plus external_id.', 422);
        if ($hasExternal) $this->requireExternalIdentitySchema();

        $lockName = 'mercato_catalog_upsert_' . substr(hash('sha256', strtolower(
            ($identity['sku'] ?? '') . '|' . ($identity['external_source'] ?? '') . '|' . ($identity['external_id'] ?? '')
        )), 0, 40);
        return $this->withLock($lockName, function () use ($input, $identity, $hasSku, $hasExternal, $context): array {
            $bySku = $hasSku ? $this->findBySku((string) $identity['sku']) : null;
            $byExternal = $hasExternal ? $this->findByExternal((string) $identity['external_source'], (string) $identity['external_id']) : null;
            if ($bySku && $byExternal && (int) $bySku->id !== (int) $byExternal->id) {
                throw new WireException('SKU and external identity resolve to different products.', 409);
            }
            $product = $byExternal ?: $bySku;
            $creating = !$product || !$product->id;
            $expectedAction = (string) ($context['expected_action'] ?? '');
            if ($expectedAction !== '' && $expectedAction !== ($creating ? 'create' : 'update')) throw new WireException('Catalog product state changed after preview.', 409);
            $data = MercatoCatalogRules::normalizeUpsert($input, $creating);
            if (!$creating && ($data['expected_version'] ?? '') !== '') $this->assertVersion($product, $data['expected_version']);

            if ($creating) {
                $template = $this->wire('templates')->get($this->productTemplate());
                $parent = $this->wire('pages')->get('/' . trim((string) ($this->commerce->products_parent ?? 'products'), '/') . '/');
                if (!$template || !$template->id || !$parent || !$parent->id) throw new WireException('Mercato product schema is not installed.', 503);
                $product = new Page();
                $product->template = $template;
                $product->parent = $parent;
                $product->name = $this->uniqueName((string) ($data['name'] ?? $data['title']));
            }

            if (array_key_exists('sku', $data) && $data['sku'] !== '') $this->assertSkuAvailable($data['sku'], (int) $product->id);
            // Variant validation compares every variant SKU with the product's
            // current base SKU. Apply the proposed value in memory before
            // validation so a single request cannot assign the same SKU to the
            // product and one of its variants.
            if (array_key_exists('sku', $data) && $product->hasField('mrc_sku')) $product->mrc_sku = $data['sku'];
            $variantDefinition = null;
            if (array_key_exists('variants', $data)) {
                $variantDefinition = $this->commerce->variantService()->validateDefinition($product, $data['variant_options'], $data['variants']);
                if (!$variantDefinition['valid']) throw new WireException(implode(' ', $variantDefinition['errors']), 422);
            }

            $product->of(false);
            $map = [
                'title' => 'title', 'sku' => 'mrc_sku', 'price' => 'mrc_price',
                'product_status' => 'mrc_product_status', 'product_type' => 'mrc_product_type',
                'stock' => 'mrc_stock', 'stock_policy' => 'mrc_stock_policy',
                'low_stock_threshold' => 'mrc_low_stock_threshold', 'description' => 'mrc_description',
                'tax_rate' => 'mrc_tax_rate', 'tax_code' => 'mrc_tax_code',
                'shipping_price' => 'mrc_shipping_price', 'external_source' => 'mrc_external_source',
                'external_id' => 'mrc_external_id',
            ];
            foreach ($map as $key => $field) {
                if (!array_key_exists($key, $data)) continue;
                if ($field !== 'title' && !$product->hasField($field)) throw new WireException("Required product field is missing: $field.", 503);
                $product->set($field, $data[$key]);
            }
            if (isset($data['name']) && $data['name'] !== '') $product->name = $this->uniqueName($data['name'], (int) $product->id);
            if (isset($data['publication_status'])) $this->applyPublicationStatus($product, $data['publication_status']);
            $this->wire('pages')->save($product);
            if ($variantDefinition !== null) {
                $this->commerce->variantService()->saveDefinition($product, $variantDefinition['options'], $variantDefinition['variants']);
            }
            $result = ['action' => $creating ? 'created' : 'updated', 'product' => $this->serialize($this->fresh((int) $product->id))];
            $this->commerce->recordEvent('mercato-products', [
                'event' => 'catalog_product_' . $result['action'],
                'product_id' => (int) $product->id,
                'sku' => (string) ($result['product']['sku'] ?? ''),
                'external_source' => (string) ($result['product']['external_source'] ?? ''),
                'reason_hash' => isset($context['reason']) ? hash('sha256', (string) $context['reason']) : '',
                'source' => substr(trim((string) ($context['source'] ?? 'catalog_service')), 0, 80),
            ], 'catalog_product_saved');
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function setInventory(string $reference, string $variantId, string $mode, int $quantity, array $context = []): array {
        if (!array_key_exists('expected_current', $context)) throw new WireException('expected_current is required for inventory mutation.', 422);
        $expected = MercatoCatalogRules::normalizeAbsoluteStock($context['expected_current']);
        if (!in_array($mode, ['set', 'adjust'], true)) throw new WireException('Inventory mode must be set or adjust.', 422);
        $target = $mode === 'set' ? $quantity : $expected + $quantity;
        $target = MercatoCatalogRules::normalizeAbsoluteStock($target);
        $result = $this->setStock($reference, $target, $expected, $variantId);
        $this->commerce->recordEvent('mercato-inventory', [
            'event' => 'catalog_inventory_updated',
            'product_id' => (int) ($result['product']['id'] ?? 0),
            'variant_id' => (string) ($result['variant_id'] ?? ''),
            'before' => (int) ($result['before'] ?? 0),
            'after' => (int) ($result['after'] ?? 0),
            'mode' => $mode,
            'reason_hash' => isset($context['reason']) ? hash('sha256', (string) $context['reason']) : '',
            'source' => substr(trim((string) ($context['source'] ?? 'catalog_service')), 0, 80),
        ], 'catalog_inventory_updated');
        return $result;
    }

    /** @return array<string,mixed> */
    public function addImages(string $reference, array $imageUrls, array $context = []): array {
        $urls = MercatoCatalogRules::normalizeImageUrls($imageUrls);
        $product = $this->findProduct($reference, true);
        if (!$product->hasField('mrc_images')) throw new WireException('Product images field is not installed.', 503);
        return $this->withLock('mercato_catalog_images_' . (int) $product->id, function () use ($product, $urls, $context): array {
            $product = $this->fresh((int) $product->id);
            $known = [];
            foreach ($product->mrc_images as $image) {
                $path = (string) $image->filename;
                if (is_file($path)) $known[] = hash_file('sha256', $path);
            }
            $ingestor = new MercatoCatalogImageIngestor();
            $fetched = [];
            $totalBytes = 0;
            foreach ($urls as $url) {
                $image = $ingestor->fetch($url, $this->imageResolver, $this->imageTransport, $known);
                $totalBytes += (int) $image['bytes'];
                if ($totalBytes > 33_554_432) throw new WireException('Combined image payload exceeds the 32 MiB operation limit.', 422);
                if (!$image['duplicate']) {
                    $known[] = (string) $image['checksum'];
                    $fetched[] = $image;
                }
            }

            $added = [];
            foreach ($fetched as $image) {
                $base = tempnam(sys_get_temp_dir(), 'mrc-image-');
                if ($base === false) throw new WireException('Could not create an image staging file.', 503);
                $path = $base . '.' . $image['extension'];
                try {
                    if (!@rename($base, $path) || file_put_contents($path, $image['body'], LOCK_EX) !== $image['bytes']) {
                        throw new WireException('Could not stage the validated product image.', 503);
                    }
                    $before = array_map(static fn($item): int => (int) $item->id, iterator_to_array($product->mrc_images));
                    $product->mrc_images->add($path);
                    $this->wire('pages')->saveField($product, 'mrc_images');
                    foreach ($product->mrc_images as $stored) {
                        if (!in_array((int) $stored->id, $before, true)) {
                            $added[] = $this->serializeImage($stored);
                            break;
                        }
                    }
                } finally {
                    if (is_file($base)) @unlink($base);
                    if (isset($path) && is_file($path)) @unlink($path);
                }
            }
            $result = [
                'product' => $this->serialize($this->fresh((int) $product->id)),
                'requested' => count($urls), 'added' => count($added),
                'duplicates' => count($urls) - count($added), 'images_added' => $added,
            ];
            $this->commerce->recordEvent('mercato-products', [
                'event' => 'catalog_product_images_added', 'product_id' => (int) $product->id,
                'requested' => $result['requested'], 'added' => $result['added'],
                'reason_hash' => isset($context['reason']) ? hash('sha256', (string) $context['reason']) : '',
                'source' => substr(trim((string) ($context['source'] ?? 'catalog_service')), 0, 80),
            ], 'catalog_product_images_added');
            return $result;
        });
    }

    /** @param int|string|array<string,mixed> $reference */
    public function setStock(int|string|array $reference, mixed $stock, mixed $expectedCurrent, string $variantId = ''): array {
        $stock = MercatoCatalogRules::normalizeAbsoluteStock($stock);
        $expectedCurrent = MercatoCatalogRules::normalizeAbsoluteStock($expectedCurrent);
        $product = $this->findProduct($reference, true);
        $variantId = MercatoVariantDefinition::slug($variantId);
        $lockName = ($variantId === '' ? 'mercato_catalog_stock_' : 'mercato_variant_') . (int) $product->id;
        return $this->withLock($lockName, function () use ($product, $variantId, $stock, $expectedCurrent): array {
            $product = $this->fresh((int) $product->id);
            if ($variantId === '') {
                if (!$product->hasField('mrc_stock')) throw new WireException('Product stock field is not installed.', 503);
                $before = (int) $product->mrc_stock;
                if ($before !== $expectedCurrent) throw new WireException(sprintf('Stock conflict: expected %d, current %d.', $expectedCurrent, $before), 409);
                $product->of(false);
                $product->mrc_stock = $stock;
                $this->wire('pages')->save($product);
                return ['before' => $before, 'after' => $stock, 'variant_id' => '', 'product' => $this->serialize($this->fresh((int) $product->id))];
            }

            $definition = $this->commerce->variantService()->getDefinition($product);
            foreach ($definition['variants'] as &$variant) {
                if ($variant['id'] !== $variantId) continue;
                $before = (int) $variant['stock'];
                if ($before !== $expectedCurrent) throw new WireException(sprintf('Stock conflict: expected %d, current %d.', $expectedCurrent, $before), 409);
                $variant['stock'] = $stock;
                $saved = $this->commerce->variantService()->saveDefinition($product, $definition['options'], $definition['variants']);
                return ['before' => $before, 'after' => $stock, 'variant_id' => $variantId, 'variant' => $this->variantById($saved['variants'], $variantId), 'product' => $this->serialize($this->fresh((int) $product->id))];
            }
            unset($variant);
            throw new WireException(sprintf('Variant "%s" was not found.', $variantId), 404);
        });
    }

    /** @param int|string|array<string,mixed> $reference */
    public function archive(int|string|array $reference, array|string $context = []): array {
        $expectedVersion = is_array($context) ? (string) ($context['expected_version'] ?? '') : $context;
        $product = $this->findProduct($reference, true);
        $result = $this->withLock('mercato_catalog_product_' . (int) $product->id, function () use ($product, $expectedVersion): array {
            $product = $this->fresh((int) $product->id);
            if ($expectedVersion !== '') $this->assertVersion($product, $expectedVersion);
            if (!$product->hasField('mrc_product_status')) throw new WireException('Product lifecycle field is not installed.', 503);
            $product->of(false);
            $product->mrc_product_status = 'archived';
            $product->removeStatus(Page::statusUnpublished);
            $product->addStatus(Page::statusHidden);
            $this->wire('pages')->save($product);
            return $this->serialize($this->fresh((int) $product->id));
        });
        $this->commerce->recordEvent('mercato-products', [
            'event' => 'catalog_product_archived',
            'product_id' => (int) ($result['id'] ?? 0),
            'sku' => (string) ($result['sku'] ?? ''),
            'reason_hash' => is_array($context) && isset($context['reason']) ? hash('sha256', (string) $context['reason']) : '',
            'source' => is_array($context) ? substr(trim((string) ($context['source'] ?? 'catalog_service')), 0, 80) : 'catalog_service',
        ], 'catalog_product_archived');
        return $result;
    }

    /** @return array<string,mixed> */
    public function previewSync(array $products, array $options): array {
        $supplier = MercatoCatalogRules::normalizeUpsert(['external_source' => (string) ($options['supplier'] ?? ''), 'external_id' => 'preview'], false)['external_source'] ?? '';
        if ($supplier === '') throw new WireException('A supplier identifier is required.', 422);
        if ($products === [] || count($products) > 100) throw new WireException('Catalog sync accepts 1-100 products per batch.', 422);
        $previews = [];
        $seenExternal = [];
        $seenSkus = [];
        foreach ($products as $index => $product) {
            if (!is_array($product)) throw new WireException('Every catalog sync item must be an object.', 422);
            $product['external_source'] = $supplier;
            $externalId = trim((string) ($product['external_id'] ?? ''));
            if ($externalId === '') throw new WireException(sprintf('Catalog sync item %d requires external_id.', $index + 1), 422);
            if (isset($seenExternal[strtolower($externalId)])) throw new WireException('Duplicate external_id in catalog sync batch.', 409);
            $seenExternal[strtolower($externalId)] = true;
            $preview = $this->previewUpsert($product);
            $batchSkus = [];
            $baseSku = strtolower(trim((string) ($preview['normalized']['sku'] ?? '')));
            if ($baseSku !== '') $batchSkus[] = $baseSku;
            foreach ((array) ($preview['normalized']['variants'] ?? []) as $variant) {
                $variantSku = strtolower(trim((string) ($variant['sku'] ?? '')));
                if ($variantSku !== '') $batchSkus[] = $variantSku;
            }
            foreach ($batchSkus as $sku) {
                if (isset($seenSkus[$sku])) throw new WireException(sprintf('Duplicate SKU "%s" in catalog sync batch.', $sku), 409);
                $seenSkus[$sku] = true;
            }
            $previews[] = $preview;
        }
        $archives = [];
        if (!empty($options['archive_missing'])) {
            $selector = 'template=' . $this->safe($this->productTemplate()) . ', include=all, mrc_external_source=' . $this->safe($supplier);
            foreach ($this->wire('pages')->find($selector) as $existing) {
                $externalId = strtolower(trim((string) $existing->mrc_external_id));
                if ($externalId !== '' && !isset($seenExternal[$externalId]) && (string) $existing->mrc_product_status !== 'archived') {
                    $archives[] = ['id' => (int) $existing->id, 'sku' => (string) $existing->mrc_sku, 'external_id' => (string) $existing->mrc_external_id, 'version' => $this->serialize($existing)['version']];
                }
            }
        }
        $payload = [
            'supplier' => $supplier,
            'archive_missing' => !empty($options['archive_missing']),
            'products' => array_map(static fn(array $preview): array => [
                'action' => (string) $preview['action'],
                'current_version' => (string) ($preview['current']['version'] ?? ''),
                'normalized' => $preview['normalized'],
            ], $previews),
            'archives' => $archives,
        ];
        return [
            'supplier' => $supplier,
            'count' => count($previews),
            'creates' => count(array_filter($previews, static fn(array $row): bool => $row['action'] === 'create')),
            'updates' => count(array_filter($previews, static fn(array $row): bool => $row['action'] === 'update')),
            'archives' => $archives,
            'items' => $previews,
            'preview_digest' => MercatoCatalogRules::version($payload),
        ];
    }

    /** @return array<string,mixed> */
    public function applySync(array $products, array $options, array $context = []): array {
        $supplier = MercatoCatalogRules::normalizeUpsert(['external_source' => (string) ($options['supplier'] ?? ''), 'external_id' => 'apply'], false)['external_source'] ?? '';
        if ($supplier === '') throw new WireException('A supplier identifier is required.', 422);
        return $this->withLock('mercato_catalog_sync_' . substr(hash('sha256', $supplier), 0, 40), function () use ($products, $options, $supplier, $context): array {
            $preview = $this->previewSync($products, $options);
            $expectedDigest = trim((string) ($options['preview_digest'] ?? ''));
            if ($expectedDigest === '' || !hash_equals($preview['preview_digest'], $expectedDigest)) throw new WireException('Catalog sync preview is stale or does not match this batch.', 409);
            $results = [];
            foreach ($products as $index => $product) {
                $product['external_source'] = $supplier;
                $itemPreview = $preview['items'][$index];
                if (($itemPreview['action'] ?? '') === 'update' && empty($product['expected_version'])) $product['expected_version'] = (string) ($itemPreview['current']['version'] ?? '');
                $results[] = $this->upsert($product, $context + ['source' => 'catalog_sync', 'expected_action' => (string) ($itemPreview['action'] ?? '')]);
            }
            $archived = [];
            foreach ($preview['archives'] as $candidate) {
                $archived[] = $this->archive((int) $candidate['id'], $context + ['source' => 'catalog_sync', 'expected_version' => (string) $candidate['version']]);
            }
            return ['supplier' => $supplier, 'processed' => count($results), 'archived' => count($archived), 'items' => $results, 'archived_items' => $archived, 'preview_digest' => $preview['preview_digest']];
        });
    }

    /** @return array<string,mixed> */
    public function serialize(Page $product): array {
        $definition = $this->commerce->variantService()->getDefinition($product);
        $snapshot = [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'title' => (string) $product->title,
            'sku' => $product->hasField('mrc_sku') ? trim((string) $product->mrc_sku) : '',
            'external_source' => $product->hasField('mrc_external_source') ? trim((string) $product->mrc_external_source) : '',
            'external_id' => $product->hasField('mrc_external_id') ? trim((string) $product->mrc_external_id) : '',
            'price' => $product->hasField('mrc_price') ? (float) $product->mrc_price : null,
            'product_status' => $product->hasField('mrc_product_status') ? (string) $product->mrc_product_status : 'active',
            'product_type' => $product->hasField('mrc_product_type') ? (string) $product->mrc_product_type : 'physical',
            'publication_status' => $this->publicationStatus($product),
            'stock' => $product->hasField('mrc_stock') ? (int) $product->mrc_stock : null,
            'stock_policy' => $product->hasField('mrc_stock_policy') ? (string) $product->mrc_stock_policy : 'deny',
            'low_stock_threshold' => $product->hasField('mrc_low_stock_threshold') ? (int) $product->mrc_low_stock_threshold : 0,
            'variant_options' => $definition['options'],
            'variants' => $definition['variants'],
            'images' => $product->hasField('mrc_images') ? array_map(fn($image): array => $this->serializeImage($image), iterator_to_array($product->mrc_images)) : [],
            'modified' => (int) $product->modified,
        ];
        $snapshot['version'] = MercatoCatalogRules::version($snapshot);
        return $snapshot;
    }

    /** @param int|string|array<string,mixed> $reference */
    protected function findProduct(int|string|array $reference, bool $required): ?Page {
        $product = null;
        if (is_array($reference)) {
            if (!empty($reference['id'])) $product = $this->wire('pages')->get('template=' . $this->safe($this->productTemplate()) . ', include=all, id=' . (int) $reference['id']);
            elseif (!empty($reference['sku'])) $product = $this->findBySku((string) $reference['sku']);
            elseif (!empty($reference['external_source']) || !empty($reference['external_id'])) {
                $query = MercatoCatalogRules::normalizeSearch($reference);
                $this->requireExternalIdentitySchema();
                $product = $this->findByExternal($query['external_source'], $query['external_id']);
            }
        } elseif (is_int($reference) || ctype_digit((string) $reference)) {
            $product = $this->wire('pages')->get('template=' . $this->safe($this->productTemplate()) . ', include=all, id=' . (int) $reference);
        } else {
            $product = $this->findBySku((string) $reference);
        }
        if ($required && (!$product || !$product->id)) throw new WireException('Product was not found.', 404);
        return $product && $product->id ? $product : null;
    }

    protected function findBySku(string $sku): ?Page {
        $sku = trim($sku);
        if ($sku === '') return null;
        $selector = 'template=' . $this->safe($this->productTemplate()) . ', include=all, mrc_sku=' . $this->safe($sku);
        $items = $this->wire('pages')->find($selector . ', limit=2');
        if ($items->count() > 1) throw new WireException('Multiple products use the same SKU.', 409);
        $product = $items->first();
        return $product && $product->id ? $product : null;
    }

    protected function findByExternal(string $source, string $id): ?Page {
        $this->requireExternalIdentitySchema();
        $selector = 'template=' . $this->safe($this->productTemplate()) . ', include=all, mrc_external_source=' . $this->safe($source) . ', mrc_external_id=' . $this->safe($id);
        $items = $this->wire('pages')->find($selector . ', limit=2');
        if ($items->count() > 1) throw new WireException('Multiple products use the same external identity.', 409);
        $product = $items->first();
        return $product && $product->id ? $product : null;
    }

    protected function assertSkuAvailable(string $sku, int $excludeProductId): void {
        $key = strtolower(trim($sku));
        foreach ($this->wire('pages')->find('template=' . $this->safe($this->productTemplate()) . ', include=all') as $candidate) {
            if ((int) $candidate->id !== $excludeProductId && strtolower(trim((string) $candidate->mrc_sku)) === $key) {
                throw new WireException('SKU is already assigned to another product.', 409);
            }
            foreach ($this->commerce->variantService()->getDefinition($candidate)['variants'] as $variant) {
                if (strtolower(trim((string) $variant['sku'])) === $key) throw new WireException('SKU is already assigned to another product variant.', 409);
            }
        }
    }

    protected function assertVersion(Page $product, string $expected): void {
        $current = $this->serialize($product)['version'];
        if (!hash_equals($current, trim($expected))) throw new WireException('Product version conflict.', 409);
    }

    protected function applyPublicationStatus(Page $product, string $status): void {
        if ($status === 'published') {
            $product->removeStatus(Page::statusUnpublished);
            $product->removeStatus(Page::statusHidden);
        } elseif ($status === 'hidden') {
            $product->removeStatus(Page::statusUnpublished);
            $product->addStatus(Page::statusHidden);
        } else {
            $product->removeStatus(Page::statusHidden);
            $product->addStatus(Page::statusUnpublished);
        }
    }

    protected function publicationStatus(Page $product): string {
        if ($product->hasStatus(Page::statusUnpublished)) return 'unpublished';
        if ($product->hasStatus(Page::statusHidden)) return 'hidden';
        return 'published';
    }

    protected function requireExternalIdentitySchema(): void {
        foreach (['mrc_external_source', 'mrc_external_id'] as $field) {
            if (!$this->wire('fields')->get($field)) throw new WireException("Catalog external identity field is missing: $field. Run Mercato installer/repair.", 503);
        }
    }

    protected function uniqueName(string $value, int $excludeProductId = 0): string {
        $base = (string) $this->wire('sanitizer')->pageName($value);
        if ($base === '') $base = 'product';
        $candidate = $base;
        for ($suffix = 2; $suffix < 10000; $suffix++) {
            $page = $this->wire('pages')->get('parent=' . (int) $this->wire('pages')->get('/' . trim((string) ($this->commerce->products_parent ?? 'products'), '/') . '/')->id . ', include=all, name=' . $this->safe($candidate));
            if (!$page || !$page->id || (int) $page->id === $excludeProductId) return $candidate;
            $candidate = $base . '-' . $suffix;
        }
        throw new WireException('Could not allocate a unique product name.', 409);
    }

    protected function fresh(int $id): Page {
        $product = $this->wire('pages')->getById($id, ['cache' => false])->first();
        if (!$product || !$product->id) throw new WireException('Product no longer exists.', 404);
        return $product;
    }

    protected function withLock(string $name, callable $operation): mixed {
        $database = $this->wire('database');
        $lock = $database->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute([':name' => substr($name, 0, 64)]);
        if ((int) $lock->fetchColumn() !== 1) throw new WireException('Could not acquire the catalog transaction lock.', 503);
        try {
            return $operation();
        } finally {
            $release = $database->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute([':name' => substr($name, 0, 64)]);
        }
    }

    protected function productTemplate(): string {
        return trim((string) ($this->commerce->product_template ?? 'mrc-product')) ?: 'mrc-product';
    }

    protected function safe(string $value): string {
        return (string) $this->wire('sanitizer')->selectorValue($value);
    }

    protected function variantById(array $variants, string $id): array {
        foreach ($variants as $variant) if (($variant['id'] ?? '') === $id) return $variant;
        return [];
    }

    /** @return array<int,string> */
    private function resolveImageHost(string $host): array {
        $addresses = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
            $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($address !== '') $addresses[] = $address;
        }
        if (!$addresses) $addresses = (array) @gethostbynamel($host);
        return array_values(array_unique($addresses));
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function fetchImageResponse(string $url, string $ip, int $maxBytes, int $timeout): array {
        if (!function_exists('curl_init')) throw new WireException('The curl extension is required for remote catalog images.', 503);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $body = '';
        $headers = [];
        $curl = curl_init($url);
        $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$host . ':443:' . $resolveIp],
            CURLOPT_USERAGENT => 'Mercato-Catalog-Image/1.8',
            CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/gif,image/webp'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $position = strpos($line, ':');
                if ($position !== false) $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if (PHP_VERSION_ID < 80500) curl_close($curl);
        if ($ok === false) throw new WireException('Product image request failed: ' . ($error !== '' ? $error : 'transfer aborted'), 502);
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /** @return array<string,mixed> */
    private function serializeImage(mixed $image): array {
        return [
            'id' => (int) $image->id, 'name' => (string) $image->name,
            'url' => (string) ($image->httpUrl() ?: $image->url),
            'width' => (int) $image->width, 'height' => (int) $image->height,
        ];
    }
}
