<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Product/MercatoCatalogRules.php';

use ProcessWire\MercatoCatalogRules;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$rejects = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
};

$data = MercatoCatalogRules::normalizeUpsert([
    'title' => 'Supplier chair', 'sku' => ' CHAIR-01 ', 'external_source' => ' Supplier.API ',
    'external_id' => ' Item:42 ', 'price' => '99.995', 'tax_rate' => 20,
    'product_type' => 'PHYSICAL', 'product_status' => 'ACTIVE', 'stock' => '7',
    'stock_policy' => 'DENY', 'variant_options' => [], 'variants' => [],
], true);
$assert($data['sku'] === 'CHAIR-01', 'SKU normalization changed the stable identifier unexpectedly.');
$assert($data['external_source'] === 'supplier.api' && $data['external_id'] === 'Item:42', 'External identity normalization failed.');
$assert($data['price'] === 100.0 && $data['stock'] === 7, 'Numeric catalog normalization failed.');
$assert($data['product_type'] === 'physical' && $data['product_status'] === 'active', 'Enum normalization failed.');

$rejects(fn() => MercatoCatalogRules::normalizeUpsert(['sku' => 'A'], true), 'Create accepted a missing title.');
$rejects(fn() => MercatoCatalogRules::normalizeUpsert(['sku' => 'A', 'external_source' => 'feed'], false), 'Partial external identity was accepted.');
$rejects(fn() => MercatoCatalogRules::normalizeUpsert(['sku' => 'A', 'price' => -1], false), 'Negative price was accepted.');
$rejects(fn() => MercatoCatalogRules::normalizeUpsert(['sku' => 'A', 'stock' => -1], false), 'Negative absolute stock was accepted.');
$rejects(fn() => MercatoCatalogRules::normalizeUpsert(['sku' => 'A', 'product_type' => 'unknown'], false), 'Unknown product type was accepted.');
$rejects(fn() => MercatoCatalogRules::normalizeUpsert(['sku' => 'A', 'variants' => []], false), 'Variants without option definitions were accepted.');
$rejects(fn() => MercatoCatalogRules::normalizeUpsert(['sku' => 'A', 'unexpected' => true], false), 'Unknown write field was accepted.');
$rejects(fn() => MercatoCatalogRules::normalizeSearch(['external_source' => 'feed']), 'Partial external search identity was accepted.');
$rejects(fn() => MercatoCatalogRules::normalizeAbsoluteStock('1.5'), 'Fractional absolute stock was accepted.');

$search = MercatoCatalogRules::normalizeSearch(['q' => 'chair', 'limit' => 1000, 'offset' => -5, 'include_inactive' => 1]);
$assert($search['limit'] === 100 && $search['offset'] === 0 && $search['include_inactive'] === true, 'Search bounds normalization failed.');
$versionA = MercatoCatalogRules::version(['sku' => 'A', 'stock' => 1]);
$versionB = MercatoCatalogRules::version(['stock' => 1, 'sku' => 'A']);
$assert($versionA === $versionB && strlen($versionA) === 64, 'Catalog version is not deterministic.');
$assert(MercatoCatalogRules::normalizeAbsoluteStock('12') === 12, 'Absolute stock normalization failed.');

echo "Mercato catalog rules tests passed.\n";
