const { test } = require('@playwright/test');
test('explicit live provider smoke @live', async () => {
  test.skip(process.env.MERCATO_LIVE_PROVIDER_SMOKE !== 'I_UNDERSTAND_THIS_CREATES_A_REAL_TRANSACTION', 'Explicit live transaction flag is required.');
  throw new Error('Provider-specific live smoke implementation must be supplied by the merchant adapter; normal CI can never execute it.');
});
