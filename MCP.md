# Mercato McpServer provider

Mercato is discoverable by the ProcessWire `McpServer` module as a governed
commerce provider. McpServer owns Streamable HTTP transport, bearer client
credentials, installation namespaces, hierarchical scopes, request limits,
schema validation, and gateway audit. Mercato owns catalog validation, order
lookup, payment, inventory, shipping, fulfilment, email validation, mutation
idempotency, and a second domain audit trail.

This integration is not a generic ProcessWire editor. It exposes no page,
field, template, PHP, SQL, shell, filesystem, credential, refund, or payment
repair tool.

## Discovery and names

Install both modules and refresh the ProcessWire module cache. Mercato opts in
with `mcpProvider => true` and publishes provider-local tool names. McpServer
prepends its configured site namespace. For a namespace of `shop`,
`mercato_get_order` is discovered as `shop_mercato_get_order`.

Keep the McpServer endpoint disabled until its environment, allowed hosts and
origins, provider inventory, and client scopes have been reviewed. Issue a
different revocable bearer credential for every agent or automation runtime.
Never store tokens in Mercato configuration, Git, screenshots, prompts, or
workflow exports.

## Tools and authority

| Provider-local tool | McpServer scope | External side effect | Boundary |
| --- | --- | --- | --- |
| `mercato_get_order` | `read` | No | One PII-minimized order snapshot |
| `mercato_list_orders_to_fulfil` | `read` | No | At most 100 paid, unfulfilled orders |
| `mercato_get_fulfilment_state` | `read` | No | One order; private label URL redacted |
| `mercato_get_inventory` | `read` | No | Exact products and variants in one order |
| `mercato_get_operational_health` | `read` | No | PII-free health categories |
| `mercato_list_products` | `read` | No | Bounded PII-free catalog page |
| `mercato_get_product` | `read` | No | One product and exact variant definition |
| `mercato_preview_product_upsert` | `read` | No | Validation and identity resolution without mutation |
| `mercato_upsert_product` | `publish` | Local catalog write | Stable SKU or external identity; optimistic version check |
| `mercato_set_inventory` | `publish` | Local inventory write | Base product or exact variant; required current-value check |
| `mercato_add_product_images` | `publish` | Remote HTTPS read and local image append | Public DNS/IP pinning, strict limits and checksum dedupe |
| `mercato_archive_product` | `admin` | Product becomes non-purchasable | Archive only; no deletion or history removal |
| `mercato_preview_catalog_sync` | `read` | No | Validate 1–100 supplier records and source-scoped archives |
| `mercato_apply_catalog_sync` | `admin` | Bounded catalog batch write | Exact reviewed digest; archive limited to one supplier |
| `mercato_verify_payment` | `publish` | Provider read and stored verification snapshot | Stops on unsettled or mismatched state |
| `mercato_create_shipment` | `publish` | Local shipment record | Paid order items only |
| `mercato_purchase_shipping_label` | `admin` | Yes; may create carrier cost | Separate exact cost confirmation |
| `mercato_update_tracking` | `publish` | Local tracking update | Carrier orders and HTTPS links only |
| `mercato_advance_fulfilment` | `publish` | Order state change | Method-compatible, non-regressing transitions |
| `mercato_send_order_email` | `publish` | Customer communication | Four state-appropriate order events only |

McpServer 1.x scopes are hierarchical: `admin` includes `publish`, and
`publish` includes `read`. Use a read-only client for inspection. Use a
dedicated publish client for a reviewed fulfilment workflow. Give `admin` only
to a separate runtime that is explicitly allowed to purchase labels. Refunds
and reconciliation repairs are intentionally absent. Catalog tools do not
accept customer data, credentials, arbitrary ProcessWire fields, or raw file
paths. The dedicated image tool is the only remote-file boundary and accepts
only validated public HTTPS images; it never removes existing media.

Every mutation requires:

