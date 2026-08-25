<?php
namespace ProcessWire;

final class MercatoVariantService extends Wire {

    public function __construct(protected Mercato $commerce) {
        parent::__construct();
    }

    public function getDefinition(Page $product): array {
        $options = $product->hasField('mrc_variant_options') ? json_decode((string) $product->mrc_variant_options, true) : [];
        $variants = $product->hasField('mrc_variants') ? json_decode((string) $product->mrc_variants, true) : [];
        return MercatoVariantDefinition::normalize(is_array($options) ? $options : [], is_array($variants) ? $variants : []);
    }

    public function hasVariants(Page $product): bool {
        return $this->getDefinition($product)['variants'] !== [];
    }

    public function resolve(Page $product, string $variantId = '', array $selection = [], bool $requireActive = true): ?array {
        $definition = $this->getDefinition($product);
        if (!$definition['variants']) return null;
        $variantId = MercatoVariantDefinition::slug($variantId);
        $combination = MercatoVariantDefinition::combinationKey($selection);
        foreach ($definition['variants'] as $variant) {
            if (($variantId !== '' && $variant['id'] === $variantId)
                || ($variantId === '' && $combination !== '' && MercatoVariantDefinition::combinationKey($variant['options']) === $combination)) {
                if ($requireActive && $variant['status'] !== 'active') return null;
                return $variant;
            }
        }
        return null;
    }

    public function validateDefinition(Page $product, array $options, array $variants): array {
        $result = MercatoVariantDefinition::validate($options, $variants);
        if (!$result['valid']) return $result;
        $seen = [];
        $baseSku = strtolower(trim((string) ($product->hasField('mrc_sku') ? $product->mrc_sku : '')));
        foreach ($this->wire('pages')->find('template=mrc-product, include=all') as $candidate) {
            if ((int) $candidate->id === (int) $product->id) continue;
            $sku = strtolower(trim((string) ($candidate->hasField('mrc_sku') ? $candidate->mrc_sku : '')));
            if ($sku !== '') $seen[$sku] = (string) $candidate->title;
            foreach ($this->getDefinition($candidate)['variants'] as $candidateVariant) {
                $sku = strtolower(trim((string) $candidateVariant['sku']));
                if ($sku !== '') $seen[$sku] = (string) $candidate->title . ' / ' . $candidateVariant['id'];
            }
        }
        if ($baseSku !== '' && isset($seen[$baseSku])) $result['errors'][] = sprintf('Product SKU "%s" is already used by %s.', (string) $product->mrc_sku, $seen[$baseSku]);
        if ($baseSku !== '') $seen[$baseSku] = sprintf('product %d', (int) $product->id);
        foreach ($result['variants'] as $variant) {
            $sku = strtolower($variant['sku']);
            if ($sku !== '' && isset($seen[$sku])) $result['errors'][] = sprintf('Variant SKU "%s" is already used by %s.', $variant['sku'], $seen[$sku]);
        }
        $result['errors'] = array_values(array_unique($result['errors']));
        $result['valid'] = $result['errors'] === [];
        return $result;
    }

