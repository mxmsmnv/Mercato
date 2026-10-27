<?php
namespace ProcessWire;

/**
 * Pure input rules for catalog mutations.
 *
 * The class deliberately has no ProcessWire runtime dependency so the MCP,
 * CLI, import and test boundaries can share one strict contract.
 */
final class MercatoCatalogRules {

    public const PRODUCT_TYPES = ['physical', 'digital', 'service', 'placeholder', 'recurring'];
    public const LIFECYCLE_STATUSES = ['active', 'archived', 'discontinued'];
    public const PUBLICATION_STATUSES = ['published', 'hidden', 'unpublished'];
    public const STOCK_POLICIES = ['deny', 'backorder', 'preorder'];

    /** @return array<string,mixed> */
    public static function normalizeUpsert(array $input, bool $creating = false): array {
        $allowed = [
            'title', 'name', 'sku', 'external_source', 'external_id', 'price',
            'product_status', 'product_type', 'publication_status', 'stock',
            'stock_policy', 'low_stock_threshold', 'description', 'tax_rate',
            'tax_code', 'shipping_price', 'variant_options', 'variants',
            'expected_version',
        ];
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown) throw new \InvalidArgumentException('Unsupported catalog fields: ' . implode(', ', $unknown) . '.');

        $data = [];
        foreach (['title', 'name', 'sku', 'external_source', 'external_id', 'description', 'tax_code', 'expected_version'] as $key) {
            if (array_key_exists($key, $input)) $data[$key] = trim((string) $input[$key]);
        }
        if ($creating && ($data['title'] ?? '') === '') throw new \InvalidArgumentException('title is required when creating a product.');
        if (isset($data['title'])) $data['title'] = self::bounded($data['title'], 255, 'title');
        if (isset($data['name'])) $data['name'] = self::bounded($data['name'], 128, 'name');
        if (isset($data['sku'])) $data['sku'] = self::bounded($data['sku'], 120, 'sku');
        if (isset($data['description'])) $data['description'] = self::bounded($data['description'], 100000, 'description');
        if (isset($data['tax_code'])) $data['tax_code'] = self::bounded($data['tax_code'], 120, 'tax_code');
        if (isset($data['expected_version'])) $data['expected_version'] = self::bounded($data['expected_version'], 128, 'expected_version');

        $hasExternalSource = ($data['external_source'] ?? '') !== '';
        $hasExternalId = ($data['external_id'] ?? '') !== '';
        if ($hasExternalSource !== $hasExternalId) throw new \InvalidArgumentException('external_source and external_id must be supplied together.');
        if ($hasExternalSource) {
            $data['external_source'] = self::externalKey($data['external_source'], 'external_source');
            $data['external_id'] = self::externalId($data['external_id']);
        }

        foreach ([
            'product_status' => self::LIFECYCLE_STATUSES,
            'product_type' => self::PRODUCT_TYPES,
            'publication_status' => self::PUBLICATION_STATUSES,
            'stock_policy' => self::STOCK_POLICIES,
        ] as $key => $values) {
            if (!array_key_exists($key, $input)) continue;
            $value = strtolower(trim((string) $input[$key]));
            if (!in_array($value, $values, true)) throw new \InvalidArgumentException(sprintf('%s must be one of: %s.', $key, implode(', ', $values)));
            $data[$key] = $value;
        }

        foreach (['price', 'shipping_price', 'tax_rate'] as $key) {
            if (!array_key_exists($key, $input)) continue;
            if (!is_numeric($input[$key])) throw new \InvalidArgumentException("$key must be numeric.");
            $value = (float) $input[$key];
            if (!is_finite($value)) throw new \InvalidArgumentException("$key must be finite.");
            if ($key !== 'tax_rate' && $value < 0) throw new \InvalidArgumentException("$key cannot be negative.");
            if ($key === 'tax_rate' && ($value < 0 || $value > 100)) throw new \InvalidArgumentException('tax_rate must be between 0 and 100.');
            $data[$key] = round($value, $key === 'tax_rate' ? 4 : 2);
        }
        foreach (['stock', 'low_stock_threshold'] as $key) {
            if (!array_key_exists($key, $input)) continue;
            if (filter_var($input[$key], FILTER_VALIDATE_INT) === false) throw new \InvalidArgumentException("$key must be an integer.");
            $value = (int) $input[$key];
            if ($value < 0) throw new \InvalidArgumentException("$key cannot be negative.");
            $data[$key] = $value;
        }

