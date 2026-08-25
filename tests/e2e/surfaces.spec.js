const { test, expect } = require('@playwright/test');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

for (const surface of [
  ['catalog', '/products/', false],
  ['product', null, false],
  ['checkout', '/checkout/', true],
  ['account', '/account/', true]
]) {
  test(`${surface[0]} responsive accessibility`, async ({ page }) => {
    const state = fixture(); const url = surface[1] || state.product_url;
    await test.step(`open ${surface[0]}`, async () => { await page.goto(url); await expect(page.locator('body')).toBeVisible(); });
    await test.step(`verify ${surface[0]} responsive layout`, async () => assertResponsive(page, surface[0]));
    await test.step(`verify ${surface[0]} critical accessibility`, async () => assertAccessible(page, surface[0]));
    if (surface[2]) await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  });
}
