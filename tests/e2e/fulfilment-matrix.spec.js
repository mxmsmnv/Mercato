const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

const targetOrigin = new URL(process.env.MERCATO_E2E_BASE_URL || 'https://mercato.test').origin;
function prepare(action) {
  execFileSync('php', [path.join(__dirname, 'fulfilment-matrix-fixtures.php'), action], { cwd: path.join(__dirname, '../..'), env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  return fixture();
}
function actionForm(page, action) { return page.locator('form').filter({ has: page.locator(`input[name="mrc_account_action"][value="${action}"]`) }); }
function observeBrowserFailures(page) {
  const failures = [];
  page.on('console', message => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
  page.on('requestfailed', request => { if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`); });
  page.on('response', response => { if (new URL(response.url()).origin === targetOrigin && response.status() >= 500) failures.push(`response: ${response.status()} ${response.url()}`); });
  return failures;
}
function expectSessionPrivate(response) {
  const headers = response.headers();
  expect(headers['cache-control']).toMatch(/private/); expect(headers['cache-control']).toMatch(/no-store/); expect(headers['cache-control']).toMatch(/max-age=0/);
  expect(headers.pragma).toMatch(/no-cache/); expect(headers.vary || '').toMatch(/Cookie/i);
}
async function signIn(page, state, scenario) {
  let response = await page.goto('/account/', { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
  const form = actionForm(page, 'login'); await form.locator('input[name="email"]').fill(scenario.email); await form.locator('input[name="password"]').fill(state.password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), form.getByRole('button', { name: 'Sign in' }).click()]);
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible();
}
async function addProducts(page, urls) {
  for (const url of urls) {
    const response = await page.goto(url, { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy(); expectSessionPrivate(response);
    const form = page.locator('form').filter({ has: page.locator('input[name="mrc_action"][value="add_to_cart"]') }).first();
    const [navigation] = await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), form.getByRole('button', { name: /Add to Cart/i }).click()]); expectSessionPrivate(navigation);
    await expect(page.getByText(/Added to cart/i)).toBeVisible();
  }
}
async function completeCheckout(page, scenario, name) {
  const response = await page.goto('/checkout/', { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy(); expectSessionPrivate(response);
  await expect(page.locator('form input[name="checkout_nonce"]')).toHaveCount(1);
  await expect(page.locator('input[name^="cart_quantity["]')).toHaveCount(scenario.products.length);
  for (const [field, value] of [['first_name', name[0].toUpperCase() + name.slice(1)], ['last_name', 'Matrix'], ['email', scenario.email]]) {
    await page.locator(`input[name="${field}"]`).fill(value);
  }
  const fulfilment = page.locator('select[name="fulfilment_method"]');
  const option = fulfilment.locator(`option[value="${scenario.method}"]`); await expect(option).toBeEnabled(); await fulfilment.selectOption(scenario.method);
  const address = page.locator('#mrc-delivery-address');
  if (!scenario.requires_shipping || scenario.method === 'store_pickup') {
    await expect(address).toBeHidden(); await expect(page.locator('input[name="address"]')).not.toHaveAttribute('required', '');
  } else {
    await expect(address).toBeVisible();
    for (const [field, value] of [['address', '10 Matrix Street'], ['city', 'New York'], ['zip', '10001']]) await page.locator(`input[name="${field}"]`).fill(value);
    const country = page.locator('select[name="country"]'); if (await country.count()) await country.selectOption('US');
  }
  if (scenario.method === 'store_pickup') {
    const pickup = page.locator('select[name="pickup_location"]'); await expect(pickup).toBeVisible(); await pickup.selectOption('pickup_2');
    await expect(page.locator('#mrc-fulfilment-detail')).toContainText('Matrix counter B');
  }
  if (!scenario.requires_shipping) await expect(option).toContainText('No shipping required');
  await page.locator('select[name="payment_method"]').selectOption('demo');
  const policy = page.locator('input[name="policy_accepted"]'); if (await policy.count()) await policy.check();
  await assertResponsive(page, `${name} matrix checkout`); await assertAccessible(page, `${name} matrix checkout`);
  await Promise.all([page.waitForURL(/checkout\/success/), page.getByRole('button', { name: /Continue to payment/i }).click()]);
  await expect(page.getByText(/Thank you for your order/i)).toBeVisible(); await expect(page.getByText(scenario.email)).toBeVisible();
  await assertResponsive(page, `${name} matrix success`); await assertAccessible(page, `${name} matrix success`);
}

test('guest and customer order-to-cash fulfilment matrix is durable and private', async ({ browser }) => {
  test.setTimeout(240_000); let state = fixture(); const observed = [];
  for (const [name, scenario] of Object.entries(state.scenarios)) {
    await test.step(`${name}: ${scenario.identity} checkout`, async () => {
      const viewport = scenario.viewport === 'mobile' ? { width: 390, height: 844 } : { width: 1440, height: 1000 };
      const context = await browser.newContext({ viewport, ignoreHTTPSErrors: true }); const page = await context.newPage(); const failures = observeBrowserFailures(page); observed.push(...failures);
      try { if (scenario.identity === 'customer') await signIn(page, state, scenario); await addProducts(page, scenario.product_urls); await completeCheckout(page, scenario, name); }
      finally { observed.push(...failures); await context.close(); }
    });
  }
  state = prepare('capture');
  for (const [name, scenario] of Object.entries(state.scenarios).filter(([, value]) => value.identity === 'customer')) {
    await test.step(`${name}: owning account sees only its paid order`, async () => {
      const context = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); const page = await context.newPage(); const failures = observeBrowserFailures(page);
      try {
        await signIn(page, state, scenario); const response = await page.goto('/account/', { waitUntil: 'domcontentloaded' }); expect(response?.ok()).toBeTruthy();
        await expect(page.getByText(state.orders[name].invoice)).toBeVisible(); await expect(page.getByText('paid', { exact: true }).first()).toBeVisible();
        for (const [other, order] of Object.entries(state.orders)) if (other !== name) await expect(page.getByText(order.invoice)).toHaveCount(0);
        await assertResponsive(page, `${name} matrix account`); await assertAccessible(page, `${name} matrix account`);
      } finally { observed.push(...failures); await context.close(); }
    });
  }
  expect(observed, 'console, same-origin server, and document/XHR/fetch failures').toEqual([]);
});
