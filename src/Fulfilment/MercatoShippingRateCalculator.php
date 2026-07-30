<?php
namespace ProcessWire;

/**
 * Calculates carrier delivery from normalized cart measurements.
 *
 * Cart item measurements are snapshots in kilograms and cubic centimetres,
 * which keeps checkout independent from later product or field changes.
 */
final class MercatoShippingRateCalculator {

    public static function calculate(array $items, array $config, array $customerData, float $flatAmount): array {
        $mode = strtolower(trim((string) ($config['mode'] ?? 'flat')));
        if (!in_array($mode, ['actual_weight', 'dimensional_weight', 'max_weight'], true)) {
            return self::fallback($flatAmount, 'flat_mode');
        }

        $missingPolicy = strtolower(trim((string) ($config['missing_policy'] ?? 'flat')));
        if (!in_array($missingPolicy, ['flat', 'ignore', 'unavailable'], true)) {
            $missingPolicy = 'flat';
        }
        $divisor = max(1.0, (float) ($config['dimensional_divisor'] ?? 5000));
        $actualKg = 0.0;
        $volumeCm3 = 0.0;
        $measuredItems = 0;
        $missingItems = [];

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $quantity = max(0.0, (float) ($item['quantity'] ?? 1));
            if ($quantity <= 0 || in_array((string) ($item['product_type'] ?? ''), ['digital', 'service'], true)) {
                continue;
            }
            $measurements = $item['shipping_dimensions'] ?? null;
            $hasWeight = is_array($measurements) && isset($measurements['weight_kg']) && (float) $measurements['weight_kg'] > 0;
            $hasVolume = is_array($measurements) && isset($measurements['volume_cm3']) && (float) $measurements['volume_cm3'] > 0;
            $required = $mode === 'actual_weight' ? $hasWeight
                : ($mode === 'dimensional_weight' ? $hasVolume : ($hasWeight && $hasVolume));
            if (!$required) {
                $missingItems[] = (string) ($item['sku'] ?? $item['title'] ?? $item['id'] ?? '');
                continue;
            }
            $actualKg += max(0.0, (float) ($measurements['weight_kg'] ?? 0)) * $quantity;
            $volumeCm3 += max(0.0, (float) ($measurements['volume_cm3'] ?? 0)) * $quantity;
            $measuredItems++;
        }

        if ($missingItems && $missingPolicy === 'flat') {
            return self::fallback($flatAmount, 'missing_measurements', $missingItems);
        }
        if ($missingItems && $missingPolicy === 'unavailable') {
            return self::fallback($flatAmount, 'missing_measurements', $missingItems, false);
        }
        if ($measuredItems === 0) {
            return self::fallback($flatAmount, 'no_measurements', $missingItems, $missingPolicy !== 'unavailable');
        }

        $dimensionalKg = $volumeCm3 / $divisor;
        $billableKg = match ($mode) {
            'actual_weight' => $actualKg,
            'dimensional_weight' => $dimensionalKg,
            default => max($actualKg, $dimensionalKg),
        };
        $band = self::selectBand(
            self::parseRateTable((string) ($config['rate_table'] ?? '')),
            $billableKg,
            (string) ($customerData['country'] ?? ''),
            (string) ($customerData['region'] ?? $customerData['state'] ?? '')
        );
        if (!$band) {
            return self::fallback($flatAmount, 'no_matching_rate', $missingItems) + [
                'mode' => $mode,
                'actual_weight_kg' => round($actualKg, 6),
                'volume_cm3' => round($volumeCm3, 3),
                'dimensional_weight_kg' => round($dimensionalKg, 6),
                'billable_weight_kg' => round($billableKg, 6),
            ];
        }

        return [
            'calculation' => 'dimensions',
            'mode' => $mode,
            'available' => true,
            'amount' => round(max(0.0, (float) $band['rate']), 2),
            'actual_weight_kg' => round($actualKg, 6),
            'volume_cm3' => round($volumeCm3, 3),
            'dimensional_weight_kg' => round($dimensionalKg, 6),
            'billable_weight_kg' => round($billableKg, 6),
            'dimensional_divisor' => $divisor,
            'rate_band' => $band,
            'missing_items' => $missingItems,
            'fallback_reason' => '',
        ];
    }

    public static function parseRateTable(string $source): array {
        $bands = [];
        foreach (preg_split('/\R/', trim($source)) ?: [] as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $parts = array_map('trim', str_getcsv($line, str_contains($line, '|') ? '|' : ',', '"', ''));
            if (count($parts) < 4 || !is_numeric($parts[1]) || ($parts[2] !== '' && !is_numeric($parts[2])) || !is_numeric($parts[3])) {
                continue;
            }
            $scope = strtoupper($parts[0] ?: '*');
            $bands[] = [
                'scope' => $scope,
                'min_kg' => max(0.0, (float) $parts[1]),
                'max_kg' => $parts[2] === '' ? null : max(0.0, (float) $parts[2]),
                'rate' => max(0.0, (float) $parts[3]),
                'label' => (string) ($parts[4] ?? ''),
                '_line' => $lineNumber,
            ];
        }
        return $bands;
    }

    protected static function selectBand(array $bands, float $weightKg, string $country, string $region): ?array {
        $country = strtoupper(trim($country));
        $region = strtoupper(trim($region));
        $targets = array_filter([$country && $region ? "$country:$region" : '', $country, '*']);
        foreach ($targets as $target) {
            foreach ($bands as $band) {
                if (($band['scope'] ?? '') !== $target) continue;
                $max = $band['max_kg'] ?? null;
                if ($weightKg >= (float) $band['min_kg'] && ($max === null || $weightKg <= (float) $max)) {
                    unset($band['_line']);
                    return $band;
                }
            }
        }
        return null;
    }

    protected static function fallback(float $amount, string $reason, array $missingItems = [], bool $available = true): array {
        return [
            'calculation' => 'flat',
            'mode' => 'flat',
            'available' => $available,
            'amount' => round(max(0.0, $amount), 2),
            'actual_weight_kg' => 0.0,
            'volume_cm3' => 0.0,
            'dimensional_weight_kg' => 0.0,
            'billable_weight_kg' => 0.0,
            'dimensional_divisor' => 0.0,
            'rate_band' => null,
            'missing_items' => $missingItems,
            'fallback_reason' => $reason,
        ];
    }
}
