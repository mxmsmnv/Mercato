const { test, expect } = require('@playwright/test');
const { fixture, assertAccessible, assertResponsive } = require('./helpers');

const targetOrigin = new URL(process.env.MERCATO_E2E_BASE_URL || 'https://mercato.test').origin;

function observeBrowserFailures(page) {
  const failures = [];
  page.on('console', message => { if (message.type() === 'error') failures.push(`console: ${message.text()}`); });
  page.on('requestfailed', request => { if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`); });
  page.on('response', response => {
    if (new URL(response.url()).origin === targetOrigin && response.status() >= 500) failures.push(`response: ${response.status()} ${response.url()}`);
  });
  return failures;
}

function accountForm(page, action) {
  return page.locator('form').filter({ has: page.locator(`input[name="mrc_account_action"][value="${action}"]`) });
}

async function loginCustomer(page, state) {
  await page.goto(state.account_url, { waitUntil: 'domcontentloaded' });
  const form = accountForm(page, 'login');
  await form.locator('input[name="email"]').fill(state.customer_email);
  await form.locator('input[name="password"]').fill(state.password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), form.getByRole('button', { name: 'Sign in' }).click()]);
}

async function loginManager(page, state) {
  await page.goto(state.process_url, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="login_name"]').fill(state.manager_username);
  await page.locator('input[name="login_pass"]').fill(state.password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('[name="login_submit"]').click()]);
}

test('empty, loading, long, translated, large-data, and readiness states remain usable', async ({ browser }) => {
  test.setTimeout(90_000);
  const state = fixture(); const contexts = []; const failures = [];
  try {
    const mobileContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(mobileContext);
    const catalog = await mobileContext.newPage(); failures.push(observeBrowserFailures(catalog));

    await test.step('empty catalog is explicit, responsive, and accessible', async () => {
      const response = await catalog.goto(state.empty_catalog_url, { waitUntil: 'domcontentloaded' });
      expect(response?.ok()).toBeTruthy();
      await expect(catalog.getByRole('status').filter({ hasText: 'No products are available yet.' })).toBeVisible();
      await expect(catalog.locator('#mrc-catalog-results')).toHaveAttribute('aria-busy', 'false');
      await assertResponsive(catalog, 'empty catalog'); await assertAccessible(catalog, 'empty catalog');
    });

    await test.step('filter navigation exposes a real announced loading state', async () => {
      await catalog.goto(state.large_catalog_url, { waitUntil: 'domcontentloaded' });
      const form = catalog.locator('[data-mrc-product-filters]');
      await form.locator('select[name="sort"]').selectOption('title');
      await form.evaluate(element => {
        window.__mrcPreventFilterNavigation = event => event.preventDefault();
        element.addEventListener('submit', window.__mrcPreventFilterNavigation, { capture: true });
      });
      await form.getByRole('button', { name: 'Apply filters' }).click();
      await expect(catalog.locator('#mrc-catalog-results')).toHaveAttribute('aria-busy', 'true');
      await expect(catalog.locator('[data-mrc-loading-status]')).toHaveText('Loading products…');
      await form.evaluate(element => element.removeEventListener('submit', window.__mrcPreventFilterNavigation, { capture: true }));
      await Promise.all([
        catalog.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        form.getByRole('button', { name: 'Apply filters' }).click()
      ]);
      await expect(catalog.locator('#mrc-catalog-results')).toHaveAttribute('aria-busy', 'false');
    });

    await test.step('large catalog and long translated product content wrap on mobile', async () => {
      await catalog.goto(state.large_catalog_url, { waitUntil: 'domcontentloaded' });
      await expect(catalog.locator('article').filter({ hasText: 'Presentation state' })).toHaveCount(23);
      await assertResponsive(catalog, 'large multilingual catalog'); await assertAccessible(catalog, 'large multilingual catalog');
      await catalog.goto(state.long_product_url, { waitUntil: 'domcontentloaded' });
      await expect(catalog.getByRole('heading', { level: 1 })).toContainText('Présentation 日本語 العربية');
      await expect(catalog.getByText('Mehrsprachiger Inhalt — 日本語の説明 — وصف عربي.')).toBeVisible();
      await assertResponsive(catalog, 'long translated product'); await assertAccessible(catalog, 'long translated product');
    });

    const customerContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true }); contexts.push(customerContext);
    const account = await customerContext.newPage(); failures.push(observeBrowserFailures(account));
    await test.step('large account history paginates without overflow', async () => {
      await loginCustomer(account, state);
      await expect(account.locator('.mrc-table tbody tr')).toHaveCount(5);
      await expect(account.getByRole('link', { name: 'Next' })).toBeVisible();
      await account.getByRole('link', { name: 'Next' }).click();
      await expect(account.locator('.mrc-table tbody tr')).toHaveCount(5);
      await account.goto('/account/?page=3', { waitUntil: 'domcontentloaded' });
      await expect(account.locator('.mrc-table tbody tr')).toHaveCount(4);
      await assertResponsive(account, 'large account history'); await assertAccessible(account, 'large account history');
    });

    const adminContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true }); contexts.push(adminContext);
    const admin = await adminContext.newPage(); failures.push(observeBrowserFailures(admin));
    await test.step('large admin product and order tables remain keyboard reachable', async () => {
      await loginManager(admin, state);
      await admin.goto(state.admin_products_url, { waitUntil: 'domcontentloaded' });
      await expect(admin.locator('.mrc-products-table tbody tr')).toHaveCount(23);
      const productScroll = admin.locator('.mrc-admin-table-wrap').first(); await productScroll.focus(); await expect(productScroll).toBeFocused();
      await assertResponsive(admin, 'large admin product list'); await assertAccessible(admin, 'large admin product list', '.mrc-admin-dashboard');
      await admin.goto(state.admin_orders_url, { waitUntil: 'domcontentloaded' });
      for (const invoice of state.invoices.slice(-3)) await expect(admin.getByRole('link', { name: invoice, exact: true })).toBeVisible();
      const orderScroll = admin.locator('.mrc-admin-table-wrap').first(); await orderScroll.focus(); await expect(orderScroll).toBeFocused();
      await assertResponsive(admin, 'large admin order list'); await assertAccessible(admin, 'large admin order list', '.mrc-admin-dashboard');
    });

    expect(failures.flat(), 'console, same-origin server, and document/XHR/fetch failures').toEqual([]);
  } finally {
    await Promise.allSettled(contexts.map(context => context.close()));
  }
});
