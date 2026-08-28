<?php
namespace ProcessWire;

/**
 * Builds a bounded, provider-safe Stripe projection of a Mercato order.
 *
 * The projection is deliberately based on the stored cart snapshot rather
 * than project-specific product fields. Customer PII belongs in Stripe's
 * customer/billing fields and must never be copied into metadata here.
 */
final class MercatoStripeOrderData {

    private const DESCRIPTION_LIMIT = 500;
    private const METADATA_LIMIT = 450;

    /**
     * @return array{description:string,metadata:array<string,string>}
     */
    public static function fromPendingOrder(array $pendingOrder): array {
        $items = self::items($pendingOrder['mrc_items'] ?? ($pendingOrder['items'] ?? []));
        $invoice = self::scalar($pendingOrder['mrc_invoice_number'] ?? '');
        $orderId = self::scalar($pendingOrder['mrc_order_page_id'] ?? '');
        $lineCount = count($items);
        $quantityTotal = 0.0;
        $titles = [];
        $skus = [];
        $productTypes = [];

        foreach ($items as $item) {
            $quantity = max(0.0, (float) ($item['quantity'] ?? 1));
            $quantityTotal += $quantity;

            $title = self::scalar($item['title'] ?? ($item['name'] ?? ''));
            if ($title !== '') {
                $titles[] = $title . ($quantity !== 1.0 ? ' x' . self::number($quantity) : '');
            }

            $sku = self::scalar($item['sku'] ?? '');
            if ($sku !== '') {
                $skus[] = $sku;
            }

            $productType = strtolower(self::scalar($item['product_type'] ?? ''));
            if ($productType !== '') {
                $productTypes[] = $productType;
            }
        }

        $reference = $invoice !== '' ? $invoice : $orderId;
        $descriptionParts = [$reference !== '' ? 'Order ' . $reference : 'Mercato order'];
        if ($lineCount > 0) {
            $descriptionParts[] = self::number($quantityTotal) . ($quantityTotal === 1.0 ? ' item' : ' items');
        }
        if ($titles !== []) {
            $descriptionParts[] = implode(', ', $titles);
        }

        $metadata = [];
        if ($orderId !== '') {
            $metadata['mrc_order_id'] = $orderId;
        }
        if ($invoice !== '') {
            $metadata['mrc_invoice_number'] = $invoice;
        }
        if ($lineCount > 0) {
            $metadata['mrc_line_item_count'] = (string) $lineCount;
            $metadata['mrc_quantity_total'] = self::number($quantityTotal);
        }
        if ($skus !== []) {
            $metadata['mrc_skus'] = self::boundedList($skus);
        }
        if ($productTypes !== []) {
            $metadata['mrc_product_types'] = self::boundedList($productTypes);
        }

        return [
            'description' => self::limit(implode(' · ', $descriptionParts), self::DESCRIPTION_LIMIT),
            'metadata' => $metadata,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function items(mixed $value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn(mixed $item): bool => is_array($item)));
    }

    private static function scalar(mixed $value): string {
        if (!is_scalar($value)) {
            return '';
        }
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));
        return is_string($normalized) ? $normalized : '';
    }

    private static function number(float $value): string {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    /** @param list<string> $values */
    private static function boundedList(array $values): string {
        $values = array_values(array_unique($values));
        return self::limit(implode(', ', $values), self::METADATA_LIMIT);
    }

    private static function limit(string $value, int $length): string {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }
}
