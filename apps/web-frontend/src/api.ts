import axios from 'axios';
import type Keycloak from 'keycloak-js';

const catalogBase = import.meta.env.VITE_CATALOG_API_BASE || '/catalog';
const offerBase = import.meta.env.VITE_OFFER_API_BASE || '/offers';
const pricingBase = import.meta.env.VITE_PRICING_API_BASE || '/pricing';
const orderBase = import.meta.env.VITE_ORDER_API_BASE || '/orders';
const paymentBase = import.meta.env.VITE_PAYMENT_API_BASE || '/payments';

export const api = axios.create({
  timeout: 15000,
  headers: { Accept: 'application/json' },
});

let accessToken: () => string | undefined = () => undefined;

export function setAuth(keycloak: Keycloak) {
  accessToken = () => keycloak.token;
}

api.interceptors.request.use((config) => {
  const currentToken = accessToken();
  if (currentToken) config.headers.Authorization = `Bearer ${currentToken}`;
  config.headers.Accept = 'application/json';
  return config;
});

export const catalog = {
  list: (params: Record<string, unknown>) => api.get(`${catalogBase}/api/v1/vehicles`, { params }),
  detail: (id: string) => api.get(`${catalogBase}/api/v1/vehicles/${encodeURIComponent(id)}`),
  manufacturers: () => api.get(`${catalogBase}/api/v1/manufacturers`),
  createManufacturer: (payload: { code: string; name: string }) => api.post(`${catalogBase}/api/v1/manufacturers`, payload),
  createModel: (payload: { manufacturerId: string; name: string }) => api.post(`${catalogBase}/api/v1/models`, payload),
  createGeneration: (payload: Record<string, unknown>) => api.post(`${catalogBase}/api/v1/generations`, payload),
  createVariant: (payload: Record<string, unknown>) => api.post(`${catalogBase}/api/v1/vehicles`, payload),
  adminList: (resource: string, limit = 100, offset = 0) => api.get(`${catalogBase}/api/v1/admin/catalog/${resource}`, { params: { limit, offset } }),
  adminGet: (resource: string, id: string) => api.get(`${catalogBase}/api/v1/admin/catalog/${resource}/${encodeURIComponent(id)}`),
  adminUpdate: (resource: string, id: string, payload: Record<string, unknown>) => api.patch(`${catalogBase}/api/v1/admin/catalog/${resource}/${encodeURIComponent(id)}`, payload),
  adminArchive: (resource: string, id: string) => api.delete(`${catalogBase}/api/v1/admin/catalog/${resource}/${encodeURIComponent(id)}`),
};

export const offers = {
  list: (params: Record<string, unknown>) => api.get(`${offerBase}/api/offers`, { params }),
  quote: (id: string, payload: unknown) => api.post(`${offerBase}/api/offers/${encodeURIComponent(id)}/quotes`, payload),
  create: (id: string, payload: unknown) => api.post(`${offerBase}/api/offers/${encodeURIComponent(id)}`, payload),
  show: (id: string) => api.get(`${offerBase}/api/offers/${encodeURIComponent(id)}`),
  history: (id: string) => api.get(`${offerBase}/api/offers/${encodeURIComponent(id)}/history`),
  downloadPdf: (id: string) => api.get(`${offerBase}/api/offers/${encodeURIComponent(id)}/pdf`, { responseType: 'blob' }),
  publish: (id: string) => api.post(`${offerBase}/api/offers/${encodeURIComponent(id)}/publish`),
  accept: (id: string) => api.post(`${offerBase}/api/offers/${encodeURIComponent(id)}/accept`),
  reject: (id: string) => api.post(`${offerBase}/api/offers/${encodeURIComponent(id)}/reject`),
};

export const pricing = {
  quotes: (limit = 20, offset = 0) => api.get(`${pricingBase}/api/pricing/quotes`, { params: { limit, offset } }),
  quote: (id: string) => api.get(`${pricingBase}/api/pricing/quotes/${encodeURIComponent(id)}`),
  deleteQuote: (id: string) => api.delete(`${pricingBase}/api/pricing/quotes/${encodeURIComponent(id)}`),
};

const customerBase = import.meta.env.VITE_CUSTOMER_API_BASE || '/customers';

