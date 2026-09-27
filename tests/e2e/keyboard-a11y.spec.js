const fs = require('fs');
const { test, expect } = require('@playwright/test');

function readState(path) {
  if (!path || !fs.existsSync(path)) return null;
  return JSON.parse(fs.readFileSync(path, 'utf8'));
}

function coreFixture() {
  const state = readState(process.env.MERCATO_E2E_CORE_STATE || process.env.MERCATO_E2E_STATE);
  return state && state.product_url && state.customer_email ? state : null;
}

function adminFixture() {
  const state = readState(process.env.MERCATO_E2E_ADMIN_STATE) || readState(process.env.MERCATO_E2E_STATE);
  return state && state.manager && state.manager.username && state.fulfilment_url ? state : null;
}

async function tabTo(page, locator, limit = 120) {
  const target = await locator.elementHandle();
  if (!target) throw new Error(`Keyboard target was not rendered: ${locator}`);
  for (let index = 0; index < limit; index++) {
    await page.keyboard.press('Tab');
    if (await page.evaluate(element => document.activeElement === element, target)) return;
  }
  throw new Error(`Tab did not reach the requested target within ${limit} steps.`);
}

async function expectVisibleFocus(locator, label) {
  const evidence = await locator.evaluate(element => {
    const snapshot = () => {
      const style = getComputedStyle(element);
      return {
        outlineStyle: style.outlineStyle,
        outlineWidth: Number.parseFloat(style.outlineWidth) || 0,
        outlineColor: style.outlineColor,
        boxShadow: style.boxShadow,
        borderColor: style.borderColor,
        backgroundColor: style.backgroundColor,
      };
    };
    const focused = snapshot();
    element.blur();
    const idle = snapshot();
    element.focus({ preventScroll: true });
    const outline = focused.outlineStyle !== 'none' && focused.outlineWidth >= 1 && focused.outlineColor !== 'transparent';
    const shadow = focused.boxShadow !== 'none' && focused.boxShadow !== idle.boxShadow;
    const changedSurface = focused.borderColor !== idle.borderColor || focused.backgroundColor !== idle.backgroundColor;
    return { visible: outline || shadow || changedSurface, focused, idle };
  });
  expect(evidence.visible, `${label} has no computed visible focus change: ${JSON.stringify(evidence)}`).toBe(true);
}

async function expectNoHorizontalOverflow(page, label) {
  const overflow = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth));
  expect(overflow, `${label} has document-level horizontal overflow`).toBeLessThanOrEqual(2);
}

async function submitWithEnter(page, button) {
  await expect(button).toBeFocused();
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.keyboard.press('Enter'),
  ]);
}

