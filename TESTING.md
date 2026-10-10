# Mercato testing plan

## Scope

- Project: Mercato
- Classification: Class D — complex commerce product/platform
- Test owner: Mercato maintainers; release evidence must name the person running the release profile
- Supported ProcessWire versions: 3.0.200 or newer in the 3.x line
- Supported PHP versions: 8.1–8.5
- Uninstall data policy: `preserve-user-data`; normal uninstall deliberately keeps orders, products, fields, templates, and copied storefront templates

## Risk summary

Mercato owns or coordinates money state, immutable order snapshots, inventory,
discounts, tax, shipping, fulfilment, refunds, customer identity, privacy
requests, transactional notifications, public signed links, provider webhooks,
headless checkout, analytics, and governed MCP operations. The highest-risk
failures are duplicate or regressing payment transitions, incorrect totals or
stock, authorization/CSRF bypass, leaked PII or secrets, replayed external
events, destructive install/upgrade/uninstall behavior, and a storefront that
cannot complete checkout accessibly on supported browsers.

Most combinatorial coverage belongs in deterministic PHP and ProcessWire
integration tests. Browser coverage is intentionally limited to critical
journeys and representative presentation; it must not be described as
exhaustive coverage of every rule or provider.

## Test commands

```bash
# PHP syntax for shipped and test PHP, excluding installed dependencies
find . -path './vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l

# Deterministic tests that do not require a ProcessWire site
composer test:unit

# Complete PHP suite against an isolated ProcessWire installation
MERCATO_TEST_SITE=/absolute/path/to/processwire php scripts/run-tests.php

# Prepared presentation-state profile (setup/verify/cleanup may run without a browser)
MERCATO_E2E_SITE=/absolute/path/to/processwire \
MERCATO_E2E_STATE=/tmp/mercato-presentation-state.json \
php tests/e2e/presentation-states-fixtures.php setup
MERCATO_E2E_PROFILE=presentation-states \
MERCATO_E2E_STATE=/tmp/mercato-presentation-state.json \
npx playwright test -c tests/e2e/playwright.config.js
MERCATO_E2E_SITE=/absolute/path/to/processwire \
MERCATO_E2E_STATE=/tmp/mercato-presentation-state.json \
php tests/e2e/presentation-states-fixtures.php cleanup

# Isolated assistive semantics, contrast, and responsive visual evidence
# (uses the same presentation-state setup/verify/cleanup lifecycle)
MERCATO_E2E_PROFILE=assistive-visual \
MERCATO_E2E_ASSISTIVE_VISUAL=1 \
MERCATO_E2E_PRESENTATION_STATE=/tmp/mercato-presentation-state.json \
npx playwright test -c tests/e2e/playwright.config.js

# Guarded local HTTP boundary: two account sessions plus scoped MCP clients
MERCATO_TEST_SITE=/absolute/path/to/processwire \
MERCATO_TEST_HTTP_BASE=https://shop.test \
MERCATO_HTTP_BOUNDARY_CONFIRM=I_UNDERSTAND_THIS_TEMPORARILY_ENABLES_THE_LOCAL_MCP_ENDPOINT \
php tests/MercatoMcpSessionHttpBoundaryIntegrationTest.php

# Guarded fresh ProcessWire 3.0.265 + empty disposable database lifecycle
MERCATO_FRESH_DB_CONFIG_SITE=/absolute/path/to/non-production/processwire \
MERCATO_FRESH_CONFIRM=I_UNDERSTAND_THIS_CREATES_AND_DROPS_A_DISPOSABLE_DATABASE \
php scripts/run-fresh-install.php

# Supported ProcessWire minimum/stable/current fresh-install matrix
MERCATO_FRESH_DB_CONFIG_SITE=/absolute/path/to/non-production/processwire \
MERCATO_FRESH_CONFIRM=I_UNDERSTAND_THIS_CREATES_AND_DROPS_A_DISPOSABLE_DATABASE \
php scripts/run-processwire-matrix.php

# Runtime, dependency, and acceptance-contract checks
composer verify:runtime
composer check:security
composer check:licenses
php scripts/validate-acceptance.php
npx playwright test -c tests/e2e/playwright.config.js --list

# Complete release acceptance profile (creates and removes run-owned fixtures)
MERCATO_E2E_SITE=/absolute/path/to/processwire \
MERCATO_E2E_BASE_URL=https://shop.test \
php scripts/run-acceptance.php
```

The non-production config site's database account must be allowed to create and
drop a database. When that site intentionally uses a schema-scoped account,
provide a local administrative account through `MERCATO_FRESH_DB_ADMIN_USER`
and `MERCATO_FRESH_DB_ADMIN_PASS`; the password is written only to the mode-0600
installer config inside the guarded temporary site and is removed with it.

Install locked JavaScript dependencies with `npm ci` and install Chromium,
Firefox, and WebKit once with `npx playwright install chromium firefox webkit`.
Use `MERCATO_E2E_IGNORE_HTTPS_ERRORS=1` only for an explicitly local
self-signed development certificate. No WireTests suite is currently present;
the repository-native ProcessWire integration scripts are the active boundary
runner.

On macOS 27, TCC can deny command-line Firefox access to the installed
Firefox application's shared support directory. The Playwright configuration
detects that exact denial and launches the bundled browser through
`playwright-firefox-wrapper.sh` with an isolated `PlaywrightFirefox` application
identity. This avoids the real Firefox profile and does not require Full Disk
Access. Other operating systems and readable macOS Firefox installations keep
the normal Playwright launch path.

## Test environment

- Development site: isolated non-production ProcessWire site with the demo storefront installed; the local convention is `https://mercato.test`
- Database isolation: disposable database or dedicated acceptance tenant only; fixture setup refuses production mode
- Administrator account: the fixture runner uses the site's existing superuser internally and never records credentials
- Customer accounts: setup creates two run-owned verified customers and deletes both during cleanup; no durable browser credentials are retained
- Staff/manager accounts: admin acceptance setup creates two isolated run-owned roles and users with exact least-privilege grants, validates the positive and negative permission matrix before browser launch, and deletes all four records during cleanup
- External service fakes: Demo Payment, deterministic tax/shipping/mail/push adapters, fixture webhook payloads, and local ProcessWire storage
- Fixture prefix: `E2E` / `e2e-{run_id}-`; cleanup is restricted to recorded page IDs and matching run-owned order email prefixes

Record the installed Mercato version, ProcessWire/PHP/database versions,
enabled optional modules, relevant non-secret configuration, and exact commit
before a release run. Never run the fixture or browser suite against a merchant
production database.

## ProcessWire boundary coverage

- [x] Module metadata and runtime compatibility are checked by repository tests.
- [x] Installer bootstrap/preflight and schema repair behavior have automated coverage.
- [x] Configuration behavior is exercised by integration tests and acceptance fixture snapshot/restore.
- [x] All 182 stored/default configuration fields, 179 rendered UI inputs, 6 transient actions, and 9 storage-only values have inventory coverage. The lower-level matrix has 53 parameterized normal/boundary/invalid/conflict cases; the 1,134-assertion ProcessWire profile safely mutates and persists all 172 user-configurable non-production settings, verifies public effects and Stripe/Mollie/PayPal readiness, and exactly restores config, runtime URL state, and settings-audit logs.
- [x] Composer/runtime dependencies, licenses, and vulnerability audit have dedicated commands.
- [x] Public service APIs and major provider contracts have deterministic tests.
- [x] Payment, privacy, MCP, account, signed-link, and provider permission boundaries have lower-level tests.
- [x] A guarded real-HTTP boundary profile issues run-owned read/publish MCP credentials, exercises namespaced Mercato read and local-only fulfilment mutation calls, read/publish/admin scope denials, exact-confirmation schema denial, replay/conflict semantics, credential revocation, PII-minimized gateway audit, and exact cleanup. The same profile uses two independent cookie jars to prove login rotation, distinct concurrent sessions, stale profile-revision rejection, and winner preservation. It passed twice consecutively with 26 assertions on 2026-09-27 and restored the disabled MCP endpoint after each run.
- [x] Orders, customers, markets, tax, shipping, analytics, and notification state are saved/reloaded in ProcessWire integration tests.
- [x] Empty and large ProcessWire persistence states have a repeatable boundary profile: empty optional values and zero totals, formatted/unformatted reloads, a 1,200-line UTF-8 order snapshot (470,560 JSON bytes), a 100,500-byte note, analytics/repository projections, the 25-row pagination boundary across 27 orders, and exact run-scoped cleanup. The 2026-09-27 profile passed twice consecutively with zero residual fixtures.
- [x] Upgrade and rollback guards have a configured-site integration test.
- [x] A fresh-install profile automates a pinned ProcessWire 3.0.265 empty database, database/path/token ownership guards, demo/schema/config smoke, idempotent installer repair, uninstall preservation, reinstall, and verified database/site cleanup. The 2026-09-27 local run passed with module version 145/schema 12, 8 demo products, 4 collections, and 3 discounts; the disposable database and site directory were both removed.
- [x] Normal uninstall preservation and reinstall have a separately gated disposable-site test (`MERCATO_TEST_INSTALL_LIFECYCLE=1`); it must not be enabled on a shared or production site.
- [x] Ordinary CI runs guarded fresh databases at pinned ProcessWire 3.0.200, 3.0.246, and 3.0.265 commits on PHP 8.1, 8.3, and 8.5 respectively. The 2026-09-27 local matrix passed all three boundaries with module version 146/schema 12 and verified zero residual databases, temporary sites, or loopback installer processes.