export const customers = {
  list: (params: Record<string, unknown> = {}) => api.get(`${customerBase}/api/v1/customers`, { params }),
  get: (id: string) => api.get(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}`),
  create: (payload: Record<string, unknown>, key: string) =>
    api.post(`${customerBase}/api/v1/customers`, payload, { headers: { 'Idempotency-Key': key } }),
  update: (id: string, payload: Record<string, unknown>) =>
    api.patch(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}`, payload),
  archive: (id: string) => api.delete(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}`),
  addresses: (id: string) => api.get(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}/addresses`),
  addAddress: (id: string, payload: Record<string, unknown>) =>
    api.post(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}/addresses`, payload),
  updateAddress: (id: string, addressId: string, payload: Record<string, unknown>) =>
    api.put(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}/addresses/${encodeURIComponent(addressId)}`, payload),
  deleteAddress: (id: string, addressId: string) =>
    api.delete(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}/addresses/${encodeURIComponent(addressId)}`),
  consents: (id: string) => api.get(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}/consents`),
  setConsent: (id: string, payload: { purpose: string; granted: boolean; source?: string }) =>
    api.put(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}/consents`, payload),
  history: (id: string) => api.get(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}/history`),
  linkIdentity: (id: string, subject: string) => api.put(`${customerBase}/api/v1/customers/${encodeURIComponent(id)}/identity`, { subject }),
  mercureAccess: () => api.get('/customers/api/v1/realtime/access', { withCredentials: true }),
  portalMe: () => api.get(`${customerBase}/api/v1/customers/me`),
  portalUpdate: (payload: Record<string, unknown>) => api.patch(`${customerBase}/api/v1/customers/me`, payload),
  portalAddresses: () => api.get(`${customerBase}/api/v1/customers/me/addresses`),
  portalConsents: () => api.get(`${customerBase}/api/v1/customers/me/consents`),
  portalSetConsent: (purpose: string, granted: boolean) => api.put(`${customerBase}/api/v1/customers/me/consents`, { purpose, granted }),
};

export const orders = {
  list: (limit = 20, offset = 0) => api.get(`${orderBase}/api/v1/orders`, { params: { limit, offset } }),
  get: (id: string) => api.get(`${orderBase}/api/v1/orders/${encodeURIComponent(id)}`),
  history: (id: string) => api.get(`${orderBase}/api/v1/orders/${encodeURIComponent(id)}/history`),
  create: (payload: { offerId: string; quoteId: string; vehicleId: string; customerId: string }, key: string) =>
    api.post(`${orderBase}/api/v1/orders`, payload, { headers: { 'Idempotency-Key': key } }),
  transition: (id: string, action: string, key: string, payload?: Record<string, unknown>) =>
    api.post(`${orderBase}/api/v1/orders/${encodeURIComponent(id)}/${action}`, payload ?? {}, { headers: { 'Idempotency-Key': key } }),
};

export const payments = {
  list: (limit = 20, offset = 0) => api.get(`${paymentBase}/api/v1/payments`, { params: { limit, offset } }),
  create: (orderId: string, key: string) =>
    api.post(`${paymentBase}/api/v1/payments`, { orderId }, { headers: { 'Idempotency-Key': key } }),
  get: (id: string) => api.get(`${paymentBase}/api/v1/payments/${encodeURIComponent(id)}`),
  transition: (id: string, action: 'capture' | 'cancel' | 'refund', key: string, amountMinor?: number) =>
    api.post(`${paymentBase}/api/v1/payments/${encodeURIComponent(id)}/${action}`,
      action === 'refund' ? { amountMinor } : {},
      { headers: { 'Idempotency-Key': key } }),
};

const crmBase = import.meta.env.VITE_CRM_API_BASE || '/crm';
export const crm = {
  listApplications: (params: Record<string, unknown> = {}) => api.get(`${crmBase}/api/v1/crm/applications`, { params }),
  createApplication: (payload: Record<string, unknown>, key: string) =>
    api.post(`${crmBase}/api/v1/crm/applications`, payload, { headers: { 'Idempotency-Key': key } }),
  getApplication: (id: string) => api.get(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}`),
  transition: (id: string, status: string, reason?: string) =>
    api.patch(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/status`, { status, reason }),
  assignAdvisor: (id: string, advisorId: string) =>
    api.put(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/assignment`, { advisorId }),
  setEligibility: (id: string, status: string, result: Record<string, unknown>) =>
    api.put(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/eligibility`, { status, result }),
  createTask: (id: string, payload: Record<string, unknown>) =>
    api.post(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/tasks`, payload),
  listTasks: (id: string) => api.get(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/tasks`),
  updateTask: (id: string, taskId: string, status: string) =>
    api.patch(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/tasks/${encodeURIComponent(taskId)}`, { status }),
  addInteraction: (id: string, payload: Record<string, unknown>) =>
    api.post(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/interactions`, payload),
  listInteractions: (id: string) => api.get(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/interactions`),
  history: (id: string) => api.get(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/history`),
  convert: (id: string) => api.post(`${crmBase}/api/v1/crm/applications/${encodeURIComponent(id)}/convert`, {}),
};

const billingBase = import.meta.env.VITE_BILLING_API_BASE || '/billing';
export const billing = {
  summary: () => api.get(`${billingBase}/api/v1/billing/summary`),
  invoices: (limit = 20, offset = 0) => api.get(`${billingBase}/api/v1/billing/invoices`, { params: { limit, offset } }),
  invoice: (id: string) => api.get(`${billingBase}/api/v1/billing/invoices/${encodeURIComponent(id)}`),
  creditNotes: (limit = 20, offset = 0) => api.get(`${billingBase}/api/v1/billing/credit-notes`, { params: { limit, offset } }),
  creditNote: (id: string) => api.get(`${billingBase}/api/v1/billing/credit-notes/${encodeURIComponent(id)}`),
};

