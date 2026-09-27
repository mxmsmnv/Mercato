const { test, expect } = require('@playwright/test');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

function observeBrowserFailures(page) {
  const failures = [];
  page.on('console', message => {
    if (message.type() === 'error') failures.push(`console: ${message.text()}`);
  });
  page.on('requestfailed', request => {
    if (['document', 'xhr', 'fetch'].includes(request.resourceType())) {
      failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`);
    }
  });
  page.on('response', response => {
    const url = new URL(response.url());
    if (url.origin === 'https://mercato.test' && response.status() >= 500) {
      failures.push(`response: ${response.status()} ${response.url()}`);
    }
  });
  return failures;
}

async function signIn(page, email, password) {
  const response = await page.goto('/account/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  const form = page.locator('form').filter({ has: page.locator('input[name="mrc_account_action"][value="login"]') });
  await form.locator('input[name="email"]').fill(email);
  await form.locator('input[name="password"]').fill(password);
  await form.getByRole('button', { name: /Sign in/i }).click();
  await expect(page.getByRole('heading', { name: 'Order history' })).toBeVisible();
}

test('customer failed payment recovery is private, replay-safe and persisted', async ({ page }, testInfo) => {
  const state = fixture();
  const target = testInfo.project.name === 'webkit-mobile' ? state.recovery.mobile : state.recovery.desktop;
  const other = testInfo.project.name === 'webkit-mobile' ? state.recovery.desktop : state.recovery.mobile;
  const browserFailures = observeBrowserFailures(page);

  await test.step('authenticate and enforce account ownership isolation', async () => {
    await signIn(page, target.email, target.password);
    await expect(page.getByText(target.invoice)).toBeVisible();
    await expect(page.getByText(other.invoice)).toHaveCount(0);
    await assertResponsive(page, `${testInfo.project.name} recovery account`);
    await assertAccessible(page, `${testInfo.project.name} recovery account`);
  });

  if (testInfo.project.name === 'chromium-desktop') {
    await test.step('deny malformed and expired recovery links without mutation', async () => {
      const malformed = new URL(target.payment_url, page.url());
      malformed.searchParams.set('mrc_token', 'malformed-token');
      let response = await page.goto(malformed.toString(), { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(page.getByText('This payment link is invalid or no longer payable.')).toBeVisible();

      response = await page.goto(state.recovery.expired.payment_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(page.getByText('This payment link is invalid or no longer payable.')).toBeVisible();
    });
  }

  await test.step('complete the failed order through the normal Demo Payment recovery route', async () => {
    const response = await page.goto(target.payment_url, { waitUntil: 'domcontentloaded' });
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByText(target.invoice)).toBeVisible();
    await expect(page.locator('input[name="email"]')).toHaveValue(target.email);
    await expect(page.locator('select[name="payment_method"]')).toHaveValue('demo');
    const policy = page.locator('input[name="policy_accepted"]');
    if (await policy.count()) await policy.check();
    await assertResponsive(page, `${testInfo.project.name} recovery checkout`);
    await assertAccessible(page, `${testInfo.project.name} recovery checkout`);
    await Promise.all([
      page.waitForURL(/checkout\/success/),
      page.getByRole('button', { name: /Continue to payment/i }).click(),
    ]);
    await expect(page.getByText(/Thank you for your order/i)).toBeVisible();
    await assertResponsive(page, `${testInfo.project.name} recovery success`);
    await assertAccessible(page, `${testInfo.project.name} recovery success`);
  });

  await test.step('deny replay and show the durable paid state in the owner account', async () => {
    let response = await page.goto(target.payment_url, { waitUntil: 'domcontentloaded' });
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByText('This payment link is invalid or no longer payable.')).toBeVisible();
    response = await page.goto('/account/', { waitUntil: 'domcontentloaded' });
    expect(response?.ok()).toBeTruthy();
    const row = page.locator('tr').filter({ hasText: target.invoice });
    await expect(row).toContainText('paid');
    await expect(row).not.toContainText('failed');
  });

  expect(browserFailures, 'console, same-origin server, and document/XHR/fetch failures').toEqual([]);
});
