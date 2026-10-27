<?php
$root = dirname(__DIR__);
$provider = (string) file_get_contents($root . '/src/Mcp/MercatoMcpCatalogProviderTrait.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$tools = [
    'mercato_list_products',
    'mercato_get_product',
    'mercato_preview_product_upsert',
    'mercato_upsert_product',
    'mercato_set_inventory',
    'mercato_add_product_images',
    'mercato_archive_product',
    'mercato_preview_catalog_sync',
    'mercato_apply_catalog_sync',
];

foreach ($tools as $tool) $expect(str_contains($provider, "'{$tool}'"), "Missing catalog MCP tool: {$tool}");
$expect(substr_count($provider, "'additionalProperties' => false") >= 5, 'Catalog tool and nested product schemas must be closed.');
$expect(str_contains($provider, "'mercato_list_products'") && str_contains($provider, "'read'"), 'Catalog listing must be read scoped.');
$expect(str_contains($provider, "'mercato_upsert_product'") && str_contains($provider, "'publish'"), 'Product upsert must be publish scoped.');
$expect(str_contains($provider, "'mercato_archive_product'") && str_contains($provider, "'admin'"), 'Product archival must be admin scoped.');
$expect(str_contains($provider, "'mercato_apply_catalog_sync'") && str_contains($provider, "'APPLY_REVIEWED_CATALOG_SYNC'"), 'Catalog apply lacks exact reviewed-sync confirmation.');
$expect(str_contains($provider, "'preview_digest'") && str_contains($provider, "'^[a-f0-9]{64}$'"), 'Catalog apply must bind to an exact preview digest.');
$expect(str_contains($provider, 'mcpCatalogMutation') && str_contains($provider, 'self::MCP_OPERATION_TABLE') && str_contains($provider, 'ensureMcpOperationsSchema'), 'Catalog mutations lack durable idempotency.');
$expect(str_contains($provider, "recordEvent('mercato-mcp'"), 'Catalog mutations lack domain audit.');
$expect(!preg_match('/mercato_(delete_product|execute_sql|edit_page|edit_field|edit_template|refund|repair)/', $provider), 'Catalog provider exposed a destructive or generic editor tool.');
$expect(str_contains($provider, 'catalogService()->upsert') && str_contains($provider, 'catalogService()->applySync'), 'Catalog MCP handlers must delegate to the shared catalog service.');
$expect(str_contains($provider, "'options' => [") && str_contains($provider, "'patternProperties'"), 'Variant schema must expose the bounded domain options map.');
$expect(!str_contains($provider, "'selection' => ['type' => 'array'"), 'Variant schema must not expose the obsolete selection array.');
$expect(str_contains($provider, "'ADD_VALIDATED_PRODUCT_IMAGES'") && str_contains($provider, "catalogService()->addImages"), 'Product image ingestion must use its exact confirmation and governed catalog service.');

echo "Mercato MCP catalog provider contract tests passed.\n";
