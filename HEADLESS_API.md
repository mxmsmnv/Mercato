# Mercato Headless Commerce API v1

Base URL: `https://shop.example/api/mercato/v1`. Responses are JSON and include `ok`, `api_version`, and `request_id`. Monetary values and stock decisions are always recalculated by Mercato; client-supplied price, tax, shipping, discount, currency, and total fields are ignored.

## Resources

- `GET /store` — currency, checkout state, payment capabilities, policies, and links.
- `GET /products?page=1&limit=20&q=&type=&collection=&sort=title` and `GET /products/{id-or-name}`.
- `GET /collections?page=1&limit=20` and `GET /collections/{id-or-name}`.
- `POST /quotes` — authoritative fulfilment choices, discount, tax, and totals.
- `POST /checkouts` — guest validation, draft order, reservation, and payment initialization. Requires `Idempotency-Key`.
- `GET /checkouts/{opaque-id}` — reservation/payment state with bearer token.
- `POST /checkouts/{opaque-id}/complete` — completion/reconciliation with bearer token and `Idempotency-Key`.
- `GET /orders/{opaque-id}` — safe status, totals/items, fulfilment, and signed receipt/status links with bearer token.

Pagination contains `page`, `limit`, `total`, `pages`, and `has_next`. Product sorting is `title`, `price`, `-price`, or `-created`. Products include public images, descriptions, collections, variants, and live purchasability—never filesystem paths or admin URLs.

## Checkout example

```http
POST /api/mercato/v1/checkouts
Content-Type: application/json
Idempotency-Key: 018f-native-checkout-01

{"items":[{"product_id":1770,"variant_id":"sand-large","quantity":1}],"customer":{"first_name":"Ada","last_name":"Lovelace","email":"buyer@example.com","address":"1 Example Street","city":"London","zip":"SW1A 1AA","country":"GB"},"options":{"payment_method":"stripe-card","fulfilment_method":"carrier_delivery","discount_code":"WELCOME10","policy_accepted":true}}
```

The response contains opaque `id`, opaque `order_id`, bearer `token`, expiry, authoritative quote/state, and payment instructions. `payment.mode` is `client_confirmation` (native SDK and `client_secret`), `redirect` (validated HTTPS URL), `offline`, or `processing`. Store credentials in Keychain/secure storage and never log tokens or client secrets. Identical idempotent replay returns the original resource; a different payload returns `409`.

## Errors

```json
{"ok":false,"api_version":"v1","request_id":"req_…","error":{"code":"validation_failed","message":"Checkout data is invalid.","fields":{"customer.email":"Valid email required."}}}
```

The API uses 400 malformed/idempotency input, 404 non-enumerating invalid resource/token, 409 conflict, 410 expired credential, 413 body limit, 422 validation, 429 rate limit, 502 provider failure, and 503 disabled/maintenance/HTTPS. Errors redact customer data, provider payloads, and secrets.

## Security and operations

- Credentials are random, scoped, hashed at rest, and expiring. Existing order/payment/webhook/inventory locks remain authoritative.
- Bearer calls do not rely on cookies or CSRF state. The compatibility session is snapshot/restored inside one request. Future cookie-authenticated resources must add ProcessWire CSRF validation.
- Requests are rate/body limited, responses are `no-store`, and production requires HTTPS.
- Browser CORS is denied by default. Configure exact HTTPS origins one per line; wildcard origins are rejected. Native apps do not require CORS.
- Extend safe resources with `Mercato::headlessApiResource(array $resource, string $type, array $context)`.

For redirect gateways, configure HTTPS universal/app links and resume by polling the opaque checkout; query parameters are not payment truth and webhooks still complete orders while the app is closed. Keep a web fallback.

App Store rules for digital goods/subscriptions are distribution policy, not an API capability. Review current App Review Guidelines and obtain platform/legal advice before enabling external payment for in-app digital consumption.

## Compatibility

`v1` may gain optional fields, resources, error codes, filters, payment modes, or enum values. Existing field meaning and required inputs do not change incompatibly within v1. Clients must ignore unknown fields and safely handle unknown enums. Breaking changes require `/v2`; security fixes may tighten validation, expiry, or rate limits.