- a unique `idempotency_key` of 8–191 safe characters;
- a human-readable `reason` of 8–500 characters (stored only as a hash and
  length in Mercato's MCP audit);
- the exact operation-specific `confirmation` string declared in discovery;
- server-authoritative order, payment, inventory, shipping, and state checks.

Successful and failed results are persisted in `mercato_mcp_operations`.
Replaying the same key and input returns the stored result. Reusing a key with
different input fails. An old in-progress record is treated as an unknown
outcome and requires human review instead of silently repeating an external
action.

## Structured failures

Expected failures are returned by McpServer as tool errors whose message is a
JSON object:

```json
{
  "code": "payment_discrepancy",
  "message": "Remote and local payment state do not match. Human review is required.",
  "human_review_required": true,
  "details": {
    "issues": ["missing_webhook"],
    "local_status": "paid",
    "remote_status": "failed"
  }
}
```

The orchestrator must stop the workflow whenever a tool errors or returns an
unexpected state. It must never convert `human_review_required` into an
automatic repair. Mercato does not expose repair or refund tools through MCP.

## Example agent or n8n workflow

The calling agent or n8n workflow controls sequencing. Mercato does not run an
autonomous fulfilment loop. A reviewed workflow can perform these calls:

1. Call `shop_mercato_list_orders_to_fulfil` with `limit: 25` and persist the
   cursor only after the batch finishes.
2. For each invoice, call `shop_mercato_get_order` and
   `shop_mercato_get_fulfilment_state`. Stop if either state changed since the
   workflow selected it.
3. Call `shop_mercato_verify_payment` using a new key such as
   `verify:INV-1042:2026-08-27T12:00Z`, the exact confirmation
   `VERIFY_REMOTE_PAYMENT`, and an operational reason.
4. Call `shop_mercato_get_inventory`. Stop if `available` is false.
5. Call `shop_mercato_create_shipment` with only the exact product/variant
   quantities returned by the order and confirmation
   `CREATE_VALIDATED_SHIPMENT`.
6. Hand the order to the separately authorized label-purchase credential and
   call `shop_mercato_purchase_shipping_label` with confirmation
   `PURCHASE_PROVIDER_LABEL_WITH_COST`.
7. Call `shop_mercato_update_tracking`, then
   `shop_mercato_advance_fulfilment` with status `shipped`.
8. Call `shop_mercato_send_order_email` with event `shipment_tracking` only
   after the tracking and fulfilment tools returned the expected state.
9. Record the returned stable order, shipment, label, and idempotency results
   in the workflow run. Do not copy customer PII into workflow logs.

Configure n8n error branches to stop the order immediately, retain the JSON
error, and create a human-review task. Do not connect an error branch back to a
mutation node with a new idempotency key.

## Example supplier catalog workflow

Use a dedicated supplier identifier that never changes after rollout:

1. Normalize the feed in n8n to the discovered closed product schema. Preserve
   the supplier's stable `external_id`; do not generate it from mutable titles.
2. Call `shop_mercato_preview_catalog_sync` in batches of at most 100 with the
   same `supplier`. Start with `archive_missing: false`.
3. Stop on SKU/external-identity conflicts or validation errors. Review creates,
   updates and proposed archives. Store the returned `preview_digest`.
4. Call `shop_mercato_apply_catalog_sync` with the byte-for-byte same product
   batch, supplier, archive flag and digest, a unique idempotency key, an audit
   reason, and confirmation `APPLY_REVIEWED_CATALOG_SYNC`.
5. Replay the same key only after a transport interruption. Mercato returns the
   stored result; it does not apply the batch twice. Changed input with the same
   key fails closed.
6. Use `shop_mercato_set_inventory` for stock-only changes. Always pass the last
   observed `expected_current`; concurrent changes return a conflict instead of
   silently overwriting stock.
7. Enable `archive_missing` only for a complete-feed run. It affects only
   products already carrying that exact `external_source`; it never deletes
   products or archives another supplier's catalog.

For supplier images, call `shop_mercato_add_product_images` after the product
upsert with confirmation `ADD_VALIDATED_PRODUCT_IMAGES`. The tool appends only,
pins each validated public DNS result for the HTTPS request, revalidates every
redirect, limits redirects/bytes/aggregate bytes/pixels/MIME, and skips existing
content by SHA-256 checksum. It never accepts local paths, private/reserved IPs,
credentials in URLs, non-HTTPS origins, or a request to delete existing media.

## Validation and rollout

Start on an isolated non-production ProcessWire installation:

```bash
MERCATO_TEST_SITE=/absolute/path/to/processwire \
MERCATO_MYSQL_SOCKET=/path/to/mysql.sock \
php scripts/run-tests.php
```

Then verify McpServer discovery and make real Streamable HTTP calls with a
development-only read credential. Exercise invalid credentials, insufficient
scope, malformed schemas, idempotency conflicts, payment mismatch,
insufficient inventory, provider failure, invalid transitions, and audit
records before issuing any write credential. Production endpoint enablement,
token issuance, and write scopes require operator approval and a current
backup/rollback plan.
