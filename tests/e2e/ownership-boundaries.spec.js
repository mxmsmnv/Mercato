const { test, expect } = require('@playwright/test');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

const targetOrigin = new URL(process.env.MERCATO_E2E_BASE_URL || 'https://mercato.test').origin;

function observeBrowserFailures(page) {
  const failures = [];
  page.on('console', message => {
    if (message.type() !== 'error') return;
    // Chromium emits a generic console error for the intentional private 404
    // document probes below. Resource-level 404s remain blocking via the
    // response listener, while each expected document response is asserted.
    if (/Failed to load resource: the server responded with a status of 404/i.test(message.text())) return;
    failures.push(`console: ${message.text()}`);
  });
  page.on('requestfailed', request => {
    if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`);
  });
  page.on('response', response => {
    if (new URL(response.url()).origin !== targetOrigin) return;
    if (response.status() >= 500 || (response.status() === 404 && !['document', 'xhr', 'fetch'].includes(response.request().resourceType()))) {
      failures.push(`response: ${response.status()} ${response.url()}`);
    }
  });
  return failures;
}

function routeCode(url, beforePdf = false) {
  const parts = new URL(url, targetOrigin).pathname.split('/').filter(Boolean);
  return parts[parts.length - (beforePdf ? 2 : 1)];
}

function expectPrivateHeaders(response) {
  expect(response.headers()['cache-control']).toContain('no-store');
  expect(response.headers()['x-robots-tag']).toContain('noindex');
}

async function expectPrivateHtml(page, response, status, forbiddenInvoice = '') {
  expect(response.status()).toBe(status);
  expectPrivateHeaders(response);
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  if (forbiddenInvoice) await expect(page.locator('body')).not.toContainText(forbiddenInvoice);
}

function actionForm(page, action) {
  return page.locator('form').filter({ has: page.locator(`input[name="mrc_account_action"][value="${action}"]`) });
}

async function loginCustomer(page, email, password) {
  let response = await page.goto('/account/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  const form = actionForm(page, 'login');
  await form.locator('input[name="email"]').fill(email);
  await form.locator('input[name="password"]').fill(password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), form.getByRole('button', { name: 'Sign in' }).click()]);
  await expect(page.getByRole('heading', { name: 'Order history' })).toBeVisible();
}

async function loginStaff(page, state) {
  let response = await page.goto(state.process_url, { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await page.locator('input[name="login_name"]').fill(state.staff_username);
  await page.locator('input[name="login_pass"]').fill(state.password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('[name="login_submit"]').click()]);
}

async function postForm(page, url, fields) {
  return page.evaluate(async ({ url, fields }) => {
    const response = await fetch(url, { method: 'POST', credentials: 'same-origin', redirect: 'follow', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields).toString() });
    return { status: response.status, body: await response.text(), url: response.url };
  }, { url, fields });
}

test('anonymous capability links, account ownership, and unauthorized staff stay isolated', async ({ browser }) => {
  test.setTimeout(90_000);
  const state = fixture(); const contexts = []; const observedFailures = [];
  try {
    const anonymousContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(anonymousContext);
    const anonymousPage = await anonymousContext.newPage(); observedFailures.push(observeBrowserFailures(anonymousPage));

    await test.step('anonymous account and mutation attempts disclose and change nothing', async () => {
      let response = await anonymousPage.goto('/account/', { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(anonymousPage.getByText(state.owner_invoice)).toHaveCount(0);
      await expect(anonymousPage.getByText(state.second_invoice)).toHaveCount(0);
      const denied = await postForm(anonymousPage, '/account/', { mrc_account_action: 'profile', user_id: String(state.owner_user_id), order_id: String(state.owner_order_id), first_name: 'Anonymous overwrite', revision: String(state.owner_revision) });
      expect(denied.status).toBe(200);
      expect(denied.body).toContain('Form session expired. Reload and try again.');
      await assertResponsive(anonymousPage, 'anonymous account isolation');
      await assertAccessible(anonymousPage, 'anonymous account isolation');
    });

    await test.step('valid signed routes are private read-only capabilities with stable replay', async () => {
      let response = await anonymousPage.goto(state.owner_status_url, { waitUntil: 'domcontentloaded' });
      await expectPrivateHtml(anonymousPage, response, 200);
      await expect(anonymousPage.getByRole('heading', { name: `Order ${state.owner_invoice}` })).toBeVisible();
      await assertResponsive(anonymousPage, 'anonymous signed status');
      await assertAccessible(anonymousPage, 'anonymous signed status');
      response = await anonymousPage.goto(state.owner_status_url, { waitUntil: 'domcontentloaded' });
      await expectPrivateHtml(anonymousPage, response, 200);
      const postReplay = await anonymousPage.request.post(state.owner_status_url);
      expect(postReplay.status()).toBe(200); expectPrivateHeaders(postReplay);

      response = await anonymousPage.goto(state.owner_receipt_url, { waitUntil: 'domcontentloaded' });
      await expectPrivateHtml(anonymousPage, response, 200);
      await expect(anonymousPage.getByRole('heading', { name: `Receipt ${state.owner_invoice}` })).toBeVisible();
      const pdf = await anonymousPage.request.get(state.owner_pdf_url);
      expect(pdf.status()).toBe(200); expectPrivateHeaders(pdf);
      expect(pdf.headers()['content-type']).toContain('application/pdf');
      expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');
    });

    await test.step('malformed, wrong-purpose, and expired signed routes fail privately', async () => {
      const statusCode = routeCode(state.owner_status_url); const receiptCode = routeCode(state.owner_receipt_url);
      const htmlFailures = [
        `/order/status/malformed-${state.run_id}/`,
        `/order/receipt/malformed-${state.run_id}/`,
        `/order/status/${receiptCode}/`,
        `/order/receipt/${statusCode}/`,
        state.expired_status_url,
        state.expired_receipt_url,
      ];
      for (const url of htmlFailures) {
        const response = await anonymousPage.goto(url, { waitUntil: 'domcontentloaded' });
        await expectPrivateHtml(anonymousPage, response, 404, state.owner_invoice);
      }
      for (const url of [`/order/receipt/malformed-${state.run_id}/pdf/`, state.expired_pdf_url]) {
        const response = await anonymousPage.request.get(url);
        expect(response.status()).toBe(404); expectPrivateHeaders(response);
        expect(await response.text()).not.toContain(state.owner_invoice);
      }
      await assertResponsive(anonymousPage, 'private signed-route failure');
      await assertAccessible(anonymousPage, 'private signed-route failure');
    });

    const secondContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true }); contexts.push(secondContext);
    const secondPage = await secondContext.newPage(); observedFailures.push(observeBrowserFailures(secondPage));
    await test.step('second customer sees only owned records and cannot target another account or order', async () => {
      await loginCustomer(secondPage, state.second_email, state.password);
      await expect(secondPage.getByText(state.second_invoice)).toBeVisible();
      await expect(secondPage.getByText(state.owner_invoice)).toHaveCount(0);
      await expect(secondPage.getByText(state.expired_invoice)).toHaveCount(0);

      const profile = actionForm(secondPage, 'profile');
      await profile.locator('input[name="first_name"]').fill(state.second_expected_first_name);
      const fields = await profile.evaluate((element, target) => ({
        ...Object.fromEntries(new FormData(element).entries()),
        user_id: String(target.ownerUserId),
        order_id: String(target.ownerOrderId)
      }), { ownerUserId: state.owner_user_id, ownerOrderId: state.owner_order_id });
      let result = await postForm(secondPage, '/account/', fields);
      expect(result.status).toBe(200); expect(result.body).toContain('Profile saved.');
      result = await postForm(secondPage, '/account/', fields);
      expect(result.status).toBe(200); expect(result.body).toContain('Your profile changed in another session. Reload and try again.');

      await secondPage.goto('/account/', { waitUntil: 'domcontentloaded' });
      await expect(actionForm(secondPage, 'profile').locator('input[name="first_name"]')).toHaveValue(state.second_expected_first_name);
      const claim = actionForm(secondPage, 'request-claim');
      await claim.locator('input[name="order_reference"]').fill(state.owner_invoice);
      await Promise.all([secondPage.waitForNavigation({ waitUntil: 'domcontentloaded' }), claim.getByRole('button', { name: 'Send claim confirmation' }).click()]);
      await expect(secondPage.getByText('Order is not eligible for account claim.')).toBeVisible();
      let response = await secondPage.goto(`/account/?action=claim&order=${state.owner_order_id}&token=malformed-${state.run_id}`, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(secondPage.getByText('This claim link is invalid or expired.')).toBeVisible();
      await expect(secondPage.getByText(state.owner_invoice)).toHaveCount(0);

      response = await secondPage.goto(state.owner_status_url, { waitUntil: 'domcontentloaded' });
      await expectPrivateHtml(secondPage, response, 200);
      await expect(secondPage.getByText(state.owner_invoice)).toBeVisible();
      await secondPage.goto('/account/', { waitUntil: 'domcontentloaded' });
      await expect(secondPage.getByText(state.owner_invoice)).toHaveCount(0);
      await assertResponsive(secondPage, 'second customer ownership isolation');
      await assertAccessible(secondPage, 'second customer ownership isolation');
    });

    const staffContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(staffContext);
    const staffPage = await staffContext.newPage(); observedFailures.push(observeBrowserFailures(staffPage));
    await test.step('staff without order permission cannot inspect or mutate the owner record', async () => {
      await loginStaff(staffPage, state);
      let response = await staffPage.goto(state.order_detail_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(staffPage.getByText('Access denied')).toBeVisible();
      await expect(staffPage.getByText('mercato-view-orders')).toBeVisible();
      await expect(staffPage.getByText(state.owner_invoice)).toHaveCount(0);
      const result = await postForm(staffPage, state.order_detail_url, { mrc_issue_refund: '1', order_id: String(state.owner_order_id), refund_confirmed: '1', refund_amount: '18', refund_reason: `unauthorized-${state.run_id}` });
      expect(result.status).toBe(200); expect(result.body).toContain('mercato-view-orders'); expect(result.body).toContain('Access denied');
      response = await staffPage.goto('/account/', { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(staffPage.getByText('This signed-in staff/user session is not a verified customer account.')).toBeVisible();
      await expect(staffPage.getByText(state.owner_invoice)).toHaveCount(0);
      await assertResponsive(staffPage, 'unauthorized staff isolation');
      await assertAccessible(staffPage, 'unauthorized staff isolation');
    });

    expect(observedFailures.flat(), 'console, same-origin server, and document/XHR/fetch failures').toEqual([]);
  } finally {
    await Promise.allSettled(contexts.map(context => context.close()));
  }
});
