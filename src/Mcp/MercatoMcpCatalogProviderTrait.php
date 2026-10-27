<?php
namespace ProcessWire;

/**
 * Governed catalog tools for the McpServer provider.
 *
 * This trait deliberately delegates catalog rules to catalogService(). The
 * service is the authoritative boundary for these MCP handlers and reusable by
 * future admin, scheduled-feed, and CLI adapters. It must expose:
 *
 * - listForAutomation(array $query): array
 * - getForAutomation(int|string|array $reference): array
 * - previewUpsert(array $product): array
 * - upsert(array $product, array $context): array
 * - setInventory(string $reference, string $variantId, string $mode,
 *   int $quantity, array $context): array
 * - addImages(string $reference, array $imageUrls, array $context): array
 * - archive(string $reference, array $context): array
 * - previewSync(array $products, array $options): array
 * - applySync(array $products, array $options, array $context): array
 *
 * Every returned payload must be PII-free and must not contain filesystem
 * paths, credentials, private image source URLs, or unrestricted Page data.
 * The service must allow remote images only from validated public HTTPS
 * origins with bounded redirects, response bytes, MIME types, and timeouts.
 * archive_missing must be restricted to products previously assigned to the
 * exact stable supplier identifier; it must never archive the whole catalog.
 */
trait MercatoMcpCatalogProviderTrait {

