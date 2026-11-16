const { test, expect } = require('@playwright/test');
const path = require('node:path');

test('structured variant editor keeps generated API keys and combinations synchronized', async ({ page }) => {
  await page.setContent(`
    <div class="mrc-variant-editor" data-base-sku="CHAIR" data-base-price="199">
      <input type="hidden" class="mrc-variant-value" name="mrc_variant_definition" value='{"options":[],"variants":[]}'>
      <button type="button" class="mrc-add-option">Add option group</button>
      <button type="button" class="mrc-generate-variants">Generate combinations</button>
      <button type="button" class="mrc-add-variant">Add variant</button>
      <div class="mrc-variant-summary" aria-live="polite"></div>
      <div class="mrc-option-list"></div>
      <div class="mrc-variant-table-wrap"><table class="mrc-variant-table"><thead></thead><tbody></tbody></table></div>
      <div class="mrc-variant-errors" role="alert" hidden></div>
    </div>
  `);
  await page.addScriptTag({ path: path.join(__dirname, '../../assets/js/mercato-variants.js') });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));

  await page.getByRole('button', { name: 'Add option group' }).click();
  await page.locator('[data-field="option-label"]').fill('Finish');
  await expect(page.locator('[data-field="option-id"]')).toHaveValue('finish');

  await page.getByRole('button', { name: 'Add value' }).click();
  await page.locator('[data-field="value-label"]').fill('Natural Oak');
  await expect(page.locator('[data-field="value-id"]')).toHaveValue('natural-oak');

  await page.getByRole('button', { name: 'Generate combinations' }).click();
  await expect(page.locator('[data-field="variant-sku"]')).toHaveValue('CHAIR-NATURAL-OAK');
  await expect(page.locator('[data-field="variant-option"]')).toHaveValue('natural-oak');

  await page.locator('[data-field="option-id"]').fill('surface');
  await page.locator('[data-field="value-id"]').fill('oak');

  const definition = await page.locator('.mrc-variant-value').evaluate(input => JSON.parse(input.value));
  expect(definition.options[0]).toMatchObject({ id: 'surface', label: 'Finish' });
  expect(definition.options[0].values[0]).toMatchObject({ id: 'oak', label: 'Natural Oak' });
  expect(definition.variants[0].options).toEqual({ surface: 'oak' });
  await expect(page.locator('.mrc-variant-errors')).toBeHidden();
  await expect(page.locator('.mrc-variant-summary')).toHaveText('1 option group · 1 variant');
});

test('structured variant editor exposes duplicate combinations before submission', async ({ page }) => {
  const definition = JSON.stringify({
    options: [{ id: 'size', label: 'Size', values: [{ id: 'small', label: 'Small' }] }],
    variants: [
      { id: 'small-1', sku: 'CHAIR-S-1', options: { size: 'small' }, stock: 1 },
      { id: 'small-2', sku: 'CHAIR-S-2', options: { size: 'small' }, stock: 1 },
    ],
  }).replaceAll('&', '&amp;').replaceAll("'", '&#39;');
  await page.setContent(`
    <div class="mrc-variant-editor">
      <input type="hidden" class="mrc-variant-value" value='${definition}'>
      <button type="button" class="mrc-add-option">Add option group</button>
      <button type="button" class="mrc-generate-variants">Generate combinations</button>
      <button type="button" class="mrc-add-variant">Add variant</button>
      <div class="mrc-variant-summary"></div><div class="mrc-option-list"></div>
      <div class="mrc-variant-table-wrap"><table class="mrc-variant-table"><thead></thead><tbody></tbody></table></div>
      <div class="mrc-variant-errors" role="alert" hidden></div>
    </div>
  `);
  await page.addScriptTag({ path: path.join(__dirname, '../../assets/js/mercato-variants.js') });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));

  await expect(page.locator('.mrc-variant-errors')).toBeVisible();
  await expect(page.locator('.mrc-variant-errors')).toContainText('Duplicate option combination: size=small.');
});
