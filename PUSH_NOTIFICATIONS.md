# Native Push Notifications

Mercato exposes transactional Apple Push Notification service (APNs) delivery for native storefront extensions. Push uses the same order lifecycle events as transactional email, but delivery and failure are independent.

## Security contract

- Native clients register through `POST /api/mercato/v1/devices` with an account bearer token or an exact guest-order bearer token plus `order_id`.
- APNs device tokens are encrypted with AES-256-GCM at rest. Hashes are used only for lookup and deduplication; API responses never return the token.
- Registrations are owner-scoped. Revocation at `POST /api/mercato/v1/devices/{registration}/revoke` requires the same account or order authority.
- Payloads contain a bounded event, app route, and opaque reference only. Customer names, email addresses, postal addresses, payment details, signed links, and provider secrets are forbidden.
- Delivery is idempotent per business event and registration. APNs `BadDeviceToken`, `DeviceTokenNotForTopic`, and `Unregistered` responses disable and redact the local token.

## APNs setup

Configure the Team ID, Key ID, exact app bundle ID, sandbox/production environment, and a readable `.p8` key path in Mercato settings. Keep the key outside the web root and deployment artifacts. Verify delivery on a signed physical device before enabling `push_notifications_enabled`; the iOS simulator does not provide a production APNs device token.

The default transport uses APNs token authentication over HTTP/2. A deployment can hook `Mercato::pushTransport` and return another `MercatoPushTransportInterface` implementation without changing the headless API or lifecycle handlers.

## Native request

```json
{
  "token": "<hex APNs device token>",
  "installation_id": "<app-generated stable UUID>",
  "environment": "sandbox",
  "bundle_id": "org.example.store",
  "locale": "en_GB",
  "topics": ["order_updates"]
}
```

Use a unique `Idempotency-Key` header. Guest registration additionally includes `order_id`; account registration does not.
