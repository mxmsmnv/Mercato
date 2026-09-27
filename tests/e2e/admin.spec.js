const { test, expect } = require('@playwright/test');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

function observeBrowserFailures(page) {
  const failures = [];
  page.on('console', message => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
  page.on('requestfailed', request => {
    if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`);
  });
  page.on('response', response => {
    if (response.url().startsWith('https://mercato.test/') && response.status() >= 500) failures.push(`response: ${response.status()} ${response.url()}`);
  });
  return failures;
}

async function loginAdmin(page, state, username) {
  let response = await page.goto(state.process_url, { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await page.locator('input[name="login_name"]').fill(username);
  await page.locator('input[name="login_pass"]').fill(state.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.locator('[name="login_submit"]').click(),
  ]);
  response = await page.goto(state.process_url, { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await expect(page.getByText(/Mercato Dashboard/i).first()).toBeVisible();
}

async function postWithoutCsrf(page, url, fields) {
  return page.evaluate(async ({ url, fields }) => {
    const response = await fetch(url, {
      method: 'POST', credentials: 'same-origin', redirect: 'follow',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(fields).toString(),
    });
    return { status: response.status, body: await response.text(), url: response.url };
  }, { url, fields });
}

async function loginCustomer(page, email, password) {
  const response = await page.goto('/account/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  const form = page.locator('form').filter({ has: page.locator('input[name="mrc_account_action"][value="login"]') });
  await form.locator('input[name="email"]').fill(email);
  await form.locator('input[name="password"]').fill(password);
  await form.getByRole('button', { name: /Sign in/i }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Order history' })).toBeVisible();
}

test('least-privilege staff denial, manager fulfilment, and customer-visible update', async ({ browser }) => {
  test.setTimeout(90_000);
  const state = fixture();
  const contexts = [];
  const observedFailures = [];
  try {
    const staffContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(staffContext);
    const staffPage = await staffContext.newPage(); observedFailures.push(observeBrowserFailures(staffPage));
    await test.step('viewer inspects the order but privileged mutations and routes are denied', async () => {
      await loginAdmin(staffPage, state, state.staff.username);
      await expect(staffPage.getByRole('link', { name: 'Customers', exact: true })).toBeVisible();
      await expect(staffPage.getByRole('link', { name: 'Fulfilment', exact: true })).toHaveCount(0);
      let response = await staffPage.goto(state.orders_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(staffPage.getByText(state.invoice).first()).toBeVisible();
      await assertResponsive(staffPage, 'mobile staff order list');
      await assertAccessible(staffPage, 'mobile staff order list', '.mrc-admin-dashboard');

      response = await staffPage.goto(state.order_detail_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(staffPage.getByText(state.invoice).first()).toBeVisible();
      await expect(staffPage.getByText(state.customer_email).first()).toBeVisible();
      await assertResponsive(staffPage, 'mobile staff order detail');
      await assertAccessible(staffPage, 'mobile staff order detail', '.mrc-admin-dashboard');

      let result = await postWithoutCsrf(staffPage, state.order_detail_url, { mrc_issue_refund: '1', order_id: String(state.order_ids[0]), refund_confirmed: '1', refund_amount: '1', refund_reason: 'unauthorized probe' });
      expect(result.status).toBe(200); expect(result.body).toContain('Missing permission: mercato-refund-orders');
      result = await postWithoutCsrf(staffPage, state.order_detail_url, { mrc_reconcile_payment: '1', order_id: String(state.order_ids[0]), payment_status: 'refunded', payment_reason: 'unauthorized probe' });
      expect(result.status).toBe(200); expect(result.body).toContain('Missing permission: mercato-edit-orders');

      response = await staffPage.goto(state.customer_detail_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(staffPage.getByText(state.customer_email).first()).toBeVisible();
      result = await postWithoutCsrf(staffPage, state.customer_detail_url, { mrc_privacy_action: 'anonymize', privacy_confirmed: '1', privacy_reason: 'unauthorized probe' });
      expect(result.status).toBe(200); expect(result.body).toContain('Missing permission: mercato-manage-privacy');

      result = await postWithoutCsrf(staffPage, state.notifications_url, { notification_action: 'save_template', template_key: 'order_confirmation', subject: 'Unauthorized', text_body: 'Unauthorized', html_body_order_confirmation: '<p>Unauthorized</p>' });
      expect(result.status).toBe(200); expect(result.body).toContain('mercato-manage-notifications'); expect(result.body).toContain('Access denied');
      response = await staffPage.goto(state.fulfilment_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(staffPage.getByText('Access denied')).toBeVisible();
      await expect(staffPage.getByText('mercato-fulfil-orders')).toBeVisible();
    });

    const managerContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true }); contexts.push(managerContext);
    const managerPage = await managerContext.newPage(); observedFailures.push(observeBrowserFailures(managerPage));
    await test.step('fulfilment manager inspects and updates the paid order using the keyboard', async () => {
      await loginAdmin(managerPage, state, state.manager.username);
      await expect(managerPage.getByRole('link', { name: 'Fulfilment', exact: true })).toBeVisible();
      await expect(managerPage.getByRole('link', { name: 'Customers', exact: true })).toHaveCount(0);
      let response = await managerPage.goto(state.order_detail_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(managerPage.getByText(state.invoice).first()).toBeVisible();
      await assertResponsive(managerPage, 'manager order detail');
      await assertAccessible(managerPage, 'manager order detail', '.mrc-admin-dashboard');

      response = await managerPage.goto(state.fulfilment_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      const row = managerPage.locator('tr').filter({ hasText: state.invoice });
      await expect(row).toBeVisible();
      const form = row.locator('form.mrc-fulfilment-form');
      await form.locator('select[name="fulfilment_status"]').selectOption('shipped');
      await form.locator('input[name="fulfilment_tracking"]').fill(state.expected_tracking);
      await form.locator('input[name="fulfilment_tracking_url"]').fill('https://tracking.example.test/' + state.expected_tracking);
      await form.locator('textarea[name="fulfilment_notes"]').fill(state.expected_note);
      await assertResponsive(managerPage, 'manager fulfilment queue');
      await assertAccessible(managerPage, 'manager fulfilment queue', '.mrc-admin-dashboard');
      // The ProcessWire icon font contributes a private-use glyph to the
      // computed accessible name, so address the semantic submit control
      // directly while still exercising keyboard submission.
      const save = form.locator('button[name="mrc_update_fulfilment"]');
      await save.focus(); await expect(save).toBeFocused();
      await Promise.all([managerPage.waitForNavigation({ waitUntil: 'domcontentloaded' }), managerPage.keyboard.press('Enter')]);
      await expect(managerPage.getByText(`Updated fulfilment for ${state.invoice}.`).first()).toBeVisible();
      await expect(managerPage.locator('tr').filter({ hasText: state.invoice }).locator('select[name="fulfilment_status"]')).toHaveValue('shipped');
    });

    const customerContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(customerContext);
    const customerPage = await customerContext.newPage(); observedFailures.push(observeBrowserFailures(customerPage));
    await test.step('isolated customer session observes the persisted fulfilment update', async () => {
      await loginCustomer(customerPage, state.customer.email, state.password);
      const row = customerPage.locator('tr').filter({ hasText: state.invoice });
      await expect(row).toContainText('paid');
      await expect(row).toContainText('shipped');
      await expect(customerPage.getByText(state.manager.email)).toHaveCount(0);
      await expect(customerPage.getByText(state.staff.email)).toHaveCount(0);
      await assertResponsive(customerPage, 'mobile customer fulfilment state');
      await assertAccessible(customerPage, 'mobile customer fulfilment state');
    });

    expect(observedFailures.flat(), 'console, same-origin server, and document/XHR/fetch failures').toEqual([]);
  } finally {
    await Promise.allSettled(contexts.map(context => context.close()));
  }
});
