<?php
namespace ProcessWire;

/** Stable, source-scoped identity for replay-safe supplier catalog sync. */
final class MercatoCatalogSourceReference {
    public function normalizeSource(string $source): string {
        $source = strtolower(trim($source));
        $source = preg_replace('/[^a-z0-9._-]+/', '-', $source) ?? '';
        return trim(substr($source, 0, 120), '-');
    }

    public function normalizeExternalId(string $externalId): string {
        $externalId = trim($externalId);
        if ($externalId === '' || strlen($externalId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $externalId)) return '';
        return $externalId;
    }

    public function key(string $source, string $externalId): string {
        $source = $this->normalizeSource($source);
        $externalId = $this->normalizeExternalId($externalId);
        if ($source === '' || $externalId === '') throw new \InvalidArgumentException('Catalog source and external id are required.');
        return hash('sha256', $source . "\0" . $externalId);
    }

    /** @param array<string,mixed> $payload */
    public function payloadHash(array $payload): string {
        $normalized = $this->sortRecursive($payload);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function sortRecursive(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn(mixed $item): mixed => $this->sortRecursive($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->sortRecursive($item);
        return $value;
    }
}
