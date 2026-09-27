const { test, expect } = require('@playwright/test');

async function tabTo(page, locator, limit = 80) {
  const target = await locator.elementHandle();
  if (!target) throw new Error(`Keyboard target was not rendered: ${locator}`);
  for (let index = 0; index < limit; index++) {
    await page.keyboard.press('Tab');
    if (await page.evaluate(element => document.activeElement === element, target)) return;
  }
  throw new Error(`Tab did not reach the requested target within ${limit} steps.`);
}

test('mobile menu and featured carousel expose deterministic keyboard semantics', async ({ browser }) => {
  const contexts = [];
  try {
    const mobileContext = await browser.newContext({
      viewport: { width: 390, height: 844 },
      ignoreHTTPSErrors: true,
    });
    contexts.push(mobileContext);
    const mobile = await mobileContext.newPage();
    await mobile.goto('/products/', { waitUntil: 'domcontentloaded' });

    const menuToggle = mobile.locator('[data-mrc-menu-toggle]');
    const menu = mobile.locator('[data-mrc-menu-panel]');
    await expect(menuToggle).toHaveAttribute('aria-controls', 'mrc-store-menu');
    await expect(menuToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(menu).toHaveAttribute('hidden', '');

    await tabTo(mobile, menuToggle);
    await expect(menuToggle).toBeFocused();
    await mobile.keyboard.press('Enter');
    await expect(menuToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(menu).not.toHaveAttribute('hidden', '');
    await expect(menu.getByRole('link').first()).toBeFocused();

    await mobile.keyboard.press('Escape');
    await expect(menuToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(menu).toHaveAttribute('hidden', '');
    await expect(menuToggle).toBeFocused();
    await mobile.keyboard.press('Tab');
    expect(await mobile.evaluate(() => !document.querySelector('[data-mrc-menu-panel]').contains(document.activeElement))).toBe(true);

    const reducedContext = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
      reducedMotion: 'reduce',
      ignoreHTTPSErrors: true,
    });
    contexts.push(reducedContext);
    const reduced = await reducedContext.newPage();
    await reduced.addInitScript(() => {
      const nativeSetInterval = window.setInterval.bind(window);
      window.__mrcIntervalDelays = [];
      window.setInterval = (callback, delay, ...args) => {
        window.__mrcIntervalDelays.push(delay);
        return nativeSetInterval(callback, delay, ...args);
      };
    });
    await reduced.goto('/products/', { waitUntil: 'domcontentloaded' });

    const slider = reduced.locator('[data-mrc-slider]');
    const slides = slider.locator('[data-mrc-slide]');
    const dots = slider.locator('[data-mrc-dot]');
    const rotation = slider.locator('[data-mrc-slider-toggle]');
    expect(await slides.count()).toBeGreaterThan(1);
    await expect(slider).toHaveAttribute('role', 'region');
    await expect(slider).toHaveAttribute('aria-roledescription', 'carousel');
    await expect(slider.locator('[data-mrc-slide]:not([hidden])[aria-hidden="false"]')).toHaveCount(1);
    await expect(slider.locator('[data-mrc-dot][aria-current="true"]')).toHaveCount(1);
    await expect(rotation).toHaveText('Play slideshow');
    expect(await reduced.evaluate(() => window.__mrcIntervalDelays.includes(5200))).toBe(false);

    await tabTo(reduced, rotation);
    await reduced.keyboard.press('Enter');
    await expect(rotation).toHaveText('Pause slideshow');
    expect(await reduced.evaluate(() => window.__mrcIntervalDelays.includes(5200))).toBe(true);
    await reduced.keyboard.press('Enter');
    await expect(rotation).toHaveText('Play slideshow');

    const selectedIndex = (await dots.count()) - 1;
    const selectedDot = dots.nth(selectedIndex);
    await reduced.keyboard.press('Shift+Tab');
    await expect(selectedDot).toBeFocused();
    await reduced.keyboard.press('Enter');
    await expect(selectedDot).toHaveAttribute('aria-current', 'true');
    await expect(slides.nth(selectedIndex)).not.toHaveAttribute('hidden', '');
    await expect(slides.nth(selectedIndex)).toHaveAttribute('aria-hidden', 'false');
    await expect(slides.nth(0)).toHaveAttribute('hidden', '');
    await expect(slides.nth(0)).toHaveAttribute('aria-hidden', 'true');
  } finally {
    await Promise.allSettled(contexts.map(context => context.close()));
  }
});
