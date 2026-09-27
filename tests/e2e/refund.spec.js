const { test, expect } = require('@playwright/test');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');
const targetOrigin = new URL(process.env.MERCATO_E2E_BASE_URL || 'https://mercato.test').origin;

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

async function submit(page, button) {
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), button.click()]);
}

async function loginAdmin(page, state) {
  let response = await page.goto(state.process_url, { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await page.locator('input[name="login_name"]').fill(state.manager_username);
  await page.locator('input[name="login_pass"]').fill(state.password);
  await submit(page, page.locator('[name="login_submit"]'));
  response = await page.goto(state.order_detail_url, { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
}

async function loginCustomer(page, state) {
  let response = await page.goto('/account/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  const form = page.locator('form').filter({ has: page.locator('input[name="mrc_account_action"][value="login"]') });
  await form.locator('input[name="email"]').fill(state.email);
  await form.locator('input[name="password"]').fill(state.password);
  await submit(page, form.getByRole('button', { name: 'Sign in' }));
  await expect(page.getByRole('heading', { name: 'Order history' })).toBeVisible();
}

test('authorized full refund is exact-once and customer-visible across account and signed documents', async ({ browser }) => {
  test.setTimeout(90_000);
  const state = fixture();
  const contexts = [];
  const observedFailures = [];
  try {
    const customerContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(customerContext);
    const customerPage = await customerContext.newPage(); observedFailures.push(observeBrowserFailures(customerPage));
    await loginCustomer(customerPage, state);
    let customerRow = customerPage.locator('tr').filter({ hasText: state.invoice });
    await expect(customerRow).toContainText('paid');
    await expect(customerRow).not.toContainText('refunded');

    const managerContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true }); contexts.push(managerContext);
    const managerPage = await managerContext.newPage(); observedFailures.push(observeBrowserFailures(managerPage));
    await test.step('least-privilege refund manager confirms a full Demo refund with the keyboard', async () => {
      await loginAdmin(managerPage, state);
      await expect(managerPage.getByText(state.invoice).first()).toBeVisible();
      await expect(managerPage.getByRole('link', { name: 'Customers', exact: true })).toHaveCount(0);
      const form = managerPage.locator('form.mrc-payment-reconcile-form').filter({ has: managerPage.locator('input[name="mrc_issue_refund"]') });
      await expect(form).toBeVisible();
      await form.locator('input[name="refund_amount"]').fill(String(state.total));
      await form.locator('textarea[name="refund_reason"]').fill(state.reason);
      await form.locator('input[name="refund_confirmed"]').check();
      const replayFields = await form.evaluate(element => Object.fromEntries(new FormData(element).entries()));
      await assertResponsive(managerPage, 'refund manager order detail');
      await assertAccessible(managerPage, 'refund manager order detail', '.mrc-admin-dashboard');
      const submitButton = form.locator('button[type="submit"]');
      await submitButton.focus(); await expect(submitButton).toBeFocused();
      await Promise.all([managerPage.waitForNavigation({ waitUntil: 'domcontentloaded' }), managerPage.keyboard.press('Enter')]);
      await expect(managerPage.getByText(/Refunded .*Payment status is now Refunded\./).first()).toBeVisible();
      await expect(managerPage.getByText('This order has been fully refunded.')).toBeVisible();

      const replay = await managerPage.evaluate(async ({ url, fields }) => {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', redirect: 'follow', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields).toString() });
        return { status: response.status, body: await response.text() };
      }, { url: state.order_detail_url, fields: replayFields });
      expect(replay.status).toBe(200);
      expect(replay.body).toMatch(/Only paid or partially refunded orders can be refunded|CSRF token validation failed/);
      await managerPage.goto(state.order_detail_url, { waitUntil: 'domcontentloaded' });
      await expect(managerPage.getByText('This order has been fully refunded.')).toBeVisible();
      await expect(managerPage.locator('input[name="mrc_issue_refund"]')).toHaveCount(0);
    });

    await test.step('the owning customer sees one durable refund across account, status, receipt and PDF', async () => {
      let response = await customerPage.goto('/account/', { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      customerRow = customerPage.locator('tr').filter({ hasText: state.invoice });
      await expect(customerRow).toContainText('refunded');
      await assertResponsive(customerPage, 'mobile refunded account');
      await assertAccessible(customerPage, 'mobile refunded account');

      response = await customerPage.goto(state.status_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(customerPage.getByRole('heading', { name: `Order ${state.invoice}` })).toBeVisible();
      await expect(customerPage.getByText('Payment: Refunded')).toBeVisible();
      await expect(customerPage.getByText('Refunded', { exact: true }).first()).toBeVisible();
      await expect(customerPage.getByText('Net paid', { exact: true }).first()).toBeVisible();
      await assertResponsive(customerPage, 'mobile refunded status');
      await assertAccessible(customerPage, 'mobile refunded status');

      response = await customerPage.goto(state.receipt_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(customerPage.getByRole('heading', { name: `Receipt ${state.invoice}` })).toBeVisible();
      await expect(customerPage.getByText('Refunded', { exact: true }).first()).toBeVisible();
      await expect(customerPage.getByText('Net paid', { exact: true }).first()).toBeVisible();
      await assertResponsive(customerPage, 'mobile refunded receipt');
      await assertAccessible(customerPage, 'mobile refunded receipt');

      const pdf = await customerPage.request.get(state.receipt_pdf_url);
      expect(pdf.ok()).toBeTruthy();
      expect(pdf.headers()['content-type']).toContain('application/pdf');
      expect(pdf.headers()['cache-control']).toContain('no-store');
      const body = await pdf.body();
      expect(body.subarray(0, 5).toString()).toBe('%PDF-');
      expect(body.toString('latin1')).toContain('(Refunded)');
      expect(body.toString('latin1')).toContain('(Net paid)');
    });

    expect(observedFailures.flat(), 'console, same-origin server, and document/XHR/fetch failures').toEqual([]);
  } finally {
    await Promise.allSettled(contexts.map(context => context.close()));
  }
});