The uninstall policy is preservation, not schema cleanup. Any future destructive
cleanup tool requires its own backup, explicit confirmation, and disposable-site
tests.

## Critical automated journeys

### Anonymous storefront checkout

- Role: anonymous shopper
- Starting state: unique physical product with stock 50, unique 10% coupon, Demo Payment enabled, optional accounts
- Actions: add product, trigger native required-field validation, apply coupon, submit customer/fulfilment data, accept policy, complete Demo Payment
- Expected UI result: success URL and order confirmation are visible
- Expected stored result: paid order contains the coupon and product snapshot; inventory decreases exactly once
- Access/security assertion: private checkout/success data is not exposed in scripts; checkout/account surfaces are noindex
- Cleanup: orders matching the run email prefix plus the exact recorded product/coupon IDs are removed; changed configuration is restored

### Native/headless guest checkout

- Role: anonymous native client
- Starting state: same isolated product and coupon
- Actions: quote, create checkout with idempotency key, replay create, deny wrong token, complete, replay completion, read order
- Expected API result: stable checkout identity, paid order, replay markers, and no customer email in the order representation
- Expected stored result: one paid order and one inventory adjustment
- Access/security assertion: incorrect bearer token returns not found and client totals are not trusted
- Cleanup: same run-scoped cleanup

### Browser presentation matrix

- Roles: anonymous/public plus two isolated verified customers
- Browsers/viewports: Chromium desktop, Pixel 7 Chromium, Firefox desktop, iPhone 15 WebKit
- Surfaces: catalog, product, checkout, unauthenticated and authenticated account, validation state, payment recovery, and successful order
- Assertions: successful HTTP response, no horizontal overflow beyond 2 px, and no serious/critical axe-core violations

### Presentation state matrix

- Roles: anonymous mobile catalog visitor, verified mobile customer, and isolated desktop product/order manager
- Starting state: 24 run-owned products, including long unbroken multilingual content; 14 paid owner-bound orders; five-row account pagination; checkout disabled with a unique non-production maintenance message; outbound notifications disabled
- Surfaces: empty filtered catalog, announced filter-loading transition, large catalog, long/translated product detail, three account-history pages, admin product list, and admin order list
- Assertions: explicit status/busy semantics, keyboard-focusable scroll regions, UTF-8 content preservation, long-token wrapping, pagination cardinality, serious/critical axe and horizontal-overflow gates, and no console/document/XHR/fetch or same-origin 5xx failures
- Readiness assertion: `checkout_enabled=false` takes effect through `MercatoOperationalService`, preserves catalog/admin/account availability, and restores the exact original configuration during cleanup
- Cleanup: exact 14 orders, 24 products, 2 users, 1 role, configuration snapshot, state file, and any run-owned error evidence
- Status: passed on 2026-09-27. The single Chromium scenario completed in 9.1 seconds (9.6 seconds including runner overhead) with no retries, skips, flaky results, or unexpected results; persisted verification and exact cleanup also passed. Evidence is retained in `artifacts/e2e/presentation-states-canonical-20260927T1335/`

### Failed-payment recovery

- Role: verified customer, isolated from a second verified customer
- Starting state: a failed Demo Payment order per customer plus an expired failed order, all run-owned
- Actions: log in, confirm order-list ownership, deny malformed and expired links, retry the original order through Demo Payment, deny replay, reload the account
- Expected UI result: recovery checkout and success are usable on Chromium desktop and iPhone 15 WebKit; the owner sees `paid` and never sees the other customer's invoice
- Expected stored result: each recovered order is paid and complete, inventory is adjusted exactly once, consumed links are no longer payable, and the expired order remains failed and unadjusted
- Diagnostics: console errors, same-origin 5xx responses, document/XHR/fetch failures, horizontal overflow, serious/critical axe violations, and run-owned ProcessWire log errors are blocking
- Cleanup: all recovery orders and both customer users are deleted by exact recorded identifiers/run prefix

### Least-privilege administration and customer-visible fulfilment

- Roles: isolated order/customer viewer on a mobile viewport, isolated fulfilment manager on desktop, and the isolated owning customer on mobile
- Starting state: one run-owned paid Demo order; notification sending is disabled and the existing notification configuration is snapshotted
- Actions: staff inspects the order, is denied refund/reconciliation/privacy/notification/fulfilment mutations, manager inspects and keyboard-submits a shipped transition with tracking and notes, customer signs in and observes the result
- Expected stored result: fulfilment/tracking/note persist, payment remains paid, refund totals remain zero, customer identity and notification templates remain unchanged
- Boundaries: the browser covers routed admin actions; MCP has no browser surface and remains covered by scoped deterministic authorization and mutation contracts
- Diagnostics: Mercato-scoped serious/critical axe violations, horizontal overflow, console errors, same-origin 5xx responses, document/XHR/fetch failures, and run-owned ProcessWire log errors are blocking
- Cleanup: the exact order, three users, two roles, and snapshotted configuration are restored or removed

### Customer identity lifecycle, guest claim, and private documents

- Roles: newly registered customer plus an isolated verified second customer on desktop/mobile contexts
- Starting state: one run-owned unclaimed paid digital order and one run-owned digital product with a one-download limit; outbound notifications are disabled
- Actions: register and repeat registration, deny unverified login, reject malformed/consumed verification proof, enforce bounded login attempts, request/reset a password without account enumeration, reject the old password, request and complete an owner-bound guest claim, deny another customer and replay, open signed status/receipt/PDF, download once, and deny download replay
- Expected stored result: verification/reset/claim tokens are consumed, the guest order is attached only to the intended account, paid state does not regress, and exactly one download event is recorded
- Diagnostics: desktop/mobile overflow, serious/critical axe violations, keyboard submission, console/network/5xx failures, signed-route privacy, PDF headers/content, and run-owned ProcessWire logs are blocking
- Cleanup: the exact order, two users, digital product/file, and snapshotted configuration are restored or removed

### Authorized full refund across manager and customer sessions

- Roles: isolated least-privilege refund manager on desktop and the isolated owning customer on mobile
- Starting state: one run-owned paid Demo order with one physical product unit already removed from stock; outbound notifications are disabled
- Actions: customer observes the paid baseline, manager confirms a full refund from the order detail with the keyboard, the exact POST is replayed and denied, and the customer reloads account, signed status, HTML receipt, and PDF receipt
- Expected stored result: payment is `refunded` and incomplete, one refund detail and one `refund_issued` event exist, pending refund is zero, and inventory is restored from 9 to 10 exactly once
- Diagnostics: Mercato-scoped serious/critical axe violations, desktop/mobile overflow, keyboard focus, console errors, same-origin 5xx responses, document/XHR/fetch failures, PDF headers/content, and run-owned ProcessWire log errors are blocking
- Cleanup: the exact order, two users, refund role, product, and snapshotted configuration are restored or removed

### Anonymous signed routes and cross-role ownership boundaries

- Roles: anonymous mobile visitor, isolated second customer on desktop, and isolated staff user with Mercato dashboard access but no order/customer/refund permission
- Starting state: separate paid orders for the owner and second customer plus an owner order older than the signed-link retention window; notifications are disabled
- Actions: deny anonymous account mutation, exercise reusable read-only signed status/receipt/PDF links, deny malformed/wrong-purpose/expired codes, inject another user/order ID into a self-profile update and deny stale replay, deny another customer's order claim, and deny direct staff order inspection/refund POST
- Expected stored result: all orders remain paid with original owners, the victim account is unchanged, only one self-owned profile revision persists, and unauthorized staff permissions remain absent
- Privacy assertions: every signed HTML/PDF success and failure response is private/no-store and noindex; anonymous/account/staff pages do not disclose unrelated invoices; possession of a valid signed capability remains intentionally distinct from account ownership
- Diagnostics: responsive and serious/critical axe gates, console errors, same-origin 5xx responses, document/XHR/fetch failures, run-owned ProcessWire logs, and exact cleanup are blocking
- Cleanup: the exact three orders, three users, one role, and snapshotted configuration are restored or removed

### Physical and digital guest checkout route lifecycle

