# Production-readiness acceptance suite

The acceptance suite combines deterministic PHP lifecycle tests with real-browser storefront journeys. The core matrix, least-privilege admin journey, customer identity lifecycle, authorized-refund journey, ownership-boundary journey, physical/digital guest-checkout journey, and presentation-state journey each own a separate fixture state and artifact directory; every profile performs setup, browser assertions, persisted verification, and `finally` cleanup. Core fixtures cover checkout/recovery/inventory, admin fixtures cover permission denial and fulfilment, customer fixtures cover registration/verification/reset/throttling, guest claim, signed documents, and a one-time digital download, refund fixtures cover a full Demo refund, exact replay denial, customer-visible documents, the audit ledger, and exact-once stock restoration, ownership fixtures cover anonymous signed-route privacy, malformed/wrong-purpose/expired codes, cross-customer isolation, and staff denial, guest fixtures cover real physical/digital checkout plus payment/status/receipt/PDF/download/access-recovery expiry and replay boundaries, and presentation fixtures cover empty/loading/long/translated/large catalog, account, admin, and non-production readiness states. Backend coverage also validates the optional McpServer provider contract, PII minimization, exact-variant inventory, payment verification, mutation idempotency, label redaction, tracking, non-regressing fulfilment, transactional email, and structured failures.

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

For a local self-signed certificate, add `MERCATO_E2E_IGNORE_HTTPS_ERRORS=1`. The bundled `mercato.test` development target enables this automatically; release/staging targets must use a trusted certificate. The public native-app tunnel is `https://mercato.smnv.org` and must retain a trusted certificate.

The local Yerd target uses its configured MySQL TCP endpoint. Set `MERCATO_MYSQL_SOCKET` only when an alternative installation explicitly requires a Unix socket.

Production mode must be disabled. Fixture setup refuses to run otherwise. The cleanup uses the generated run ID and exact created page IDs; it never deletes arbitrary catalog or order records. Run against an isolated acceptance database or a disposable staging copy, never a merchant's production database.

Reports are written to `artifacts/e2e/acceptance.json` (machine-readable), `artifacts/e2e/acceptance.md` (human-readable), the Playwright JSON report, HTML report, screenshots, traces, and videos on failure. Every runner row records the scenario, expected result, pass/fail transition, exit status, duration, and diagnostics location. `tests/e2e/coverage.json` is the versioned coverage map.

## Browser and accessibility contract

The blocking core matrix is Chromium desktop, Chromium/Pixel 7, Firefox desktop, and WebKit/iPhone 15. Catalog, product, checkout, private account, validation, and order-success surfaces are checked for horizontal overflow and axe-core serious/critical violations. Dedicated sequential Chromium profiles own isolated admin, customer-lifecycle, refund, ownership-boundary, guest-checkout, and presentation-state data rather than sharing the core fixture file. Customer lifecycle covers enumeration-safe registration/reset, verification and proof replay, effective throttling, guest claim ownership, signed status/receipt/PDF, and exact-once download; admin covers least-privilege denial, keyboard fulfilment, and the customer-visible update; refund covers authorized keyboard submission, replay denial, owner-visible account/status/receipt/PDF state, one refund audit entry, and exact-once stock restoration; ownership boundaries cover private/noindex signed-route success and failure, stable read-only replay, cross-account mass-assignment/claim denial, and direct staff denial; guest checkout covers real physical/digital purchases, ownerless persistence, stock/download exact-once behavior, paid-payment denial, default-safe access recovery, and route expiry; presentation states cover announced loading, explicit empty results, long multilingual content, large lists/pagination, keyboard-scroll regions, and an independently disabled checkout. Backend integration scenarios cover stock races, reservation/release/restock, address/tax/shipping rejection, decline/retry, delayed and duplicate webhook replay, cancellation, refunds, email, exports, signed links, permissions, noindex, analytics, and governed MCP commerce operations.