    public function saveDefinition(Page $product, array $options, array $variants): array {
        if (!$product->hasField('mrc_variant_options') || !$product->hasField('mrc_variants')) {
            throw new WireException('Variant fields are not installed. Run the Mercato installer.');
        }
        $result = $this->validateDefinition($product, $options, $variants);
        if (!$result['valid']) throw new WireException(implode(' ', $result['errors']));
        $product->of(false);
        $product->mrc_variant_options = json_encode($result['options'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $product->mrc_variants = json_encode($result['variants'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->wire('pages')->save($product);
        return $result;
    }

    public function hydrateItem(Page $product, array $item, bool $requireActive = true): array {
        $definition = $this->getDefinition($product);
        $variant = null;
        if ($definition['variants']) {
            $variant = $this->resolve($product, (string) ($item['variant_id'] ?? ''), (array) ($item['variant_options'] ?? []), $requireActive);
            if (!$variant) throw new WireException('Choose an available product variant.');
        } elseif (!empty($item['variant_id']) || !empty($item['variant_options'])) {
            throw new WireException('This product does not have variants.');
        }

        $item['id'] = (string) $product->id;
        $item['product_id'] = (int) $product->id;
        $item['variant_snapshot_version'] = 1;
        $item['quantity'] = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
        $item['title'] = (string) $product->title;
        $item['price'] = (float) $product->mrc_price;
        $item['tax_rate'] = $product->hasField('mrc_tax_rate') ? (float) $product->mrc_tax_rate : 0.0;
        $item['tax_code'] = $product->hasField('mrc_tax_code') ? trim((string) $product->mrc_tax_code) : '';
        $item['shipping_price'] = $product->hasField('mrc_shipping_price') ? (float) $product->mrc_shipping_price : 0.0;
        $item['template'] = (string) $product->template->name;
        $item['uid'] = (string) $product->name;
        $item['sku'] = $product->hasField('mrc_sku') ? (string) $product->mrc_sku : '';
        $item['stock'] = $product->hasField('mrc_stock') ? (int) $product->mrc_stock : null;
        $item['stock_policy'] = $product->hasField('mrc_stock_policy') ? (string) $product->mrc_stock_policy : 'deny';
        $item['product_type'] = $product->hasField('mrc_product_type') ? (string) $product->mrc_product_type : 'physical';
        $item['stripe_price_id'] = $product->hasField('mrc_stripe_price_id') ? trim((string) $product->mrc_stripe_price_id) : '';
        $item['collection_ids'] = [];
        if ($product->hasField('mrc_collections') && $product->mrc_collections instanceof PageArray) {
            foreach ($product->mrc_collections as $collection) if ($collection instanceof Page && $collection->id) $item['collection_ids'][] = (int) $collection->id;
        }
        if ($variant) {
            $labels = $this->getOptionLabels($definition['options'], $variant['options']);
            $item['variant_id'] = $variant['id'];
            $item['variant_options'] = $variant['options'];
            $item['variant_labels'] = $labels;
            $item['variant_label'] = implode(' / ', array_values($labels));
            $item['sku'] = $variant['sku'];
            $item['price'] = $variant['price'] !== null ? $variant['price'] : round($item['price'] + $variant['price_adjustment'], 2);
            $item['stock'] = $variant['stock'];
            $item['stock_policy'] = $variant['stock_policy'];
            if ($variant['shipping_price'] !== null) $item['shipping_price'] = $variant['shipping_price'];
            $item['variant_status'] = $variant['status'];
            $item['variant_images'] = $this->resolveImageUrls($product, $variant['images']);
            if ($item['variant_images']) $item['image_url'] = $item['variant_images'][0];
            if (array_filter([$variant['weight_kg'], $variant['length_cm'], $variant['width_cm'], $variant['height_cm']], static fn($value): bool => $value !== null)) {
                $item['shipping_dimensions'] = [
                    'weight_kg' => $variant['weight_kg'], 'length_cm' => $variant['length_cm'],
                    'width_cm' => $variant['width_cm'], 'height_cm' => $variant['height_cm'],
                    'volume_cm3' => $variant['length_cm'] !== null && $variant['width_cm'] !== null && $variant['height_cm'] !== null
                        ? round($variant['length_cm'] * $variant['width_cm'] * $variant['height_cm'], 6) : null,
                    'source_field' => 'mrc_variants',
                ];
            }
            $item['key'] = $this->lineKey((int) $product->id, $variant['id']);
        } else {
            unset($item['variant_id'], $item['variant_options'], $item['variant_labels'], $item['variant_label']);
            $item['key'] = $this->lineKey((int) $product->id);
        }
        return $item;
    }

    public function lineKey(int $productId, string $variantId = ''): string {
        return $variantId === '' ? (string) $productId : $productId . '::' . MercatoVariantDefinition::slug($variantId);
    }

    public function getOptionLabels(array $options, array $selection): array {
        $labels = [];
        foreach ($options as $option) {
            $optionId = (string) $option['id'];
            $selected = (string) ($selection[$optionId] ?? '');
            foreach ($option['values'] as $value) {
                if ($value['id'] === $selected) {
                    $labels[(string) $option['label']] = (string) $value['label'];
                    break;
                }
            }
        }
        return $labels;
    }

    public function resolveImageUrls(Page $product, array $references): array {
        $urls = [];
        foreach ($references as $reference) {
            $reference = trim((string) $reference);
            if ($reference === '') continue;
            if (preg_match('~^https?://~i', $reference) || str_starts_with($reference, '/')) {
                $urls[] = $reference;
                continue;
            }
            if (!$product->hasField('mrc_images')) continue;
            foreach ($product->mrc_images as $image) {
                if ((string) $image->id === $reference || (string) $image->name === $reference || basename((string) $image->filename) === $reference) {
                    $urls[] = (string) ($image->httpUrl() ?: $image->url);
                    break;
                }
            }
        }
        return array_values(array_unique($urls));
    }

    public function updateStock(Page $product, string $variantId, int $delta): array {
        $variantId = MercatoVariantDefinition::slug($variantId);
        $lockName = 'mercato_variant_' . (int) $product->id;
        $database = $this->wire('database');
        $lock = $database->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute([':name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) throw new WireException('Could not acquire the variant inventory lock.');
        try {
            $fresh = $this->wire('pages')->getById((int) $product->id, ['cache' => false])->first();
            if (!$fresh || !$fresh->id) throw new WireException('Product no longer exists.');
            $definition = $this->getDefinition($fresh);
            foreach ($definition['variants'] as &$variant) {
                if ($variant['id'] !== $variantId) continue;
                $before = (int) $variant['stock'];
                $after = $before + $delta;
                if ($after < 0 && !in_array($variant['stock_policy'], ['backorder', 'preorder'], true)) {
                    throw new WireException(sprintf('Insufficient stock for variant %s.', $variantId));
                }
                $variant['stock'] = $after;
                $this->saveDefinition($fresh, $definition['options'], $definition['variants']);
                return ['before' => $before, 'after' => $after, 'variant_id' => $variantId, 'sku' => $variant['sku']];
            }
            unset($variant);
            throw new WireException(sprintf('Variant "%s" no longer exists.', $variantId));
        } finally {
            $release = $database->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute([':name' => $lockName]);
        }
    }
}
