<?php
namespace ProcessWire;

/**
 * Narrow McpServer provider for governed commerce automation.
 *
 * McpServer owns transport authentication, client identity, scopes, rate
 * limits, and gateway audit. Mercato owns every business validation below.
 */
trait MercatoMcpProviderTrait {

    /** @return array<string,mixed> */
    public function mcpProviderInfo(): array {
        return [
            'name' => 'mercato',
            'title' => 'Mercato Commerce',
            'version' => '1.4.0',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function mcpTools(): array {
        $orderReference = ['type' => 'string', 'minLength' => 1, 'maxLength' => 120];
        $idempotency = ['type' => 'string', 'minLength' => 8, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9._:-]+$'];
        $reason = ['type' => 'string', 'minLength' => 8, 'maxLength' => 500];

        return [
            $this->mcpCommerceTool(
                'mercato_get_order',
                'Get Mercato order',
                'Return one bounded order snapshot without customer contact data, addresses, private notes, signed links, or provider secrets.',
                [$this, 'mcpGetOrder'],
                'read',
                ['order_reference' => $orderReference],
                ['order_reference']
            ),
            $this->mcpCommerceTool(
                'mercato_list_orders_to_fulfil',
                'List Mercato orders to fulfil',
                'Return a bounded cursor-based list of paid orders that still require fulfilment.',
                [$this, 'mcpListOrdersToFulfil'],
                'read',
                [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'after_id' => ['type' => 'integer', 'minimum' => 0],
                ]
            ),
            $this->mcpCommerceTool(
                'mercato_get_fulfilment_state',
                'Get Mercato fulfilment state',
                'Return the current fulfilment, shipment, label, and tracking state with private label URLs redacted.',
                [$this, 'mcpGetFulfilmentState'],
                'read',
                ['order_reference' => $orderReference],
                ['order_reference']
            ),
            $this->mcpCommerceTool(
                'mercato_get_inventory',
                'Get Mercato order inventory',
                'Validate the exact products and variants in an order against current inventory without changing stock.',
                [$this, 'mcpGetInventory'],
                'read',
                ['order_reference' => $orderReference],
                ['order_reference']
            ),
            $this->mcpCommerceTool(
                'mercato_get_operational_health',
                'Get Mercato operational health',
                'Return PII-free application, database, storage, provider, email, reservation, cron, backup, and checkout health.',
                [$this, 'mcpGetOperationalHealth'],
                'read',
                ['detailed' => ['type' => 'boolean']]
            ),
            $this->mcpCommerceTool(
                'mercato_verify_payment',
                'Verify Mercato payment',
                'Retrieve remote provider state and stop with a structured exception when payment is unsettled or differs from Mercato.',
                [$this, 'mcpVerifyPayment'],
                'publish',
                [
                    'order_reference' => $orderReference,
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'VERIFY_REMOTE_PAYMENT'],
                ],
                ['order_reference', 'idempotency_key', 'reason', 'confirmation'],
                true
            ),
            $this->mcpCommerceTool(
                'mercato_create_shipment',
                'Create Mercato shipment',
                'Record one validated shipment for paid, available order items. Replays return the original shipment.',
                [$this, 'mcpCreateShipment'],
                'publish',
                [
                    'order_reference' => $orderReference,
                    'items' => [
                        'type' => 'array', 'minItems' => 1, 'maxItems' => 100,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'product_id' => ['type' => 'integer', 'minimum' => 1],
                                'variant_id' => ['type' => 'string', 'maxLength' => 120],
                                'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                            ],
                            'required' => ['product_id', 'quantity'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'carrier' => ['type' => 'string', 'maxLength' => 120],
                    'service' => ['type' => 'string', 'maxLength' => 120],
                    'notes' => ['type' => 'string', 'maxLength' => 1000],
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'CREATE_VALIDATED_SHIPMENT'],
                ],
                ['order_reference', 'items', 'idempotency_key', 'reason', 'confirmation']
            ),
            $this->mcpCommerceTool(
                'mercato_purchase_shipping_label',
                'Purchase Mercato shipping label',
                'Purchase a provider shipping label for a paid order. This creates an external cost and requires admin scope plus explicit confirmation.',
                [$this, 'mcpPurchaseShippingLabel'],
                'admin',
                [
                    'order_reference' => $orderReference,
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'PURCHASE_PROVIDER_LABEL_WITH_COST'],
                ],
                ['order_reference', 'idempotency_key', 'reason', 'confirmation'],
                true,
                true
            ),
            $this->mcpCommerceTool(
                'mercato_update_tracking',
                'Update Mercato tracking',
                'Save validated carrier tracking data without changing the fulfilment status.',
                [$this, 'mcpUpdateTracking'],
                'publish',
                [
                    'order_reference' => $orderReference,
                    'tracking' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 240],
                    'tracking_url' => ['type' => 'string', 'maxLength' => 1000, 'pattern' => '^https://'],
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'UPDATE_CARRIER_TRACKING'],
                ],
                ['order_reference', 'tracking', 'idempotency_key', 'reason', 'confirmation']
            ),
            $this->mcpCommerceTool(
                'mercato_advance_fulfilment',
                'Advance Mercato fulfilment',
                'Advance a paid order through a method-compatible, non-regressing fulfilment transition.',
                [$this, 'mcpAdvanceFulfilment'],
                'publish',
                [
                    'order_reference' => $orderReference,
                    'status' => ['type' => 'string', 'enum' => MercatoFulfilmentStatus::all()],
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'ADVANCE_VALIDATED_FULFILMENT'],
                ],
                ['order_reference', 'status', 'idempotency_key', 'reason', 'confirmation']
            ),
            $this->mcpCommerceTool(
                'mercato_send_order_email',
                'Send Mercato order email',
                'Send one state-appropriate transactional order email through Mercato delivery controls. Recipient PII is never returned.',
                [$this, 'mcpSendOrderEmail'],
                'publish',
                [
                    'order_reference' => $orderReference,
                    'event' => ['type' => 'string', 'enum' => ['order_confirmation', 'shipment_tracking', 'pickup_ready', 'local_delivery']],
                    'idempotency_key' => $idempotency,
                    'reason' => $reason,
                    'confirmation' => ['type' => 'string', 'const' => 'SEND_TRANSACTIONAL_ORDER_EMAIL'],
                ],
                ['order_reference', 'event', 'idempotency_key', 'reason', 'confirmation'],
                true
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function mcpCommerceTool(
        string $name,
        string $title,
        string $description,
        callable $handler,
        string $scope,
        array $properties = [],
        array $required = [],
        bool $openWorld = false,
        bool $destructive = false
    ): array {
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
    public function mcpGetOrder(string $order_reference): array {
        return $this->mcpSerializeOrder($this->mcpOrder($order_reference));
    }

    /** @return array<string,mixed> */
    public function mcpListOrdersToFulfil(int $limit = 50, int $after_id = 0): array {
        $limit = max(1, min(100, $limit));
        $afterId = max(0, $after_id);
        $template = $this->wire('sanitizer')->selectorValue((string) $this->order_template);
        $statuses = implode('|', [
            MercatoFulfilmentStatus::UNFULFILLED,
            MercatoFulfilmentStatus::PARTIALLY_FULFILLED,
        ]);
        $orders = $this->wire('pages')->find(
            "template={$template}, include=all, id>{$afterId}, mrc_payment_complete=1, "
            . "mrc_fulfilment_status={$statuses}, sort=id, limit=" . ($limit + 1)
        );
        $hasMore = count($orders) > $limit;
        $items = [];
        foreach ($orders as $index => $order) {
            if ($index >= $limit) break;
            $items[] = $this->mcpSerializeOrder($order);
        }
        $last = end($items);
        return [
            'count' => count($items),
            'items' => $items,
            'next_after_id' => $last ? (int) $last['order_id'] : $afterId,
            'has_more' => $hasMore,
        ];
    }

    /** @return array<string,mixed> */
    public function mcpGetFulfilmentState(string $order_reference): array {
        return $this->mcpSerializeFulfilment($this->mcpOrder($order_reference));
    }

    /** @return array<string,mixed> */
    public function mcpGetInventory(string $order_reference): array {
        return $this->mcpInventoryState($this->mcpOrder($order_reference));
    }

    /** @return array<string,mixed> */
    public function mcpGetOperationalHealth(bool $detailed = true): array {
        return $this->operationalService()->health($detailed);
    }

    /** @return array<string,mixed> */
    public function mcpVerifyPayment(string $order_reference, string $idempotency_key, string $reason, string $confirmation): array {
        return $this->mcpMutation('verify_payment', $order_reference, $idempotency_key, $reason, compact('confirmation'), function (Page $order) use ($confirmation): array {
            $this->mcpConfirm($confirmation, 'VERIFY_REMOTE_PAYMENT');
            $remote = $this->paymentReconciliationAuditService()->verifyRemote($order);
            $fresh = $this->mcpOrder((string) $order->id);
            $audit = $this->paymentReconciliationAuditService()->inspect($fresh);
            if (empty($audit['healthy'])) {
                throw new MercatoMcpException('payment_discrepancy', 'Remote and local payment state do not match. Human review is required.', true, [
                    'issues' => array_values((array) ($audit['issues'] ?? [])),
                    'local_status' => (string) ($audit['local_status'] ?? ''),
                    'remote_status' => (string) ($audit['remote_status'] ?? ''),
                ]);
            }
            if (!in_array((string) ($remote['status'] ?? ''), [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED], true)) {
                throw new MercatoMcpException('payment_not_settled', 'The remote payment is not settled; fulfilment must stop.', false, [
                    'remote_status' => (string) ($remote['status'] ?? 'unknown'),
                ]);
            }
            return [
                'verified' => true,
                'order_id' => (int) $fresh->id,
                'invoice' => (string) ($fresh->mrc_invoice_number ?: $fresh->title),
                'local_status' => (string) ($audit['local_status'] ?? ''),
                'remote_status' => (string) ($audit['remote_status'] ?? ''),
                'verified_at' => (string) ($remote['verified_at'] ?? ''),
            ];
        });
    }

    /** @param array<int,array<string,mixed>> $items @return array<string,mixed> */
    public function mcpCreateShipment(
        string $order_reference,
        array $items,
        string $idempotency_key,
        string $reason,
        string $confirmation,
        string $carrier = '',
        string $service = '',
        string $notes = ''
    ): array {
        $input = compact('items', 'carrier', 'service', 'notes', 'confirmation');
        return $this->mcpMutation('create_shipment', $order_reference, $idempotency_key, $reason, $input, function (Page $order) use ($items, $carrier, $service, $notes, $confirmation): array {
            $this->mcpConfirm($confirmation, 'CREATE_VALIDATED_SHIPMENT');
            $this->mcpAssertPaid($order);
            $inventory = $this->mcpInventoryState($order);
            if (empty($inventory['available'])) {
                throw new MercatoMcpException('insufficient_inventory', 'One or more exact order variants are not available. Human review is required.', true, [
                    'unavailable_items' => array_values(array_map(
                        static fn(array $item): array => array_intersect_key($item, array_flip(['product_id', 'variant_id', 'sku', 'requested_quantity', 'available_quantity', 'reason'])),
                        array_filter((array) $inventory['items'], static fn(array $item): bool => empty($item['available']))
                    )),
                ]);
            }
            $shipmentItems = $this->mcpValidateShipmentItems($order, $items);
            $shipment = $this->createShipment($order, [
                'items' => $shipmentItems,
                'carrier' => $carrier,
                'service' => $service,
                'notes' => $notes,
                'source' => 'mcp',
                'status' => MercatoFulfilmentStatus::SHIPPED,
            ]);
            unset($shipment['label_url']);
            return $shipment;
        });
    }

    /** @return array<string,mixed> */
    public function mcpPurchaseShippingLabel(string $order_reference, string $idempotency_key, string $reason, string $confirmation): array {
        return $this->mcpMutation('purchase_shipping_label', $order_reference, $idempotency_key, $reason, compact('confirmation'), function (Page $order) use ($confirmation): array {
            $this->mcpConfirm($confirmation, 'PURCHASE_PROVIDER_LABEL_WITH_COST');
            $this->mcpAssertPaid($order);
            $inventory = $this->mcpInventoryState($order);
            if (empty($inventory['available'])) throw new MercatoMcpException('insufficient_inventory', 'A label was not purchased because order inventory is unavailable.', true);
            try {
                $label = $this->shippingProviderService()->purchaseLabel($order);
            } catch (\Throwable $e) {
                throw new MercatoMcpException('shipping_provider_failure', 'The shipping provider did not create a usable label. Human review is required.', true, [], 0, $e);
            }
            unset($label['label_url']);
            return [
                'order_id' => (int) $order->id,
                'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
                'label' => array_intersect_key($label, array_flip(['status', 'label_reference', 'shipment_reference', 'tracking', 'tracking_url', 'at'])),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function mcpUpdateTracking(
        string $order_reference,
        string $tracking,
        string $idempotency_key,
        string $reason,
        string $confirmation,
        string $tracking_url = ''
    ): array {
        $input = compact('tracking', 'tracking_url', 'confirmation');
        return $this->mcpMutation('update_tracking', $order_reference, $idempotency_key, $reason, $input, function (Page $order) use ($tracking, $tracking_url, $confirmation): array {
            $this->mcpConfirm($confirmation, 'UPDATE_CARRIER_TRACKING');
            $this->mcpAssertPaid($order);
            if ((string) $order->mrc_fulfilment_method !== MercatoFulfilmentMethodType::CARRIER_DELIVERY) {
                throw new MercatoMcpException('invalid_fulfilment_method', 'Carrier tracking is only valid for carrier-delivery orders.');
            }
            $tracking = substr(trim($this->wire('sanitizer')->text($tracking)), 0, 240);
            if ($tracking === '') throw new MercatoMcpException('invalid_tracking', 'A non-empty tracking reference is required.');
            $url = trim($tracking_url);
            if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://'))) {
                throw new MercatoMcpException('invalid_tracking_url', 'Tracking URL must be an absolute HTTPS URL.');
            }
            $previousTracking = (string) ($order->mrc_fulfilment_tracking ?? '');
            $order->of(false);
            $order->mrc_fulfilment_tracking = $tracking;
            $order->mrc_fulfilment_tracking_url = $url;
            $this->wire('pages')->save($order);
            $this->recordEvent('mercato-fulfilment', [
                'event' => 'mcp_tracking_updated',
                'status' => 'completed',
                'order_id' => (int) $order->id,
                'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
                'tracking_changed' => $previousTracking !== $tracking,
                'tracking_url_present' => $url !== '',
                'source' => 'mcp',
            ], 'mcp_tracking_updated');
            return $this->mcpSerializeFulfilment($this->mcpOrder((string) $order->id));
        });
    }

    /** @return array<string,mixed> */
    public function mcpAdvanceFulfilment(string $order_reference, string $status, string $idempotency_key, string $reason, string $confirmation): array {
        return $this->mcpMutation('advance_fulfilment', $order_reference, $idempotency_key, $reason, compact('status', 'confirmation'), function (Page $order) use ($status, $confirmation): array {
            $this->mcpConfirm($confirmation, 'ADVANCE_VALIDATED_FULFILMENT');
            $this->mcpAssertPaid($order);
            $target = strtolower(trim($status));
            if (!MercatoFulfilmentStatus::isValid($target)) throw new MercatoMcpException('invalid_state_transition', 'Unknown fulfilment status.');
            $method = (string) ($order->mrc_fulfilment_method ?: MercatoFulfilmentMethodType::CARRIER_DELIVERY);
            if (!in_array($target, $this->mcpStatusesForMethod($method), true)) {
                throw new MercatoMcpException('invalid_state_transition', 'The requested fulfilment status is not valid for this order method.');
            }
            $current = strtolower(trim((string) $order->mrc_fulfilment_status)) ?: MercatoFulfilmentStatus::UNFULFILLED;
            if ($target !== $current && $this->mcpFulfilmentRank($target) < $this->mcpFulfilmentRank($current)) {
                throw new MercatoMcpException('invalid_state_transition', 'Fulfilment status regression was blocked.', true, ['current' => $current, 'requested' => $target]);
            }
            if ($target === $current) return $this->mcpSerializeFulfilment($order) + ['unchanged' => true];
            $previousOrderStatus = $this->getDerivedOrderStatus($order);
            $order->of(false);
            $order->mrc_fulfilment_status = $target;
            if (in_array($target, [MercatoFulfilmentStatus::FULFILLED, MercatoFulfilmentStatus::SHIPPED, MercatoFulfilmentStatus::COLLECTED, MercatoFulfilmentStatus::DELIVERED], true)) {
                $order->mrc_fulfilled_date = (string) $order->mrc_fulfilled_date ?: date('Y-m-d H:i:s');
            }
            $this->wire('pages')->save($order);
            $this->recordEvent('mercato-fulfilment', [
                'event' => 'mcp_fulfilment_advanced', 'status' => 'completed',
                'order_id' => (int) $order->id, 'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
                'from' => $current, 'to' => $target, 'method' => $method, 'source' => 'mcp',
            ], 'mcp_fulfilment_advanced');
            $this->fulfilmentUpdated($order, ['source' => 'mcp', 'method' => $method, 'from' => $current, 'to' => $target]);
            $this->emitOrderStatusChanged($order, $previousOrderStatus, ['source' => 'mcp', 'fulfilment_status_from' => $current, 'fulfilment_status_to' => $target]);
            return $this->mcpSerializeFulfilment($this->mcpOrder((string) $order->id)) + ['unchanged' => false];
        });
    }

    /** @return array<string,mixed> */
    public function mcpSendOrderEmail(string $order_reference, string $event, string $idempotency_key, string $reason, string $confirmation): array {
        return $this->mcpMutation('send_order_email', $order_reference, $idempotency_key, $reason, compact('event', 'confirmation'), function (Page $order) use ($event, $idempotency_key, $confirmation): array {
            $this->mcpConfirm($confirmation, 'SEND_TRANSACTIONAL_ORDER_EMAIL');
            $this->mcpAssertEmailMatchesState($order, $event);
            $result = $this->notificationDeliveryService()->sendOrderEvent($order, $event, [
                'event_id' => 'mcp:' . hash('sha256', $idempotency_key),
            ]);
            $status = (string) ($result['status'] ?? 'failed');
            if (!in_array($status, ['sent', 'skipped'], true)) {
                throw new MercatoMcpException('email_delivery_failure', 'Transactional email was not accepted by the configured delivery transport.', true, ['delivery_status' => $status]);
            }
            return [
                'order_id' => (int) $order->id,
                'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
                'event' => $event,
                'status' => $status,
                'provider' => (string) ($result['provider'] ?? ''),
                'retry_count' => (int) ($result['retry_count'] ?? 0),
            ];
        });
    }

    public function ensureMcpOperationsSchema(): void {
        $table = self::MCP_OPERATION_TABLE;
        $this->wire('database')->exec(
            "CREATE TABLE IF NOT EXISTS `{$table}` ("
            . "operation_key_hash CHAR(64) NOT NULL PRIMARY KEY,"
            . "action VARCHAR(64) NOT NULL,"
            . "input_digest CHAR(64) NOT NULL,"
            . "status VARCHAR(16) NOT NULL,"
            . "result_json MEDIUMTEXT NOT NULL,"
            . "created_at DATETIME NOT NULL,"
            . "updated_at DATETIME NOT NULL,"
            . "INDEX action_status (action, status),"
            . "INDEX updated_at (updated_at)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** @return array<string,mixed> */
    private function mcpMutation(string $action, string $orderReference, string $key, string $reason, array $input, callable $operation): array {
        $reason = trim($reason);
        if (!preg_match(self::MCP_IDEMPOTENCY_PATTERN, $key)) throw new MercatoMcpException('invalid_idempotency_key', 'Use an idempotency key of 8-191 safe characters.');
        if (strlen($reason) < 8 || strlen($reason) > 500) throw new MercatoMcpException('invalid_reason', 'Provide an audit reason between 8 and 500 characters.');
        $order = $this->mcpOrder($orderReference);
        $this->ensureMcpOperationsSchema();
        $digest = hash('sha256', $this->mcpCanonicalJson(['order_id' => (int) $order->id, 'input' => $input]));
        $keyHash = hash('sha256', $action . ':' . $key);
        $existing = $this->mcpOperationRow($keyHash);
        if ($existing) return $this->mcpReplayOperation($existing, $digest);

        $now = date('Y-m-d H:i:s');
        $insert = $this->wire('database')->prepare(
            'INSERT INTO ' . self::MCP_OPERATION_TABLE
            . ' (operation_key_hash, action, input_digest, status, result_json, created_at, updated_at)'
            . ' VALUES (:key, :action, :digest, :status, :result, :created, :updated)'
        );
        try {
            $insert->execute([':key' => $keyHash, ':action' => $action, ':digest' => $digest, ':status' => 'running', ':result' => '{}', ':created' => $now, ':updated' => $now]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') throw $e;
            $existing = $this->mcpOperationRow($keyHash);
            if (!$existing) throw $e;
            return $this->mcpReplayOperation($existing, $digest);
        }

        $auditBase = [
            'event' => 'mcp_operation', 'operation' => $action, 'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'idempotency_hash' => $keyHash, 'input_digest' => $digest,
            'reason_hash' => hash('sha256', $reason), 'reason_length' => strlen($reason), 'source' => 'mcp',
        ];
        try {
            $result = $operation($order);
            $result['idempotent_replay'] = false;
            $this->mcpFinishOperation($keyHash, 'succeeded', ['ok' => true, 'result' => $result]);
            $this->recordEvent('mercato-mcp', $auditBase + ['status' => 'succeeded', 'result_digest' => hash('sha256', $this->mcpCanonicalJson($result))], 'mcp_operation');
            return $result;
        } catch (\Throwable $e) {
            $error = $e instanceof MercatoMcpException ? $e : MercatoMcpException::fromThrowable($e);
            $payload = $error->payload();
            $this->mcpFinishOperation($keyHash, 'failed', ['ok' => false, 'error' => $payload]);
            $this->recordEvent('mercato-mcp', $auditBase + ['status' => 'failed', 'error_code' => $payload['code'], 'human_review_required' => $payload['human_review_required']], 'mcp_operation');
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function mcpOperationRow(string $keyHash): ?array {
        $statement = $this->wire('database')->prepare('SELECT * FROM ' . self::MCP_OPERATION_TABLE . ' WHERE operation_key_hash=:key LIMIT 1');
        $statement->execute([':key' => $keyHash]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function mcpReplayOperation(array $row, string $digest): array {
        if (!hash_equals((string) $row['input_digest'], $digest)) {
            throw new MercatoMcpException('idempotency_conflict', 'The idempotency key was already used with different input.', true);
        }
        $status = (string) ($row['status'] ?? '');
        $stored = json_decode((string) ($row['result_json'] ?? ''), true);
        if ($status === 'succeeded' && is_array($stored['result'] ?? null)) {
            $result = $stored['result'];
            $result['idempotent_replay'] = true;
            return $result;
        }
        if ($status === 'failed' && is_array($stored['error'] ?? null)) throw MercatoMcpException::fromPayload($stored['error']);
        $updated = strtotime((string) ($row['updated_at'] ?? '')) ?: time();
        throw new MercatoMcpException(
            'operation_in_progress',
            time() - $updated > 900
                ? 'The prior operation outcome is unknown. Do not retry automatically; human review is required.'
                : 'The same operation is already in progress.',
            time() - $updated > 900,
            ['retry_after_seconds' => time() - $updated > 900 ? null : 5]
        );
    }

    private function mcpFinishOperation(string $keyHash, string $status, array $result): void {
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

    private function mcpOrder(string $reference): Page {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 120) throw new MercatoMcpException('invalid_order_reference', 'Order reference must contain 1-120 characters.');
        $order = $this->getOrder($reference);
        if (!$order || !$order->id) throw new MercatoMcpException('order_not_found', 'Order was not found.');
        return $order;
    }

    /** @return array<string,mixed> */
    private function mcpSerializeOrder(Page $order): array {
        $items = json_decode((string) $order->mrc_items, true);
        $safeItems = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) continue;
            $safeItems[] = [
                'product_id' => (int) ($item['product_id'] ?? $item['id'] ?? 0),
                'title' => substr(trim((string) ($item['title'] ?? $item['name'] ?? '')), 0, 240),
                'sku' => substr(trim((string) ($item['sku'] ?? '')), 0, 120),
                'variant_id' => substr(trim((string) ($item['variant_id'] ?? '')), 0, 120),
                'variant_label' => substr(trim((string) ($item['variant_label'] ?? '')), 0, 240),
                'quantity' => max(1, (int) ceil((float) ($item['quantity'] ?? 1))),
                'product_type' => substr(trim((string) ($item['product_type'] ?? 'physical')), 0, 32),
            ];
        }
        return [
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'created_at' => date(DATE_ATOM, (int) $order->created),
            'order_status' => $this->getDerivedOrderStatus($order),
            'payment' => [
                'method' => (string) $order->mrc_payment_method,
                'status' => (string) $order->mrc_payment_status,
                'complete' => (int) $order->mrc_payment_complete === 1,
            ],
            'fulfilment' => $this->mcpSerializeFulfilment($order),
            'items' => $safeItems,
            'totals' => [
                'subtotal' => round((float) $order->mrc_subtotal_amount, 2),
                'shipping' => round((float) $order->mrc_shipping_amount, 2),
                'discount' => round((float) $order->mrc_discount_total, 2),
                'tax' => round((float) $order->mrc_tax_amount, 2),
                'total' => round((float) $order->mrc_total_amount, 2),
                'currency' => (string) $order->mrc_currency,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function mcpSerializeFulfilment(Page $order): array {
        $details = json_decode((string) $order->mrc_fulfilment_details, true);
        $details = is_array($details) ? $this->shippingProviderService()->redactSnapshot($details) : [];
        $provider = (array) ($details['provider_shipping'] ?? []);
        $shipment = (array) ($provider['shipment'] ?? []);
        $label = (array) ($provider['label'] ?? []);
        unset($shipment['label_url'], $label['label_url']);
        return [
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'method' => (string) $order->mrc_fulfilment_method,
            'status' => (string) ($order->mrc_fulfilment_status ?: MercatoFulfilmentStatus::UNFULFILLED),
            'label' => (string) $order->mrc_fulfilment_label,
            'tracking' => (string) $order->mrc_fulfilment_tracking,
            'tracking_url' => (string) $order->mrc_fulfilment_tracking_url,
            'fulfilled_at' => (string) $order->mrc_fulfilled_date,
            'provider_shipping' => [
                'shipment' => array_intersect_key($shipment, array_flip(['status', 'shipment_reference', 'at'])),
                'label' => array_intersect_key($label, array_flip(['status', 'shipment_reference', 'label_reference', 'tracking', 'tracking_url', 'at'])),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function mcpInventoryState(Page $order): array {
        $items = json_decode((string) $order->mrc_items, true);
        $result = [];
        $allAvailable = true;
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item) || ($item['product_type'] ?? 'physical') !== 'physical') continue;
            $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);
            $quantity = max(1, (int) ceil((float) ($item['quantity'] ?? 1)));
            $variantId = trim((string) ($item['variant_id'] ?? ''));
            $product = $productId > 0 ? $this->wire('pages')->get($productId) : null;
            if (!$product || !$product->id) {
                $row = ['product_id' => $productId, 'variant_id' => $variantId, 'sku' => (string) ($item['sku'] ?? ''), 'requested_quantity' => $quantity, 'available_quantity' => 0, 'available' => false, 'reason' => 'product_not_found'];
                $allAvailable = false;
                $result[] = $row;
                continue;
            }
            $variant = $variantId !== '' ? $this->variantService()->resolve($product, $variantId, [], false) : null;
            if ($variantId !== '' && !$variant) {
                $result[] = ['product_id' => (int) $product->id, 'variant_id' => $variantId, 'sku' => (string) ($item['sku'] ?? ''), 'requested_quantity' => $quantity, 'available_quantity' => 0, 'available' => false, 'reason' => 'variant_not_found'];
                $allAvailable = false;
                continue;
            }
            $stockPolicy = (string) ($variant['stock_policy'] ?? $item['stock_policy'] ?? $product->mrc_stock_policy ?? 'deny');
            $allowsOversell = in_array($stockPolicy, ['backorder', 'preorder'], true);
            $stock = $variant ? (int) ($variant['stock'] ?? 0) : (int) ($product->mrc_stock ?? 0);
            $reserved = $allowsOversell ? 0 : ($variant
                ? $this->orderRepository()->getReservedQuantityForVariant((int) $product->id, $variantId, (int) $order->id)
                : $this->orderRepository()->getReservedQuantityForProduct((int) $product->id, (int) $order->id));
            $availableQuantity = max(0, $stock - $reserved);
            $available = $allowsOversell || $quantity <= $availableQuantity;
            $result[] = [
                'product_id' => (int) $product->id,
                'variant_id' => $variantId,
                'variant_label' => substr(trim((string) ($item['variant_label'] ?? '')), 0, 240),
                'sku' => substr(trim((string) ($item['sku'] ?? ($variant['sku'] ?? $product->mrc_sku ?? ''))), 0, 120),
                'requested_quantity' => $quantity,
                'available_quantity' => $availableQuantity,
                'stock_policy' => $stockPolicy,
                'available' => $available,
                'reason' => $available ? '' : 'requested_quantity_not_available',
            ];
            if (!$available) $allAvailable = false;
        }
        return [
            'order_id' => (int) $order->id,
            'invoice' => (string) ($order->mrc_invoice_number ?: $order->title),
            'available' => $allAvailable,
            'items' => $result,
        ];
    }

    private function mcpAssertPaid(Page $order): void {
        $status = strtolower(trim((string) $order->mrc_payment_status));
        if ((int) $order->mrc_payment_complete !== 1 || !in_array($status, [MercatoPaymentStatus::PAID, MercatoPaymentStatus::PARTIALLY_REFUNDED], true)) {
            throw new MercatoMcpException('payment_not_settled', 'The order is not locally recorded as paid; fulfilment must stop.', true, ['local_status' => $status]);
        }
    }

    /** @param array<int,array<string,mixed>> $requested @return array<int,array<string,mixed>> */
    private function mcpValidateShipmentItems(Page $order, array $requested): array {
        if ($requested === [] || count($requested) > 100) throw new MercatoMcpException('invalid_shipment_items', 'Ship between 1 and 100 exact order items.');
        $stored = json_decode((string) $order->mrc_items, true);
        $available = [];
        foreach (is_array($stored) ? $stored : [] as $item) {
            if (!is_array($item)) continue;
            $key = $this->partialFulfilmentItemKey($item);
            if ($key !== '') $available[$key] = $item;
        }
        $seen = [];
        $normalized = [];
        foreach ($requested as $item) {
            if (!is_array($item)) throw new MercatoMcpException('invalid_shipment_items', 'Every shipment item must be an object.');
            $key = $this->partialFulfilmentItemKey($item);
            if ($key === '' || !isset($available[$key])) throw new MercatoMcpException('invalid_shipment_items', 'A requested product or exact variant is not present in the order.');
            if (isset($seen[$key])) throw new MercatoMcpException('invalid_shipment_items', 'Duplicate shipment item references are not allowed.');
            $seen[$key] = true;
            $quantity = (int) ($item['quantity'] ?? 0);
            $orderedQuantity = max(1, (int) ceil((float) ($available[$key]['quantity'] ?? 1)));
            if ($quantity < 1 || $quantity > $orderedQuantity) throw new MercatoMcpException('invalid_shipment_items', 'Shipment quantity exceeds the immutable order snapshot.');
            $snapshot = $available[$key];
            $normalized[] = [
                'product_id' => (int) ($snapshot['product_id'] ?? $snapshot['id'] ?? 0),
                'variant_id' => (string) ($snapshot['variant_id'] ?? ''),
                'variant_label' => (string) ($snapshot['variant_label'] ?? ''),
                'title' => (string) ($snapshot['title'] ?? $snapshot['name'] ?? ''),
                'sku' => (string) ($snapshot['sku'] ?? ''),
                'quantity' => $quantity,
            ];
        }
        return $normalized;
    }

    private function mcpAssertEmailMatchesState(Page $order, string $event): void {
        if (!in_array($event, ['order_confirmation', 'shipment_tracking', 'pickup_ready', 'local_delivery'], true)) {
            throw new MercatoMcpException('email_event_not_allowed', 'This email event is not exposed to MCP fulfilment clients.');
        }
        $this->mcpAssertPaid($order);
        $status = (string) ($order->mrc_fulfilment_status ?: MercatoFulfilmentStatus::UNFULFILLED);
        if ($event === 'shipment_tracking' && (!in_array($status, [MercatoFulfilmentStatus::SHIPPED, MercatoFulfilmentStatus::DELIVERED], true) || trim((string) $order->mrc_fulfilment_tracking) === '')) {
            throw new MercatoMcpException('invalid_email_state', 'Shipment email requires shipped/delivered state and tracking.');
        }
        if ($event === 'pickup_ready' && $status !== MercatoFulfilmentStatus::READY_FOR_PICKUP) throw new MercatoMcpException('invalid_email_state', 'Pickup email requires ready-for-pickup state.');
        if ($event === 'local_delivery' && !in_array($status, [MercatoFulfilmentStatus::OUT_FOR_DELIVERY, MercatoFulfilmentStatus::DELIVERED], true)) throw new MercatoMcpException('invalid_email_state', 'Local-delivery email requires out-for-delivery or delivered state.');
    }

    /** @return string[] */
    private function mcpStatusesForMethod(string $method): array {
        return match ($method) {
            MercatoFulfilmentMethodType::STORE_PICKUP => [MercatoFulfilmentStatus::UNFULFILLED, MercatoFulfilmentStatus::PARTIALLY_FULFILLED, MercatoFulfilmentStatus::READY_FOR_PICKUP, MercatoFulfilmentStatus::COLLECTED, MercatoFulfilmentStatus::RETURNED],
            MercatoFulfilmentMethodType::LOCAL_DELIVERY => [MercatoFulfilmentStatus::UNFULFILLED, MercatoFulfilmentStatus::PARTIALLY_FULFILLED, MercatoFulfilmentStatus::OUT_FOR_DELIVERY, MercatoFulfilmentStatus::DELIVERED, MercatoFulfilmentStatus::RETURNED],
            default => [MercatoFulfilmentStatus::UNFULFILLED, MercatoFulfilmentStatus::PARTIALLY_FULFILLED, MercatoFulfilmentStatus::FULFILLED, MercatoFulfilmentStatus::SHIPPED, MercatoFulfilmentStatus::DELIVERED, MercatoFulfilmentStatus::RETURNED],
        };
    }

    private function mcpFulfilmentRank(string $status): int {
        return match ($status) {
            MercatoFulfilmentStatus::UNFULFILLED => 0,
            MercatoFulfilmentStatus::PARTIALLY_FULFILLED => 1,
            MercatoFulfilmentStatus::FULFILLED,
            MercatoFulfilmentStatus::SHIPPED,
            MercatoFulfilmentStatus::READY_FOR_PICKUP => 2,
            MercatoFulfilmentStatus::OUT_FOR_DELIVERY => 3,
            MercatoFulfilmentStatus::COLLECTED,
            MercatoFulfilmentStatus::DELIVERED => 4,
            MercatoFulfilmentStatus::RETURNED => 5,
            default => -1,
        };
    }

    private function mcpConfirm(string $actual, string $expected): void {
        if (!hash_equals($expected, $actual)) throw new MercatoMcpException('confirmation_required', 'The exact operation confirmation is required.');
    }

    private function mcpCanonicalJson(mixed $value): string {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            foreach ($item as $key => $child) $item[$key] = $normalize($child);
            return $item;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

final class MercatoMcpException extends WireException {
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $safeMessage,
        public readonly bool $humanReviewRequired = false,
        public readonly array $details = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct(json_encode([
            'code' => $errorCode,
            'message' => $safeMessage,
            'human_review_required' => $humanReviewRequired,
            'details' => $details ?: new \stdClass(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $code, $previous);
    }

    /** @return array<string,mixed> */
    public function payload(): array {
        $payload = json_decode($this->getMessage(), true);
        return is_array($payload) ? $payload : ['code' => $this->errorCode, 'message' => 'Mercato MCP operation failed.', 'human_review_required' => $this->humanReviewRequired, 'details' => new \stdClass()];
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(array $payload): self {
        return new self(
            substr(trim((string) ($payload['code'] ?? 'operation_failed')), 0, 80),
            substr(trim((string) ($payload['message'] ?? 'Mercato MCP operation failed.')), 0, 500),
            !empty($payload['human_review_required']),
            is_array($payload['details'] ?? null) ? $payload['details'] : []
        );
    }

    public static function fromThrowable(\Throwable $error): self {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'stock') || str_contains($message, 'inventory') || str_contains($message, 'available')) return new self('insufficient_inventory', 'Inventory validation failed. Human review is required.', true, [], 0, $error);
        if (str_contains($message, 'shipping') || str_contains($message, 'carrier') || str_contains($message, 'label')) return new self('shipping_provider_failure', 'The shipping provider operation failed. Human review is required.', true, [], 0, $error);
        if (str_contains($message, 'payment') || str_contains($message, 'gateway')) return new self('payment_verification_failure', 'Payment verification failed. Human review is required.', true, [], 0, $error);
        return new self('operation_failed', 'The Mercato operation failed without applying a confirmed result.', true, [], 0, $error);
    }
}
