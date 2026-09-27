const fs = require('fs');
const AxeBuilder = require('@axe-core/playwright').default;
const { test, expect } = require('@playwright/test');

const enabled = process.env.MERCATO_E2E_ASSISTIVE_VISUAL === '1';

const evidenceChecklist = {
  automated: [
    'Named storefront landmarks, menu, carousel, checkout fields, account forms, and admin controls.',
    'Catalog loading status, checkout coupon error, account authentication error/profile status, and admin permission alert semantics.',
    'WCAG 2 AA axe color-contrast rule on representative public, account, checkout, and admin states.',
    'Document overflow and captured evidence at 320, 390, 768, and 1440 CSS-pixel widths.',
    'Long multilingual product content, large catalog/account/admin data, and disabled-checkout readiness presentation.',
  ],
  manualVoiceOver: [
    'Safari + VoiceOver: navigate landmarks/headings in DOM order without relying on the visual layout.',
    'Confirm the mobile menu announces collapsed/expanded state, moves to the first link, and returns focus on Escape.',
    'Confirm the featured-products carousel announces its name, active slide position, and Play/Pause state without reading hidden slides.',
    'Confirm catalog loading, coupon rejection, login rejection, profile success, and admin permission denial are announced once at the point of change.',
    'Confirm checkout field names, required state, native validation error, and correction path are understandable without sight.',
    'Confirm account order tables and admin product/order tables expose useful headers and reading order at narrow and desktop widths.',
    'Inspect any axe contrast-incomplete nodes, focus indicators, non-text icons, disabled controls, and text over imagery manually.',
  ],
};

function state() {
  const path = process.env.MERCATO_E2E_PRESENTATION_STATE || process.env.MERCATO_E2E_STATE;
  if (!path || !fs.existsSync(path)) return null;
  const parsed = JSON.parse(fs.readFileSync(path, 'utf8'));
  return parsed.long_product_url && parsed.admin_products_url && parsed.customer_email ? parsed : null;
}

async function attachChecklist(testInfo) {
  await testInfo.attach('assistive-visual-checklist', {
    body: Buffer.from(`${JSON.stringify(evidenceChecklist, null, 2)}\n`),
    contentType: 'application/json',
  });
}

async function expectNoDocumentOverflow(page, label) {
  const overflow = await page.evaluate(() => Math.max(
    0,
    document.documentElement.scrollWidth - document.documentElement.clientWidth,
  ));
  expect(overflow, `${label} has document-level horizontal overflow`).toBeLessThanOrEqual(2);
}

async function capture(page, testInfo, name) {
  await testInfo.attach(name, {
    body: await page.screenshot({ fullPage: true, animations: 'disabled' }),
    contentType: 'image/png',
  });
}

async function auditContrast(page, testInfo, label, include = null) {
  const builder = new AxeBuilder({ page }).withRules(['color-contrast']);
  if (include) builder.include(include);
  const result = await builder.analyze();
  const summarize = entry => ({
    id: entry.id,
    impact: entry.impact,
    nodes: entry.nodes.map(node => ({ target: node.target, summary: node.failureSummary })),
  });
  const evidence = {
    label,
    passes: result.passes.reduce((count, entry) => count + entry.nodes.length, 0),
    violations: result.violations.map(summarize),
    incomplete: result.incomplete.map(summarize),
  };
  await testInfo.attach(`contrast-${label.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`, {
    body: Buffer.from(`${JSON.stringify(evidence, null, 2)}\n`),
    contentType: 'application/json',
  });
  if (evidence.incomplete.length) {
    testInfo.annotations.push({
      type: 'manual-contrast-review',
      description: `${label}: axe returned ${evidence.incomplete.length} incomplete contrast result(s).`,
    });
  }
  expect(evidence.violations, `${label} has automated color-contrast violations`).toEqual([]);
}

async function loginCustomer(page, fixture) {
  await page.goto(fixture.account_url, { waitUntil: 'domcontentloaded' });
  const login = page.locator('form').filter({
    has: page.locator('input[name="mrc_account_action"][value="login"]'),
  });
  await login.getByLabel('Email').fill(fixture.customer_email);
  await login.getByLabel('Password').fill(fixture.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    login.getByRole('button', { name: 'Sign in' }).click(),
  ]);
}

