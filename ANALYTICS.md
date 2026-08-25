# Mercato analytics contract

Mercato analytics events use schema `mercato.analytics.v1`. Analytics is disabled by default and never gates cart, checkout, payment, fulfilment, account, or recovery behavior. The `analytics` consent category must be granted before non-essential adapters receive events; `essential` is always true but is not used to justify analytics delivery. Consent can be updated server-side with `$commerce->setAnalyticsConsent(['analytics' => true])` or by CSRF-protected `POST /api/mercato/analytics-consent`.

## Event names

| Event | Authoritative source | Main fields |
|---|---|---|
| `product_view` | saved product/variant | product/variant ID, SKU, name, price, currency |
| `search` | catalog query/results | query length, result count |
| `collection_view` | collection/catalog selector | collection ID/name, result count |
| `cart_change` | mutated server cart | action, product/variant, quantity, value, currency |
| `checkout_start` | persisted pending order | immutable items/totals/currency/coupon/tax/shipping |
| `shipping_selection` | validated fulfilment quote | shipping method and order snapshot |
| `payment_result` | mapped local payment transition | result and order snapshot |
| `purchase` | first paid completion | immutable order snapshot |
| `refund` | confirmed provider refund transition | partial/full refund value and original snapshot |
| `account_registered`, `account_verified`, `account_login`, `account_profile_updated`, `guest_order_claimed` | account service | omitted or salted-hashed account identifier; non-PII record IDs where applicable |

Every payload contains `schema`, `event`, `event_id`, `occurred_at`, and `consent_category`. Commerce events normalize `order_identifier`, `currency`, `value`, `tax`, `shipping`, `discount`, `coupon`, and items with product ID, variant ID, SKU, name, unit price, and quantity. Monetary data comes from saved order snapshots, not browser calculations. Choose invoice/public reference, SHA-256 hash, or omission for order identifiers; account identifiers default to omission and can only be omitted or salted-hashed.

Email, names, phone, street/postal addresses, payment details/secrets, credentials, raw provider payloads, and signed customer URLs are forbidden and recursively removed by the schema normalizer. Adapter diagnostics store event/category/status metadata and minimized payloads only.

## Delivery and exact-once behavior

Order-backed event state is stored in `mrc_analytics_details` under a per-order lock. Business IDs make purchase, payment transition, and each provider refund replay-safe across redirects, refreshes, webhooks, and reconciliation. Delivery status is tracked independently per adapter, so one adapter can retry/fail without repeating successful adapters or interrupting commerce. If consent is denied, the order event remains pending; a later grant can dispatch it when the customer returns to an authorized order-success/account surface. Browser `dataLayer` consumption marks delivery before rendering, so refresh does not push purchase/refund twice.

Behavioral events without an order are discarded for non-essential adapters while consent is denied. They are not reconstructed later because doing so would require unnecessary behavioral storage.

## Adapters and diagnostics

Built-ins are `data_layer` and `first_party`. `data_layer` renders minimized browser events; `first_party` writes the redacted Mercato analytics log. External modules implement `MercatoAnalyticsAdapterInterface` and register through `Mercato::analyticsAdapters`:

```php
$wire->addHookAfter('Mercato::analyticsAdapters', function($event) use ($adapter) {
    $adapters = (array) $event->return;
    $adapters[$adapter->getAnalyticsAdapterKey()] = $adapter;
    $event->return = $adapters;
});
```

Adapters declare their consent category and return `accepted`; exceptions are isolated and recorded by class only, without request payloads or credentials. Enable adapter keys in Mercato settings. **Setup → Mercato → Launch → Analytics diagnostics** shows enabled/registered adapters, current session consent, and recent minimized events. Validate lawful consent defaults, retention, cross-border transfers, vendor contracts, and browser-tag behavior for the actual merchant jurisdiction before production use.