- Roles: isolated anonymous desktop physical shopper and isolated anonymous mobile digital shopper
- Starting state: one stocked physical product, one digital product with a one-download file, Demo Payment only, notifications/analytics disabled, one-day signed-link retention, and core access recovery enabled without a project credential hook
- Actions: complete both real storefront checkouts, deny paid payment-link replay, open private status/receipt/PDF routes, verify default-safe access-recovery unavailability, consume the digital download once and deny replay, then expire both orders and deny every signed document/recovery route
- Expected stored result: exactly two ownerless paid orders, physical stock decremented once, exactly one digital download event, expired signed routes, and no payment/ownership regression
- Privacy assertions: signed success and failure responses, including download success/denial, are no-store/noindex; invoices and guest email are not exposed on denied/payment/success surfaces
- Diagnostics: desktop/mobile overflow, serious/critical axe violations, console errors, unexpected resource `404`, same-origin 5xx, document/XHR/fetch failures, run-owned ProcessWire logs, and exact cleanup are blocking
- Cleanup: the exact two orders, physical/digital products and uploaded file, plus snapshotted configuration are restored or removed

## Agent-led release scenarios

These checks are required release evidence until converted into deterministic
automation. A checked box belongs in a dated release report, not permanently in
this plan.

## Exhaustive closure plan — reopened 2026-09-27

The 1.4.9 acceptance profile is a passing release baseline, not evidence that
every commerce combination or external provider has been exercised. Testing
continues until every row below is `tested`, `not applicable`, or `blocked`
with a reproducible reason and an evidence path. `Not tested` is never a
release-complete state.

| Priority | Domain | Required closure evidence | Status |
|---|---|---|---|
| P0 | Browser order-to-cash | Real browser checkout through persisted paid order for physical, digital, service, exact variant, guest, and authenticated customer paths; verify authoritative totals, inventory/download/ownership, documents, logs, and cleanup | tested — dedicated physical/digital guest and authenticated exact-variant profiles plus the six-order fulfilment matrix cover physical, digital, service, mixed, guest, and customer checkouts with persisted owner/product/amount/address/tax/inventory/log assertions and exact cleanup |
| P0 | Fulfilment combinations | Carrier delivery, store pickup, local delivery, and no-shipping digital/service orders across valid/invalid address and readiness states; verify quote snapshot and customer/admin presentation | tested — `tests/e2e/fulfilment-matrix.spec.js` and its fixture complete six paid browser checkouts; the six-order ProcessWire matrix covers invalid address/country/postcode, quote, replay, cancellation, and snapshot boundaries |
| P0 | Stock policy combinations | Deny, backorder, preorder, low/out-of-stock, reservations, concurrent checkout, cancellation, expiry, refund, and exact-variant inventory transitions | tested — the 14-movement/9-event ProcessWire matrix covers the complete policy combination set; browser profiles separately verify physical, digital, service, mixed, and exact-variant paid inventory effects. Per the Class D standard, combinatorial stock rules remain below the browser layer |
| P0 | Payment gateways | Stripe, Mollie, and PayPal test-mode protocols through deterministic local provider emulators: create/return/capture, decline, cancel, timeout, ambiguous response, signed/verified webhook, duplicate/out-of-order replay, refund, and reconciliation | tested for deterministic local emulators — `tests/MercatoProviderEmulatorIntegrationTest.php` passes 70 no-network assertions; real provider sandbox HTTP/hosted surfaces remain blocked without credentials |
| P0 | Order lifecycle | Pending, failed, paid, partially refunded, refunded, cancelled, recovery, fulfilment transitions, return/cancellation requests, notification consequences, and non-regression under replay | tested — the 71-assertion persisted profile covers three orders, all listed transitions and requests, exact-once notification consequences, replay/non-regression, PII-minimized logs, and exact cleanup |
| P1 | Discounts/tax/markets | No discount, percentage, fixed, free shipping, target/limit conflicts; manual, Stripe Tax, and Quaderno tax; default/non-default market and currency snapshots through checkout | tested — a 100-assertion real ProcessWire checkout-engine profile persists six paid Demo orders across no/%/fixed/free-shipping discounts, manual/provider tax, GBP default and explicit USD market snapshots, conflicts and exact cleanup. The dedicated browser/HTTP profile completes Stripe Tax and Quaderno quote, commit, paid order, full refund, inventory restoration, private status, idempotency, a11y/responsive, and exact cleanup through deterministic loopback emulators. Non-default storefront UI is not applicable because the documented non-default market contract is native/headless only |
| P1 | External delivery boundaries | Deterministic SMTP inbox, push device, carrier label/tracking webhook, analytics sink, storage/PDF, and MCP HTTP emulator runs with secret/PII leakage gates | tested — the 50-assertion local integration covers mail timeout/retry/capture, push replay, carrier partial failure/idempotency/signed tracking, analytics isolation, PDF bytes/hash/readback, leakage gates, and exact cleanup; guarded MCP Streamable HTTP is covered separately |
| P1 | Cross-browser checkout | Critical checkout success, validation failure, recovery, and private-document paths in Chromium, Firefox, and WebKit with persisted-state verification | tested — the dedicated three-engine profile passes native validation, cart/cache continuity, Demo success, failed-payment recovery/replay denial, signed status/receipt/PDF privacy, a11y/responsive/network gates, six persisted paid orders, exact inventory, PII-safe logs, and exact cleanup |
| P1 | Keyboard and assistive UX | Keyboard-only checkout/account/admin with visible focus and no trap; VoiceOver names, errors, status/live announcements, reading order, and contrast on critical screens | tested for keyboard/AX/DOM/contrast/reflow; blocked for actual VoiceOver speech — automated keyboard-only checkout/account/admin, names, roles/states, hidden/exposed content, native/server errors, live-region semantics, WCAG AA contrast, and 320/390/768/1440 reflow pass. Spoken timing/deduplication, rotor order, speech naturalness, and VO cursor retention require interactive macOS VoiceOver authorization and are not claimed |
| P2 | Visual/state breadth | Every admin/storefront screen in empty, loading, normal, error, long/translated, large-data, and responsive states; dark mode only if declared supported | tested / reasoned N/A — the fail-closed 38-screen/304-cell inventory records 236 browser-evidenced and 68 reasoned N/A cells, with zero planned or fixture-ready gaps. The expanded public profile passed every applicable state across 15 storefront/public routes at 1440/390 with persisted checkout and exact cleanup. Normal/error/long/translated/large admin coverage passed all 22 HTML routes plus module settings; a guarded fresh ProcessWire database then passed every applicable empty admin route, 20 permission denials, responsive/AX/contrast gates, exact fixture cleanup, and complete database/site teardown. Dark mode remains N/A because no store-wide contract exists |
| P2 | Real provider sandbox | Optional smallest-value provider smoke using already-authorized official sandbox credentials and test recipients only; never production or real money | blocked — no Stripe, Mollie, or PayPal test credentials are configured on the dev site; emulator coverage proceeds without waiting |

Execution order is P0 order-to-cash matrix, P0 gateway emulators, P0 lifecycle
matrix, P1 browser/accessibility and external-boundary orchestration, then P2
screen inventory. Each block must add deterministic regression coverage,
persisted-state assertions, exact cleanup, and dated evidence before its status
changes.

### Administrator session

- [x] Configure non-production payment, tax, shipping, email, and checkout readiness without exposing secrets. The 1,127-assertion configuration profile safely persists/restores all 171 user-configurable non-production settings, readiness gates verify provider dependencies without printing secrets, and browser presentation proves checkout-maintenance effects.
- [x] Exercise a failed payment and recovery/retry path, then verify reconciliation and non-regressing state. Dedicated Chromium/WebKit recovery, the three-engine checkout profile, the 71-assertion persisted lifecycle and reconciliation matrices prove one successful recovery, replay denial, exact-once inventory, and no regression under delayed/out-of-order/repeated states.
- [x] Exercise MCP mutations through an authorized scoped client; the browser has no MCP surface. Completed through the guarded real Streamable HTTP boundary on 2026-09-27 with read/publish/admin scope gates and no external side effects.

### Customer session

- [x] Inspect session-cookie rotation directly and exercise concurrent profile revision conflict. Completed on 2026-09-27 through two independent real HTTP cookie jars; both login cookies rotated, remained distinct, and the stale revision could not overwrite the winning update. Registration, verification, login/reset, profile update, logout, guest claim, documents, downloads, enumeration safety, and rate limiting are automated.

### Anonymous session

- [x] Complete guest physical and digital checkout; verify signed status, receipt, payment, download, and access-recovery routes. The isolated Chromium profile passed with two ownerless paid orders, exact stock/download transitions, route expiry, and exact cleanup on 2026-09-27.
- [x] Expired, malformed, and wrong-purpose signed routes fail privately with no-store/noindex headers; one-time verification/reset/claim/download proofs deny replay, while durable order status/receipt capabilities remain intentionally reusable and read-only until expiry.

### Cross-role or multi-user scenario

- [x] Customer places an order; authorized staff refunds it; the isolated customer session observes the correct update exactly once. Both refund and fulfilment are automated with isolated roles and persisted-state gates.
- [x] A second customer and an unauthorized staff account cannot access or mutate the first customer's records. The isolated Chromium ownership-boundary profile passed with persisted-state and exact-cleanup gates on 2026-09-27.

