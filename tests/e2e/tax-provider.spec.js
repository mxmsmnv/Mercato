const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

const targetOrigin = new URL(process.env.MERCATO_E2E_BASE_URL || 'https://mercato.test').origin;

function prepare(action) {
  execFileSync('php', [path.join(__dirname, 'tax-provider-fixtures.php'), action], {
    cwd: path.join(__dirname, '../..'), env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe']
  });
  return fixture();
}

function observeBrowserFailures(page) {
  const failures = [];
  page.on('console', message => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
  page.on('requestfailed', request => {
    if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`);
  });
  page.on('response', response => {
    if (new URL(response.url()).origin === targetOrigin && response.status() >= 500) failures.push(`response: ${response.status()} ${response.url()}`);
  });
  return failures;
}

async function completeCheckout(page, productUrl, email, provider) {
  let response = await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  const add = page.locator('form').filter({ has: page.locator('input[name="mrc_action"][value="add_to_cart"]') }).first();
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), add.getByRole('button').click()]);
  response = await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await page.locator('input[name="first_name"]').fill('Provider');
  await page.locator('input[name="last_name"]').fill(provider);
  await page.locator('input[name="email"]').fill(email);
  for (const [name, value] of [['address', '1 Tax Test Street'], ['city', 'New York'], ['zip', '10001']]) {
    const input = page.locator(`input[name="${name}"]`);
    if (await input.isVisible()) await input.fill(value);
  }
  const country = page.locator('select[name="country"]');
  if (await country.isVisible()) await country.selectOption('US');
  const region = page.locator('select[name="region"], input[name="region"]');
  if (await region.count() && await region.first().isVisible()) {
    if (await region.first().evaluate(element => element.tagName === 'SELECT')) {
      const ny = region.first().locator('option[value="NY"]');
      if (await ny.count()) await region.first().selectOption('NY');
    }
    else await region.first().fill('NY');
  }
  await page.locator('select[name="payment_method"]').selectOption('demo');
  const policy = page.locator('input[name="policy_accepted"]');
  if (await policy.count()) await policy.check();
  await assertResponsive(page, `${provider} provider checkout`);
  await assertAccessible(page, `${provider} provider checkout`);
  await Promise.all([
    page.waitForURL(/checkout\/success/),
    page.getByRole('button', { name: /Continue to payment/i }).click()
  ]);
  await expect(page.getByText(/Thank you for your order/i)).toBeVisible();
  await expect(page.getByText(email)).toBeVisible();
  await assertResponsive(page, `${provider} provider success`);
  await assertAccessible(page, `${provider} provider success`);
}

test('Stripe Tax and Quaderno complete real HTTP tax quote, commit, and refund lifecycles', async ({ browser }) => {
  test.setTimeout(150_000);
  const contexts = [];
  const failures = [];
  try {
    let state = prepare('activate-stripe');
    const stripeContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true });
    contexts.push(stripeContext);
    const stripePage = await stripeContext.newPage(); failures.push(observeBrowserFailures(stripePage));
    await test.step('Stripe Tax checkout reaches a durably paid and committed order', async () => {
      await completeCheckout(stripePage, state.stripe_product_url, state.stripe_email, 'Stripe');
      state = prepare('capture-stripe');
      expect(state.stripe_tax).toBeCloseTo(2, 2);
      expect(state.stripe_total).toBeCloseTo(22, 2);
    });

    state = prepare('activate-quaderno');
    const quadernoContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true });
    contexts.push(quadernoContext);
    const quadernoPage = await quadernoContext.newPage(); failures.push(observeBrowserFailures(quadernoPage));
    await test.step('Quaderno checkout reaches a durably paid and committed order', async () => {
      await completeCheckout(quadernoPage, state.quaderno_product_url, state.quaderno_email, 'Quaderno');
      state = prepare('capture-quaderno');
      expect(state.quaderno_tax).toBeCloseTo(3, 2);
      expect(state.quaderno_total).toBeCloseTo(33, 2);
    });

    await test.step('both provider refunds are committed and customer-visible', async () => {
      prepare('activate-stripe'); prepare('refund-stripe');
      prepare('activate-quaderno'); state = prepare('refund-quaderno');
      for (const [page, provider] of [[stripePage, 'stripe'], [quadernoPage, 'quaderno']]) {
        const response = await page.goto(state[`${provider}_status_url`], { waitUntil: 'domcontentloaded' });
        expect(response?.ok()).toBeTruthy();
        expect(response.headers()['cache-control']).toContain('no-store');
        expect(response.headers()['x-robots-tag']).toContain('noindex');
        await expect(page.getByText('Payment: Refunded')).toBeVisible();
        await expect(page.getByText(state[`${provider}_invoice`])).toBeVisible();
        await assertResponsive(page, `${provider} refunded status`);
        await assertAccessible(page, `${provider} refunded status`);
      }
    });

    prepare('verify');
    expect(failures.flat(), 'console, same-origin server, and document/XHR/fetch failures').toEqual([]);
  } finally {
    await Promise.allSettled(contexts.map(context => context.close()));
  }
});
