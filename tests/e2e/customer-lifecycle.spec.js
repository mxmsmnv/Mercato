const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

function prepare(action) {
  execFileSync('php', [path.join(__dirname, 'customer-lifecycle-fixtures.php'), action], {
    cwd: path.join(__dirname, '../..'), env: process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
  });
  return fixture();
}

function observeBrowserFailures(page) {
  const failures = [];
  page.on('console', message => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
  page.on('requestfailed', request => {
    // Chromium reports a successful Content-Disposition attachment hand-off
    // as an aborted document navigation even though Playwright receives the
    // download and its contents normally.
    if (request.url().includes('/api/mercato/download?') && request.failure()?.errorText === 'net::ERR_ABORTED') return;
    if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`);
  });
  page.on('response', response => {
    if (response.url().startsWith('https://mercato.test/') && response.status() >= 500) failures.push(`response: ${response.status()} ${response.url()}`);
  });
  return failures;
}

function actionForm(page, action) {
  return page.locator('form').filter({ has: page.locator(`input[name="mrc_account_action"][value="${action}"]`) });
}

async function submit(page, button) {
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), button.click()]);
}

async function login(page, email, password) {
  let response = await page.goto('/account/', { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  const form = actionForm(page, 'login');
  await form.locator('input[name="email"]').fill(email);
  await form.locator('input[name="password"]').fill(password);
  await submit(page, form.getByRole('button', { name: 'Sign in' }));
}

test('registration, verification, reset, throttling, guest claim, receipt and one-time download', async ({ page, browser }) => {
  test.setTimeout(120_000);
  let state = fixture();
  const observedFailures = [observeBrowserFailures(page)];
  const contexts = [];
  const genericMessage = 'If the account exists, the requested instructions have been sent.';

  try {
    await test.step('registration is CSRF-backed, enumeration-safe, and requires verification', async () => {
      let response = await page.goto('/account/', { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      const registration = actionForm(page, 'register');
      await registration.locator('input[name="first_name"]').fill('Lifecycle');
      await registration.locator('input[name="last_name"]').fill('Customer');
      await registration.locator('input[name="email"]').fill(state.email);
      await registration.locator('input[name="password"]').fill(state.password);
      await registration.getByRole('button', { name: 'Create account' }).focus();
      await expect(registration.getByRole('button', { name: 'Create account' })).toBeFocused();
      await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.keyboard.press('Enter')]);
      await expect(page.getByText(genericMessage)).toBeVisible();

      const duplicate = actionForm(page, 'register');
      await duplicate.locator('input[name="first_name"]').fill('Lifecycle');
      await duplicate.locator('input[name="last_name"]').fill('Customer');
      await duplicate.locator('input[name="email"]').fill(state.email);
      await duplicate.locator('input[name="password"]').fill(state.password);
      await submit(page, duplicate.getByRole('button', { name: 'Create account' }));
      await expect(page.getByText(genericMessage)).toBeVisible();

      await login(page, state.email, state.password);
      await expect(page.getByText('Email or password is incorrect.')).toBeVisible();
      state = prepare('prepare-registration');

      response = await page.goto(state.invalid_verification_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(page.getByText('This verification link is invalid or expired.')).toBeVisible();
      response = await page.goto(state.verification_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(page.getByText('Email verified. You can sign in.')).toBeVisible();
      await page.goto(state.verification_url, { waitUntil: 'domcontentloaded' });
      await expect(page.getByText('This verification link is invalid or expired.')).toBeVisible();
    });

    await test.step('login throttling is enforced without poisoning an independent session', async () => {
      const rateContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(rateContext);
      const ratePage = await rateContext.newPage(); observedFailures.push(observeBrowserFailures(ratePage));
      // Two failures reach Mercato's configured bound before ProcessWire's
      // independent short login-overflow guard can shadow the module result.
      for (let attempt = 0; attempt < 2; attempt++) {
        await login(ratePage, state.other_email, `wrong-${attempt}-password`);
        await expect(ratePage.getByText('Email or password is incorrect.')).toBeVisible();
      }
      await login(ratePage, state.other_email, state.password);
      await expect(ratePage.getByText('Sign-in is temporarily unavailable. Try again later.')).toBeVisible();
      await assertResponsive(ratePage, 'mobile rate-limited sign-in');
      await assertAccessible(ratePage, 'mobile rate-limited sign-in');
      await rateContext.close();

      // ProcessWire also applies a five-second IP/username overflow guard
      // outside Mercato's session-scoped limiter. Let that independent guard
      // expire before proving that a clean browser session can authenticate.
      await page.waitForTimeout(5_500);
      const otherContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(otherContext);
      const otherPage = await otherContext.newPage(); observedFailures.push(observeBrowserFailures(otherPage));
      await login(otherPage, state.other_email, state.password);
      await expect(otherPage.getByRole('heading', { name: 'Order history' })).toBeVisible();
      state.otherContextReady = true;
    });

    await test.step('password reset is enumeration-safe, rejects invalid proof, and rotates the credential', async () => {
      let response = await page.goto('/account/', { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      let reset = actionForm(page, 'request-reset');
      await reset.locator('input[name="email"]').fill(state.email);
      await submit(page, reset.getByRole('button', { name: 'Send reset link' }));
      await expect(page.getByText(genericMessage)).toBeVisible();
      reset = actionForm(page, 'request-reset');
      await reset.locator('input[name="email"]').fill(state.nonexistent_emails[0]);
      await submit(page, reset.getByRole('button', { name: 'Send reset link' }));
      await expect(page.getByText(genericMessage)).toBeVisible();
      state = prepare('prepare-reset');

      response = await page.goto(state.invalid_reset_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      let resetPassword = actionForm(page, 'reset');
      await resetPassword.locator('input[name="password"]').fill(state.new_password);
      await submit(page, resetPassword.getByRole('button', { name: 'Set new password' }));
      await expect(page.getByText('This reset link is invalid, expired, or the password is too short.')).toBeVisible();

      response = await page.goto(state.reset_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      resetPassword = actionForm(page, 'reset');
      await resetPassword.locator('input[name="password"]').fill(state.new_password);
      await submit(page, resetPassword.getByRole('button', { name: 'Set new password' }));
      await expect(page.getByText('Password changed. Sign in again.')).toBeVisible();
      await login(page, state.email, state.password);
      await expect(page.getByText('Email or password is incorrect.')).toBeVisible();
      // Avoid ProcessWire's outer five-second overflow guard masking the
      // positive authentication check immediately after the old-password probe.
      await page.waitForTimeout(5_500);
      await login(page, state.email, state.new_password);
      await expect(page.getByRole('heading', { name: 'Order history' })).toBeVisible();
    });

    await test.step('owner-bound guest claim denies another customer and persists for the owner', async () => {
      const claimRequest = actionForm(page, 'request-claim');
      await claimRequest.locator('input[name="order_reference"]').fill(state.invoice);
      await submit(page, claimRequest.getByRole('button', { name: 'Send claim confirmation' }));
      await expect(page.getByText(genericMessage)).toBeVisible();
      state = prepare('prepare-claim');

      const otherContext = contexts[1];
      const otherPage = otherContext.pages()[0];
      let response = await otherPage.goto(state.claim_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(otherPage.getByText('This claim link is invalid or expired.')).toBeVisible();
      await expect(otherPage.getByText(state.invoice)).toHaveCount(0);

      response = await page.goto(state.invalid_claim_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(page.getByText('This claim link is invalid or expired.')).toBeVisible();
      response = await page.goto(state.claim_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(page.getByText('The guest order is now attached to your account.')).toBeVisible();
      await expect(page.getByText(state.invoice)).toBeVisible();
      await page.goto(state.claim_url, { waitUntil: 'domcontentloaded' });
      await expect(page.getByText('This claim link is invalid or expired.')).toBeVisible();
      await assertResponsive(page, 'claimed customer account');
      await assertAccessible(page, 'claimed customer account');
    });

    await test.step('signed status, HTML/PDF receipt, and bounded digital download work without replay', async () => {
      let response = await page.request.get(state.invalid_status_url);
      expect(response.status()).toBe(404);
      expect(await response.text()).not.toContain(state.invoice);
      response = await page.request.get(state.invalid_receipt_url);
      expect(response.status()).toBe(404);
      expect(await response.text()).not.toContain(state.invoice);

      response = await page.goto(state.status_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(page.getByRole('heading', { name: `Order ${state.invoice}` })).toBeVisible();
      await expect(page.getByText('Payment: Paid')).toBeVisible();
      await assertResponsive(page, 'signed order status');
      await assertAccessible(page, 'signed order status');

      response = await page.goto(state.receipt_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(page.getByRole('heading', { name: `Receipt ${state.invoice}` })).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Downloads' })).toBeVisible();
      await assertResponsive(page, 'signed receipt and downloads');
      await assertAccessible(page, 'signed receipt and downloads');

      const pdf = await page.request.get(state.receipt_pdf_url);
      expect(pdf.ok()).toBeTruthy();
      expect(pdf.headers()['content-type']).toContain('application/pdf');
      expect(pdf.headers()['cache-control']).toContain('no-store');
      expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');

      const downloadPromise = page.waitForEvent('download');
      await page.getByRole('link', { name: state.download_filename }).click();
      const download = await downloadPromise;
      expect(download.suggestedFilename()).toBe(state.download_filename);
      const downloadedPath = await download.path();
      expect(fs.readFileSync(downloadedPath, 'utf8')).toBe(state.download_contents);
      const replay = await page.request.get(state.download_url);
      expect(replay.status()).toBe(404);
    });

    expect(observedFailures.flat(), 'console, same-origin server, and document/XHR/fetch failures').toEqual([]);
  } finally {
    await Promise.allSettled(contexts.map(context => context.close()));
  }
});