### Presentation

- [x] Representative mobile public/catalog/product/account and desktop product/order-admin layouts passed the targeted presentation-state Chromium profile.
- [x] Dedicated Chromium keyboard-only checkout/account/admin journeys pass with real Tab/Shift+Tab/Enter/Escape input, visible focus on Mercato-owned controls, native validation focus, accessible alert/status feedback, no focus traps, and 320 CSS-pixel reflow. The host ProcessWire login remains keyboard-reachable; its visual design is outside Mercato's ownership.
- [x] Chromium accessibility-tree names, errors, status/live announcements, WCAG AA automated contrast, and 320/390/768/1440 reflow evidence passed in the isolated assistive/visual profile. Manual Safari + VoiceOver reading order and announcement behavior remains open and is not represented by this check.
- [x] Browser console errors, failed document/XHR/fetch requests, same-origin 5xx responses, and run-owned ProcessWire logs were inspected with zero suspicious failures.
- [x] Empty, loading, long-content, translated, and large-data states passed in the isolated targeted profile.
- [x] Dark mode is not applicable: Mercato declares no store-wide dark theme or configuration contract. The fail-closed visual inventory records the two incidental OS-adaptive rules and requires this decision to be revisited if a `dark_mode` setting or supported theme contract appears.

## Failure paths

- [x] Invalid/missing checkout input and invalid tax/shipping destinations have automated coverage.
- [x] Wrong headless token, permission checks, PII minimization, and signed-link rules have automated lower-level coverage.
- [x] Duplicate checkout/completion/email/MCP mutations have automated coverage; local Stripe/Mollie/PayPal emulators cover verified/invalid/malformed/unknown callbacks, stable idempotency IDs, duplicate/out-of-order transitions, refund IDs, and reconciliation. Real provider sandbox HTTP delivery remains separately blocked without credentials.
- [x] Demo decline/recovery, full refund, replay, status mapping, persisted requires-action/processing/delayed/out-of-order transitions, partial and pending refunds, cancellation finalization, reconciliation replay, and exact-once inventory are covered with real ProcessWire records and deterministic provider adapters. Provider-hosted browser surfaces remain separately blocked without authorized sandbox credentials.
- [x] Provider timeout/retry policies and deterministic unavailable/failing adapters have lower-level coverage.
- [x] The degraded-dependency matrix covers tax and shipping transient retry/timeout/fallback plus fail-fast contract defects; transactional email retry/replay/redaction plus fail-fast contract defects; retryable push failure followed by successful exact-once replay; partial analytics-adapter isolation; background-job exception/result retry, fail-fast contract defects, partial isolation, advisory-lock contention and crash resume; and payment-reconciliation invalid/non-finite amount boundaries. All adapters are deterministic and local, and the ProcessWire profile restores run-owned pages, rows, logs, locks, and template overrides.
- [x] The gateway request boundary has a 34-assertion matrix for retry bounds/attempt numbering, invalid responses, preserved exception causes, ambiguous post-deadline mutation handling, invalid/non-finite timeouts, fail-fast programming errors, and empty valid responses. Provider status normalization covers 65 generic/Stripe/Mollie/PayPal mappings and unknown fallbacks.
- [x] Logs, events, labels, API data, analytics, and MCP payloads have redaction/minimization tests.
- [x] The primary ProcessMercato route is denied to an anonymous browser session and resolves to the ProcessWire login surface.
- [x] Browser-level account registration without a CSRF token is rejected and verified not to persist a customer account.
- [x] Browser-level verified customer login, profile/address update, owned order history, logout, and cross-customer order-list isolation are automated.
- [x] Browser-level customer payment retry, persisted paid state, replay denial, malformed/expired link denial, owner-list isolation, and exact-once stock adjustment are automated on Chromium desktop and iPhone 15 WebKit.
- [x] Browser-level isolated staff/manager/customer sessions automate order inspection, least-privilege denial of refund/reconciliation/privacy/notification/fulfilment actions, keyboard fulfilment, persisted non-regressing payment state, and the customer-visible shipped update.
- [x] Browser-level registration/verification/reset, effective login throttling, enumeration-safe duplicate/reset requests, owner-bound guest claim, malformed/replayed proof denial, signed status/HTML/PDF receipt, and exact one-time digital download are automated with persisted-state and cleanup gates.
- [x] Browser-level authorized full refund, exact POST replay denial, customer-visible account/status/HTML/PDF updates, one refund ledger/audit event, and exact-once stock restoration are automated with isolated manager/customer sessions and cleanup gates.
- [x] Browser-level anonymous signed-route privacy, malformed/wrong-purpose/expired HTML/PDF denial, durable read-only capability replay, cross-customer list/profile/claim isolation, stale profile replay denial, and direct unauthorized-staff route/mutation denial are automated with persisted-state and cleanup gates.
- [x] Browser-level physical and digital guest checkout, paid-payment replay denial, private status/receipt/PDF/download/access-recovery routes, one-time download replay denial, signed-route expiry, ownerless persistence, and exact stock adjustment are automated with desktop/mobile contexts and cleanup gates.
- [x] Every current admin mutation handler and privileged route has a deterministic authorization contract requiring its exact permission and CSRF enforcement; real role grants are also checked by the install lifecycle profile.
- [x] All 23 bundled localization CSV catalogs have deterministic structural coverage for identical 1509-entry inventories, source hashes, placeholders, HTML tag shape, and non-empty translations.
- [x] Discount rules have a standalone 58-assertion ProcessWire matrix covering activation boundaries, percentage/fixed/free-shipping calculations, non-finite inputs, product/collection/customer targets, minimum totals, global/per-customer paid usage, non-default-market conflicts, PII-minimized audit records, repeatability, and exact fixture/log cleanup.
- [x] Browser empty/loading/long/translated/large-data and keyboard-scroll state coverage passed as one isolated Chromium profile with deterministic presentation contracts, 24 products, 14 paid owner-bound orders, persisted verification, and exact idempotent cleanup. Degraded dependencies remain a separate backend profile.
- [x] Queue/scheduler concurrency, bounded retry, partial-failure isolation, attempt metadata, advisory-lock contention, SIGKILL crash-resume, and cleanup have a dedicated two-process ProcessWire integration profile.
- [x] Carrier, pickup, and local-delivery fulfilment have both a six-order ProcessWire matrix and a six-order Chromium checkout matrix across physical, digital, service, and mixed carts. Browser persistence verifies guest/customer ownership, product and method snapshots, address requirements, shipping/tax, exact inventory, logs, responsive/a11y gates, and cleanup; lower-level coverage adds invalid address/country/postcode, replay, and cancellation boundaries.
- [x] The persisted payment lifecycle has a 71-assertion ProcessWire profile covering pending/requires-action/processing/paid/failed, delayed and out-of-order success, payment recovery, cancellation, fulfilment, returns, partial/pending/full refunds, reconciliation non-regression, exact-once notifications, PII-minimized logs, exact fake boundaries, and zero-residual cleanup.
- [x] Deterministic external delivery boundaries pass 50 assertions for captured text/HTML mail and retry, push timeout/replay, carrier label/tracking partial failures and signed replay, analytics timeout/sanitization, PDF byte/hash/path/readback, leakage gates, and exact file/DB/config/log cleanup. No real email, push, carrier purchase, analytics endpoint, or external storage was used.
- [x] Checkout economics pass 100 assertions with six real paid Demo orders: no, percentage, fixed and free-shipping discounts; manual and deterministic-provider tax; included/excluded tax; default GBP and explicit non-default USD product/shipping/item/tax/order snapshots; invalid market pricing, stale-cart and discount conflicts; and exact 12-page/cache/lock/log/config/cart cleanup.
- [x] The dedicated cross-browser checkout profile passes in Chromium, Firefox and WebKit. Each engine completes native validation failure, paid Demo checkout, signed private status/receipt/PDF access, failed-payment recovery and replay denial; verification proves six unique paid orders, six exact inventory movements, consumed recovery tokens, PII-safe logs, and removal of ten run-owned orders plus all supporting fixtures.
- [x] A fail-closed visual-state manifest inventories 38 Mercato-owned HTML screens/routes and rejects missing shipped templates, admin routes, state classifications, evidence references, or unjustified dark-mode/VoiceOver claims. It now records 236 browser-evidenced and 68 reasoned N/A cells with zero planned/fixture-ready gaps. Expanded public evidence is retained at `artifacts/e2e/public-visual-expanded-20260927T2111/`; normal and settings-state admin evidence is at `artifacts/e2e/admin-visual-expanded-20260927T2117/`. The guarded empty-admin run additionally passed inside a fresh ProcessWire 3.0.265 database and removed the exact database and temporary site afterward.
- [x] Persisted quote lifecycle coverage passes twice and in the complete configured-site suite: immutable acceptance policy, active reservations after configuration changes, exact replay, invalid transitions, immediate explicit expiry, signed-token ownership, conversion side effects, redacted notification logs, and exact zero-residual cleanup.
- [x] Notification template and mail-layout administration has a 39-assertion least-privilege profile covering the dedicated permission, CSRF/config immutability, invalid input, subject normalization, markup sanitization, persistence/runtime parity, escaped preview, reset, and exact restoration.