    /** @return array<int,array<string,mixed>> */
    public function mcpCatalogTools(): array {
        $reference = ['type' => 'string', 'minLength' => 1, 'maxLength' => 160];
        $idempotency = ['type' => 'string', 'minLength' => 8, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9._:-]+$'];
        $reason = ['type' => 'string', 'minLength' => 8, 'maxLength' => 500];
        $product = $this->mcpCatalogProductSchema();

        return [
            $this->mcpCatalogTool(
                'mercato_list_products',
                'List Mercato products',
                'Return a bounded PII-free catalog page for supplier matching and sync planning.',
                [$this, 'mcpListProducts'],
                'read',
                [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'after_id' => ['type' => 'integer', 'minimum' => 0],
                    'query' => ['type' => 'string', 'maxLength' => 240],
                    'product_status' => ['type' => 'string', 'enum' => ['all', 'active', 'archived', 'discontinued']],
                ]
            ),
            $this->mcpCatalogTool(
                'mercato_get_product',
                'Get Mercato product',
                'Return one product and its exact variant definition by product id, SKU, or an explicit external source/id pair.',
                [$this, 'mcpGetProduct'],
                'read',
                [
                    'product_reference' => $reference,
                    'external_source' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                    'external_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                ]
            ),
            $this->mcpCatalogTool(
                'mercato_preview_product_upsert',
                'Preview Mercato product upsert',
                'Validate and diff one product without changing catalog, inventory, images, or lifecycle state.',
                [$this, 'mcpPreviewProductUpsert'],
                'read',
                ['product' => $product],
                ['product']
            ),
            $this->mcpCatalogTool(
                'mercato_upsert_product',
                'Upsert Mercato product',
                'Create or update one validated product through the catalog service. Supplier SKU and external id matching are server-authoritative.',
                [$this, 'mcpUpsertProduct'],
                'publish',
                [
                    'product' => $product,
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'UPSERT_VALIDATED_PRODUCT'],
                ],
                ['product', 'idempotency_key', 'reason', 'confirmation'],
                true
            ),
            $this->mcpCatalogTool(
                'mercato_set_inventory',
                'Set Mercato inventory',
                'Set an absolute stock quantity or apply a bounded delta to one base product or exact variant under an inventory lock.',
                [$this, 'mcpSetInventory'],
                'publish',
                [
                    'product_reference' => $reference,
                    'variant_id' => ['type' => 'string', 'maxLength' => 120],
                    'mode' => ['type' => 'string', 'enum' => ['set', 'adjust']],
                    'quantity' => ['type' => 'integer', 'minimum' => -100000000, 'maximum' => 100000000],
                    'expected_current' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000000],
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'SET_VALIDATED_INVENTORY'],
                ],
                ['product_reference', 'mode', 'quantity', 'expected_current', 'idempotency_key', 'reason', 'confirmation']
            ),
            $this->mcpCatalogTool(
                'mercato_add_product_images',
                'Add Mercato product images',
                'Fetch and append validated public HTTPS product images with DNS pinning, bounded redirects/bytes/pixels/MIME, and checksum deduplication.',
                [$this, 'mcpAddProductImages'],
                'publish',
                [
                    'product_reference' => $reference,
                    'image_urls' => [
                        'type' => 'array', 'minItems' => 1, 'maxItems' => 12, 'uniqueItems' => true,
                        'items' => ['type' => 'string', 'minLength' => 9, 'maxLength' => 1000, 'pattern' => '^https://'],
                    ],
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'ADD_VALIDATED_PRODUCT_IMAGES'],
                ],
                ['product_reference', 'image_urls', 'idempotency_key', 'reason', 'confirmation'],
                true
            ),
            $this->mcpCatalogTool(
                'mercato_archive_product',
                'Archive Mercato product',
                'Make one product non-purchasable without deleting its page, order references, images, or audit history.',
                [$this, 'mcpArchiveProduct'],
                'admin',
                [
                    'product_reference' => $reference,
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'ARCHIVE_PRODUCT_WITH_HISTORY'],
                ],
                ['product_reference', 'idempotency_key', 'reason', 'confirmation'],
                false,
                true
            ),
            $this->mcpCatalogTool(
                'mercato_preview_catalog_sync',
                'Preview Mercato catalog sync',
                'Validate a bounded supplier batch and report creates, updates, unchanged records, conflicts, and proposed archives without mutation.',
                [$this, 'mcpPreviewCatalogSync'],
                'read',
                [
                    'products' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => $product],
                    'supplier' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                    'archive_missing' => ['type' => 'boolean'],
                ],
                ['products', 'supplier']
            ),
            $this->mcpCatalogTool(
                'mercato_apply_catalog_sync',
                'Apply Mercato catalog sync',
                'Apply a previously reviewed bounded supplier batch. Missing-product archival is opt-in and requires the exact confirmation.',
                [$this, 'mcpApplyCatalogSync'],
                'admin',
                [
                    'products' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => $product],
                    'supplier' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                    'archive_missing' => ['type' => 'boolean'],
                    'preview_digest' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[a-f0-9]{64}$'],
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'APPLY_REVIEWED_CATALOG_SYNC'],
                ],
                ['products', 'supplier', 'preview_digest', 'idempotency_key', 'reason', 'confirmation'],
                true,
                true
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function mcpCatalogProductSchema(): array {
        $optionValue = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 240],
            ],
            'required' => ['id', 'label'],
            'additionalProperties' => false,
        ];
        $option = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 240],
                'values' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => $optionValue],
            ],
            'required' => ['id', 'label', 'values'],
            'additionalProperties' => false,
        ];
        $variant = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'sku' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'options' => [
                    'type' => 'object',
                    'minProperties' => 1,
                    'maxProperties' => 32,
                    'patternProperties' => [
                        '^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                    ],
                    'additionalProperties' => false,
                ],
                'price' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1000000000],
                'price_adjustment' => ['type' => 'number', 'minimum' => -1000000000, 'maximum' => 1000000000],
                'stock' => ['type' => 'integer', 'minimum' => -100000000, 'maximum' => 100000000],
                'low_stock_threshold' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000000],
                'stock_policy' => ['type' => 'string', 'enum' => ['deny', 'backorder', 'preorder']],
                'status' => ['type' => 'string', 'enum' => ['active', 'unavailable', 'archived']],
                'shipping_price' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1000000000],
                'weight_kg' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1000000],
                'length_cm' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1000000],
                'width_cm' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1000000],
                'height_cm' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1000000],
            ],
            'required' => ['id', 'sku', 'options', 'stock'],
            'additionalProperties' => false,
        ];
        return [
            'type' => 'object',
            'properties' => [
                'external_id' => ['type' => 'string', 'maxLength' => 191],
                'external_source' => ['type' => 'string', 'maxLength' => 120],
                'sku' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'name' => ['type' => 'string', 'maxLength' => 128],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'description' => ['type' => 'string', 'maxLength' => 100000],
                'price' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000000000],
                'tax_rate' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'tax_code' => ['type' => 'string', 'maxLength' => 120],
                'shipping_price' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000000000],
                'stock' => ['type' => 'integer', 'minimum' => -100000000, 'maximum' => 100000000],
                'low_stock_threshold' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000000],
                'stock_policy' => ['type' => 'string', 'enum' => ['deny', 'backorder', 'preorder']],
                'product_type' => ['type' => 'string', 'enum' => ['physical', 'digital', 'service', 'placeholder', 'recurring']],
                'product_status' => ['type' => 'string', 'enum' => ['active', 'archived', 'discontinued']],
                'publication_status' => ['type' => 'string', 'enum' => ['published', 'hidden', 'unpublished']],
                'variant_options' => ['type' => 'array', 'maxItems' => 32, 'items' => $option],
                'variants' => ['type' => 'array', 'maxItems' => 1000, 'items' => $variant],
            ],
            'required' => ['sku', 'title', 'price'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function mcpCatalogTool(string $name, string $title, string $description, callable $handler, string $scope, array $properties, array $required = [], bool $openWorld = false, bool $destructive = false): array {
        return [
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'handler' => $handler,
            'scope' => $scope,
            'read_only' => $scope === 'read',
            'destructive' => $destructive,
            'idempotent' => true,
            'open_world' => $openWorld,
            'input_schema' => [
                'type' => 'object',
                'properties' => $properties ?: new \stdClass(),
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function mcpListProducts(int $limit = 50, int $after_id = 0, string $query = '', string $product_status = 'all'): array {
        return $this->catalogService()->listForAutomation([
            'limit' => max(1, min(100, $limit)),
            'after_id' => max(0, $after_id),
            'query' => substr(trim($query), 0, 240),
            'product_status' => in_array($product_status, ['all', 'active', 'archived', 'discontinued'], true) ? $product_status : 'all',
        ]);
    }

    /** @return array<string,mixed> */
    public function mcpGetProduct(string $product_reference = '', string $external_source = '', string $external_id = ''): array {
        $product_reference = trim($product_reference);
        $external_source = trim($external_source);
        $external_id = trim($external_id);
        $hasReference = $product_reference !== '';
        $hasExternal = $external_source !== '' || $external_id !== '';
        if ($hasReference === $hasExternal) {
            throw new MercatoMcpException('invalid_product_identity', 'Provide either product_reference or external_source plus external_id.');
        }
        if ($hasReference) return $this->catalogService()->getForAutomation($this->mcpCatalogReference($product_reference));
        if ($external_source === '' || $external_id === '') {
            throw new MercatoMcpException('invalid_product_identity', 'external_source and external_id must be supplied together.');
        }
        return $this->catalogService()->getForAutomation([
            'external_source' => $this->mcpCatalogSupplier($external_source),
            'external_id' => $external_id,
        ]);
    }

    /** @return array<string,mixed> */
    public function mcpPreviewProductUpsert(array $product): array {
        return $this->catalogService()->previewUpsert($product);
    }

    /** @return array<string,mixed> */
    public function mcpUpsertProduct(array $product, string $idempotency_key, string $reason, string $confirmation): array {
        return $this->mcpCatalogMutation('upsert_product', $idempotency_key, $reason, ['product' => $product, 'confirmation' => $confirmation], function () use ($product, $reason, $confirmation): array {
            $this->mcpCatalogConfirm($confirmation, 'UPSERT_VALIDATED_PRODUCT');
            return $this->catalogService()->upsert($product, ['source' => 'mcp', 'reason' => $reason]);
        });
    }

    /** @return array<string,mixed> */
    public function mcpSetInventory(string $product_reference, string $mode, int $quantity, int $expected_current, string $idempotency_key, string $reason, string $confirmation, string $variant_id = ''): array {
        $reference = $this->mcpCatalogReference($product_reference);
        if (!in_array($mode, ['set', 'adjust'], true)) throw new MercatoMcpException('invalid_inventory_mode', 'Inventory mode must be set or adjust.');
        $input = ['product_reference' => $reference, 'variant_id' => trim($variant_id), 'mode' => $mode, 'quantity' => $quantity, 'expected_current' => $expected_current, 'confirmation' => $confirmation];
        return $this->mcpCatalogMutation('set_inventory', $idempotency_key, $reason, $input, function () use ($reference, $variant_id, $mode, $quantity, $expected_current, $reason, $confirmation): array {
            $this->mcpCatalogConfirm($confirmation, 'SET_VALIDATED_INVENTORY');
            return $this->catalogService()->setInventory($reference, trim($variant_id), $mode, $quantity, ['source' => 'mcp', 'reason' => $reason, 'expected_current' => $expected_current]);
        });
    }

    /** @return array<string,mixed> */
    public function mcpAddProductImages(string $product_reference, array $image_urls, string $idempotency_key, string $reason, string $confirmation): array {
        $reference = $this->mcpCatalogReference($product_reference);
        $input = ['product_reference' => $reference, 'image_urls' => $image_urls, 'confirmation' => $confirmation];
        return $this->mcpCatalogMutation('add_product_images', $idempotency_key, $reason, $input, function () use ($reference, $image_urls, $reason, $confirmation): array {
            $this->mcpCatalogConfirm($confirmation, 'ADD_VALIDATED_PRODUCT_IMAGES');
            return $this->catalogService()->addImages($reference, $image_urls, ['source' => 'mcp', 'reason' => $reason]);
        });
    }

    /** @return array<string,mixed> */
    public function mcpArchiveProduct(string $product_reference, string $idempotency_key, string $reason, string $confirmation): array {
        $reference = $this->mcpCatalogReference($product_reference);
        return $this->mcpCatalogMutation('archive_product', $idempotency_key, $reason, ['product_reference' => $reference, 'confirmation' => $confirmation], function () use ($reference, $reason, $confirmation): array {
            $this->mcpCatalogConfirm($confirmation, 'ARCHIVE_PRODUCT_WITH_HISTORY');
            return $this->catalogService()->archive($reference, ['source' => 'mcp', 'reason' => $reason]);
        });
    }

    /** @return array<string,mixed> */
    public function mcpPreviewCatalogSync(array $products, string $supplier, bool $archive_missing = false): array {
        return $this->catalogService()->previewSync($products, [
            'supplier' => $this->mcpCatalogSupplier($supplier),
            'archive_missing' => $archive_missing,
            'source' => 'mcp',
        ]);
    }

    /** @return array<string,mixed> */
    public function mcpApplyCatalogSync(array $products, string $supplier, string $preview_digest, string $idempotency_key, string $reason, string $confirmation, bool $archive_missing = false): array {
        if (!preg_match('/^[a-f0-9]{64}$/', $preview_digest)) throw new MercatoMcpException('invalid_preview_digest', 'A valid catalog preview digest is required.');
        $options = [
            'supplier' => $this->mcpCatalogSupplier($supplier),
            'archive_missing' => $archive_missing,
            'preview_digest' => $preview_digest,
            'source' => 'mcp',
        ];
        return $this->mcpCatalogMutation('apply_catalog_sync', $idempotency_key, $reason, ['products' => $products, 'options' => $options, 'confirmation' => $confirmation], function () use ($products, $options, $reason, $confirmation): array {
            $this->mcpCatalogConfirm($confirmation, 'APPLY_REVIEWED_CATALOG_SYNC');
            return $this->catalogService()->applySync($products, $options, ['source' => 'mcp', 'reason' => $reason]);
        });
    }

    /** @return array<string,mixed> */
    private function mcpCatalogMutation(string $action, string $key, string $reason, array $input, callable $operation): array {
        $reason = trim($reason);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,191}$/', $key)) throw new MercatoMcpException('invalid_idempotency_key', 'Use an idempotency key of 8-191 safe characters.');
        if (strlen($reason) < 8 || strlen($reason) > 500) throw new MercatoMcpException('invalid_reason', 'Provide an audit reason between 8 and 500 characters.');
        $this->ensureMcpOperationsSchema();
        $digest = hash('sha256', $this->mcpCatalogCanonicalJson($input));
        $keyHash = hash('sha256', 'catalog:' . $action . ':' . $key);
        $existing = $this->mcpCatalogOperationRow($keyHash);
        if ($existing) return $this->mcpCatalogReplayOperation($existing, $digest);

        $now = date('Y-m-d H:i:s');
        $insert = $this->wire('database')->prepare(
            'INSERT INTO ' . self::MCP_OPERATION_TABLE
            . ' (operation_key_hash, action, input_digest, status, result_json, created_at, updated_at)'
            . ' VALUES (:key, :action, :digest, :status, :result, :created, :updated)'
        );
        try {
            $insert->execute([':key' => $keyHash, ':action' => 'catalog_' . $action, ':digest' => $digest, ':status' => 'running', ':result' => '{}', ':created' => $now, ':updated' => $now]);
        } catch (\PDOException $error) {
            if ((string) $error->getCode() !== '23000') throw $error;
            $existing = $this->mcpCatalogOperationRow($keyHash);
            if (!$existing) throw $error;
            return $this->mcpCatalogReplayOperation($existing, $digest);
        }

        $audit = [
            'event' => 'mcp_catalog_operation',
            'operation' => $action,
            'idempotency_hash' => $keyHash,
            'input_digest' => $digest,
            'reason_hash' => hash('sha256', $reason),
            'reason_length' => strlen($reason),
            'source' => 'mcp',
        ];
        try {
            $result = $operation();
            $result['idempotent_replay'] = false;
            $this->mcpCatalogFinishOperation($keyHash, 'succeeded', ['ok' => true, 'result' => $result]);
            $this->recordEvent('mercato-mcp', $audit + ['status' => 'succeeded', 'result_digest' => hash('sha256', $this->mcpCatalogCanonicalJson($result))], 'mcp_catalog_operation');
            return $result;
        } catch (\Throwable $error) {
            $safe = $error instanceof MercatoMcpException ? $error : MercatoMcpException::fromThrowable($error);
            $payload = $safe->payload();
            $this->mcpCatalogFinishOperation($keyHash, 'failed', ['ok' => false, 'error' => $payload]);
            $this->recordEvent('mercato-mcp', $audit + ['status' => 'failed', 'error_code' => $payload['code'], 'human_review_required' => $payload['human_review_required']], 'mcp_catalog_operation');
            throw $safe;
        }
    }

    /** @return array<string,mixed>|null */
    private function mcpCatalogOperationRow(string $keyHash): ?array {
        $statement = $this->wire('database')->prepare('SELECT * FROM ' . self::MCP_OPERATION_TABLE . ' WHERE operation_key_hash=:key LIMIT 1');
        $statement->execute([':key' => $keyHash]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function mcpCatalogReplayOperation(array $row, string $digest): array {
        if (!hash_equals((string) $row['input_digest'], $digest)) throw new MercatoMcpException('idempotency_conflict', 'The idempotency key was already used with different input.', true);
        $status = (string) ($row['status'] ?? '');
        $stored = json_decode((string) ($row['result_json'] ?? ''), true);
        if ($status === 'succeeded' && is_array($stored['result'] ?? null)) {
            $result = $stored['result'];
            $result['idempotent_replay'] = true;
            return $result;
        }
        if ($status === 'failed' && is_array($stored['error'] ?? null)) throw MercatoMcpException::fromPayload($stored['error']);
        $updated = strtotime((string) ($row['updated_at'] ?? '')) ?: time();
        throw new MercatoMcpException('operation_in_progress', time() - $updated > 900
            ? 'The prior catalog operation outcome is unknown. Do not retry automatically; human review is required.'
            : 'The same catalog operation is already in progress.', time() - $updated > 900, ['retry_after_seconds' => time() - $updated > 900 ? null : 5]);
    }

    private function mcpCatalogFinishOperation(string $keyHash, string $status, array $result): void {
        $statement = $this->wire('database')->prepare(
            'UPDATE ' . self::MCP_OPERATION_TABLE . ' SET status=:status, result_json=:result, updated_at=:updated WHERE operation_key_hash=:key'
        );
        $statement->execute([
            ':status' => $status,
            ':result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':updated' => date('Y-m-d H:i:s'),
            ':key' => $keyHash,
        ]);
    }

    private function mcpCatalogConfirm(string $actual, string $expected): void {
        if (!hash_equals($expected, $actual)) throw new MercatoMcpException('confirmation_required', 'The exact catalog operation confirmation is required.');
    }

    private function mcpCatalogReference(string $reference): string {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 160) throw new MercatoMcpException('invalid_product_reference', 'Product reference must contain 1-160 characters.');
        return $reference;
    }

    private function mcpCatalogSupplier(string $supplier): string {
        $supplier = strtolower(trim($supplier));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,119}$/', $supplier)) throw new MercatoMcpException('invalid_supplier', 'Supplier must be a stable 1-120 character identifier.');
        return $supplier;
    }

    private function mcpCatalogCanonicalJson(mixed $value): string {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            foreach ($item as $key => $child) $item[$key] = $normalize($child);
            return $item;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
