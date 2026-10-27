<?php
declare(strict_types=1);

namespace ProcessWire;

$site = rtrim((string) getenv('MERCATO_TEST_SITE'), '/');
if ($site === '') {
    echo "Mercato catalog service integration test skipped (set MERCATO_TEST_SITE).\n";
    exit(0);
}

$_SERVER['HTTP_HOST'] = 'mercato.test';
$_SERVER['SERVER_NAME'] = 'mercato.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
require $site . '/wire/core/ProcessWire.php';
$config = ProcessWire::buildConfig($site);
$config->dbHost = '127.0.0.1';
$wire = new ProcessWire($config);
$superuser = $wire->users->get('template=user, roles.name=superuser');
if (!$superuser || !$superuser->id) throw new WireException('A superuser is required for the catalog integration profile.');
$wire->users->setCurrentUser($superuser);
$wire->set('page', $wire->pages->get('/'));

/** @var Mercato $commerce */
$commerce = $wire->modules->get('Mercato');
if (!$commerce || !empty($commerce->production)) throw new WireException('Catalog mutation fixtures are forbidden in production mode.');
foreach (['mrc_external_source', 'mrc_external_id'] as $fieldName) {
    if (!$wire->fields->get($fieldName)) throw new WireException("Catalog integration field {$fieldName} is missing. Run Mercato installer/repair.");
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
};
$runId = 'catalog-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
$supplier = str_replace('-', '_', $runId);
$prefix = 'e2e-' . $runId . '-';
$createdProductIds = [];
$operationHashes = [];
$cleaned = false;
$logsRoot = rtrim((string) $wire->config->paths->logs, '/') . '/';

$cleanup = static function () use ($wire, &$createdProductIds, &$operationHashes, &$cleaned, $prefix, $runId, $logsRoot): void {
    if ($cleaned) return;
    $cleaned = true;
    foreach (array_reverse(array_unique($createdProductIds)) as $id) {
        $product = $wire->pages->get((int) $id);
        if (!$product || !$product->id) continue;
        if (!str_starts_with((string) $product->name, $prefix)) throw new WireException("Refusing to delete unexpected catalog fixture {$product->id}.");
        $wire->pages->delete($product, true);
    }
    foreach (array_unique($operationHashes) as $hash) {
        $statement = $wire->database->prepare('DELETE FROM mercato_mcp_operations WHERE operation_key_hash=:hash');
        $statement->execute([':hash' => $hash]);
    }
    foreach (glob($logsRoot . 'mercato-*.txt') ?: [] as $path) {
        $lines = file($path);
        if ($lines === false) continue;
        $kept = array_values(array_filter($lines, static fn(string $line): bool => !str_contains($line, $runId)));
        if ($kept === $lines) continue;
        if ($kept === []) @unlink($path); else file_put_contents($path, implode('', $kept), LOCK_EX);
    }
};
register_shutdown_function($cleanup);

$trackProduct = static function (array $result) use (&$createdProductIds): array {
    $product = (array) ($result['product'] ?? $result);
    if (!empty($product['id'])) $createdProductIds[] = (int) $product['id'];
    return $product;
};
$trackKey = static function (string $action, string $key) use (&$operationHashes): void {
    $operationHashes[] = hash('sha256', 'catalog:' . $action . ':' . $key);
};
$conflict = static function (callable $operation, string $message) use ($expect): void {
    try {
        $operation();
    } catch (\Throwable $error) {
        $expect((int) $error->getCode() === 409 || str_contains(strtolower($error->getMessage()), 'conflict'), $message . ' Returned: ' . $error->getMessage());
        return;
    }
    throw new \RuntimeException($message);
};

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
if (!is_string($png)) throw new \RuntimeException('Catalog image fixture failed to decode.');
$service = new MercatoCatalogService(
    $commerce,
    static fn(string $host): array => ['93.184.216.34'],
    static fn(string $url, string $ip, int $maxBytes, int $timeout): array => ['status' => 200, 'headers' => ['Content-Type' => 'image/png'], 'body' => $png]
);
$service->setWire($wire);
$baseSku = 'CAT-' . strtoupper(substr(hash('sha256', $runId . '|base'), 0, 12));
$variantSku = 'CAT-' . strtoupper(substr(hash('sha256', $runId . '|variant'), 0, 12));
$productInput = [
    'name' => $prefix . 'base',
    'title' => 'Catalog integration base ' . $runId,
    'sku' => $baseSku,
    'external_source' => $supplier,
    'external_id' => 'base',
    'price' => 49.95,
    'product_type' => 'physical',
    'product_status' => 'active',
    'publication_status' => 'hidden',
    'stock' => 8,
    'stock_policy' => 'deny',
    'variant_options' => [[
        'id' => 'finish', 'label' => 'Finish',
        'values' => [['id' => 'oak', 'label' => 'Oak']],
    ]],
    'variants' => [[
        'id' => 'oak', 'sku' => $variantSku, 'options' => ['finish' => 'oak'],
        'price' => 54.95, 'stock' => 3, 'stock_policy' => 'deny', 'status' => 'active',
    ]],
];

