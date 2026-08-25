<?php
namespace ProcessWire;

/**
 * Pure normalization and validation for first-class product variant data.
 * Kept free of ProcessWire runtime dependencies so imports and tests can use it.
 */
final class MercatoVariantDefinition {

    public static function normalize(array $options, array $variants): array {
        $normalizedOptions = [];
        foreach ($options as $position => $option) {
            if (!is_array($option)) continue;
            $id = self::slug((string) ($option['id'] ?? $option['name'] ?? ''));
            $label = trim((string) ($option['label'] ?? $option['name'] ?? $id));
            $values = [];
            foreach ((array) ($option['values'] ?? []) as $value) {
                $value = is_array($value) ? $value : ['id' => $value, 'label' => $value];
                $valueId = self::slug((string) ($value['id'] ?? $value['value'] ?? ''));
                if ($valueId === '') continue;
                $values[] = [
                    'id' => $valueId,
                    'label' => trim((string) ($value['label'] ?? $value['value'] ?? $valueId)) ?: $valueId,
                ];
            }
            if ($id === '' && $label === '' && !$values) continue;
            $normalizedOptions[] = [
                'id' => $id,
                'label' => $label ?: $id,
                'position' => (int) ($option['position'] ?? $position),
                'values' => $values,
            ];
        }
        usort($normalizedOptions, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);

        $normalizedVariants = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) continue;
            $selection = [];
            foreach ((array) ($variant['options'] ?? []) as $optionId => $valueId) {
                $optionId = self::slug((string) $optionId);
                $valueId = self::slug((string) $valueId);
                if ($optionId !== '' && $valueId !== '') $selection[$optionId] = $valueId;
            }
            ksort($selection);
            $id = self::slug((string) ($variant['id'] ?? ''));
            if ($id === '' && $selection) $id = implode('-', array_values($selection));
            $status = strtolower(trim((string) ($variant['status'] ?? 'active')));
            $policy = strtolower(trim((string) ($variant['stock_policy'] ?? 'deny')));
            $dimensions = is_array($variant['dimensions'] ?? null) ? $variant['dimensions'] : [];
            $normalizedVariants[] = [
                'id' => $id,
                'options' => $selection,
                'sku' => trim((string) ($variant['sku'] ?? '')),
                'price' => array_key_exists('price', $variant) && $variant['price'] !== '' && $variant['price'] !== null ? round((float) $variant['price'], 2) : null,
                'price_adjustment' => round((float) ($variant['price_adjustment'] ?? 0), 2),
                'stock' => (int) ($variant['stock'] ?? 0),
                'low_stock_threshold' => max(0, (int) ($variant['low_stock_threshold'] ?? 0)),
                'stock_policy' => in_array($policy, ['deny', 'backorder', 'preorder'], true) ? $policy : 'deny',
                'status' => in_array($status, ['active', 'unavailable', 'archived'], true) ? $status : 'active',
                'shipping_price' => array_key_exists('shipping_price', $variant) && $variant['shipping_price'] !== '' && $variant['shipping_price'] !== null ? round((float) $variant['shipping_price'], 2) : null,
                'weight_kg' => self::nullableNumber($variant['weight_kg'] ?? $dimensions['weight_kg'] ?? null),
                'length_cm' => self::nullableNumber($variant['length_cm'] ?? $dimensions['length_cm'] ?? null),
                'width_cm' => self::nullableNumber($variant['width_cm'] ?? $dimensions['width_cm'] ?? null),
                'height_cm' => self::nullableNumber($variant['height_cm'] ?? $dimensions['height_cm'] ?? null),
                'images' => array_values(array_unique(array_filter(array_map(
                    static fn($value): string => trim((string) $value),
                    (array) ($variant['images'] ?? $variant['image_ids'] ?? [])
                )))),
            ];
        }

        return ['options' => $normalizedOptions, 'variants' => $normalizedVariants];
    }

    public static function validate(array $options, array $variants): array {
        $data = self::normalize($options, $variants);
        $errors = [];
        $optionMap = [];
        foreach ($data['options'] as $option) {
            $id = $option['id'];
            if ($id === '') {
                $errors[] = 'Every option group needs an id.';
                continue;
            }
            if (isset($optionMap[$id])) $errors[] = sprintf('Duplicate option group id "%s".', $id);
            $valueMap = [];
            foreach ($option['values'] as $value) {
                if (isset($valueMap[$value['id']])) $errors[] = sprintf('Duplicate value "%s" in option "%s".', $value['id'], $id);
                $valueMap[$value['id']] = true;
            }
            if (!$valueMap) $errors[] = sprintf('Option group "%s" needs at least one value.', $id);
            $optionMap[$id] = $valueMap;
        }

        $ids = [];
        $skus = [];
        $combinations = [];
        foreach ($data['variants'] as $variant) {
            $id = $variant['id'];
            if ($id === '') $errors[] = 'Every variant needs an id or option selection.';
            if ($id !== '' && isset($ids[$id])) $errors[] = sprintf('Duplicate variant id "%s".', $id);
            $ids[$id] = true;
            if ($variant['sku'] === '') $errors[] = sprintf('Variant "%s" needs a SKU.', $id ?: '?');
            $skuKey = strtolower($variant['sku']);
            if ($skuKey !== '' && isset($skus[$skuKey])) $errors[] = sprintf('Duplicate variant SKU "%s".', $variant['sku']);
            $skus[$skuKey] = true;
            foreach ($optionMap as $optionId => $values) {
                $valueId = (string) ($variant['options'][$optionId] ?? '');
                if ($valueId === '') {
                    $errors[] = sprintf('Variant "%s" is missing option "%s".', $id ?: '?', $optionId);
                } elseif (!isset($values[$valueId])) {
                    $errors[] = sprintf('Variant "%s" uses invalid value "%s" for "%s".', $id ?: '?', $valueId, $optionId);
                }
            }
            foreach (array_keys($variant['options']) as $optionId) {
                if (!isset($optionMap[$optionId])) $errors[] = sprintf('Variant "%s" uses unknown option "%s".', $id ?: '?', $optionId);
            }
            if ($variant['price'] !== null && $variant['price'] < 0) $errors[] = sprintf('Variant "%s" price cannot be negative.', $id ?: '?');
            foreach (['weight_kg', 'length_cm', 'width_cm', 'height_cm'] as $measurement) {
                if ($variant[$measurement] !== null && $variant[$measurement] < 0) $errors[] = sprintf('Variant "%s" %s cannot be negative.', $id ?: '?', $measurement);
            }
            $combination = self::combinationKey($variant['options']);
            if ($combination !== '' && isset($combinations[$combination])) $errors[] = sprintf('Duplicate option combination on variants "%s" and "%s".', $combinations[$combination], $id ?: '?');
            $combinations[$combination] = $id ?: '?';
        }
        if ($data['variants'] && !$data['options']) $errors[] = 'Variants require at least one option group.';
        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors))] + $data;
    }

    public static function combinationKey(array $selection): string {
        $selection = array_filter(array_map(static fn($value): string => self::slug((string) $value), $selection));
        ksort($selection);
        return implode('|', array_map(static fn($key, $value): string => $key . '=' . $value, array_keys($selection), array_values($selection)));
    }

    public static function slug(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private static function nullableNumber(mixed $value): ?float {
        return $value === null || $value === '' ? null : round((float) $value, 6);
    }
}
