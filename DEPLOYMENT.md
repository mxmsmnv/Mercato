# Mercato deployment contract

## Supported runtime

- PHP 8.1–8.5 for the current release line. Required extensions: `filter`, `hash`, `json`, and `session`; `curl` is strongly recommended for provider HTTP traffic.
- ProcessWire 3.0.200 or newer in the 3.x line.
- MySQL 8.0+ or MariaDB 10.6+ using a transactional InnoDB database and `utf8mb4`.
- Composer 2.7+ for source installs and release assembly. Tagged release ZIPs include production dependencies and do not require Composer on the web host.

`composer.json` is the declared graph and `composer.lock` is the exact graph used for CI and release assembly. `require` contains production runtime packages; `require-dev` contains test tooling only. Stripe's PHP SDK is required because Stripe methods are enabled by default. Mollie and PayPal use ProcessWire `WireHttp` and add no PHP package. Third-party tax, shipping, mail, or gateway modules own and document their own dependencies.

## Fresh install

Source checkout:

```sh
git checkout <tag>
composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction
composer verify:runtime
```

Copy the complete directory, including `vendor/`, to `/site/modules/Mercato/`, refresh ProcessWire modules, install Mercato, then run **Modules → Mercato → Run Mercato installer**. A release ZIP can instead be extracted directly as `/site/modules/Mercato`; verify its `.sha256` file first. Do not deploy a GitHub-generated source archive as a release artifact because it does not contain `vendor/`.

The installer checks PHP, ProcessWire, required extensions, and enabled Stripe dependencies before modifying schema. Missing packages produce the exact Composer command needed. After install, configure environment-specific credentials only in ProcessWire module configuration or the hosting secret mechanism—never in this repository, a ZIP, template, or environment file inside the public module directory.

## Upgrade

1. Put checkout/payment-changing deployments into a maintenance window and stop workers/LazyCron traffic.
2. Back up the database and `/site/assets/files/`; record the deployed tag, `composer.lock` hash, PHP version, and module configuration separately from secrets.
3. Build/download the new complete artifact and verify its checksum in a staging directory.
4. Atomically replace the Mercato module directory; do not merge old and new `vendor/` trees.
5. Refresh ProcessWire modules, clear compiled-file/module caches, and run the Mercato installer/repair once. Existing site templates are preserved unless overwrite is explicitly selected.
6. Run Launch diagnostics, `composer verify:runtime` when Composer exists on-host, one guest/account checkout smoke test, gateway webhook verification, and the scheduled-job dry runs before resuming traffic.

Schema installers are idempotent and move forward from the previous supported schema. CI and `MercatoDeploymentUpgradeIntegrationTest.php` exercise the previous-schema upgrade and reject code whose schema is older than the installed database.

## Rollback

Application files and database schema are one deployment unit. If failure occurs before installer/repair changes schema, atomically restore the prior complete module artifact and clear ProcessWire caches. If the schema was upgraded, restore the matching pre-upgrade database and files backup before restoring the old code. Mercato blocks a code-only rollback when `installed_schema_version` is newer than the code. Never roll back order/payment/webhook/refund/inventory rows independently or replay a production database into a less protected environment.

## Cache and scheduled work

After install/upgrade, use ProcessWire's module refresh and remove only ProcessWire-documented compiled module caches; do not delete sessions, orders, or `/site/assets/files/`. Confirm LazyCron or the site's external runner invokes configured reservation cleanup, recovery automation, quote expiry, and daily privacy retention. Run privacy retention as a dry run first. Ensure only one scheduler instance owns a batch or rely on Mercato's idempotency/locking for retries.

## Backup evidence and restore drill

Back up the database and all `/site/assets/files/` content together, plus non-secret ProcessWire/Mercato configuration, the exact release ZIP/checksum, and `composer.lock`. Product images and digital entitlements live in site files; order/payment/inventory truth lives in the database. Store at least one copy outside the web host and record only a destination category in Mercato—not bucket names, credentials, URLs, encryption keys, or customer data. An external backup module can enrich status through `Mercato::backupStatus` or call `$commerce->operationalService()->recordBackupEvidence()` after a successful job. Production schema upgrades can be configured to fail closed unless evidence is recent and covers database, site files, configuration, and release metadata.

Perform a restore drill on a clean isolated environment:

1. Install the recorded PHP/ProcessWire versions and exact Mercato release artifact; keep outbound email, provider mutations, and public DNS disabled.
2. Restore the database and site files from the same recovery point, then restore non-secret environment configuration and inject test/sandbox credentials separately.
3. Refresh modules and caches without running a newer installer. Confirm the code schema equals `installed_schema_version` before any migration.
4. Run detailed health diagnostics, release manifest verification, expired-reservation cleanup, and privacy dry-run. Confirm product/download files exist and storage is writable.
5. Compare order counts/totals, provider references, payment attempts, refunds, inventory movements, and webhook IDs against the backup manifest. Use remote provider verification and the reconciliation queue; never infer paid state from the restored local row alone.
6. Verify guest and owned-account receipts/status/download authorization, one sandbox checkout, one signed webhook replay, email transport test, scheduler evidence, and a refund fixture.
7. Record the restore timestamp only after these checks pass, destroy or re-protect the drill environment, and document recovery time/data-loss observations outside Mercato.

## Monitoring and maintenance mode

`GET /api/mercato/health` is a PII-free minimal uptime endpoint. `GET /api/mercato/health?details=1` requires `Authorization: Bearer <health token>` and separates application/runtime, database, storage, email, payment providers, webhooks, reservations, scheduler, backup, configuration, and checkout status. Put the token in the monitor's secret store and never in a query string. Responses contain counts/status/categories only—no customers, orders, provider payloads, paths, or credentials. Alert separately on `down` (application/database/storage) and `degraded` (configuration/provider/email/cron/backup/checkout) states.

Disable **Checkout enabled** during deployment or an integrity incident. New payment and quote initialization then fails with HTTP 503 before order/provider mutation, while catalog, admin recovery, signed existing-order pages, and provider webhooks remain available. Re-enable only after schema/runtime health, provider reconciliation, reservations, storage, email, and scheduler checks pass.

Responsibility split:

- Merchant: defines retention/recovery objectives, funds and monitors backups, owns provider reconciliation and legal/financial sign-off, and authorizes production recovery.
- Host/operator: provides encrypted offsite backups, database/file consistency, access controls, monitoring transport, capacity, restore tooling, and tested infrastructure recovery.
- Implementer: builds/deploys the exact artifact, configures hooks/health/schedulers without secrets in code, runs migrations and smoke tests, preserves idempotency, and documents application-specific integrations.

## Release assembly and verification

From a clean tagged checkout:

```sh
scripts/build-release.sh 1.3.0
shasum -a 256 -c dist/mercato-1.3.0.zip.sha256
```

The builder starts from `git archive`, applies `.gitattributes` export rules, installs the locked production graph with an authoritative classmap, normalizes file timestamps to the commit time, strips ZIP metadata, verifies runtime files, and emits a SHA-256 checksum. It excludes tests, CI configuration, local/private tooling, `.env`, VCS data, logs, local paths, and OS metadata. Demo storefront assets are intentional runtime/install data, not test fixtures.

CI validates Composer metadata, installs the lock on PHP 8.1/8.2/8.3/8.4/8.5, lints source, runs unit tests, verifies required release files, audits known vulnerabilities, records dependency licenses, and smoke-builds the release archive. The ProcessWire integration suite separately exercises upgrade/rollback against a configured test site. A release must not be published when audit, license review, checksum, runtime-manifest, upgrade, or payment smoke checks fail.
