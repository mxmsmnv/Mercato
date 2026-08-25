const { test, expect } = require('@playwright/test');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

test('catalog to paid order, coupon, validation and success @journey', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating journey runs once; surfaces cover every engine/viewport.');
  const state = fixture(); const email = `e2e-${state.run_id}-${testInfo.project.name}@example.test`;
  await test.step('add deterministic product to cart', async () => {
    await page.goto(state.product_url);
    await page.locator('form').filter({ has: page.locator('input[name="mrc_action"][value="add_to_cart"]') }).first().getByRole('button').click();
    await expect(page.getByText(/Added to cart/i)).toBeVisible();
  });
  await test.step('exercise validation state', async () => {
    await page.goto('/checkout/');
    await page.getByRole('button', { name: /Continue to payment/i }).click();
    await expect(page.locator(':invalid').first()).toBeVisible();
    await assertAccessible(page, 'checkout validation');
  });
  await test.step('apply deterministic coupon', async () => {
    const couponForm = page.locator('form').filter({ has: page.locator('input[name="discount_code"]') }).last();
    await couponForm.locator('input[name="discount_code"]').fill(state.coupon);
    const couponEmail = couponForm.locator('input[name="email"]'); if (await couponEmail.count()) await couponEmail.fill(email);
    await couponForm.getByRole('button', { name: /Apply coupon/i }).click();
    await expect(page.getByText(/applied|discount/i).first()).toBeVisible();
  });
  await test.step('complete Demo Payment through normal checkout', async () => {
    await page.locator('input[name="first_name"]').fill('E2E'); await page.locator('input[name="last_name"]').fill('Fixture'); await page.locator('input[name="email"]').fill(email);
    for (const [name, value] of [['address','Acceptance Street'],['city','Test City'],['zip','10001']]) { const field=page.locator(`input[name="${name}"]`); if(await field.count()) await field.fill(value); }
    const country=page.locator('select[name="country"]'); if(await country.count()) await country.selectOption({index:1});
    await page.locator('select[name="payment_method"]').selectOption('demo');
    const policy=page.locator('input[name="policy_accepted"]'); if(await policy.count()) await policy.check();
    await page.getByRole('button', { name: /Continue to payment/i }).click();
    await expect(page).toHaveURL(/checkout\/success/); await expect(page.getByText(/Thank you for your order/i)).toBeVisible();
    await assertResponsive(page, 'success'); await assertAccessible(page, 'success');
    expect(await page.locator('script').allTextContents()).not.toEqual(expect.arrayContaining([expect.stringMatching(/private@example|Acceptance Street/)]));
  });
});
