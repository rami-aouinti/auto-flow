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
  await expect(page.locator('h1')).toContainText('Auftrag');
  await expect(page.locator('h1')).toContainText('order-demo');
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
  await page.getByRole('button', { name: 'Ansehen' }).first().click();
  await expect(page.getByText('Ereignisdetails')).toBeVisible();
  await expect(page.locator('pre.event-payload')).toContainText('order-demo');
  await page.getByRole('button', { name: 'Schließen' }).last().click();
  await page.getByRole('textbox', { name: 'Aktivitäten durchsuchen' }).fill('not-a-real-order');
  await expect(page.getByText('Keine Aktivitäten passen zu diesem Filter.')).toBeVisible();
});


test('customer self-service portal reads and updates only the linked profile', async ({ page }) => {
  let profile = {
    customer_id: '00000000-0000-4000-8000-000000000101',
    customer_type: 'individual',
    first_name: 'Demo',
    last_name: 'Customer',
    email: 'customer-demo@autoflow.local',
    phone: '',
    locale: 'de-DE',
    status: 'active',
    version: 1,
  };
  await page.route('**/customers/api/v1/customers/me', async route => {
    if (route.request().method() === 'PATCH') {
      profile = { ...profile, ...route.request().postDataJSON(), version: profile.version + 1 };
    }
    await route.fulfill({ json: profile });
  });
  let portalAddresses = [{ address_id: 'portal-address', address_type: 'billing', line1: 'Demo Street 1', postal_code: '50667', city: 'Köln', country_code: 'DE', is_default: true }];
  await page.route('**/customers/api/v1/customers/me/addresses', async route => {
    if (route.request().method() === 'POST') {
      const body = route.request().postDataJSON();
      portalAddresses.push({ ...body, address_id: 'portal-address-new' });
      return route.fulfill({ status: 201, json: portalAddresses[1] });
    }
    return route.fulfill({ json: { items: portalAddresses } });
  });
  await page.route('**/customers/api/v1/customers/me/addresses/*', async route => {
    const id = route.request().url().split('/').pop();
    if (route.request().method() === 'PUT') {
      const body = route.request().postDataJSON();
      portalAddresses = portalAddresses.map(address => address.address_id === id ? { ...address, ...body } : address);
      return route.fulfill({ json: portalAddresses.find(address => address.address_id === id) });
    }
    if (route.request().method() === 'DELETE') {
      portalAddresses = portalAddresses.filter(address => address.address_id !== id);
      return route.fulfill({ status: 204, body: '' });
    }
    return route.fulfill({ status: 404 });
  });
  await page.route('**/customers/api/v1/customers/me/consents', async route => {
    if (route.request().method() === 'PUT') return route.fulfill({ json: route.request().postDataJSON() });
    return route.fulfill({ json: { items: [{ purpose: 'marketing.email', granted: false }, { purpose: 'marketing.sms', granted: false }] } });
  });

  await page.goto('/portal');
  await expect(page.getByRole('heading', { name: 'Mein Kundenkonto' })).toBeVisible();
  await expect(page.getByText('Demo Street 1')).toBeVisible();
  await page.getByLabel('Vorname').fill('Alex');
  await page.getByRole('button', { name: 'Änderungen speichern' }).click();
  await expect(page.getByText('Deine Profildaten wurden gespeichert.')).toBeVisible();
  await expect(page.getByLabel('Vorname')).toHaveValue('Alex');

  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await page.getByLabel('Straße und Hausnummer').fill('Demo Street 2');
  await page.getByLabel('Postleitzahl').fill('50667');
  await page.getByLabel('Stadt').fill('Köln');
  await page.getByLabel('Land (ISO)').fill('DE');
  await page.getByRole('button', { name: 'Speichern' }).last().click();
  await expect(page.getByText(/Demo Street 2/)).toBeVisible();
});
