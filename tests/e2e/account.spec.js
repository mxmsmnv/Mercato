const { test, expect } = require('@playwright/test');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

async function signIn(page, email, password) {
  await page.goto('/account/');
  const form = page.locator('form').filter({ has: page.locator('input[name="mrc_account_action"][value="login"]') });
  await form.locator('input[name="email"]').fill(email);
  await form.locator('input[name="password"]').fill(password);
  await form.getByRole('button', { name: /Sign in/i }).click();
}

test('verified customer profile, order history, logout and ownership isolation', async ({ page, browser }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Stateful multi-session account journey runs once.');
  const state = fixture();
  const other = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    ignoreHTTPSErrors: testInfo.project.use.ignoreHTTPSErrors,
  });
  try {
    await signIn(page, state.customer_email, 'incorrect-password');
    await expect(page.getByText('Email or password is incorrect.')).toBeVisible();
    await signIn(page, state.customer_email, state.customer_password);
    await expect(page.getByRole('heading', { name: 'Order history' })).toBeVisible();
    await expect(page.getByText(state.customer_invoice)).toBeVisible();

    const profile = page.locator('form').filter({ has: page.locator('input[name="mrc_account_action"][value="profile"]') });
    await profile.locator('input[name="phone"]').fill('+1 555 0199');
    await profile.locator('input[name="address"]').fill('Updated Browser Street');
    await profile.locator('input[name="city"]').fill('Test City');
    await profile.locator('input[name="zip"]').fill('10001');
    await profile.locator('input[name="country"]').fill('US');
    await profile.getByRole('button', { name: /Save profile/i }).click();
    await expect(page.getByText('Profile saved.')).toBeVisible();
    await assertResponsive(page, 'authenticated customer account');
    await assertAccessible(page, 'authenticated customer account');

    const otherPage = await other.newPage();
    await signIn(otherPage, state.other_customer_email, state.other_customer_password);
    await expect(otherPage.getByRole('heading', { name: 'Order history' })).toBeVisible();
    await expect(otherPage.getByText(state.customer_invoice)).toHaveCount(0);

    const logout = page.locator('form').filter({ has: page.locator('input[name="mrc_account_action"][value="logout"]') });
    await logout.getByRole('button', { name: /Sign out/i }).click();
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  } finally {
    await other.close();
  }
});
