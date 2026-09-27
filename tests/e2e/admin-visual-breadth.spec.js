const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { assertAccessible, assertResponsive } = require('./helpers');

const enabled = process.env.MERCATO_E2E_ADMIN_VISUAL_BREADTH === '1';

function fixture() {
  const path = process.env.MERCATO_E2E_STATE;
  if (!path || !fs.existsSync(path)) return null;
  const state = JSON.parse(fs.readFileSync(path, 'utf8'));
  return state.schema_version === 1 && Array.isArray(state.routes) ? state : null;
}

function safeName(value) {
  return value.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase();
}

async function login(page, state, username) {
  const response = await page.goto(state.process_url, { waitUntil: 'domcontentloaded' });
  expect(response?.ok()).toBeTruthy();
  await page.locator('input[name="login_name"]').fill(username);
  await page.locator('input[name="login_pass"]').fill(state.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.locator('[name="login_submit"]').click(),
  ]);
}

async function capture(page, testInfo, name) {
  await testInfo.attach(`${safeName(name)}.png`, {
    body: await page.screenshot({ fullPage: true, animations: 'disabled' }),
    contentType: 'image/png',
  });
}

function observeFailures(page) {
  const failures = [];
  page.on('console', message => {
    if (message.type() !== 'error') return;
    const text = message.text();
    if (/^Blocked script execution in 'about:(?:blank|srcdoc)' because the document's frame is sandboxed and the 'allow-scripts' permission is not set\.$/.test(text)) return;
    if (text === 'Failed to load resource: the server responded with a status of 404 (Not Found)') return;
    failures.push(`console: ${text}`);
  });
  page.on('requestfailed', request => {
    if (['document', 'xhr', 'fetch'].includes(request.resourceType())) failures.push(`request: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`);
  });
  page.on('response', response => {
    const sameOrigin = response.url().startsWith(new URL(page.url()).origin + '/');
    if (sameOrigin && response.status() >= 400) failures.push(`response: ${response.status()} ${response.url()}`);
  });
  return failures;
}

test.describe('Mercato admin visual/state breadth', () => {
  test.skip(!enabled, 'Set MERCATO_E2E_ADMIN_VISUAL_BREADTH=1 for the isolated admin evidence profile.');
  test.beforeEach(async ({}, testInfo) => test.skip(testInfo.project.name !== 'chromium-desktop', 'A single controlled Chromium project owns this profile.'));

  test('normal or isolated-empty state of every HTML route is named, accessible, and captured', async ({ browser }, testInfo) => {
    test.setTimeout(180_000);
    const state = fixture(); test.skip(!state, 'Admin visual fixture state is required.');
    const expectedNormal = ['dashboard', 'products', 'product-detail', 'orders', 'quotes', 'quote-detail', 'manual-order', 'fulfilment', 'order-timeline', 'order-detail', 'customers', 'recovery', 'customer-detail', 'search', 'reports', 'discounts', 'webhooks', 'payment-attempts', 'refunds', 'inventory', 'launch', 'notifications'];
    const expectedEmpty = ['dashboard', 'products', 'orders', 'quotes', 'manual-order', 'fulfilment', 'customers', 'recovery', 'search', 'reports', 'discounts', 'webhooks', 'payment-attempts', 'refunds', 'inventory', 'launch', 'notifications'];
    expect(state.routes.map(route => route.id).sort()).toEqual((state.dataset === 'normal' ? expectedNormal : expectedEmpty).sort());

    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true });
    const page = await context.newPage(); const failures = observeFailures(page);
    try {
      await login(page, state, state.operator_username);
      for (const route of state.routes) {
        await test.step(`${state.dataset}: ${route.id}`, async () => {
          const response = await page.goto(route.url, { waitUntil: 'domcontentloaded' });
          expect(response?.ok(), `${route.id} response`).toBeTruthy();
          await expect(page.locator('.mrc-admin-dashboard')).toBeVisible();
          await expect(page.locator('h1, h2').first()).toBeVisible();
          const hostEditorExclusions = route.id === 'notifications'
            ? ['.tox-tinymce', '.mrc-notification-tinymce iframe']
            : [];
          await assertAccessible(page, `${state.dataset} admin ${route.id}`, '.mrc-admin-dashboard', hostEditorExclusions);
          await capture(page, testInfo, `${state.dataset}-1440-${route.id}`);
        });
      }

      const settingsContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true });
      const settingsPage = await settingsContext.newPage(); const settingsFailures = observeFailures(settingsPage);
      try {
        await login(settingsPage, state, state.settings_username);
        const settingsResponse = await settingsPage.goto(state.settings_url, { waitUntil: 'domcontentloaded' });
        expect(settingsResponse?.ok(), 'module settings response').toBeTruthy();
        const settingsForm = settingsPage.locator('#ModuleEditForm, form:has([name="submit_save_module"])').first();
        await expect(settingsForm).toBeVisible();
        await expect(settingsPage.locator('#ModuleEditForm')).toHaveAttribute('data-mrc-config-a11y-ready', '1');
        await assertAccessible(settingsPage, 'module settings', '#ModuleEditForm');
        await capture(settingsPage, testInfo, `${state.dataset}-1440-module-settings`);
        const collapsed = settingsPage.locator('#ModuleEditForm .InputfieldStateCollapsed > .InputfieldHeader');
        for (let attempt = 0; attempt < 80 && await collapsed.count() > 0; attempt++) await collapsed.first().click();
        expect(await collapsed.count(), 'all module-settings fieldsets should expand within the bounded loop').toBe(0);
        const readiness = settingsPage.getByText('Production mode is disabled.', { exact: false }).first();
        await expect(readiness).toBeVisible();
        await readiness.scrollIntoViewIfNeeded();
        await assertAccessible(settingsPage, 'module settings readiness errors', '#ModuleEditForm');
        await capture(settingsPage, testInfo, `${state.dataset}-1440-module-settings-error`);
        const maintenance = settingsPage.locator('[name="checkout_maintenance_message"]');
        const longTranslated = `Maintenance visuelle 日本語 العربية ${'UnbrokenSettings'.repeat(24)}`;
        await maintenance.fill(longTranslated);
        await expect(maintenance).toHaveValue(longTranslated);
        await maintenance.scrollIntoViewIfNeeded();
        await assertResponsive(settingsPage, 'module settings long translated value');
        await assertAccessible(settingsPage, 'module settings long translated value', '#ModuleEditForm');
        await capture(settingsPage, testInfo, `${state.dataset}-1440-module-settings-long-translated`);
        expect(settingsFailures).toEqual([]);
      } finally { await settingsContext.close(); }

      if (state.dataset === 'normal') {
        await page.goto(state.routes.find(route => route.id === 'products').url, { waitUntil: 'domcontentloaded' });
        expect(await page.locator('.mrc-products-table tbody tr').count()).toBeGreaterThanOrEqual(18);
        await page.goto(state.routes.find(route => route.id === 'product-detail').url, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.mrc-admin-dashboard')).toContainText('Présentation 日本語 العربية');
        await page.goto(state.routes.find(route => route.id === 'order-detail').url, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.mrc-admin-dashboard')).toContainText('顧客 العربية');
        await page.goto(state.routes.find(route => route.id === 'quote-detail').url, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.mrc-admin-dashboard')).toContainText('日本語');
      }
      expect(failures).toEqual([]);
    } finally { await context.close(); }
  });

  test('all HTML routes reflow at 390px without document-level overflow', async ({ browser }, testInfo) => {
    test.setTimeout(180_000);
    const state = fixture(); test.skip(!state, 'Admin visual fixture state is required.');
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true });
    const page = await context.newPage(); const failures = observeFailures(page);
    try {
      await login(page, state, state.operator_username);
      for (const route of state.routes) {
        const response = await page.goto(route.url, { waitUntil: 'domcontentloaded' });
        expect(response?.ok(), `${route.id} mobile response`).toBeTruthy();
        await assertResponsive(page, `390px admin ${route.id}`);
        await capture(page, testInfo, `${state.dataset}-390-${route.id}`);
      }
      const settingsContext = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true });
      const settingsPage = await settingsContext.newPage(); const settingsFailures = observeFailures(settingsPage);
      try {
        await login(settingsPage, state, state.settings_username);
        const response = await settingsPage.goto(state.settings_url, { waitUntil: 'domcontentloaded' });
        expect(response?.ok(), 'module settings mobile response').toBeTruthy();
        await expect(settingsPage.locator('#ModuleEditForm, form:has([name="submit_save_module"])').first()).toBeVisible();
        await expect(settingsPage.locator('#ModuleEditForm')).toHaveAttribute('data-mrc-config-a11y-ready', '1');
        await assertResponsive(settingsPage, '390px module settings');
        await capture(settingsPage, testInfo, `${state.dataset}-390-module-settings`);
        expect(settingsFailures).toEqual([]);
      } finally { await settingsContext.close(); }
      expect(failures).toEqual([]);
    } finally { await context.close(); }
  });

  test('least-privilege denial states use explicit Mercato alerts', async ({ browser }, testInfo) => {
    test.setTimeout(120_000);
    const state = fixture(); test.skip(!state, 'Admin visual fixture state is required.');
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true });
    const page = await context.newPage(); const failures = observeFailures(page);
    try {
      await login(page, state, state.viewer_username);
      for (const route of state.denied_routes) {
        const response = await page.goto(route.url, { waitUntil: 'domcontentloaded' });
        expect(response?.ok(), `${route.id} denial response`).toBeTruthy();
        const alert = page.getByRole('alert');
        await expect(page.getByRole('heading', { name: 'Access denied' })).toBeVisible();
        await expect(alert).toContainText(route.permission);
        await expect(alert).toHaveAttribute('aria-live', 'assertive');
        await assertResponsive(page, `denied admin ${route.id}`);
        await capture(page, testInfo, `denied-${route.id}`);
      }
      expect(failures).toEqual([]);
    } finally { await context.close(); }
  });
});
