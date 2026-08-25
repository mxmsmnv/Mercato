const fs = require('fs');
const AxeBuilder = require('@axe-core/playwright').default;

function fixture() { return JSON.parse(fs.readFileSync(process.env.MERCATO_E2E_STATE, 'utf8')); }
async function assertAccessible(page, label) {
  const result = await new AxeBuilder({ page }).analyze();
  const critical = result.violations.filter(v => ['critical', 'serious'].includes(v.impact));
  if (critical.length) throw new Error(`${label}: ${critical.map(v => `${v.id} (${v.nodes.length})`).join(', ')}`);
}
async function assertResponsive(page, label) {
  const overflow = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth));
  if (overflow > 2) throw new Error(`${label}: horizontal overflow ${overflow}px`);
}
module.exports = { fixture, assertAccessible, assertResponsive };
