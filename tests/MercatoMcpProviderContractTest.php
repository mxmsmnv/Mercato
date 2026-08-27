<?php
$root = dirname(__DIR__);
$module = (string) file_get_contents($root . '/Mercato.module.php');
$provider = (string) file_get_contents($root . '/src/Mcp/MercatoMcpProviderTrait.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$tools = [
    'mercato_get_order',
    'mercato_list_orders_to_fulfil',
    'mercato_get_fulfilment_state',
    'mercato_get_inventory',
    'mercato_get_operational_health',
    'mercato_verify_payment',
    'mercato_create_shipment',
    'mercato_purchase_shipping_label',
    'mercato_update_tracking',
    'mercato_advance_fulfilment',
    'mercato_send_order_email',
];

$expect(str_contains($module, "'mcpProvider' => true"), 'Mercato did not opt in to McpServer discovery.');
$expect(str_contains($module, 'use MercatoMcpProviderTrait;'), 'Mercato does not compose the MCP provider trait.');
foreach ($tools as $tool) $expect(str_contains($provider, "'{$tool}'"), "Missing MCP tool: {$tool}");
$expect(substr_count($provider, "'additionalProperties' => false") >= 2, 'Tool and nested mutation object schemas must be closed.');
$expect(str_contains($provider, "'mercato_purchase_shipping_label'") && str_contains($provider, "'admin'"), 'Label purchase must require the highest McpServer scope.');
$expect(str_contains($provider, "'PURCHASE_PROVIDER_LABEL_WITH_COST'"), 'Label purchase lacks explicit cost confirmation.');
$expect(str_contains($provider, 'ensureMcpOperationsSchema'), 'Durable MCP idempotency storage is missing.');
$expect(str_contains($provider, "recordEvent('mercato-mcp'"), 'Provider-local mutation audit is missing.');
$expect(str_contains($provider, "'human_review_required'"), 'Structured human-review errors are missing.');
$expect(!preg_match('/mercato_(refund|repair|edit_page|edit_field|edit_template)/', implode("\n", array_filter(explode("\n", $provider), static fn(string $line): bool => str_contains($line, "mcpCommerceTool(")))), 'Sensitive or generic tools were exposed.');

echo "Mercato MCP provider contract tests passed.\n";
