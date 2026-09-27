const fs = require('fs');
const AxeBuilder = require('@axe-core/playwright').default;

function fixture() { return JSON.parse(fs.readFileSync(process.env.MERCATO_E2E_STATE, 'utf8')); }
async function assertAccessible(page, label, include = null, exclude = []) {
  const builder = new AxeBuilder({ page });
  if (include) builder.include(include);
  for (const selector of exclude) builder.exclude(selector);
  const result = await builder.analyze();
  const critical = result.violations.filter(v => ['critical', 'serious'].includes(v.impact));
  if (critical.length) throw new Error(`${label}: ${critical.map(v => {
    const targets = v.nodes.slice(0, 10).flatMap(node => node.target).join(', ');
    return `${v.id} (${v.nodes.length}: ${targets})`;
  }).join(', ')}`);
}
async function assertResponsive(page, label) {
  const overflow = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth));
  if (overflow > 2) {
    const offenders = await page.evaluate(() => [...document.querySelectorAll('body *')].flatMap(element => {
      const rect = element.getBoundingClientRect();
      const ownOverflow = Math.max(0, element.scrollWidth - element.clientWidth);
      if (rect.right <= window.innerWidth + 2 && rect.left >= -2 && ownOverflow <= 2) return [];
      const id = element.id ? `#${element.id}` : '';
      const classes = [...element.classList].slice(0, 3).map(name => `.${name}`).join('');
      return [`${element.tagName.toLowerCase()}${id}${classes} right=${Math.round(rect.right)} left=${Math.round(rect.left)} own=${ownOverflow}`];
    }).slice(0, 12));
    throw new Error(`${label}: horizontal overflow ${overflow}px; ${offenders.join('; ')}`);
  }
}
module.exports = { fixture, assertAccessible, assertResponsive };