async function loginManager(page, fixture) {
  await page.goto(fixture.process_url, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="login_name"]').fill(fixture.manager_username);
  await page.locator('input[name="login_pass"]').fill(fixture.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.locator('[name="login_submit"]').click(),
  ]);
}

test.describe('agent-led assistive and visual evidence', () => {
  test.skip(!enabled, 'Set MERCATO_E2E_ASSISTIVE_VISUAL=1 for the isolated evidence profile.');

  test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-desktop', 'The evidence profile is a single controlled Chromium run.');
  });

  test('screen-reader names, errors, statuses, and live regions are explicit', async ({ browser }, testInfo) => {
    test.setTimeout(90_000);
    const fixture = state();
    test.skip(!fixture, 'Presentation fixture state is required.');
    await attachChecklist(testInfo);

    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    try {
      await test.step('storefront landmarks, carousel, and loading state have stable names', async () => {
        await page.goto(fixture.large_catalog_url, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('nav[aria-label="Store navigation"]')).toHaveCount(1);
        await expect(page.locator('nav[aria-label="Mobile store navigation"]')).toBeHidden();
        await expect(page.getByRole('button', { name: 'Menu' })).toHaveAttribute('aria-expanded', 'false');
        const carousel = page.getByRole('region', { name: 'Featured products' });
        if (await carousel.count()) {
          await expect(carousel).toHaveAttribute('aria-roledescription', 'carousel');
          await expect(carousel.locator('[data-mrc-slide]:not([hidden])')).toHaveCount(1);
          await expect(carousel.getByRole('button', { name: /automatic slide rotation/i })).toBeVisible();
        }

        const filters = page.locator('[data-mrc-product-filters]');
        await filters.evaluate(element => element.addEventListener('submit', event => event.preventDefault(), { once: true }));
        await filters.getByRole('button', { name: 'Apply filters' }).click();
        await expect(page.locator('#mrc-catalog-results')).toHaveAttribute('aria-busy', 'true');
        const loading = page.getByRole('status').filter({ hasText: 'Loading products…' });
        await expect(loading).toHaveAttribute('aria-live', 'polite');
      });

      await test.step('checkout fields and rejection feedback expose names and urgency', async () => {
        await page.goto(fixture.long_product_url, { waitUntil: 'domcontentloaded' });
        const add = page.locator('form').filter({
          has: page.locator('input[name="mrc_action"][value="add_to_cart"]'),
        }).first();
        await add.getByRole('button').click();
        await page.goto(fixture.checkout_url, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { level: 1 })).toHaveAccessibleName(/checkout/i);
        await expect(page.getByLabel('First name')).toBeVisible();
        await expect(page.getByLabel('Last name')).toBeVisible();
        await expect(page.getByLabel('Email')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Continue to payment' })).toHaveAttribute('aria-disabled', 'true');

        const couponForm = page.locator('form').filter({ has: page.locator('input[name="discount_code"]') }).last();
        await couponForm.getByLabel('Coupon code').fill(`INVALID-${fixture.run_id}`);
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
          couponForm.getByRole('button', { name: 'Apply coupon' }).click(),
        ]);
        const couponError = page.getByRole('alert');
        await expect(couponError).toContainText(/coupon|discount/i);
        await expect(couponError).toHaveAttribute('aria-live', 'assertive');
      });

      await test.step('account error feedback and authenticated controls expose explicit semantics', async () => {
        await page.goto(fixture.account_url, { waitUntil: 'domcontentloaded' });
        let login = page.locator('form').filter({
          has: page.locator('input[name="mrc_account_action"][value="login"]'),
        });
        await login.getByLabel('Email').fill(`invalid-${fixture.run_id}@example.test`);
        await login.getByLabel('Password').fill('incorrect-password');
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
          login.getByRole('button', { name: 'Sign in' }).click(),
        ]);
        const loginError = page.getByRole('alert');
        await expect(loginError).toHaveText('Email or password is incorrect.');
        await expect(loginError).toHaveAttribute('aria-live', 'assertive');

        await loginCustomer(page, fixture);
        const profile = page.locator('form').filter({
          has: page.locator('input[name="mrc_account_action"][value="profile"]'),
        });
        await expect(profile.getByLabel('First name')).toBeVisible();
        await expect(profile.getByLabel('Receive optional commerce updates')).toBeVisible();
        await page.setViewportSize({ width: 768, height: 1024 });
        await expect(page.getByRole('heading', { name: 'Order history' })).toBeVisible();
        await expectNoDocumentOverflow(page, '768px large account history');
        await auditContrast(page, testInfo, '768px-account');
        await capture(page, testInfo, '768px-account.png');
      });
    } finally {
      await context.close();
    }

    const adminContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true });
    const admin = await adminContext.newPage();
    try {
      await loginManager(admin, fixture);
      const deniedUrl = `${fixture.process_url.replace(/\/$/, '')}/customer-detail/?key=${encodeURIComponent(fixture.customer_email)}`;
      await admin.goto(deniedUrl, { waitUntil: 'domcontentloaded' });
      const denial = admin.getByRole('alert');
      await expect(denial).toContainText('mercato-view-customers');
      await expect(denial).toHaveAttribute('aria-live', 'assertive');

      await admin.goto(fixture.admin_products_url, { waitUntil: 'domcontentloaded' });
      await expect(admin.getByLabel('Import Products CSV')).toBeVisible();
      await expect(admin.getByRole('link', { name: /Export CSV/i })).toBeVisible();
      await expect(admin.locator('.mrc-admin-table-wrap[tabindex="0"]').first()).toBeVisible();
    } finally {
      await adminContext.close();
    }
  });

  test('representative public, account, checkout, and admin states retain contrast and reflow evidence', async ({ browser }, testInfo) => {
    test.setTimeout(90_000);
    const fixture = state();
    test.skip(!fixture, 'Presentation fixture state is required.');
    await attachChecklist(testInfo);

    const publicContext = await browser.newContext({ viewport: { width: 320, height: 900 }, ignoreHTTPSErrors: true });
    const page = await publicContext.newPage();
    try {
      await page.goto(fixture.long_product_url, { waitUntil: 'domcontentloaded' });
      await expectNoDocumentOverflow(page, '320px long multilingual product');
      await auditContrast(page, testInfo, '320px-long-product');
      await capture(page, testInfo, '320px-long-product.png');
      const add = page.locator('form').filter({
        has: page.locator('input[name="mrc_action"][value="add_to_cart"]'),
      }).first();
      await add.getByRole('button').click();

      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto(fixture.empty_catalog_url, { waitUntil: 'domcontentloaded' });
      await expect(page.getByRole('status').filter({ hasText: 'No products are available yet.' })).toBeVisible();
      await expectNoDocumentOverflow(page, '390px empty catalog');
      await auditContrast(page, testInfo, '390px-empty-catalog');
      await capture(page, testInfo, '390px-empty-catalog.png');

      await page.setViewportSize({ width: 768, height: 1024 });
      await page.goto(fixture.checkout_url, { waitUntil: 'domcontentloaded' });
      const readinessStatus = page.getByRole('status').filter({ hasText: fixture.maintenance_message });
      await expect(readinessStatus).toBeVisible();
      await expect(readinessStatus).toHaveAttribute('aria-live', 'polite');
      await expectNoDocumentOverflow(page, '768px disabled checkout');
      await auditContrast(page, testInfo, '768px-disabled-checkout');
      await capture(page, testInfo, '768px-disabled-checkout.png');

    } finally {
      await publicContext.close();
    }

    const adminContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ignoreHTTPSErrors: true });
    const admin = await adminContext.newPage();
    try {
      await loginManager(admin, fixture);
      await admin.goto(fixture.admin_products_url, { waitUntil: 'domcontentloaded' });
      await expect(admin.locator('.mrc-products-table tbody tr')).toHaveCount(23);
      await expectNoDocumentOverflow(admin, '1440px large admin product list');
      await auditContrast(admin, testInfo, '1440px-admin-products', '.mrc-admin-dashboard');
      await capture(admin, testInfo, '1440px-admin-products.png');
    } finally {
      await adminContext.close();
    }
  });
});
