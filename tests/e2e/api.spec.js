const { test, expect } = require('@playwright/test');
const { fixture } = require('./helpers');

test('stateless native guest checkout API @api', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium-desktop', 'Mutating API journey runs once.');
  const state = fixture(); const body = {
    items: [{ product_id: state.product_id, quantity: 1 }],
    customer: { first_name: 'Native', last_name: 'Fixture', email: `e2e-${state.run_id}-api@example.test`, address: 'API Street', city: 'Test City', zip: '10001', country: 'US' },
    options: { payment_method: 'demo', fulfilment_method: 'carrier_delivery', policy_accepted: true, discount_code: state.coupon }
  };
  const quote = await request.post('/api/mercato/v1/quotes', { data: body }); expect(quote.ok()).toBeTruthy(); const quoteJson = await quote.json(); expect(quoteJson.data.discount_valid).toBeTruthy();
  const key = `api-create-${state.run_id}`; const created = await request.post('/api/mercato/v1/checkouts', { data: body, headers: { 'Idempotency-Key': key } }); expect(created.status()).toBe(201); const checkout = (await created.json()).data;
  const replay = await request.post('/api/mercato/v1/checkouts', { data: body, headers: { 'Idempotency-Key': key } }); expect((await replay.json()).data.id).toBe(checkout.id);
  const denied = await request.get(`/api/mercato/v1/checkouts/${checkout.id}`, { headers: { Authorization: 'Bearer wrong-token' } }); expect(denied.status()).toBe(404);
  const auth = { Authorization: `Bearer ${checkout.token}` }; const pending = await request.get(`/api/mercato/v1/checkouts/${checkout.id}`, { headers: auth }); expect((await pending.json()).data.payment_status).toBe('pending');
  const completeKey = `api-complete-${state.run_id}`; const completed = await request.post(`/api/mercato/v1/checkouts/${checkout.id}/complete`, { data: {}, headers: { ...auth, 'Idempotency-Key': completeKey } }); expect((await completed.json()).data.payment_complete).toBeTruthy();
  const completeReplay = await request.post(`/api/mercato/v1/checkouts/${checkout.id}/complete`, { data: {}, headers: { ...auth, 'Idempotency-Key': completeKey } }); expect((await completeReplay.json()).data.replayed).toBeTruthy();
  const order = await request.get(`/api/mercato/v1/orders/${checkout.order_id}`, { headers: auth }); const orderJson = await order.json(); expect(orderJson.data.payment_status).toBe('paid'); expect(JSON.stringify(orderJson.data)).not.toContain(body.customer.email);
});
