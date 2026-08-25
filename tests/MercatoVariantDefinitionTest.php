<?php
require_once __DIR__ . '/../src/Product/MercatoVariantDefinition.php';

use ProcessWire\MercatoVariantDefinition;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$options = [
    ['id' => 'size', 'label' => 'Size', 'values' => ['small', 'large']],
    ['id' => 'color', 'label' => 'Color', 'values' => ['sand', 'charcoal']],
    ['id' => 'material', 'label' => 'Material', 'values' => ['stoneware', 'porcelain']],
];
$variants = [[
    'id' => 'small-sand-stoneware',
    'options' => ['size' => 'small', 'color' => 'sand', 'material' => 'stoneware'],
    'sku' => 'VAR-001', 'price' => 24.5, 'stock' => 3, 'status' => 'active',
]];
$valid = MercatoVariantDefinition::validate($options, $variants);
$expect($valid['valid'] === true, 'Expected a valid three-option definition.');
$expect(count($valid['options']) === 3, 'Expected three normalized option groups.');
$expect($valid['variants'][0]['price'] === 24.5, 'Expected exact variant price normalization.');
$expect(MercatoVariantDefinition::combinationKey(['size' => 'small', 'color' => 'sand']) === 'color=sand|size=small', 'Combination keys must be deterministic.');

$duplicate = MercatoVariantDefinition::validate($options, [$variants[0], array_merge($variants[0], ['id' => 'copy', 'sku' => 'VAR-002'])]);
$expect($duplicate['valid'] === false, 'Duplicate combinations must fail validation.');
$expect(str_contains(implode(' ', $duplicate['errors']), 'Duplicate option combination'), 'Expected duplicate combination error.');

$badValue = MercatoVariantDefinition::validate($options, [array_merge($variants[0], ['options' => ['size' => 'medium', 'color' => 'sand', 'material' => 'stoneware']])]);
$expect($badValue['valid'] === false, 'Unknown option values must fail validation.');
$expect(str_contains(implode(' ', $badValue['errors']), 'invalid value'), 'Expected invalid option value error.');

$duplicateSku = MercatoVariantDefinition::validate($options, [$variants[0], array_merge($variants[0], [
    'id' => 'large-sand-stoneware',
    'options' => ['size' => 'large', 'color' => 'sand', 'material' => 'stoneware'],
])]);
$expect($duplicateSku['valid'] === false, 'Duplicate SKUs must fail validation.');
$expect(str_contains(implode(' ', $duplicateSku['errors']), 'Duplicate variant SKU'), 'Expected duplicate SKU error.');

$missingOption = MercatoVariantDefinition::validate($options, [array_merge($variants[0], [
    'options' => ['size' => 'small', 'color' => 'sand'],
])]);
$expect($missingOption['valid'] === false, 'Incomplete combinations must fail validation.');

echo "Mercato variant definition tests passed.\n";