## External services

| Service | Ordinary test substitute | Live test policy |
|---|---|---|
| Payments (Stripe/Mollie/PayPal) | Demo Payment, gateway request fakes, signed fixture callbacks | Separate merchant-specific smallest-amount smoke with explicit `MERCATO_LIVE_PROVIDER_SMOKE=I_UNDERSTAND_THIS_CREATES_A_REAL_TRANSACTION`; never CI |
| Transactional email | deterministic fake transport and renderer tests | Explicitly authorized test-recipient smoke only; never send ordinary tests to real customers |
| Tax | manual provider and deterministic fake provider | Provider sandbox only with isolated credentials |
| Shipping/carriers | deterministic adapter for quote/shipment/label/tracking | Provider sandbox only; never buy a real label in ordinary tests |
| Push notifications | deterministic fake transport | Explicitly authorized test device only |
| Analytics | data-layer/first-party deterministic adapters | No production analytics from tests |
| MCP server | provider contract/integration fakes with scoped operations | Isolated non-production credentials only |
| Object/file storage and PDFs | local ProcessWire files and deterministic renderer | No production bucket mutation |

## Cleanup

- [x] The `1.5.5` acceptance report confirms configuration restoration.
- [x] The `1.5.5` acceptance report confirms only run-owned pages/orders were removed.
- [x] Run-owned users, roles, products/files, MCP clients/audit rows, operation keys, cookie files, notification state, and queued/background fixtures were removed or exactly restored.
- [x] Browser workers, commands, mock servers, and watchers were stopped after the `1.5.5` release run; the guarded empty-admin profile also removed its disposable database and site.
- [x] No real email, payment, label purchase, push, webhook, analytics, or production storage side effect occurred; Demo Payment and deterministic/local adapters were used.

The fresh-install profile owns its whole temporary site and database rather than
individual content fixtures. Cleanup is allowed only when the generated
database name, temporary path, and in-database random ownership token all match;
the runner then verifies that both the database and site directory are gone.

Cleanup failure blocks release even when functional assertions passed. Preserve a
failed fixture only when needed for diagnosis, record every retained identifier,
and remove it with the same scoped mechanism after investigation.

## Release evidence

Store these fields in the retained release report; do not pre-fill them from a
different run:

- Commit SHA and dirty status
- Installed module version and schema version
- ProcessWire, PHP, database, Node, Playwright, and browser versions
- Site/tenant identity and confirmation that production mode was off
- Commands, exit codes, durations, and skipped scenarios
- Browser roles, isolated sessions, and viewports
- Critical journeys and agent-led scenarios completed
- Defects found, fixes applied, and regression tests added
- Untested/blocked areas and reason
- Remaining fixtures and restored configuration status
- `artifacts/e2e/acceptance.json`, `acceptance.md`, Playwright JSON/HTML, screenshots, traces, videos, relevant sanitized logs, and any manual accessibility notes

A release candidate is blocked by any failed scenario, unexpected skip, missing
diagnostic artifact, serious/critical accessibility violation, incorrect
inventory/payment transition, cleanup failure, or unresolved high-risk gap.

## Latest complete release evidence

### 1.5.5 release evidence (2026-09-27)

- Runtime/test commit `d59b4cae70f788b49bb9f3213d2f43fd0ed4d581` is
  published on `main`. `dist/mercato-1.5.5.zip` has SHA-256
  `15985393861d4d55a04c3ba711923a8e9b8a81044df50167a570058e2a3d8bff`;
  its runtime tree and every bundled storefront template are byte-identical to
  the isolated `https://mercato.test` deployment. The refreshed site reports
  module version `155`/schema `12` with production mode off.
- The complete release report at
  `artifacts/e2e/release-1.5.5-20260927T2121/acceptance.md` passed all 51
  scenarios: the complete configured PHP suite, 25 core browser tests with 15
  deliberate dedicated-profile exclusions, the Chromium/Firefox/WebKit
  checkout boundary, all dedicated commerce profiles, persisted-state gates,
  and exact cleanup.
- The fail-closed visual inventory is fully classified: all 304 cells across
  38 Mercato-owned screens/routes are either browser-evidenced (`236`) or
  reasoned not applicable (`68`); no `planned` or `fixture_ready` cell remains.
  The expanded public run at
  `artifacts/e2e/public-visual-expanded-20260927T2111/` covers applicable
  empty/loading/normal/error/long/translated/large/responsive states. The
  normal/settings admin run at
  `artifacts/e2e/admin-visual-expanded-20260927T2117/` covers all 22 HTML
  routes, module readiness/error and long/translated values, responsive
  presentation, permissions, AX/contrast, persistence, and cleanup.
- Genuine admin empty states passed in a guarded fresh ProcessWire 3.0.265
  site/database. Evidence is retained at
  `artifacts/e2e/empty-admin-1.5.5-20260927T2127/`; the run verified zero
  product/order/quote fixtures, every applicable empty route, 20 permission
  denials, desktop/mobile AX and overflow, exact fixture cleanup, and removal
  of the owned database and temporary site.
- Defects found and fixed in this cycle were long collection-name overflow in
  home tiles, collection detail, mobile navigation, and footer links; low
  contrast in active administration tabs, Launch table links, notification
  labels, and module-settings host controls; and the lack of a guarded browser
  handoff inside the disposable fresh-install lifecycle. Regressions and the
  final release profile passed after each correction.
- Remaining non-claims are explicit blockers rather than open matrix cells:
  real provider-hosted sandbox flows require authorized Stripe/Mollie/PayPal
  credentials, while actual VoiceOver speech timing, rotor order, and cursor
  retention require interactive macOS assistive authorization. Deterministic
  provider emulators and DOM/AX/keyboard/contrast coverage remain passing.

### 1.5.4 release evidence (2026-09-27)

- Runtime/test commit `70af90681b8edf7f6b7f2188b16991182c6f0aa9` is
  published on `main`. `dist/mercato-1.5.4.zip` has SHA-256
  `fef36ca71e414ea1ca0c12e44d4a5395dfd91a298f94e41dc7656de2c3eeb13d`;
  its complete runtime tree and all storefront templates are byte-identical to
  the isolated `https://mercato.test` deployment. The refreshed site reports
  module version `154`/schema `12` with production mode off.
- The complete acceptance report at
  `artifacts/e2e/release-1.5.4-20260927T2051/acceptance.md` passed all 51
  runner scenarios. It includes the complete configured PHP suite, 25 core
  browser tests with 15 deliberate dedicated-profile exclusions, Chromium,
  Firefox, and WebKit checkout/recovery/private-document coverage, every
  persisted-state assertion, and exact cleanup for every fixture graph.
- The dedicated public visual profile passed 15 storefront/public screens at
  1440 and 390 pixels with axe, contrast, overflow, console, network,
  persisted-checkout, inventory, and exact-cleanup gates. The admin visual
  profile passed all 22 HTML routes plus module settings, desktop/mobile
  reflow, 20 least-privilege denials, accessibility gates, persisted state,
  and exact cleanup. Canonical evidence is retained at
  `artifacts/e2e/public-visual-20260927T2047/`,
  `artifacts/e2e/admin-visual-20260927T2123/`, and the release report above.
- Defects found and fixed in this cycle include insufficient contrast on
  collection, checkout-summary, content-CTA, and notification-navigation
  surfaces; long multilingual token overflow; inaccessible narrow quote,
  order, receipt, report, and diagnostic scroll regions; unnamed or
  overflowing module-settings controls; missing semantic theme kicker hooks;
  stale public-fixture cache and imprecise cleanup; and a crash-resume test
  race after worker termination. Targeted regressions and the full acceptance
  profile passed after the corrections.
- The fail-closed 38-screen/304-cell visual inventory now records 183
  browser-evidenced cells, 19 fixture-ready cells, 43 planned cells, and 59
  reasoned N/A cells. This release therefore does not claim absolute visual
  exhaustiveness: isolated-empty admin execution remains intentionally unrun
  on the shared site, real provider-hosted sandbox flows remain blocked
  without authorized credentials, and actual VoiceOver speech timing/rotor
  behavior remains blocked on interactive macOS assistive authorization.

### 1.5.3 release evidence (2026-09-27)

- Runtime/test commit `b9798d521f4474a9b9330e6c42bf8041626ed4b7` plus
  the addressless-digital regression correction at
  `4e07f5dd509f872b1ef485fc7fbfb5f6f32c2c40` are published on `main`.
  `dist/mercato-1.5.3.zip` has SHA-256
  `5fae583fc18525673c41df22130c888103295811aeb8b302acfe33a8ca3f7a29`;
  its complete runtime tree is byte-identical to the isolated dev deployment,
  whose refreshed state is module version `153`/schema `12` with production
  mode off. All ten consuming-site storefront templates are byte-identical to
  their release sources.
