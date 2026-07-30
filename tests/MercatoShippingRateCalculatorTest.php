<?php
namespace ProcessWire;

require dirname(__DIR__) . '/src/Fulfilment/MercatoShippingRateCalculator.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
}

$items = [[
    'sku' => 'BOX',
    'quantity' => 3,
    'product_type' => 'physical',
    'shipping_dimensions' => ['weight_kg' => 0.5, 'volume_cm3' => 4000],
]];
$config = [
    'mode' => 'max_weight',
    'missing_policy' => 'flat',
    'dimensional_divisor' => 5000,
    'rate_table' => "*|0|2|8.00|Global\nGB|0|5|6.00|UK\nGB:ENG|0|5|4.50|England",
];
$result = MercatoShippingRateCalculator::calculate($items, $config, ['country' => 'gb', 'region' => 'eng'], 12);
check($result['calculation'] === 'dimensions', 'Dimensions calculation was not selected.');
check(abs($result['actual_weight_kg'] - 1.5) < 0.000001, 'Quantity-aware actual weight failed.');
check(abs($result['volume_cm3'] - 12000) < 0.000001, 'Quantity-aware volume failed.');
check(abs($result['dimensional_weight_kg'] - 2.4) < 0.000001, 'Dimensional weight failed.');
check(abs($result['billable_weight_kg'] - 2.4) < 0.000001, 'Maximum weight mode failed.');
check($result['rate_band']['scope'] === 'GB:ENG', 'Region-specific band did not take precedence.');
check(abs($result['amount'] - 4.5) < 0.000001, 'Selected band rate is incorrect.');

$missing = [['sku' => 'EMPTY', 'quantity' => 1, 'product_type' => 'physical']];
$flat = MercatoShippingRateCalculator::calculate($missing, $config, [], 12);
check($flat['calculation'] === 'flat' && $flat['amount'] === 12.0, 'Flat fallback failed.');
$config['missing_policy'] = 'unavailable';
$unavailable = MercatoShippingRateCalculator::calculate($missing, $config, [], 12);
check($unavailable['available'] === false, 'Unavailable policy failed.');
$config['missing_policy'] = 'ignore';
$ignored = MercatoShippingRateCalculator::calculate(array_merge($items, $missing), $config, ['country' => 'GB'], 12);
check($ignored['calculation'] === 'dimensions' && $ignored['missing_items'] === ['EMPTY'], 'Ignore policy failed.');

echo "Mercato shipping calculator tests passed.\n";