$preview = $service->previewUpsert($productInput);
$expect(($preview['action'] ?? '') === 'create' && !empty($preview['preview_digest']), 'Create preview did not return a stable digest.');
$created = $service->upsert($productInput, ['source' => 'integration_test', 'reason' => $runId]);
$base = $trackProduct($created);
$expect(($created['action'] ?? '') === 'created' && ($base['external_id'] ?? '') === 'base', 'Catalog product create failed.');
$expect(count((array) ($base['variants'] ?? [])) === 1 && ($base['variants'][0]['options']['finish'] ?? '') === 'oak', 'Variant option map did not persist.');
$catalogProperty = new \ReflectionProperty($commerce, 'catalogService');
$originalCatalogService = $catalogProperty->getValue($commerce);
try {
    $catalogProperty->setValue($commerce, $service);
    $imageKey = $runId . ':images';
    $trackKey('add_product_images', $imageKey);
    $imageResult = $commerce->mcpAddProductImages($baseSku, ['https://images.example.test/product.png'], $imageKey, 'Add ' . $runId . ' image', 'ADD_VALIDATED_PRODUCT_IMAGES');
    $base = $imageResult['product'];
    $expect(($imageResult['added'] ?? 0) === 1 && count((array) ($base['images'] ?? [])) === 1, 'Validated MCP product image was not appended.');
    $idempotentImageReplay = $commerce->mcpAddProductImages($baseSku, ['https://images.example.test/product.png'], $imageKey, 'Add ' . $runId . ' image', 'ADD_VALIDATED_PRODUCT_IMAGES');
    $expect(!empty($idempotentImageReplay['idempotent_replay']) && ($idempotentImageReplay['added'] ?? 0) === 1, 'Product image MCP replay did not return its durable result.');
    $dedupeKey = $runId . ':images-dedupe';
    $trackKey('add_product_images', $dedupeKey);
    $imageReplay = $commerce->mcpAddProductImages($baseSku, ['https://images.example.test/product.png'], $dedupeKey, 'Dedupe ' . $runId . ' image', 'ADD_VALIDATED_PRODUCT_IMAGES');
    $expect(($imageReplay['added'] ?? -1) === 0 && ($imageReplay['duplicates'] ?? 0) === 1 && count((array) ($imageReplay['product']['images'] ?? [])) === 1, 'Product image checksum replay was not deduplicated.');
} finally {
    $catalogProperty->setValue($commerce, $originalCatalogService);
}

$listed = $service->listForAutomation(['query' => $baseSku, 'limit' => 10]);
$expect(($listed['count'] ?? 0) === 1 && (int) $listed['items'][0]['id'] === (int) $base['id'], 'Catalog list/search did not find the created SKU.');
$fetched = $service->getForAutomation($baseSku);
$expect((int) $fetched['id'] === (int) $base['id'], 'Catalog get by SKU failed.');

$updated = $service->upsert([
    'sku' => $baseSku,
    'expected_version' => (string) $base['version'],
    'title' => 'Catalog integration updated ' . $runId,
    'price' => 59.95,
], ['source' => 'integration_test', 'reason' => $runId]);
$updatedProduct = $trackProduct($updated);
$expect(($updated['action'] ?? '') === 'updated' && (float) $updatedProduct['price'] === 59.95, 'Optimistic product update failed.');
$conflict(static fn() => $service->upsert([
    'sku' => $baseSku,
    'expected_version' => (string) $base['version'],
    'title' => 'Stale write',
], ['source' => 'integration_test', 'reason' => $runId]), 'Stale product version did not fail closed.');

$baseStock = $service->setInventory($baseSku, '', 'set', 10, ['expected_current' => 8, 'source' => 'integration_test', 'reason' => $runId]);
$expect(($baseStock['before'] ?? null) === 8 && ($baseStock['after'] ?? null) === 10, 'Base stock optimistic update failed.');
$variantStock = $service->setInventory($baseSku, 'oak', 'adjust', 2, ['expected_current' => 3, 'source' => 'integration_test', 'reason' => $runId]);
$expect(($variantStock['before'] ?? null) === 3 && ($variantStock['after'] ?? null) === 5, 'Variant stock optimistic update failed.');
$conflict(static fn() => $service->setInventory($baseSku, '', 'set', 11, ['expected_current' => 8, 'source' => 'integration_test', 'reason' => $runId]), 'Stale stock value did not fail closed.');