const contractBase = import.meta.env.VITE_CONTRACT_API_BASE || '/contracts';
const fulfillmentBase = import.meta.env.VITE_FULFILLMENT_API_BASE || '/fulfillments';
const inventoryBase = import.meta.env.VITE_INVENTORY_API_BASE || '/inventory';
const reservationBase = import.meta.env.VITE_RESERVATION_API_BASE || '/reservations';

export const contracts = {
  list: (limit = 20, offset = 0) => api.get(`${contractBase}/api/v1/contracts`, { params: { limit, offset } }),
  get: (id: string) => api.get(`${contractBase}/api/v1/contracts/${encodeURIComponent(id)}`),
  create: (payload: { orderId: string; contractType: string; contractVersion: number }, key: string) =>
    api.post(`${contractBase}/api/v1/contracts`, payload, { headers: { 'Idempotency-Key': key } }),
  prepare: (id: string, key: string) => api.post(`${contractBase}/api/v1/contracts/${encodeURIComponent(id)}/prepare`, {}, { headers: { 'Idempotency-Key': key } }),
  cancel: (id: string, key: string) => api.post(`${contractBase}/api/v1/contracts/${encodeURIComponent(id)}/cancel`, {}, { headers: { 'Idempotency-Key': key } }),
  sendForSignature: (id: string, key: string) => api.post(`${contractBase}/api/v1/contracts/${encodeURIComponent(id)}/send-for-signature`, {}, { headers: { 'Idempotency-Key': key } }),
  generateDocument: (id: string) => api.post(`${contractBase}/api/v1/contracts/${encodeURIComponent(id)}/document`, {}),
  downloadDocument: (id: string) => api.get(`${contractBase}/api/v1/contracts/${encodeURIComponent(id)}/document`, { responseType: 'blob' }),
  history: (id: string) => api.get(`${contractBase}/api/v1/contracts/${encodeURIComponent(id)}/history`),
};

export const fulfillments = {
  list: (limit = 20, offset = 0) => api.get(`${fulfillmentBase}/api/v1/fulfillments`, { params: { limit, offset } }),
  get: (id: string) => api.get(`${fulfillmentBase}/api/v1/fulfillments/${encodeURIComponent(id)}`),
  history: (id: string) => api.get(`${fulfillmentBase}/api/v1/fulfillments/${encodeURIComponent(id)}/history`),
  command: (id: string, command: string, version: number, key: string, payload: Record<string, unknown> = {}) =>
    api.post(`${fulfillmentBase}/api/v1/fulfillments/${encodeURIComponent(id)}/${command}`, payload, { headers: { 'If-Match': `"${version}"`, 'Idempotency-Key': key } }),
};

export const inventory = {
  units: (limit = 20, offset = 0) => api.get(`${inventoryBase}/api/v1/inventory/units`, { params: { limit, offset } }),
  unit: (id: string) => api.get(`${inventoryBase}/api/v1/inventory/units/${encodeURIComponent(id)}`),
  create: (payload: Record<string, unknown>, key: string) =>
    api.post(`${inventoryBase}/api/v1/inventory/units`, payload, { headers: { 'Idempotency-Key': key } }),
  changeStatus: (id: string, status: string, version: number, key: string, reason?: string) =>
    api.post(`${inventoryBase}/api/v1/inventory/units/${encodeURIComponent(id)}/status`, { status, reason }, { headers: { 'If-Match': `"${version}"`, 'Idempotency-Key': key } }),
  history: (id: string) => api.get(`${inventoryBase}/api/v1/inventory/units/${encodeURIComponent(id)}/history`),
  availability: (vehicleVariantId: string) => api.get(`${inventoryBase}/api/v1/inventory/availability`, { params: { vehicleVariantId } }),
};

export const reservations = {
  list: (limit = 20, offset = 0) => api.get(`${reservationBase}/api/v1/reservations`, { params: { limit, offset } }),
  get: (id: string) => api.get(`${reservationBase}/api/v1/reservations/${encodeURIComponent(id)}`),
  create: (payload: { vehicleUnitId: string; vehicleVariantId: string; orderId: string; expiresAt: string }, key: string) =>
    api.post(`${reservationBase}/api/v1/reservations`, payload, { headers: { 'Idempotency-Key': key } }),
  transition: (id: string, action: 'confirm' | 'release' | 'fulfill', version: number, key: string) =>
    api.post(`${reservationBase}/api/v1/reservations/${encodeURIComponent(id)}/${action}`, {}, { headers: { 'If-Match': `"${version}"`, 'Idempotency-Key': key } }),
  history: (id: string) => api.get(`${reservationBase}/api/v1/reservations/${encodeURIComponent(id)}/history`),
};


export function errorMessage(error: unknown) {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { detail?: string; message?: string; title?: string } | undefined;
    if (error.response?.status === 401) return 'Session expirée ou token invalide. Reconnecte-toi puis réessaie.';
    if (error.response?.status === 403) return data?.detail || 'Permission manquante pour cette opération (vérifie les rôles/scopes Keycloak).';
    return data?.detail || data?.message || data?.title || error.message;
  }
  return error instanceof Error ? error.message : 'Une erreur inattendue est survenue.';
}