        $hasOptions = array_key_exists('variant_options', $input);
        $hasVariants = array_key_exists('variants', $input);
        if ($hasOptions !== $hasVariants) throw new \InvalidArgumentException('variant_options and variants must be supplied together.');
        if ($hasOptions) {
            if (!is_array($input['variant_options']) || !is_array($input['variants'])) throw new \InvalidArgumentException('variant_options and variants must be arrays.');
            $data['variant_options'] = $input['variant_options'];
            $data['variants'] = $input['variants'];
        }
        return $data;
    }

    /** @return array<int,string> */
    public static function normalizeImageUrls(array $input): array {
        if (count($input) < 1 || count($input) > 12) throw new \InvalidArgumentException('image_urls must contain 1-12 URLs.');
        $urls = [];
        foreach ($input as $url) {
            $url = trim((string) $url);
            if (strlen($url) > 1000) throw new \InvalidArgumentException('image URL is too long.');
            $parts = parse_url($url);
            $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === '' || filter_var($host, FILTER_VALIDATE_IP) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
                throw new \InvalidArgumentException('Every image URL must use a public HTTPS DNS host without credentials or a custom port.');
            }
            $urls[] = $url;
        }
        return array_values(array_unique($urls));
    }

    /** @return array{q:string,sku:string,external_source:string,external_id:string,status:string,type:string,include_inactive:bool,limit:int,offset:int} */
    public static function normalizeSearch(array $input): array {
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        if ($status !== '' && !in_array($status, self::LIFECYCLE_STATUSES, true)) throw new \InvalidArgumentException('Invalid product status filter.');
        if ($type !== '' && !in_array($type, self::PRODUCT_TYPES, true)) throw new \InvalidArgumentException('Invalid product type filter.');
        $source = trim((string) ($input['external_source'] ?? ''));
        $externalId = trim((string) ($input['external_id'] ?? ''));
        if (($source !== '') !== ($externalId !== '')) throw new \InvalidArgumentException('external_source and external_id filters must be supplied together.');
        return [
            'q' => self::bounded(trim((string) ($input['q'] ?? '')), 200, 'q'),
            'sku' => self::bounded(trim((string) ($input['sku'] ?? '')), 120, 'sku'),
            'external_source' => $source === '' ? '' : self::externalKey($source, 'external_source'),
            'external_id' => $externalId === '' ? '' : self::externalId($externalId),
            'status' => $status,
            'type' => $type,
            'include_inactive' => !empty($input['include_inactive']),
            'limit' => max(1, min(100, (int) ($input['limit'] ?? 25))),
            'offset' => max(0, (int) ($input['offset'] ?? 0)),
        ];
    }

    public static function normalizeAbsoluteStock(mixed $value): int {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) throw new \InvalidArgumentException('stock must be an integer.');
        $stock = (int) $value;
        if ($stock < 0) throw new \InvalidArgumentException('stock cannot be negative.');
        return $stock;
    }

    public static function version(array $snapshot): string {
        ksort($snapshot);
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function externalKey(string $value, string $label): string {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $value)) {
            throw new \InvalidArgumentException("$label contains unsupported characters.");
        }
        return $value;
    }

    private static function externalId(string $value): string {
        $value = trim($value);
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('external_id is invalid.');
        }
        return $value;
    }

    private static function bounded(string $value, int $maximum, string $label): string {
        if (strlen($value) > $maximum) throw new \InvalidArgumentException("$label is too long.");
        return $value;
    }
}
