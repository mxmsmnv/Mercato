# Production-readiness acceptance suite

The acceptance suite combines the deterministic PHP lifecycle tests with real-browser storefront journeys. It creates a uniquely named product and coupon in a non-production ProcessWire installation, switches only the settings needed for Demo Payment, runs the suite, and restores those settings and deletes run-owned pages/orders in a `finally` cleanup. Backend coverage also validates the optional McpServer provider contract, PII minimization, exact-variant inventory, payment verification, mutation idempotency, label redaction, tracking, non-regressing fulfilment, transactional email, and structured failures.

## Local command

Install the locked JavaScript development dependencies and Playwright browsers once:

```bash
npm ci
npx playwright install chromium firefox webkit
```

Run the complete clean-fixture suite:

```bash
MERCATO_E2E_SITE=/absolute/path/to/processwire \
MERCATO_E2E_BASE_URL=https://shop.test \
php scripts/run-acceptance.php
```

For a local self-signed certificate, add `MERCATO_E2E_IGNORE_HTTPS_ERRORS=1`. The bundled `mercato.dev` development target enables this automatically; release/staging targets must use a trusted certificate.

Production mode must be disabled. Fixture setup refuses to run otherwise. The cleanup uses the generated run ID and exact created page IDs; it never deletes arbitrary catalog or order records. Run against an isolated acceptance database or a disposable staging copy, never a merchant's production database.

Reports are written to `artifacts/e2e/acceptance.json` (machine-readable), `artifacts/e2e/acceptance.md` (human-readable), the Playwright JSON report, HTML report, screenshots, traces, and videos on failure. Every runner row records the scenario, expected result, pass/fail transition, exit status, duration, and diagnostics location. `tests/e2e/coverage.json` is the versioned coverage map.

## Browser and accessibility contract

The blocking matrix is Chromium desktop, Chromium/Pixel 7, Firefox desktop, and WebKit/iPhone 15. Catalog, product, checkout, private account, validation, and order-success surfaces are checked for horizontal overflow and axe-core serious/critical violations. The checkout journey covers a deterministic coupon and Demo Payment. Backend integration scenarios cover stock races, reservation/release/restock, address/tax/shipping rejection, decline/retry, delayed and duplicate webhook replay, cancellation, refunds, email, exports, signed links, permissions, noindex, analytics, and governed MCP commerce operations.

## CI and release threshold

Ordinary CI validates the versioned acceptance contract and enumerates the Playwright suite without credentials. A release candidate is not shippable until the complete acceptance command passes against the isolated release environment and both reports are retained. Any failed scenario, serious/critical accessibility violation, missing diagnostics artifact, fixture cleanup failure, or unexpected inventory/payment transition blocks release.

Live-provider smoke is separate and excluded from normal tests and CI. It requires an explicit `MERCATO_LIVE_PROVIDER_SMOKE=I_UNDERSTAND_THIS_CREATES_A_REAL_TRANSACTION` flag plus a merchant-specific implementation. Never place that flag in CI.
