const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

const targetOrigin = new URL(process.env.MERCATO_E2E_BASE_URL || 'https://mercato.test').origin;
function prepare(action, engine = '') {
  const args = [path.join(__dirname, 'fixtures.php'), action]; if (engine) args.push(engine);
  execFileSync('php', args, { cwd: path.join(__dirname, '../..'), env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }); return fixture();
}
function expectPrivate(response) {
  expect(response.status()).toBe(200); const headers = response.headers(); expect(headers['cache-control']).toContain('no-store'); expect(headers['x-robots-tag']).toContain('noindex');
}
function observeBrowserFailures(page) {
  const failures = [];
  page.on('console', message => {
    if (message.type() !== 'error') return;
    // Firefox reports blocked/unavailable third-party web-font downloads as
    // JavaScript errors even though the page correctly uses its CSS fallback.
    if (/downloadable font: download failed[\s\S]+https:\/\/fonts\.gstatic\.com\//i.test(message.text())) return;
    failures.push(`console: ${message.text()}`);
  });
  page.on('requestfailed', request => { if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`); });
  page.on('response', response => { if (new URL(response.url()).origin === targetOrigin && response.status() >= 500) failures.push(`response: ${response.status()} ${response.url()}`); });
  return failures;
}
async function fillCheckout(page, email, label) {
  for (const [name, value] of [['first_name', label], ['last_name', 'Cross Browser'], ['email', email], ['address', '10 Engine Street'], ['city', 'New York'], ['zip', '10001']]) {
    const field = page.locator(`input[name="${name}"]`); if (await field.count() && await field.isVisible()) await field.fill(value);
  }
  const country = page.locator('select[name="country"]'); if (await country.count() && await country.isVisible()) await country.selectOption('US');
  await page.locator('select[name="payment_method"]').selectOption('demo'); const policy = page.locator('input[name="policy_accepted"]'); if (await policy.count()) await policy.check();
}

test('critical checkout, recovery, and private documents work in every engine', async ({ page, browserName }, testInfo) => {
  test.setTimeout(150_000); const engine = browserName; let state = fixture(); const row = state.cross_browser[engine]; const failures = observeBrowserFailures(page);
  await test.step('cart survives navigation and native validation blocks incomplete checkout', async () => {
    let response = await page.goto(state.product_url, { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy(); expect(response.headers()['cache-control']).toContain('no-store');
    const add = page.locator('form').filter({ has: page.locator('input[name="mrc_action"][value="add_to_cart"]') }).first();
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), add.getByRole('button', { name: /Add to Cart/i }).click()]);
    response = await page.goto('/checkout/', { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy(); expect(response.headers()['cache-control']).toContain('no-store');
    await expect(page.locator('input[name^="cart_quantity["]')).toHaveCount(1);
    await page.getByRole('button', { name: /Continue to payment/i }).click(); await expect(page.locator(':invalid').first()).toBeVisible(); await expect(page).toHaveURL(/checkout\/?$/);
    await assertResponsive(page, `${engine} validation`); await assertAccessible(page, `${engine} validation`);
  });
  await test.step('normal Demo checkout reaches a durable success page', async () => {
    await fillCheckout(page, row.checkout_email, engine[0].toUpperCase() + engine.slice(1));
    await Promise.all([page.waitForURL(/checkout\/success/), page.getByRole('button', { name: /Continue to payment/i }).click()]);
    await expect(page.getByText(/Thank you for your order/i)).toBeVisible(); await expect(page.getByText(row.checkout_email)).toBeVisible();
    await assertResponsive(page, `${engine} success`); await assertAccessible(page, `${engine} success`);
  });
  state = prepare('capture-cross-browser', engine);
  await test.step('signed status, receipt, and PDF remain private', async () => {
    const captured = state.cross_browser[engine].checkout_order;
    let response = await page.goto(captured.status_url, { waitUntil: 'domcontentloaded' }); expectPrivate(response); await expect(page.getByText(captured.invoice)).toBeVisible(); await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
    response = await page.goto(captured.receipt_url, { waitUntil: 'domcontentloaded' }); expectPrivate(response); await expect(page.getByText(captured.invoice)).toBeVisible();
    const pdf = await page.request.get(captured.pdf_url); expect(pdf.status()).toBe(200); expect(pdf.headers()['cache-control']).toContain('no-store'); expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');
    await assertResponsive(page, `${engine} private receipt`); await assertAccessible(page, `${engine} private receipt`);
  });
  await test.step('failed-payment recovery succeeds once and replay is denied', async () => {
    const recovery = state.cross_browser[engine].recovery; let response = await page.goto(recovery.payment_url, { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
    await expect(page.getByText(recovery.invoice)).toBeVisible(); await expect(page.locator('input[name="email"]')).toHaveValue(recovery.email); await page.locator('select[name="payment_method"]').selectOption('demo');
    const policy = page.locator('input[name="policy_accepted"]'); if (await policy.count()) await policy.check(); await assertAccessible(page, `${engine} recovery`);
    await Promise.all([page.waitForURL(/checkout\/success/), page.getByRole('button', { name: /Continue to payment/i }).click()]); await expect(page.getByText(/Thank you for your order/i)).toBeVisible();
    response = await page.goto(recovery.payment_url, { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy(); await expect(page.getByText('This payment link is invalid or no longer payable.')).toBeVisible();
  });
  expect(failures, `${testInfo.project.name} console, server, and document/XHR/fetch failures`).toEqual([]);
});
