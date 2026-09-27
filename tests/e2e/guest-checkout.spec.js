const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

const targetOrigin = new URL(process.env.MERCATO_E2E_BASE_URL || 'https://mercato.test').origin;
function prepare(action) { execFileSync('php', [path.join(__dirname, 'guest-checkout-fixtures.php'), action], { cwd: path.join(__dirname, '../..'), env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }); return fixture(); }
function observeBrowserFailures(page, expectedNotFound) {
  const failures = [];
  let expectedConsoleNotFound = 0;
  page.on('console', message => {
    if (message.type() !== 'error') return;
    if (expectedConsoleNotFound > 0 && /Failed to load resource: the server responded with a status of 404/i.test(message.text())) { expectedConsoleNotFound--; return; }
    failures.push(`console: ${message.text()}`);
  });
  page.on('requestfailed', request => {
    if (request.url().includes('/api/mercato/download?') && request.failure()?.errorText === 'net::ERR_ABORTED') return;
    if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`);
  });
  page.on('response', response => {
    if (new URL(response.url()).origin !== targetOrigin) return;
    if (response.status() === 404 && expectedNotFound.delete(response.url())) { expectedConsoleNotFound++; return; }
    if (response.status() >= 500 || response.status() === 404) failures.push(`response: ${response.status()} ${response.url()}`);
  });
  return failures;
}
function expectPrivateHeaders(response) { expect(response.headers()['cache-control']).toContain('no-store'); expect(response.headers()['x-robots-tag']).toContain('noindex'); }
async function expectPrivateHtml(page, response, status, invoice = '') { expect(response.status()).toBe(status); expectPrivateHeaders(response); await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/); if (invoice) await expect(page.locator('body')).not.toContainText(invoice); }

async function completeGuestCheckout(page, productUrl, email, label) {
  let response = await page.goto(productUrl, { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
  const add = page.locator('form').filter({ has: page.locator('input[name="mrc_action"][value="add_to_cart"]') }).first();
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), add.getByRole('button').click()]);
  response = await page.goto('/checkout/', { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
  await page.locator('input[name="first_name"]').fill('Guest'); await page.locator('input[name="last_name"]').fill(label); await page.locator('input[name="email"]').fill(email);
  for (const [name, value] of [['address', 'Guest Route Street'], ['city', 'Test City'], ['zip', '10001']]) { const field = page.locator(`input[name="${name}"]`); if (await field.isVisible()) await field.fill(value); }
  const country = page.locator('select[name="country"]'); if (await country.isVisible()) { const us = country.locator('option[value="US"]'); await country.selectOption(await us.count() ? 'US' : { index: 1 }); }
  await page.locator('select[name="payment_method"]').selectOption('demo'); const policy = page.locator('input[name="policy_accepted"]'); if (await policy.count()) await policy.check();
  await assertResponsive(page, `${label} guest checkout`); await assertAccessible(page, `${label} guest checkout`);
  await Promise.all([page.waitForURL(/checkout\/success/), page.getByRole('button', { name: /Continue to payment/i }).click()]);
  await expect(page.getByText(/Thank you for your order/i)).toBeVisible();
  await expect(page.getByText(email)).toBeVisible();
  await assertResponsive(page, `${label} guest success`); await assertAccessible(page, `${label} guest success`);
}

test('physical and digital guest checkout preserve private signed route lifecycles', async ({ browser }) => {
  test.setTimeout(120_000); let state = fixture(); const contexts = []; const failures = []; const expectedNotFound = new Set();
  try {
    const physicalContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true }); contexts.push(physicalContext);
    const physicalPage = await physicalContext.newPage(); failures.push(observeBrowserFailures(physicalPage, expectedNotFound));
    await test.step('physical guest completes Demo checkout with delivery and inventory', async () => completeGuestCheckout(physicalPage, state.physical_product_url, state.physical_email, 'Physical'));

    const digitalContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(digitalContext);
    const digitalPage = await digitalContext.newPage(); failures.push(observeBrowserFailures(digitalPage, expectedNotFound));
    await test.step('digital guest completes Demo checkout in an isolated mobile cart', async () => completeGuestCheckout(digitalPage, state.digital_product_url, state.digital_email, 'Digital'));
    state = prepare('capture');

    await test.step('paid payment links deny replay without exposing either invoice', async () => {
      for (const [page, kind] of [[physicalPage, 'physical'], [digitalPage, 'digital']]) {
        const response = await page.goto(state[`${kind}_payment_url`], { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
        await expect(page.getByText('This payment link is invalid or no longer payable.')).toBeVisible(); await expect(page.getByText(state[`${kind}_invoice`])).toHaveCount(0);
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
      }
    });

    await test.step('physical signed status, receipt, PDF, and default-safe access recovery stay private', async () => {
      let response = await physicalPage.goto(state.physical_status_url, { waitUntil: 'domcontentloaded' }); await expectPrivateHtml(physicalPage, response, 200); await expect(physicalPage.getByText(state.physical_invoice)).toBeVisible();
      response = await physicalPage.goto(state.physical_receipt_url, { waitUntil: 'domcontentloaded' }); await expectPrivateHtml(physicalPage, response, 200); await expect(physicalPage.getByRole('heading', { name: 'Downloads' })).toHaveCount(0);
      const pdf = await physicalPage.request.get(state.physical_pdf_url); expect(pdf.status()).toBe(200); expectPrivateHeaders(pdf); expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');
      response = await physicalPage.goto(state.physical_access_url, { waitUntil: 'domcontentloaded' }); await expectPrivateHtml(physicalPage, response, 200); await expect(physicalPage.getByRole('heading', { name: 'Access recovery unavailable' })).toBeVisible(); await expect(physicalPage.getByText(state.physical_invoice)).toBeVisible();
      await assertResponsive(physicalPage, 'physical guest private routes'); await assertAccessible(physicalPage, 'physical guest private routes');
    });

    await test.step('digital receipt exposes one private download and replay is denied', async () => {
      let response = await digitalPage.goto(state.digital_status_url, { waitUntil: 'domcontentloaded' }); await expectPrivateHtml(digitalPage, response, 200); await expect(digitalPage.getByText(state.digital_invoice)).toBeVisible();
      response = await digitalPage.goto(state.digital_receipt_url, { waitUntil: 'domcontentloaded' }); await expectPrivateHtml(digitalPage, response, 200);
      const downloadHref = await digitalPage.getByRole('link', { name: state.download_filename }).getAttribute('href');
      const resolvedDownload = new URL(downloadHref, targetOrigin);
      expect(resolvedDownload.pathname + resolvedDownload.search).toBe(state.download_url);
      const download = await digitalPage.request.get(state.download_url); expect(download.status()).toBe(200); expectPrivateHeaders(download); expect(await download.text()).toBe(state.download_contents);
      const replay = await digitalPage.request.get(state.download_url); expect(replay.status()).toBe(404); expectPrivateHeaders(replay); expect(await replay.text()).toBe('Download unavailable.');
      response = await digitalPage.goto(state.digital_access_url, { waitUntil: 'domcontentloaded' }); await expectPrivateHtml(digitalPage, response, 200); await expect(digitalPage.getByRole('heading', { name: 'Access recovery unavailable' })).toBeVisible();
      await assertResponsive(digitalPage, 'digital guest private routes'); await assertAccessible(digitalPage, 'digital guest private routes');
    });

    state = prepare('expire');
    await test.step('expired status, receipt, PDF, and access-recovery capabilities fail privately', async () => {
      for (const [page, kind] of [[physicalPage, 'physical'], [digitalPage, 'digital']]) {
        for (const key of ['status_url', 'receipt_url', 'access_url']) {
          expectedNotFound.add(new URL(state[`${kind}_${key}`], targetOrigin).href);
          const response = await page.goto(state[`${kind}_${key}`], { waitUntil: 'domcontentloaded' });
          await expectPrivateHtml(page, response, 404, state[`${kind}_invoice`]);
        }
        const pdf = await page.request.get(state[`${kind}_pdf_url`]); expect(pdf.status()).toBe(404); expectPrivateHeaders(pdf); expect(await pdf.text()).not.toContain(state[`${kind}_invoice`]);
      }
    });
    expect(failures.flat(), 'console, same-origin server, and document/XHR/fetch failures').toEqual([]);
  } finally { await Promise.allSettled(contexts.map(context => context.close())); }
});