- The complete acceptance report at
  `artifacts/e2e/release-1.5.3-final-pass-20260927T1952/acceptance.md` passed
  all 39 runner scenarios. Core passed 25 tests with 15 deliberate
  cross-project exclusions; Firefox desktop, Chromium desktop/mobile, and
  WebKit mobile surfaces passed. Eight dedicated business profiles and every
  persisted-state and exact-cleanup gate passed.
- The new browser fulfilment profile created and verified six paid orders:
  carrier guest, pickup customer, local guest, digital customer, service
  guest, and mixed customer. It proved owner/product/method/address/shipping/
  tax/inventory snapshots, cache privacy, a11y/responsive behavior, clean
  browser/server logs, and removal of six orders, three users, and three
  products. The consolidated lifecycle profile passed 71 assertions and the
  local external-delivery profile passed 50 assertions with exact cleanup.
- Defects found and fixed in this cycle were stale cached empty checkout,
  hidden pickup controls remaining accessibility-focusable, unavailable local
  delivery before postcode entry, incorrect pickup/digital/service address
  requirements, raw customer emails in failed notification/return audit logs,
  and unredacted inline carrier label/document payloads.
- CI runs `36345479726` and `36345882627` passed for both published commits.
  Safari accessibility-tree inspection confirmed named checkout/account
  controls plus native and server validation feedback; actual VoiceOver
  announcements remain blocked because changing that macOS assistive setting
  requires interactive user confirmation. Provider-hosted sandbox surfaces
  and the exhaustive visual-regression screen inventory also remain explicitly
  blocked/open and are not claimed as tested.

### 1.5.2 release evidence (2026-09-27)

- Runtime/test commit `14be5ba78138426e4799675cb8231d4ae10c8ba8` was built as
  `dist/mercato-1.5.2.zip` (SHA-256
  `67680191e20eb3d629044b9a2a2a1708798343d47b0b6de96a6463dcfee6a847`),
  deployed byte-for-byte to the isolated `https://mercato.test` site, and
  refreshed to module version `152`/schema `12` with production mode off.
- The complete acceptance report at
  `artifacts/e2e/release-1.5.2-final-20260927T1904/acceptance.md` passed all
  34 runner scenarios. The core browser matrix passed 25 tests with 15
  deliberate cross-project exclusions; all seven isolated business profiles,
  persisted-state checks, and exact cleanup steps passed.
- The quote lifecycle profile passed twice and in the complete configured-site
  suite, covering immutable reservation policy, active reservation accounting,
  idempotent replay, explicit expiry, signed ownership, conversion side
  effects, notification-log PII redaction, and zero residual fixtures. The
  notification-administration profile passed 39 assertions and restored all
  configuration and users exactly.
- The isolated assistive/visual profile passed 2/2 after exact deployment with
  named controls/landmarks, alert/status/live semantics, automated WCAG AA
  contrast, and screenshots/reflow at 320, 390, 768, and 1440 CSS pixels.
  Evidence and exact fixture verification/cleanup are retained under
  `artifacts/e2e/assistive-visual-1.5.2-repeat-20260927T1902/`.
- The current-release keyboard-only profile passed 3/3 checkout, account, and
  administration journeys; its two run-owned fixture graphs were removed
  exactly. Evidence is retained under
  `artifacts/e2e/keyboard-a11y-1.5.2-final-20260927T1907/`.
- CI run `36342960303` passed for the runtime/test commit. Manual Safari +
  VoiceOver behavior, the exhaustive visual-regression inventory, and real
  provider-hosted sandbox surfaces remain explicitly open/blocked and are not
  claimed as tested.

### 1.5.1 release evidence (2026-09-27)

- Runtime commit `1c9fa859d0ee499c46f9f720e89a2f8c7c81384e` was built as
  `dist/mercato-1.5.1.zip` (SHA-256
  `942d2c127fe597d436d0a9722bbb27f9d82d44a62a434b27e74daefcc77ec670`),
  deployed byte-for-byte to the isolated `https://mercato.test` site, and
  refreshed to module version `151`/schema `12` with production mode off.
- After test-profile isolation commit
  `989228bc46e4855424091f235bc2b1012a235245`, the complete acceptance report
  at `artifacts/e2e/release-1.5.1-final-20260927T1842/acceptance.md` passed all
  34 runner scenarios. The core matrix passed 25 tests with 15 deliberate
  cross-project exclusions across Chromium desktop/mobile, Firefox desktop,
  and iPhone 15 WebKit; all seven dedicated Chromium profiles also passed.
- The six-order fulfilment/product-type integration profile passed with
  carrier, pickup, local delivery, physical, digital, service, and mixed carts;
  it restored all fixtures and configuration with zero residual products,
  orders, or run-owned log failures.
- The 55-assertion persisted payment lifecycle and the complete
  configured-site PHP suite passed, covering requires-action, processing,
  delayed/out-of-order success, partial/pending/full refunds, cancellation,
  reconciliation replay, and exact-once stock changes with zero residual data.
- The dedicated Chromium keyboard profile passed all 3 checkout/account/admin
  journeys. Evidence is retained under
  `artifacts/e2e/keyboard-a11y-1.5.1-final-20260927T1838/`; both fixture graphs
  were cleaned exactly after the run. The complete acceptance run also passed
  every persisted-state and cleanup gate.
- CI passed for both the runtime release commit and the acceptance-profile
  isolation commit. Manual VoiceOver inspection, the full visual screen
  inventory, and real provider-hosted sandbox surfaces remain explicitly
  open/blocked as recorded in the matrix; they are not claimed as tested.

### Previous 1.5.0 release evidence

On 2026-09-27, commit `b6e83fb6ceef63e7ddcfb7ecc974dd94e620d623`
was built as `dist/mercato-1.5.0.zip` (SHA-256
`6662c98102fff6bd4e13d4f03a52bffa5f10212cd3c1e9e25730b71c0c422f02`),
deployed from that exact archive to the isolated non-production
`https://mercato.test` site, and refreshed to module version `150`/schema
`12`. The complete acceptance report at
`artifacts/e2e/release-1.5.0-final-20260927T1822/acceptance.md` records all
34 runner scenarios as **PASSED**.

- The complete PHP suite passed, including the new six-order order-to-cash
  inventory matrix (14 exact movements and 9 payment/refund events) and the
  70-assertion local Stripe/Mollie/PayPal emulator matrix. Documented
  fresh-install and guarded real-HTTP profiles remained explicit skips in this
  configured-site command rather than silent passes.
- The core browser matrix passed 25 tests with 15 deliberate project
  exclusions: Chromium desktop/mobile, Firefox desktop, and iPhone 15 WebKit
  surfaces plus the keyboard/reduced-motion menu and carousel scenario on both
  desktop engines. Firefox used the macOS compatibility wrapper.
- Seven dedicated Chromium profiles passed: administrator, customer lifecycle,
  authorized refund, ownership boundary, physical/digital guest checkout,
  presentation states, and authenticated exact-variant order-to-cash.
- The new authenticated path selected `large-charcoal`, changed quantity to
  two, completed Demo Payment through the normal form, attached the paid order
  to the verified customer, exposed it in account history, preserved the exact
  SKU/price/options snapshot, and changed variant stock from 6 to 4 exactly
  once. This regression found and fixed checkout profile/email loss after a
  cart-only POST.
- Every persisted-state and cleanup gate passed. The final authenticated run
  removed its exact order, user, and product; all other profiles also restored
  configuration and removed their run-owned pages/users/roles/files/log lines.
  No Playwright, headless-browser, or acceptance process remained running.
- No real payment, email, label, push, production webhook, analytics, or
  production-storage side effect occurred. Real provider sandbox HTTP/hosted
  surfaces remain blocked because no authorized test credentials or recipients
  are configured; this is not represented as tested.

## Previous complete release evidence

On 2026-09-27, commit `710e6e58d7be496b6d2cede0d722dcf60b89012e`
was built as `dist/mercato-1.4.9.zip` (SHA-256
`9c09bfb941f575e7e0191efae1ffe4034ec01306b4905e166e0dc030023423c6`),
deployed byte-for-byte to the isolated `https://mercato.test` site, refreshed
to installed module version `149`/schema `12`, and passed the complete
acceptance command.

- Backend deterministic scenarios passed, including the 58-assertion discount
  matrix, the 34-assertion gateway request boundary, the 1,127-assertion
  configuration/readiness matrix, the empty/large ProcessWire data profile,
  and all configured-site unit, integration, contract, retry, idempotency,
  permission, localization, release-metadata, degraded-dependency, redaction,
  and cleanup checks. The separately
  guarded real-HTTP MCP/session profile also passed twice with 26 assertions.
