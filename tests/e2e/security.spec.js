const { test, expect } = require('@playwright/test');
const { fixture } = require('./helpers');

test.describe('anonymous security boundaries', () => {
  test('Mercato admin requires an authenticated ProcessWire session', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'Site-specific security probes run once.');
    const state = fixture();
    expect(state.process_mercato_url).toBeTruthy();
    const response = await page.goto(state.process_mercato_url);
    expect(response).not.toBeNull();
    expect(response.ok()).toBeTruthy();
    await expect(page).toHaveURL(new RegExp(state.admin_url.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    await expect(page.locator('input[type="password"]')).toBeVisible();
    await expect(page.getByText(/Mercato Commerce Dashboard/i)).toHaveCount(0);
  });

  test('account registration rejects a missing CSRF token without persistence', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating security probes run once.');
    const state = fixture();
    await page.goto('/account/');
    const result = await page.evaluate(async (email) => {
      const body = new URLSearchParams({
        mrc_account_action: 'register',
        first_name: 'E2E',
        last_name: 'CSRF',
        email,
        password: 'NotCreated-42!',
      });
      const response = await fetch('/account/', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      });
      return { status: response.status, body: await response.text() };
    }, state.csrf_probe_email);
    expect(result.status).toBe(200);
    expect(result.body).toContain('Form session expired. Reload and try again.');
    expect(result.body).not.toContain('Check your email to verify your account.');
  });
});