test.describe('keyboard-only checkout, account, and admin accessibility', () => {
  test('checkout supports keyboard validation, feedback, no-trap navigation, and narrow reflow', async ({ page }) => {
    const state = coreFixture();
    test.skip(!state, 'Core fixture state is required.');
    await page.setViewportSize({ width: 640, height: 900 });
    await page.goto(state.product_url, { waitUntil: 'domcontentloaded' });

    const addForm = page.locator('form').filter({ has: page.locator('input[name="mrc_action"][value="add_to_cart"]') }).first();
    const addButton = addForm.getByRole('button');
    await tabTo(page, addButton);
    await expectVisibleFocus(addButton, 'product add-to-cart button');
    await submitWithEnter(page, addButton);

    await page.goto('/checkout/', { waitUntil: 'domcontentloaded' });
    const couponForm = page.locator('form').filter({ has: page.locator('input[name="discount_code"]') }).last();
    const coupon = couponForm.locator('input[name="discount_code"]');
    await tabTo(page, coupon);
    await expectVisibleFocus(coupon, 'checkout coupon input');
    await coupon.fill(`INVALID-${state.run_id}`);
    const applyCoupon = couponForm.getByRole('button', { name: /Apply coupon/i });
    await tabTo(page, applyCoupon);
    await expectVisibleFocus(applyCoupon, 'checkout coupon submit');
    await submitWithEnter(page, applyCoupon);
    await expect(page.getByRole('alert')).toContainText(/coupon|discount/i);

    const checkout = page.getByRole('button', { name: /Continue to payment/i });
    await tabTo(page, checkout);
    await expectVisibleFocus(checkout, 'checkout submit');
    await page.keyboard.press('Enter');
    // A form element itself also matches :invalid when one of its controls is
    // invalid. Assert the browser moved focus to the first invalid control,
    // which is the actual native-validation contract.
    const invalid = page.locator('input:invalid, select:invalid, textarea:invalid').first();
    await expect(invalid).toBeFocused();
    expect(await invalid.evaluate(element => element.validationMessage.length)).toBeGreaterThan(0);
    await page.keyboard.press('Escape');
    await expect(invalid).toBeFocused();

    const footerLink = page.locator('footer a').first();
    await tabTo(page, footerLink);
    await expectVisibleFocus(footerLink, 'checkout footer escape target');
    await page.setViewportSize({ width: 320, height: 900 });
    await expectNoHorizontalOverflow(page, 'checkout 320px/400%-equivalent reflow');
  });

  test('account exposes keyboard login errors and profile status without a focus trap', async ({ page }) => {
    const state = coreFixture();
    test.skip(!state, 'Core fixture state is required.');
    await page.setViewportSize({ width: 640, height: 900 });
    await page.goto('/account/', { waitUntil: 'domcontentloaded' });

    let login = page.locator('form').filter({ has: page.locator('input[name="mrc_account_action"][value="login"]') });
    let email = login.locator('input[name="email"]');
    await tabTo(page, email);
    await expectVisibleFocus(email, 'account email');
    await email.fill(state.customer_email);
    await page.keyboard.press('Tab');
    const password = login.locator('input[name="password"]');
    await expect(password).toBeFocused();
    await password.fill('incorrect-password');
    await page.keyboard.press('Tab');
    await submitWithEnter(page, login.getByRole('button', { name: 'Sign in' }));
    await expect(page.getByRole('alert')).toHaveText('Email or password is incorrect.');

    login = page.locator('form').filter({ has: page.locator('input[name="mrc_account_action"][value="login"]') });
    email = login.locator('input[name="email"]');
    await tabTo(page, email);
    await email.fill(state.customer_email);
    await page.keyboard.press('Tab');
    await login.locator('input[name="password"]').fill(state.customer_password);
    await page.keyboard.press('Tab');
    await submitWithEnter(page, login.getByRole('button', { name: 'Sign in' }));
    await expect(page.getByRole('heading', { name: 'Order history' })).toBeVisible();

    const profile = page.locator('form').filter({ has: page.locator('input[name="mrc_account_action"][value="profile"]') });
    const save = profile.getByRole('button', { name: 'Save profile' });
    await tabTo(page, save);
    await expectVisibleFocus(save, 'account profile submit');
    await page.keyboard.press('Shift+Tab');
    await expect(profile.locator('input[name="commerce_email"]')).toBeFocused();
    await page.keyboard.press('Tab');
    await submitWithEnter(page, save);
    await expect(page.getByRole('status')).toHaveText('Profile saved.');

    const footerLink = page.locator('footer a').first();
    await tabTo(page, footerLink);
    await expectVisibleFocus(footerLink, 'account footer escape target');
    await page.setViewportSize({ width: 320, height: 900 });
    await expectNoHorizontalOverflow(page, 'account 320px/400%-equivalent reflow');
  });

  test('admin supports keyboard login, denial semantics, table focus, and read-only navigation', async ({ page }) => {
    const state = adminFixture();
    test.skip(!state, 'Admin fixture state is required through MERCATO_E2E_ADMIN_STATE.');
    await page.setViewportSize({ width: 640, height: 900 });
    await page.goto(state.process_url, { waitUntil: 'domcontentloaded' });

    const loginName = page.locator('input[name="login_name"]');
    await tabTo(page, loginName);
    // The ProcessWire login screen is host-CMS UI rather than a Mercato-owned
    // surface. Prove keyboard reachability here; visible-focus assertions start
    // after authentication on Mercato's own controls.
    await loginName.fill(state.manager.username);
    const loginPass = page.locator('input[name="login_pass"]');
    await tabTo(page, loginPass);
    await loginPass.fill(state.password);
    const loginSubmit = page.locator('[name="login_submit"]');
    await tabTo(page, loginSubmit);
    await submitWithEnter(page, loginSubmit);

    await page.goto(state.customer_detail_url, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('alert')).toContainText('mercato-view-customers');

    await page.goto(state.fulfilment_url, { waitUntil: 'domcontentloaded' });
    const row = page.locator('tr').filter({ hasText: state.invoice });
    const form = row.locator('form.mrc-fulfilment-form');
    const status = form.locator('select[name="fulfilment_status"]');
    await tabTo(page, status);
    await expectVisibleFocus(status, 'admin fulfilment status');
    const save = form.locator('button[name="mrc_update_fulfilment"]');
    await tabTo(page, save);
    await expectVisibleFocus(save, 'admin fulfilment submit');
    await page.keyboard.press('Escape');
    await expect(save).toBeFocused();

    const detail = form.locator('a').filter({ hasText: 'Detail' });
    await tabTo(page, detail);
    await expectVisibleFocus(detail, 'admin order detail link');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.keyboard.press('Enter'),
    ]);
    await expect(page.getByText(state.invoice).first()).toBeVisible();

    const scrollRegion = page.locator('.mrc-admin-table-wrap[tabindex="0"]').first();
    await tabTo(page, scrollRegion);
    await expectVisibleFocus(scrollRegion, 'admin keyboard-scroll region');
    await page.keyboard.press('Shift+Tab');
    expect(await page.evaluate(() => document.activeElement !== document.body)).toBe(true);
    await page.setViewportSize({ width: 320, height: 900 });
    await expectNoHorizontalOverflow(page, 'admin 320px/400%-equivalent reflow');
  });
});
