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

const billingBase = import.meta.env.VITE_BILLING_API_BASE || '/billing';
export const billing = {
  summary: () => api.get(`${billingBase}/api/v1/billing/summary`),
  invoices: (limit = 20, offset = 0) => api.get(`${billingBase}/api/v1/billing/invoices`, { params: { limit, offset } }),
  invoice: (id: string) => api.get(`${billingBase}/api/v1/billing/invoices/${encodeURIComponent(id)}`),
  creditNotes: (limit = 20, offset = 0) => api.get(`${billingBase}/api/v1/billing/credit-notes`, { params: { limit, offset } }),
  creditNote: (id: string) => api.get(`${billingBase}/api/v1/billing/credit-notes/${encodeURIComponent(id)}`),
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
