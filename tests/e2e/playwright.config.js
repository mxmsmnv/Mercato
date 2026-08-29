const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

const artifacts = process.env.MERCATO_E2E_ARTIFACTS || path.join(__dirname, '../../artifacts/e2e');
module.exports = defineConfig({
  testDir: __dirname,
  testMatch: '**/*.spec.js',
  grepInvert: /@live/,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45000,
  expect: { timeout: 10000 },
  outputDir: path.join(artifacts, 'results'),
  reporter: [['list'], ['json', { outputFile: path.join(artifacts, 'playwright.json') }], ['html', { outputFolder: path.join(artifacts, 'html'), open: 'never' }]],
  use: { baseURL: process.env.MERCATO_E2E_BASE_URL || 'https://mercato.test', trace: 'retain-on-failure', screenshot: 'only-on-failure', video: 'retain-on-failure', ignoreHTTPSErrors: process.env.MERCATO_E2E_IGNORE_HTTPS_ERRORS === '1' },
  projects: [
    { name: 'chromium-desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'chromium-mobile', use: { ...devices['Pixel 7'] } },
    { name: 'firefox-desktop', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit-mobile', use: { ...devices['iPhone 15'] } }
  ]
});
