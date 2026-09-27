const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

function prepare(action) {
  execFileSync('php', [path.join(__dirname, 'authenticated-variant-fixtures.php'), action], { cwd: path.join(__dirname, '../..'), env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  return fixture();
}
function actionForm(page, action) { return page.locator('form').filter({ has: page.locator(`input[name="mrc_account_action"][value="${action}"]`) }); }
function observeBrowserFailures(page) {
  const failures = [];
  page.on('console', message => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
  page.on('requestfailed', request => { if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`); });
  page.on('response', response => { if (response.url().startsWith('https://mercato.test/') && response.status() >= 500) failures.push(`response: ${response.status()} ${response.url()}`); });
  return failures;
}

test('authenticated customer buys an exact variant and sees the owned order', async ({ page }) => {
  test.setTimeout(120_000); let state = fixture(); const failures = observeBrowserFailures(page);
  await test.step('verified customer signs in', async () => {
    let response = await page.goto('/account/', { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
    const form = actionForm(page, 'login'); await form.locator('input[name="email"]').fill(state.email); await form.locator('input[name="password"]').fill(state.password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), form.getByRole('button', { name: 'Sign in' }).click()]);
    await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible();
  });
  await test.step('customer selects the exact variant and adds it to the cart', async () => {
    let response = await page.goto(state.product_url, { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
    const form = page.locator('form').filter({ has: page.locator('input[name="mrc_action"][value="add_to_cart"]') }).first();
    await form.locator('select[name="variant_options[size]"]').selectOption('large'); await form.locator('select[name="variant_options[finish]"]').selectOption('charcoal');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), form.getByRole('button', { name: /Add to Cart/i }).click()]);
    await expect(page.getByText('Added to cart.')).toBeVisible();
  });
  await test.step('cart quantity changes to two while preserving the selected SKU and options', async () => {
    let response = await page.goto('/checkout/', { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
    await expect(page.getByText(/Large \/ Charcoal/)).toBeVisible(); await expect(page.getByText(state.variant_sku)).toBeVisible();
    const cart = page.locator('form').filter({ has: page.locator('input[name="mrc_action"][value="update_cart"]') });
    await cart.locator('input[name^="cart_quantity["]').fill('2');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), cart.getByRole('button', { name: 'Update cart' }).click()]);
    await expect(page.locator('input[name^="cart_quantity["]')).toHaveValue('2'); await expect(page.locator('input[name="email"]')).toHaveValue(state.email); await assertResponsive(page, 'authenticated variant checkout'); await assertAccessible(page, 'authenticated variant checkout');
  });
  await test.step('normal checkout completes through the deterministic Demo payment adapter', async () => {
    for (const [name, value] of [['first_name', 'Variant'], ['last_name', 'Customer'], ['address', '42 Variant Street'], ['city', 'Test City'], ['zip', '10001']]) { const field = page.locator(`input[name="${name}"]`); if (await field.count()) await field.fill(value); }
    const country = page.locator('select[name="country"]'); if (await country.count()) await country.selectOption(await country.locator('option[value="US"]').count() ? 'US' : { index: 1 });
    await page.locator('select[name="payment_method"]').selectOption('demo'); const policy = page.locator('input[name="policy_accepted"]'); if (await policy.count()) await policy.check();
    await Promise.all([page.waitForURL(/checkout\/success/), page.getByRole('button', { name: /Continue to payment/i }).click()]);
    await expect(page.getByText(/Thank you for your order/i)).toBeVisible(); await expect(page.getByText(state.email)).toBeVisible(); await assertAccessible(page, 'authenticated variant success');
  });
  state = prepare('capture');
  await test.step('paid order is visible in the owning customer account', async () => {
    const response = await page.goto('/account/', { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
    await expect(page.getByText(state.invoice)).toBeVisible(); await expect(page.getByText('paid', { exact: true })).toBeVisible(); await assertResponsive(page, 'authenticated variant account'); await assertAccessible(page, 'authenticated variant account');
  });
  expect(failures, 'console, server, and document/XHR/fetch failures').toEqual([]);
});
