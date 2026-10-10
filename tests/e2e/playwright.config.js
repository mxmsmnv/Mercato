const { defineConfig, devices } = require('@playwright/test');
const { firefox } = require('playwright');
const fs = require('fs');
const os = require('os');
const path = require('path');

const artifacts = process.env.MERCATO_E2E_ARTIFACTS || path.join(__dirname, '../../artifacts/e2e');
const launchOptions = { timeout: 45000 };
const profile = process.env.MERCATO_E2E_PROFILE || 'core';

function firefoxLaunchOptions() {
  if (process.platform !== 'darwin' || Number.parseInt(os.release(), 10) < 26) return launchOptions;
  const applicationData = path.join(os.homedir(), 'Library/Application Support/Firefox');
  try {
    fs.readdirSync(applicationData);
    return launchOptions;
  } catch (error) {
    if (!error || !['EPERM', 'EACCES'].includes(error.code)) return launchOptions;
  }

  const binary = firefox.executablePath();
  const sourceIni = path.join(binary.slice(0, -'/Contents/MacOS/firefox'.length), 'Contents/Resources/application.ini');
  // Gecko resolves app resources relative to this directory, so the alternate
  // descriptor must live below Resources/browser rather than in test output.
  const brandedIni = path.join(path.dirname(sourceIni), 'browser', 'application.ini');
  fs.writeFileSync(brandedIni, fs.readFileSync(sourceIni, 'utf8')
    .replace(/^Vendor=.*$/m, 'Vendor=Playwright')
    .replace(/^Name=.*$/m, 'Name=PlaywrightFirefox')
    .replace(/^RemotingName=.*$/m, 'RemotingName=playwright-firefox'));
  process.env.MERCATO_PLAYWRIGHT_FIREFOX_BINARY = binary;
  process.env.MERCATO_PLAYWRIGHT_FIREFOX_APP_INI = brandedIni;
  return { ...launchOptions, executablePath: path.join(__dirname, 'playwright-firefox-wrapper.sh') };
}

const dedicated = ['**/admin.spec.js', '**/admin-visual-breadth.spec.js', '**/customer-lifecycle.spec.js', '**/refund.spec.js', '**/ownership-boundaries.spec.js', '**/guest-checkout.spec.js', '**/presentation-states.spec.js', '**/authenticated-variant.spec.js', '**/fulfilment-matrix.spec.js', '**/cross-browser-checkout.spec.js', '**/public-visual-states.spec.js', '**/keyboard-a11y.spec.js', '**/assistive-visual-evidence.spec.js', '**/tax-provider.spec.js'];
const projects = profile === 'core' ? [
  { name: 'chromium-desktop', testIgnore: dedicated, use: { ...devices['Desktop Chrome'] } },
  { name: 'chromium-mobile', testIgnore: ['**/a11y-interactions.spec.js', '**/recovery.spec.js', ...dedicated], use: { ...devices['Pixel 7'] } },
  { name: 'firefox-desktop', testIgnore: ['**/recovery.spec.js', ...dedicated], use: { ...devices['Desktop Firefox'], launchOptions: firefoxLaunchOptions() } },
  { name: 'webkit-mobile', testIgnore: ['**/a11y-interactions.spec.js', ...dedicated], use: { ...devices['iPhone 15'] } }
] : profile === 'cross-browser-checkout' ? [
  { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  { name: 'firefox', use: { ...devices['Desktop Firefox'], launchOptions: firefoxLaunchOptions() } },
  { name: 'webkit', use: { ...devices['Desktop Safari'] } }
] : [{ name: 'chromium-desktop', use: { ...devices['Desktop Chrome'] } }];

module.exports = defineConfig({
  testDir: __dirname,
  testMatch: profile === 'admin' ? '**/admin.spec.js' : (profile === 'admin-visual-breadth' ? '**/admin-visual-breadth.spec.js' : (profile === 'customer-lifecycle' ? '**/customer-lifecycle.spec.js' : (profile === 'refund' ? '**/refund.spec.js' : (profile === 'ownership-boundaries' ? '**/ownership-boundaries.spec.js' : (profile === 'guest-checkout' ? '**/guest-checkout.spec.js' : (profile === 'presentation-states' ? '**/presentation-states.spec.js' : (profile === 'authenticated-variant' ? '**/authenticated-variant.spec.js' : (profile === 'fulfilment-matrix' ? '**/fulfilment-matrix.spec.js' : (profile === 'cross-browser-checkout' ? '**/cross-browser-checkout.spec.js' : (profile === 'public-visual-states' ? '**/public-visual-states.spec.js' : (profile === 'keyboard-a11y' ? '**/keyboard-a11y.spec.js' : (profile === 'assistive-visual' ? '**/assistive-visual-evidence.spec.js' : (profile === 'tax-provider' ? '**/tax-provider.spec.js' : '**/*.spec.js'))))))))))))),
  grepInvert: /@live/,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45000,
  expect: { timeout: 10000 },
  outputDir: path.join(artifacts, 'results'),
  reporter: [['list'], ['json', { outputFile: path.join(artifacts, 'playwright.json') }], ['html', { outputFolder: path.join(artifacts, 'html'), open: 'never' }]],
  use: { baseURL: process.env.MERCATO_E2E_BASE_URL || 'https://mercato.test', trace: 'retain-on-failure', screenshot: 'only-on-failure', video: 'retain-on-failure', ignoreHTTPSErrors: process.env.MERCATO_E2E_IGNORE_HTTPS_ERRORS === '1', launchOptions },
  projects
});