$mcpSku = 'CAT-' . strtoupper(substr(hash('sha256', $runId . '|mcp'), 0, 12));
$mcpKey = $runId . ':upsert';
$trackKey('upsert_product', $mcpKey);
$mcpInput = [
    'name' => $prefix . 'mcp', 'title' => 'MCP catalog fixture ' . $runId,
    'sku' => $mcpSku, 'external_source' => $supplier, 'external_id' => 'mcp',
    'price' => 19.50, 'stock' => 4, 'product_type' => 'physical',
];
$mcpCreated = $commerce->mcpUpsertProduct($mcpInput, $mcpKey, 'Create ' . $runId . ' fixture', 'UPSERT_VALIDATED_PRODUCT');
$mcpProduct = $trackProduct($mcpCreated);
$expect(empty($mcpCreated['idempotent_replay']), 'First MCP upsert was incorrectly marked as replay.');
$mcpReplay = $commerce->mcpUpsertProduct($mcpInput, $mcpKey, 'Create ' . $runId . ' fixture', 'UPSERT_VALIDATED_PRODUCT');
$expect(!empty($mcpReplay['idempotent_replay']) && (int) $mcpReplay['product']['id'] === (int) $mcpProduct['id'], 'MCP upsert replay was not deterministic.');
$conflict(static fn() => $commerce->mcpUpsertProduct(array_replace($mcpInput, ['title' => 'Changed']), $mcpKey, 'Create ' . $runId . ' fixture', 'UPSERT_VALIDATED_PRODUCT'), 'Changed MCP replay did not fail with idempotency conflict.');

$batchSku = 'CAT-' . strtoupper(substr(hash('sha256', $runId . '|batch'), 0, 12));
$batch = [
    ['name' => $prefix . 'base', 'title' => 'Catalog integration batch ' . $runId, 'sku' => $baseSku, 'external_id' => 'base', 'price' => 61.00, 'stock' => 10],
    ['name' => $prefix . 'batch', 'title' => 'Catalog batch new ' . $runId, 'sku' => $batchSku, 'external_id' => 'batch', 'price' => 22.00, 'stock' => 7],
];
$syncPreview = $commerce->mcpPreviewCatalogSync($batch, $supplier, true);
$expect(($syncPreview['creates'] ?? 0) === 1 && ($syncPreview['updates'] ?? 0) === 1 && count((array) ($syncPreview['archives'] ?? [])) === 1, 'Supplier sync preview did not report create/update/archive accurately.');
$conflict(static fn() => $service->previewSync([
    $batch[0],
    array_replace($batch[1], ['external_id' => 'duplicate-sku', 'sku' => $baseSku]),
], ['supplier' => $supplier]), 'Duplicate SKU inside one supplier batch was not rejected during preview.');
$currentBeforeRace = $service->getForAutomation($baseSku);
$service->upsert(['sku' => $baseSku, 'expected_version' => $currentBeforeRace['version'], 'title' => 'Concurrent catalog change ' . $runId], ['source' => 'integration_test', 'reason' => $runId]);
$staleSyncKey = $runId . ':sync-stale';
$trackKey('apply_catalog_sync', $staleSyncKey);
$staleSyncRejected = false;
try {
    $commerce->mcpApplyCatalogSync($batch, $supplier, (string) $syncPreview['preview_digest'], $staleSyncKey, 'Reject stale ' . $runId . ' supplier batch', 'APPLY_REVIEWED_CATALOG_SYNC', true);
} catch (MercatoMcpException $error) {
    $staleSyncRejected = !empty($error->payload()['human_review_required']);
}
$expect($staleSyncRejected, 'Stale supplier preview digest did not fail closed for human review.');
$expect(($service->getForAutomation($baseSku)['title'] ?? '') === 'Concurrent catalog change ' . $runId, 'Stale supplier apply changed the concurrently updated product.');
$expect(($service->listForAutomation(['query' => $batchSku])['count'] ?? 0) === 0, 'Stale supplier apply partially created a later batch product.');
$syncPreview = $commerce->mcpPreviewCatalogSync($batch, $supplier, true);
$syncKey = $runId . ':sync';
$trackKey('apply_catalog_sync', $syncKey);
$sync = $commerce->mcpApplyCatalogSync($batch, $supplier, (string) $syncPreview['preview_digest'], $syncKey, 'Apply ' . $runId . ' supplier batch', 'APPLY_REVIEWED_CATALOG_SYNC', true);
foreach ((array) ($sync['items'] ?? []) as $item) $trackProduct($item);
$expect(($sync['processed'] ?? 0) === 2 && ($sync['archived'] ?? 0) === 1, 'Supplier sync apply did not execute the reviewed plan.');
$archived = $service->getForAutomation($mcpSku);
$expect(($archived['product_status'] ?? '') === 'archived' && ($archived['publication_status'] ?? '') === 'hidden', 'Source-scoped missing product was not archived safely.');

$archiveKey = $runId . ':archive';
$trackKey('archive_product', $archiveKey);
$archivedBatch = $commerce->mcpArchiveProduct($batchSku, $archiveKey, 'Archive ' . $runId . ' fixture', 'ARCHIVE_PRODUCT_WITH_HISTORY');
$expect(($archivedBatch['product_status'] ?? '') === 'archived', 'Explicit MCP archive failed.');
$archiveReplay = $commerce->mcpArchiveProduct($batchSku, $archiveKey, 'Archive ' . $runId . ' fixture', 'ARCHIVE_PRODUCT_WITH_HISTORY');
$expect(!empty($archiveReplay['idempotent_replay']), 'Explicit MCP archive replay was not deterministic.');

$cleanup();
echo "Mercato catalog service integration tests passed: product/variant upsert, safe image append/dedupe, optimistic versions and stock, MCP replay/conflict, stale/duplicate supplier rejection, preview/apply, source-scoped archive, cleanup.\n";
