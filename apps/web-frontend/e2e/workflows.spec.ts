import { expect, test } from '@playwright/test';

const order = {
  orderId: 'order-demo', offerId: 'offer-demo', quoteId: 'quote-demo', vehicleId: 'vehicle-demo',
  customerId: 'customer-demo', status: 'draft', createdAt: '2026-11-14T08:00:00Z',
};
const customer = {
  customer_id: 'customer-demo', customer_type: 'individual', first_name: 'Rami', last_name: 'Demo',
  email: 'rami@example.test', phone: '+4912345', locale: 'de-DE', status: 'active',
};

test('order dossier shows lifecycle, documents and performs a guarded transition', async ({ page }) => {
  let currentStatus = 'draft';
  await page.route('**/orders/api/v1/orders/order-demo', async route => {
    if (route.request().method() === 'POST') return route.fulfill({ json: { ...order, status: currentStatus } });
    return route.fulfill({ json: { ...order, status: currentStatus } });
  });
  await page.route('**/orders/api/v1/orders/order-demo/history', route => route.fulfill({ json: { items: [{ event_id: 'evt-1', event_type: 'OrderCreated', occurred_at: '2026-11-14T08:00:00Z' }] } }));
  await page.route('**/orders/api/v1/orders/order-demo/submit', async route => {
    currentStatus = 'submitted';
    await route.fulfill({ json: { ...order, status: currentStatus } });
  });
  await page.route('**/contracts/api/v1/contracts**', route => route.fulfill({ json: { items: [{ contract_id: 'contract-demo', order_id: 'order-demo', status: 'prepared', document_checksum: 'sha256:demo' }] } }));
  await page.route('**/payments/api/v1/payments**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/fulfillments/api/v1/fulfillments**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/reservations/api/v1/reservations**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/billing/api/v1/billing/invoices**', route => route.fulfill({ json: { items: [] } }));

  await page.goto('/operations/order/order-demo');
  await expect(page.getByRole('heading', { name: 'Auftrag order-demo' })).toBeVisible();
  await expect(page.getByText('Vertrag · contract-demo')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Einreichen' })).toBeVisible();
  await page.getByRole('button', { name: 'Einreichen' }).click();
  await expect(page.getByText('Auftragsstatus aktualisiert.')).toBeVisible();
  await expect(page.getByText('submitted', { exact: true })).toBeVisible();
});

test('Customer 360 loads customer profile and related activity', async ({ page }) => {
  await page.route('**/customers/api/v1/customers?**', route => route.fulfill({ json: { items: [customer], total: 1 } }));
  await page.route('**/customers/api/v1/customers/customer-demo', route => route.fulfill({ json: customer }));
  await page.route('**/customers/api/v1/customers/customer-demo/addresses', route => route.fulfill({ json: { items: [{ address_id: 'address-1', address_type: 'billing', line1: 'Demo Street 1', postal_code: '50667', city: 'Köln', country_code: 'DE', is_default: true }] } }));
  await page.route('**/customers/api/v1/customers/customer-demo/consents', route => route.fulfill({ json: { items: [{ purpose: 'marketing.email', granted: true }] } }));
  await page.route('**/customers/api/v1/customers/customer-demo/history', route => route.fulfill({ json: { items: [{ event_id: 'customer-event', event_type: 'CustomerCreated', occurred_at: '2026-11-14T08:00:00Z' }] } }));
  await page.route('**/orders/api/v1/orders**', route => route.fulfill({ json: { items: [{ order_id: 'order-demo', customer_id: 'customer-demo', status: 'accepted', vehicle_id: 'vehicle-demo' }] } }));
  await page.route('**/contracts/api/v1/contracts**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/fulfillments/api/v1/fulfillments**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/reservations/api/v1/reservations**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/billing/api/v1/billing/invoices**', route => route.fulfill({ json: { items: [] } }));

  await page.goto('/admin/customer-360');
  await expect(page.getByRole('heading', { name: 'Customer 360' })).toBeVisible();
  await expect(page.getByText('Rami Demo')).toBeVisible();
  await expect(page.getByText('order-demo')).toBeVisible();
  await expect(page.getByText('Demo Street 1')).toBeVisible();
});

test('business event monitor renders service activity', async ({ page }) => {
  await page.route('**/orders/api/v1/orders**', route => route.fulfill({ json: { items: [{ order_id: 'order-demo', status: 'accepted', created_at: '2026-11-14T08:00:00Z' }] } }));
  await page.route('**/contracts/api/v1/contracts**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/fulfillments/api/v1/fulfillments**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/inventory/api/v1/inventory/units**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/reservations/api/v1/reservations**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/billing/api/v1/billing/invoices**', route => route.fulfill({ json: { items: [] } }));
  await page.route('**/offers/api/offers**', route => route.fulfill({ json: { items: [] } }));

  await page.goto('/operations/events');
  await expect(page.getByRole('heading', { name: 'Business Events & Errors' })).toBeVisible();
  await expect(page.getByText('order-demo')).toBeVisible();
  await expect(page.getByText('Orders', { exact: true })).toBeVisible();
});