- The core browser matrix passed 23 tests with 15 deliberate project
  exclusions across Chromium desktop/mobile, Firefox desktop, and iPhone 15
  WebKit. Firefox launched through the macOS 26 compatibility wrapper and all
  four Firefox surface checks passed. Six required dedicated Chromium profiles
  passed: admin, customer lifecycle, authorized refund, ownership boundary,
  physical/digital guest checkout, and presentation states.
- Core persistence passed for two normal and two recovered paid orders, coupon
  snapshots, consumed-link replay denial, and exact inventory reduction from
  50 to 46. Admin persistence passed for fulfilment without payment/refund,
  privacy, or notification regression. Customer persistence passed for token
  consumption, guest-order ownership, and exactly one digital download event.
  Refund persistence passed with one ledger entry, one `refund_issued` event,
  and exact stock restoration from 9 to 10 after replay denial. Ownership
  persistence kept all three orders paid and correctly owned, preserved the
  victim profile, and advanced only the acting customer's revision. Guest
  persistence kept two ownerless orders paid, changed physical stock from 5
  to 4 exactly once, recorded one digital download, expired signed links, and
  emitted no suspicious logs. Presentation persistence preserved 24 UTF-8
  products and 14 paid owner-bound orders while checkout readiness remained
  unavailable with the run-specific message.
- Cleanup passed for every profile: core removed 6 orders, 2 pages, and 2
  users; admin removed 1 order, 3 users, and 2 roles; customer lifecycle
  removed 1 order, 2 users, and 1 product/file; refund removed 1 order, 2
  users, 1 role, and 1 product; ownership removed 3 orders, 3 users, and 1
  role; guest checkout removed 2 orders and 2 products/files; presentation
  removed 14 orders, 24 products, 2 users, 1 role, and 3 run-owned log lines.
  Configuration was restored, suspicious run-owned logs were zero, no
  `e2e-presentation-` log residue remained, and browser workers exited.
- Report: `artifacts/e2e/release-1.4.9-final-20260927T1724/acceptance.md`
  records all 29
  setup, backend, browser, persistence, and cleanup scenarios as **PASSED**.
  Live-provider smoke remained deliberately disabled.

## Latest targeted evidence (not a complete release profile)

### 1.6.0 tax-provider architecture, HTTP, and browser evidence (2026-10-10)

- Added 25 deterministic, network-free contract assertions for bundled Stripe Tax and Quaderno Tax cold-boot ordering, loopback-only/non-production transport guards, estimate/commit/refund/void mappings, exact discount allocation, minor units, tax-code/product-type mapping, provider-side shipping taxability, reverse-charge semantics, real Stripe reversal shape, namespaced Quaderno documents, exempt shipping, and unsafe Quaderno partial-refund blocking.
- Added ProcessWire regression evidence for non-negative aggregate tax rounding, trusted manual reverse-charge exemption, currency-specific order/refund precision, tax-identity log redaction/retention, and replay of ambiguous provider mutations with a stable idempotency key.
- Added regressions for authoritative included/excluded behavior, presentation-only `none`, market-specific provider policy, committed transaction references, lifecycle status validation, free-shipping allocation, receipt currency, and shipping tax snapshots.
- `MERCATO_TEST_SITE=/Users/mas/Sites/mercato.dev php scripts/run-tests.php` passed the complete local PHP/ProcessWire profile after a full runtime synchronization; the configuration matrix covered 182 defaults, 179 inputs, and 172 safely mutated settings with exact restore.
- `npm run test:e2e:tax-providers` passed the dedicated Chromium profile against `https://mercato.test`: two browser checkouts used the actual module cURL transports against a deterministic loopback HTTP server, persisted Stripe Tax and Quaderno 10% quotes and commits, then completed provider reversals/full refunds, restored both product stocks, rendered private refunded status pages, passed serious/critical axe and responsive gates, and removed exactly two orders plus two products while restoring all three module configurations.
- The retained emulator log contains the expected eight health/quote/commit/refund requests, stable non-empty idempotency keys on every provider call, and no credential material. Evidence is under `artifacts/e2e-tax-providers/`.
- The final `scripts/run-acceptance.php` release profile passed all 52 setup/backend/browser/persistence/cleanup scenarios with zero failed transitions. It includes 25 core browser checks, isolated admin/customer/refund/ownership/guest/presentation/variant/fulfilment journeys, Chromium/Firefox/WebKit checkout, public/admin visual breadth, and the new provider HTTP lifecycle. Evidence is under `artifacts/e2e/release-1.6.0-tax-final-20261010/`.
- `composer test:unit`, `composer verify:runtime`, `composer check:licenses`, `composer check:security`, `php scripts/validate-acceptance.php`, PHP syntax checks, and `git diff --check` passed.
- No live provider credentials, real payments, production webhooks, emails, or tax documents were used. Official hosted sandbox smoke remains an explicit opt-in release gate; the adapter HTTP boundary itself is now covered end to end without that external dependency.

On 2026-09-27, the isolated presentation-state profile passed against
`https://mercato.test` with notifications disabled and checkout independently
placed in non-production maintenance mode:

```bash
MERCATO_E2E_SITE=/Users/mas/Sites/mercato.dev \
MERCATO_E2E_BASE_URL=https://mercato.test \
MERCATO_E2E_IGNORE_HTTPS_ERRORS=1 \
MERCATO_E2E_PROFILE=presentation-states \
MERCATO_E2E_STATE=/tmp/mercato-presentation-state.json \
npx playwright test -c tests/e2e/playwright.config.js \
  --project=chromium-desktop
```

- Browser result: 1/1 Chromium scenario passed in 9.1 seconds (9.6 seconds including runner overhead), with no retries, skips, flaky results, or unexpected results.
- Presentation result: mobile empty and large catalogs, the announced loading transition, long Japanese/Arabic product content, three account-history pages, and desktop product/order administration passed responsive and serious/critical axe gates. Both admin horizontal table regions were focusable.
- Diagnostics: browser console, document/XHR/fetch failures, same-origin 5xx responses, and run-owned ProcessWire logs had zero suspicious failures.
- Persisted-state gate: passed with all 24 products and 14 paid owner-bound orders intact, checkout unavailable with the run-specific maintenance message, UTF-8 content preserved, and suspicious run-owned logs equal to zero.
- Cleanup: passed; exactly 14 orders, 24 products, 2 users, and 1 role were removed, the original configuration was restored, and the repeat cleanup removed `0/0/0/0`.
- Defects found and fixed: the alternate `art-profile` product template now wraps long product titles and descriptions without horizontal overflow, and the products CSV import textarea has a visible associated label.
- Evidence: `artifacts/e2e/presentation-states-canonical-20260927T1335/` contains the passing Playwright JSON/HTML report and last-run marker.
- Scope limit: this targeted profile is not a complete acceptance/release-profile or live-provider rerun.

On 2026-09-27, the failed-payment recovery scenario passed sequentially against
the isolated `https://mercato.test` development site with Demo Payment only:

```bash
MERCATO_E2E_SITE=/Users/mas/Sites/mercato.dev \
MERCATO_E2E_BASE_URL=https://mercato.test \
MERCATO_E2E_IGNORE_HTTPS_ERRORS=1 \
npx playwright test -c tests/e2e/playwright.config.js \
  tests/e2e/recovery.spec.js \
  --project=chromium-desktop --project=webkit-mobile
```

- Browser result: 2 passed in 8.0 seconds; no retries.
- Persisted-state gate: passed; recovery order IDs `3104` and `3105` were paid, consumed links were invalid, and fixture stock was `48` after exactly two adjustments.
- Cleanup: passed; 4 run-owned orders, 2 pages, and 2 customer users were removed.
- Defect found and fixed: the authenticated account order table caused 21 px horizontal page overflow on iPhone 15 WebKit. The account grid item now permits shrinking and the table has a scoped horizontal scroll container.
- Evidence: `artifacts/e2e/recovery-targeted-final-20260927/` contains the Playwright JSON/HTML reports and command, persisted verification, cleanup, and status logs.
- Scope limit: this targeted evidence is not a claim that the complete acceptance/release profile passed; live providers and the remaining agent-led scenarios were not exercised by this command.

On 2026-09-27, the targeted authenticated administration scenario passed
against the same isolated development site with Demo/fake boundaries only:

```bash
MERCATO_E2E_SITE=/Users/mas/Sites/mercato.dev \
MERCATO_E2E_BASE_URL=https://mercato.test \
MERCATO_E2E_IGNORE_HTTPS_ERRORS=1 \
npx playwright test -c tests/e2e/playwright.config.js \
  tests/e2e/admin.spec.js --project=chromium-desktop
```