## CI and release threshold

Ordinary CI validates the versioned acceptance contract and enumerates the Playwright suite without credentials. A release candidate is not shippable until the complete acceptance command passes against the isolated release environment and both reports are retained. Any failed scenario, serious/critical accessibility violation, missing diagnostics artifact, fixture cleanup failure, or unexpected inventory/payment transition blocks release.

Live-provider smoke is separate and excluded from normal tests and CI. It requires an explicit `MERCATO_LIVE_PROVIDER_SMOKE=I_UNDERSTAND_THIS_CREATES_A_REAL_TRANSACTION` flag plus a merchant-specific implementation. Never place that flag in CI.

## Latest complete release evidence

Mercato 1.4.9 commit `710e6e58d7be496b6d2cede0d722dcf60b89012e` was assembled as the locked production ZIP with SHA-256 `9c09bfb941f575e7e0191efae1ffe4034ec01306b4905e166e0dc030023423c6`, deployed byte-for-byte to the isolated non-production site, refreshed as module version 149/schema 12, and passed the complete acceptance command on 2026-09-27. All 29 runner scenarios passed: deterministic backend coverage; the 23-pass/15-deliberate-skip core Chromium, Firefox, and WebKit matrix; six dedicated Chromium journeys; all persistence gates; and exact cleanup. Presentation cleanup also removed three run-owned audit-log lines and left no `e2e-presentation-` residue. The retained report is `artifacts/e2e/release-1.4.9-final-20260927T1724/acceptance.md`. Live-provider smoke remained deliberately disabled.

## Latest targeted presentation-state evidence

On 2026-09-27, `MERCATO_E2E_PROFILE=presentation-states` passed 1/1 Chromium test in 9.1 seconds (9.6 seconds total) against `https://mercato.test`, with no retries, skips, flaky results, or unexpected results. Mobile empty/loading/large catalog, long Japanese/Arabic product content, three account-history pages, and desktop product/order administration passed responsive, serious/critical axe, focus, console, network, and same-origin 5xx gates. Persisted verification retained all 24 products and 14 paid owner-bound orders, preserved UTF-8 content, applied the isolated checkout-maintenance setting, and found zero suspicious run-owned logs. Cleanup removed exactly 14 orders, 24 products, 2 users, and 1 role, restored configuration, and was idempotent. The run exposed and fixed long-title/description overflow in the alternate `art-profile` product presentation and a missing visible label on the products CSV textarea. Evidence is retained in `artifacts/e2e/presentation-states-canonical-20260927T1335/`. This targeted result does not replace the complete acceptance command or live-provider smoke.

## Latest targeted ownership evidence

On 2026-09-27, `MERCATO_E2E_PROFILE=ownership-boundaries` passed 1/1 Chromium test in 5.4 seconds (5.8 seconds total) against `https://mercato.test`. Persisted verification confirmed unchanged order ownership, paid states, and victim profile; the acting customer's revision advanced exactly once to `1`; suspicious run-owned logs were zero. Cleanup removed exactly 3 orders, 3 users, and 1 role with no residual IDs. Evidence is retained in `artifacts/e2e/ownership-canonical-20260927T1232/`. This targeted result does not replace the complete acceptance command or live-provider smoke.

## Latest targeted guest-checkout evidence

On 2026-09-27, `MERCATO_E2E_PROFILE=guest-checkout` passed 1/1 Chromium test in 6.4 seconds (6.9 seconds total) against `https://mercato.test`. Real physical and digital guest checkout created paid orders `4285` and `4286` with owner IDs `0`; physical stock moved from 5 to 4, one digital download event persisted, and both signed-link sets were expired. Suspicious run-owned logs were zero. Cleanup removed exactly 2 orders and 2 products/files with no residual IDs. Evidence is retained in `artifacts/e2e/guest-checkout-canonical-20260927T1310/`. This targeted result does not replace the complete acceptance command or live-provider smoke.