- Browser result: 1 passed in 9.5 seconds; no retries. The scenario used independent mobile staff, desktop manager, and mobile customer contexts.
- Persisted-state gate: passed for order `3255`; fulfilment was `shipped`, tracking was persisted, payment remained paid, refund remained zero, and privacy/notification state remained unchanged. No run-owned suspicious log line was found.
- Cleanup: passed; exactly 1 order, 3 users, and 2 roles were removed and configuration was restored.
- Accessibility/presentation: Mercato-owned surfaces had no serious/critical axe violations or horizontal overflow; the fulfilment submit was focused and activated with Enter.
- Evidence: `artifacts/e2e/admin-canonical-20260927T1113/` contains setup, Playwright JSON/HTML, run, persisted verification, cleanup, and status artifacts.
- Scope limit: this targeted evidence is not a complete acceptance/release-profile rerun and does not claim live-provider, email, MCP-client, or broad agent-led visual coverage.

On 2026-09-27, the targeted customer lifecycle profile passed against the
isolated development site:

```bash
MERCATO_E2E_SITE=/Users/mas/Sites/mercato.dev \
MERCATO_E2E_BASE_URL=https://mercato.test \
MERCATO_E2E_IGNORE_HTTPS_ERRORS=1 \
MERCATO_E2E_PROFILE=customer-lifecycle \
npx playwright test -c tests/e2e/playwright.config.js

MERCATO_E2E_SITE=/Users/mas/Sites/mercato.dev \
MERCATO_E2E_STATE=/tmp/mercato-presentation-state.json \
php tests/e2e/presentation-states-fixtures.php cleanup
```

- Browser result: 1 Chromium scenario passed in 19.0 seconds with independent desktop/mobile sessions and no retry.
- Persisted-state gate: passed for customer `3376` and order `3375`; ownership claim persisted, verification/reset/claim proofs were consumed, payment stayed paid, and exactly one digital download event was recorded. Suspicious run-owned logs: zero.
- Cleanup: passed; exactly 1 order, 2 users, and 1 digital product/file were removed and configuration was restored.
- Defects found and fixed: generated public order/status/receipt/recovery HTML now declares `lang="en"`; its accent color now passes contrast on the cream surface. The scenario also accounts for ProcessWire's independent five-second login-overflow guard without weakening Mercato's configured limiter.
- Evidence: `artifacts/e2e/customer-lifecycle-canonical-20260927T1133/` contains setup, Playwright JSON/HTML, run, persisted verification, cleanup, and status artifacts.
- Scope limit: no real email was sent; deterministic fixture helpers substitute known run-owned verification/reset/claim proof only after confirming that the browser request persisted a current opaque proof.

On 2026-09-27, the targeted authorized-refund profile passed against the same
isolated development site with Demo Payment and notifications disabled:

```bash
MERCATO_E2E_SITE=/Users/mas/Sites/mercato.dev \
MERCATO_E2E_BASE_URL=https://mercato.test \
MERCATO_E2E_IGNORE_HTTPS_ERRORS=1 \
MERCATO_E2E_PROFILE=refund \
npx playwright test -c tests/e2e/playwright.config.js \
  --project=chromium-desktop
```

- Browser result: 1 Chromium scenario passed in 4.7 seconds (5.3 seconds including runner overhead), with independent mobile customer and desktop manager contexts and no retry.
- Cross-role result: the owner saw the paid baseline, the least-privilege manager keyboard-submitted a full refund, the exact POST replay was denied, and the owner then saw `refunded` in account, signed status, HTML receipt, and PDF receipt.
- Persisted-state gate: passed for order `3651`; payment was `refunded`, refunded amount was `20`, pending amount was zero, stock returned from `9` to `10`, and exactly one refund ledger entry and one `refund_issued` event existed. Suspicious run-owned logs: zero.
- Presentation/diagnostics: desktop admin and mobile customer surfaces passed overflow and serious/critical axe gates; browser console, document/XHR/fetch, same-origin 5xx, PDF signature/content, focus, and response assertions passed.
- Cleanup: passed; exactly 1 order, 2 users, 1 role, and 1 product were removed, exact residual IDs were empty, configuration was restored, and the temporary credential state was removed.
- Evidence: `artifacts/e2e/refund-canonical-20260927T1602/` contains Playwright JSON/HTML and the successful last-run marker.
- Scope limit: this targeted profile used Demo Payment and is not a complete acceptance/release-profile or live-provider rerun.

On 2026-09-27, the targeted ownership-boundary profile passed against the same
isolated development site with Demo/fake boundaries and notifications disabled:

```bash
MERCATO_E2E_SITE=/Users/mas/Sites/mercato.dev \
MERCATO_E2E_BASE_URL=https://mercato.test \
MERCATO_E2E_IGNORE_HTTPS_ERRORS=1 \
MERCATO_E2E_PROFILE=ownership-boundaries \
npx playwright test -c tests/e2e/playwright.config.js \
  --project=chromium-desktop
```

- Browser result: 1 Chromium scenario passed in 5.4 seconds (5.8 seconds including runner overhead), with independent anonymous-mobile, second-customer desktop, and unauthorized-staff mobile contexts; no retries, skips, flaky results, or unexpected results.
- Privacy result: valid status/receipt/PDF capabilities were private, no-store, noindex, read-only, and stable on replay; malformed, wrong-purpose, and expired HTML/PDF routes returned private `404` responses without invoice disclosure.
- Cross-role result: anonymous profile mutation was denied by CSRF, the second customer saw only its own account order, injected owner/order identifiers could not target the victim, stale profile replay was denied, the foreign claim was denied, and staff without view/refund permission could neither inspect nor mutate the order.
- Persisted-state gate: all three orders retained their original owners and paid state, the victim profile stayed unchanged, the acting second customer's revision advanced exactly once to `1`, unauthorized staff permissions remained absent, and suspicious run-owned log lines were zero.
- Presentation/diagnostics: responsive and serious/critical axe gates passed; console/network/5xx gates passed after narrowly classifying only the scenario's asserted private document `404` console noise as intentional while keeping resource `404` responses blocking.
- Cleanup: passed; exactly 3 orders, 3 users, and 1 role were removed, exact residual IDs were empty, configuration was restored, and the temporary credential state was removed.
- Harness fixes: the profile now passes cross-account target IDs explicitly into `page.evaluate`, and expected private document `404` console messages are filtered without weakening unexpected resource-failure detection.
- Evidence: `artifacts/e2e/ownership-canonical-20260927T1232/` contains Playwright JSON/HTML, machine-readable evidence, the run summary, and the successful last-run marker.
- Scope limit: this targeted profile is not a complete acceptance/release-profile or live-provider rerun.

On 2026-09-27, the targeted physical/digital guest-checkout profile passed
against the same isolated development site with Demo Payment, local fixtures,
and notifications/analytics disabled:

```bash
MERCATO_E2E_SITE=/Users/mas/Sites/mercato.dev \
MERCATO_E2E_BASE_URL=https://mercato.test \
MERCATO_E2E_IGNORE_HTTPS_ERRORS=1 \
MERCATO_E2E_PROFILE=guest-checkout \
npx playwright test -c tests/e2e/playwright.config.js \
  --project=chromium-desktop
```

- Browser result: 1 Chromium scenario passed in 6.4 seconds (6.9 seconds including runner overhead), using isolated desktop physical and mobile digital guest contexts; no retries, skips, flaky results, or unexpected results.
- Checkout result: real storefront checkout created physical order `4285` and digital order `4286`; both were paid and retained guest owner ID `0`.
- Route result: paid payment links denied replay; signed status, HTML receipt, PDF, download, and default-safe access-recovery routes passed privacy checks; the digital download succeeded once and replay was denied; both orders' signed routes were then expired and denied privately.
- Persisted-state gate: physical stock moved from `5` to `4` exactly once, digital `download_events` was exactly `1`, both signed-link sets were expired, and suspicious run-owned log lines were zero.
- Presentation/diagnostics: desktop/mobile responsive and serious/critical axe gates passed; console, unexpected resource `404`, document/XHR/fetch, same-origin 5xx, privacy-header, and ProcessWire-log gates passed.
- Cleanup: passed; exactly 2 orders and 2 products (including the uploaded digital file) were removed, exact residual IDs were empty, configuration was restored, and the temporary state was removed.
- Defects fixed: signed download success/denial responses now emit private no-store/noindex/nosniff/no-referrer headers, and the default access-recovery document declares `lang="en"`.
- Evidence: `artifacts/e2e/guest-checkout-canonical-20260927T1310/` contains Playwright JSON/HTML, machine-readable evidence, the run summary, and the successful last-run marker.
- Scope limit: this targeted profile is not a complete acceptance/release-profile or live-provider rerun.

The packaged `1.4.5` release was then rebuilt from commit
`3293579577902e45626ef2d4cc972b741c59c4b0`, installed byte-for-byte on the
development site, and verified again. Its SHA-256 is
`791d47d934dc34ae7d82aeaf6c259068ddec0690c3e046c7ac3434018c445a47`.
The release-path blacklist, runtime manifest, module refresh (`145`, schema
`12`), HTTP health check, and complete configured-site PHP suite passed. The
same admin browser scenario passed from the installed ZIP in 9.3 seconds;
persisted verification and exact cleanup passed. Evidence is in
`artifacts/e2e/admin-release-145-20260927T1115/`.
