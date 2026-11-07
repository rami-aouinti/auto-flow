<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { orders, payments, pricing, errorMessage } from './api';
import PageHeader from '../app/components/shared/PageHeader.vue';

type Order = {
  orderId: string; offerId?: string; quoteId?: string; vehicleId?: string;
  status: string; createdAt?: string; updatedAt?: string;
  offerSnapshot?: Record<string, unknown>; quoteSnapshot?: Record<string, unknown>;
  vehicleSnapshot?: Record<string, unknown>;
};
type StripePaymentElement = { mount(target: HTMLElement): void; unmount(): void };
type StripeElements = { create(type: 'payment'): StripePaymentElement; submit(): Promise<{ error?: { message: string } }> };
type StripeClient = {
  elements(options: { clientSecret: string; appearance?: Record<string, unknown> }): StripeElements;
  confirmPayment(options: { elements: StripeElements; clientSecret: string; confirmParams: { return_url: string }; redirect: 'if_required' }): Promise<{ error?: { message: string }; paymentIntent?: { id: string; status: string } }>;
};
declare global { interface Window { Stripe?: (publishableKey: string) => StripeClient } }

type Payment = {
  paymentId: string; orderId: string; status: string; amountMinor: number;
  currency: string; provider?: string; clientSecret?: string | null; createdAt?: string;
};

const route = useRoute();
const router = useRouter();
const tab = ref<'orders' | 'payments' | 'quotes'>('orders');
watch(tab, (value) => {
  const query = { ...route.query };
  if (value === 'orders') delete query.tab;
  else query.tab = value;
  void router.replace({ query });
});
const orderRows = ref<Order[]>([]);
const orderTotal = ref(0);
const orderLoading = ref(false);
const orderError = ref('');
const orderOffset = ref(0);
const orderLimit = 20;
const orderSearchId = ref('');
const selectedOrder = ref<Order | null>(null);
const orderHistory = ref<any[]>([]);
const historyError = ref('');
const historyLoading = ref(false);
const createForm = ref({ offerId: '', quoteId: '', vehicleId: '' });
const createBusy = ref(false);
const createError = ref('');
const actionBusy = ref('');
const actionError = ref('');
const rejectReason = ref('');
const idempotencyKey = () => globalThis.crypto?.randomUUID?.() ?? `af-${Date.now()}-${Math.random().toString(16).slice(2)}`;

const quoteRows = ref<any[]>([]);
const quoteLoading = ref(false);
const quoteError = ref('');
const quoteOffset = ref(0);
const quoteLimit = 20;

const paymentRows = ref<Payment[]>([]);
const paymentTotal = ref(0);
const paymentOffset = ref(0);
const paymentLimit = 20;
const paymentHistoryLoading = ref(false);
const paymentHistoryError = ref('');
const checkoutMount = ref<HTMLElement | null>(null);
const checkoutBusy = ref(false);
const checkoutError = ref('');
const stripePublicKey = import.meta.env.VITE_STRIPE_PUBLISHABLE_KEY || '';
let stripeClient: StripeClient | null = null;
let stripeElements: StripeElements | null = null;
let stripePaymentElement: StripePaymentElement | null = null;
const paymentIdInput = ref('');
const paymentRecord = ref<Payment | null>(null);
const paymentBusy = ref(false);
const paymentError = ref('');
const refundAmount = ref('');

const paymentPages = computed(() => Math.max(1, Math.ceil(paymentTotal.value / paymentLimit)));
const orderPages = computed(() => Math.max(1, Math.ceil(orderTotal.value / orderLimit)));

async function loadOrders() {
  orderLoading.value = true; orderError.value = '';
  try {
    const response = await orders.list(orderLimit, orderOffset.value);
    const data = response.data;
    orderRows.value = Array.isArray(data) ? data : (data.items ?? []);
    orderTotal.value = data.total ?? orderRows.value.length;
  } catch (error) { orderError.value = errorMessage(error); }
  finally { orderLoading.value = false; }
}
async function createOrder() {
  createBusy.value = true; createError.value = ''; actionError.value = '';
  try {
    const response = await orders.create(createForm.value, idempotencyKey());
    createForm.value = { offerId: '', quoteId: '', vehicleId: '' };
    await loadOrders();
    await selectOrder(response.data);
  } catch (error) { createError.value = errorMessage(error); }
  finally { createBusy.value = false; }
}
async function selectOrder(order: Order) {
  selectedOrder.value = order; historyLoading.value = true; historyError.value = ''; orderHistory.value = [];
  try {
    const [detail, history] = await Promise.all([orders.get(order.orderId), orders.history(order.orderId)]);
    selectedOrder.value = detail.data;
    orderHistory.value = history.data.items ?? [];
  } catch (error) { historyError.value = errorMessage(error); }
  finally { historyLoading.value = false; }
}
async function transitionOrder(order: Order, action: 'submit'|'review'|'accept'|'reject'|'cancel'|'contract-pending'|'complete') {
  actionBusy.value = order.orderId; actionError.value = '';
  try {
    const payload = action === 'reject' ? { reason: rejectReason.value.trim() } : undefined;
    await orders.transition(order.orderId, action, idempotencyKey(), payload);
    rejectReason.value = '';
    await loadOrders();
    await selectOrder(order);
  } catch (error) { actionError.value = errorMessage(error); }
  finally { actionBusy.value = ''; }
}
async function loadQuotes() {
  quoteLoading.value = true; quoteError.value = '';
  try { const response = await pricing.quotes(quoteLimit, quoteOffset.value); quoteRows.value = response.data.items ?? []; }
  catch (error) { quoteError.value = errorMessage(error); }
  finally { quoteLoading.value = false; }
}
async function deleteQuote(id: string) {
  quoteError.value = '';
  try { await pricing.deleteQuote(id); await loadQuotes(); }
  catch (error) { quoteError.value = errorMessage(error); }
}
async function loadPayments() {
  paymentHistoryLoading.value = true; paymentHistoryError.value = '';
  try {
    const response = await payments.list(paymentLimit, paymentOffset.value);
    paymentRows.value = (response.data.items ?? []).map((item: any) => normalizePayment(item));
    paymentTotal.value = response.data.total ?? paymentRows.value.length;
  } catch (error) { paymentHistoryError.value = errorMessage(error); }
  finally { paymentHistoryLoading.value = false; }
}
async function createPayment(order: Order) {
  paymentBusy.value = true; paymentError.value = '';
  try {
    const response = await payments.create(order.orderId, idempotencyKey());
    paymentRecord.value = normalizePayment(response.data);
    paymentIdInput.value = response.data.paymentId;
    tab.value = 'payments';
    paymentOffset.value = 0;
    await loadPayments();
  } catch (error) { paymentError.value = errorMessage(error); }
  finally { paymentBusy.value = false; }
}
function normalizePayment(data: any): Payment {
  return {
    paymentId: data.paymentId ?? data.payment_id,
    orderId: data.orderId ?? data.order_id,
    status: data.status,
    amountMinor: Number(data.amountMinor ?? data.amount_minor ?? 0),
    currency: data.currency,
    provider: data.provider,
    clientSecret: data.clientSecret ?? (paymentRecord.value?.paymentId === (data.paymentId ?? data.payment_id) ? paymentRecord.value?.clientSecret : null),
    createdAt: data.createdAt ?? data.created_at,
  };
}
async function loadPayment() {
  if (!paymentIdInput.value.trim()) return;
  paymentBusy.value = true; paymentError.value = '';
  try { paymentRecord.value = normalizePayment((await payments.get(paymentIdInput.value.trim())).data); }
  catch (error) { paymentError.value = errorMessage(error); paymentRecord.value = null; }
  finally { paymentBusy.value = false; }
}
async function mountStripeElement() {
  await nextTick();
  const secret = paymentRecord.value?.clientSecret;
  if (!secret || tab.value !== 'payments' || !checkoutMount.value) return;
  if (!stripePublicKey) { checkoutError.value = 'VITE_STRIPE_PUBLISHABLE_KEY fehlt. Hinterlege ausschließlich deinen Stripe Publishable Key.'; return; }
  if (!window.Stripe) { checkoutError.value = 'Stripe.js konnte nicht geladen werden. Prüfe die Verbindung zu js.stripe.com.'; return; }
  try {
    stripePaymentElement?.unmount();
    stripeClient = window.Stripe(stripePublicKey);
    stripeElements = stripeClient.elements({ clientSecret: secret, appearance: { theme: 'night' } });
    stripePaymentElement = stripeElements.create('payment');
    stripePaymentElement.mount(checkoutMount.value);
    checkoutError.value = '';
  } catch (error) {
    checkoutError.value = error instanceof Error ? error.message : 'Stripe Checkout konnte nicht initialisiert werden.';
  }
}
async function confirmCardPayment() {
  if (!stripeClient || !stripeElements || !paymentRecord.value) return;
  checkoutBusy.value = true; checkoutError.value = '';
  try {
    const submitted = await stripeElements.submit();
    if (submitted.error) { checkoutError.value = submitted.error.message; return; }
    const returnUrl = new URL(window.location.href);
    returnUrl.searchParams.set('paymentId', paymentRecord.value.paymentId);
    const result = await stripeClient.confirmPayment({
      elements: stripeElements,
      clientSecret: paymentRecord.value.clientSecret!,
      confirmParams: { return_url: returnUrl.toString() },
      redirect: 'if_required',
    });
    if (result.error) {
      checkoutError.value = result.error.message;
      return;
    }
    if (result.paymentIntent) {
      paymentRecord.value.status = result.paymentIntent.status === 'requires_capture' ? 'authorized' : result.paymentIntent.status;
    }
    await loadPayment();
    await loadPayments();
    checkoutError.value = 'Zahlungsdaten wurden an Stripe übermittelt. Der finale Status wird über den Payment Webhook synchronisiert.';
  } catch (error) { checkoutError.value = errorMessage(error); }
  finally { checkoutBusy.value = false; }
}
function destroyStripeElement() {
  stripePaymentElement?.unmount();
  stripePaymentElement = null;
  stripeElements = null;
  stripeClient = null;
}
watch(() => paymentRecord.value?.clientSecret, () => {
  if (tab.value === 'payments' && paymentRecord.value?.clientSecret) void mountStripeElement();
  else destroyStripeElement();
});
watch(tab, (value) => {
  if (value === 'payments' && paymentRecord.value?.clientSecret) void mountStripeElement();
  else if (value !== 'payments') destroyStripeElement();
});
onBeforeUnmount(destroyStripeElement);

async function paymentAction(action: 'capture'|'cancel'|'refund') {
  const currentPayment = paymentRecord.value;
  if (!currentPayment) return;
  paymentBusy.value = true; paymentError.value = '';
  try {
    const amountMinor = action === 'refund' ? Math.round(Number(refundAmount.value)) : undefined;
    const response = await payments.transition(currentPayment.paymentId, action, idempotencyKey(), amountMinor);
    if (response.data?.paymentId) paymentRecord.value = { ...currentPayment, ...response.data };
    else await loadPayment();
  } catch (error) { paymentError.value = errorMessage(error); }
  finally { paymentBusy.value = false; }
}
function moneyMinor(amount: number, currency: string) {
  return new Intl.NumberFormat('de-DE', { style: 'currency', currency: currency || 'EUR' }).format((amount || 0) / 100);
}
function label(value?: string) { return (value || '–').replaceAll('_', ' '); }
function date(value?: string) { return value ? new Date(value).toLocaleString('de-DE') : '–'; }
onMounted(() => {
  const params = new URLSearchParams(window.location.search);
  const requestedTab = params.get('tab');
  if (requestedTab === 'payments' || requestedTab === 'quotes' || requestedTab === 'orders') {
    tab.value = requestedTab;
  }
  const returnedPaymentId = params.get('paymentId');
  if (returnedPaymentId) {
    paymentIdInput.value = returnedPaymentId;
    tab.value = 'payments';
    void loadPayment();
  }
  void loadOrders();
  void loadQuotes();
  void loadPayments();
});
</script>

<template>
  <section class="ops">
    <PageHeader eyebrow="AUTOFLOW · OPERATIONS" title="Aufträge & Payments" description="Steuere den Auftragslebenszyklus, verwalte Zahlungsvorgänge und prüfe gespeicherte Pricing Quotes." />
    <v-tabs v-model="tab" color="primary" class="operations-tabs mb-4" align-tabs="start">
      <v-tab value="orders" prepend-icon="mdi-clipboard-list-outline">Aufträge</v-tab>
      <v-tab value="payments" prepend-icon="mdi-credit-card-outline">Payments</v-tab>
      <v-tab value="quotes" prepend-icon="mdi-calculator-variant-outline">Pricing Quotes</v-tab>
    </v-tabs>

    <template v-if="tab==='orders'">
      <div class="ops-columns">
        <section class="ops-panel">
          <div class="ops-panel-head"><div><p class="eyebrow">ORDER MANAGEMENT</p><h2>Neuen Auftrag anlegen</h2></div><span class="ops-count">{{orderTotal}} Aufträge</span></div>
          <p class="ops-help">Es werden nur Referenz-IDs gesendet. Order Service validiert Offer, Quote und Fahrzeug serverseitig und erstellt die unveränderlichen Snapshots selbst.</p>
          <form class="ops-form" @submit.prevent="createOrder">
            <label>Offer ID<input v-model.trim="createForm.offerId" required placeholder="offer-…"/></label>
            <label>Quote ID<input v-model.trim="createForm.quoteId" required placeholder="quote-…"/></label>
            <label>Vehicle Variant ID<input v-model.trim="createForm.vehicleId" required placeholder="vehicle variant UUID"/></label>
            <button class="ops-primary" :disabled="createBusy">{{createBusy?'Auftrag wird angelegt …':'Draft Order erstellen ↗'}}</button>
          </form>
          <p v-if="createError" class="ops-error">{{createError}}</p>
        </section>
        <section class="ops-panel">
          <div class="ops-panel-head"><div><p class="eyebrow">TENANT ORDERS</p><h2>Auftragsübersicht</h2></div><button class="ops-ghost" @click="loadOrders">↻ Aktualisieren</button></div>
          <p v-if="orderError" class="ops-error">{{orderError}}</p>
          <p v-else-if="orderLoading" class="ops-empty">Aufträge werden geladen …</p>
          <p v-else-if="!orderRows.length" class="ops-empty">Noch keine Aufträge vorhanden.</p>
          <div v-else class="ops-list">
            <button v-for="order in orderRows" :key="order.orderId" class="ops-row" :class="{chosen:selectedOrder?.orderId===order.orderId}" @click="selectOrder(order)">
              <span><b>{{order.orderId}}</b><small>{{order.vehicleId || 'Fahrzeug nicht angegeben'}} · {{date(order.createdAt)}}</small></span><span class="ops-status">{{label(order.status)}}</span>
            </button>
          </div>
          <div class="ops-pagination" v-if="orderTotal>orderLimit">
            <button :disabled="orderOffset===0" @click="orderOffset=Math.max(0,orderOffset-orderLimit);loadOrders()">←</button>
            {{Math.floor(orderOffset/orderLimit)+1}} / {{orderPages}}
            <button :disabled="orderOffset+orderLimit>=orderTotal" @click="orderOffset+=orderLimit;loadOrders()">→</button>
          </div>
        </section>
      </div>

      <section v-if="selectedOrder" class="ops-panel ops-detail">
        <div class="ops-panel-head"><div><p class="eyebrow">ORDER DETAILS</p><h2>{{selectedOrder.orderId}}</h2><span class="ops-status">{{label(selectedOrder.status)}}</span></div><button class="ops-ghost" @click="selectedOrder=null">Schließen</button></div>
        <div class="ops-facts"><div><small>Offer</small><b>{{selectedOrder.offerId || '–'}}</b></div><div><small>Quote</small><b>{{selectedOrder.quoteId || '–'}}</b></div><div><small>Vehicle</small><b>{{selectedOrder.vehicleId || '–'}}</b></div><div><small>Erstellt</small><b>{{date(selectedOrder.createdAt)}}</b></div></div>
        <div class="ops-actions">
          <button v-if="selectedOrder.status==='draft'" :disabled="!!actionBusy" @click="transitionOrder(selectedOrder,'submit')">Einreichen</button>
          <button v-if="selectedOrder.status==='submitted'" :disabled="!!actionBusy" @click="transitionOrder(selectedOrder,'review')">In Prüfung</button>
          <button v-if="['submitted','under_review'].includes(selectedOrder.status)" :disabled="!!actionBusy" @click="transitionOrder(selectedOrder,'accept')">Annehmen</button>
          <button v-if="['submitted','under_review'].includes(selectedOrder.status)" :disabled="!!actionBusy || !rejectReason.trim()" @click="transitionOrder(selectedOrder,'reject')">Ablehnen</button>
          <button v-if="!['completed','cancelled','rejected'].includes(selectedOrder.status)" :disabled="!!actionBusy" @click="transitionOrder(selectedOrder,'cancel')">Stornieren</button>
          <button v-if="selectedOrder.status==='accepted'" :disabled="paymentBusy" @click="createPayment(selectedOrder)">Payment Intent erstellen</button>
          <button v-if="selectedOrder.status==='accepted'" :disabled="!!actionBusy" @click="transitionOrder(selectedOrder,'contract-pending')">Vertrag vorbereiten</button>
          <button v-if="selectedOrder.status==='contract_pending'" :disabled="!!actionBusy" @click="transitionOrder(selectedOrder,'complete')">Abschließen</button>
        </div>
        <p v-if="['submitted','under_review'].includes(selectedOrder.status)" class="ops-inline"><input v-model="rejectReason" maxlength="500" placeholder="Ablehnungsgrund (für Ablehnen)"/></p>
        <p v-if="actionError" class="ops-error">{{actionError}}</p><p v-if="paymentError" class="ops-error">{{paymentError}}</p>
        <div class="ops-history"><h3>Audit-Verlauf</h3><p v-if="historyLoading" class="ops-empty">Verlauf wird geladen …</p><p v-else-if="historyError" class="ops-error">{{historyError}}</p><p v-else-if="!orderHistory.length" class="ops-empty">Noch keine Ereignisse vorhanden.</p><div v-for="(event,index) in orderHistory" :key="event.eventId || index" class="ops-history-row"><b>{{label(event.eventType || event.toStatus || event.to_status || event.status)}}</b><small>{{date(event.occurredAt || event.occurred_at || event.createdAt)}} · {{event.actorType || event.actor || 'Audit'}}</small></div></div>
      </section>
    </template>

    <template v-else-if="tab==='payments'">
      <section class="ops-panel">
        <div class="ops-panel-head"><div><p class="eyebrow">TENANT PAYMENT HISTORY</p><h2>Zahlungshistorie <small>({{paymentTotal}})</small></h2></div><button class="ops-ghost" :disabled="paymentHistoryLoading" @click="loadPayments">↻ Aktualisieren</button></div>
        <p v-if="paymentHistoryError" class="ops-error">{{paymentHistoryError}}</p>
        <p v-else-if="paymentHistoryLoading" class="ops-empty">Zahlungen werden geladen …</p>
        <p v-else-if="!paymentRows.length" class="ops-empty">Für diesen Mandanten wurden noch keine Zahlungen gefunden.</p>
        <div v-else class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Payment ID</th><th>Order ID</th><th>Betrag</th><th>Status</th><th>Erstellt</th><th></th></tr></thead><tbody><tr v-for="item in paymentRows" :key="item.paymentId"><td>{{item.paymentId}}</td><td>{{item.orderId}}</td><td>{{moneyMinor(item.amountMinor,item.currency)}}</td><td>{{label(item.status)}}</td><td>{{date(item.createdAt)}}</td><td><button class="ops-ghost" @click="paymentIdInput=item.paymentId;loadPayment()">Öffnen</button></td></tr></tbody></table></div>
        <div class="ops-pagination" v-if="paymentTotal>paymentLimit"><button :disabled="paymentOffset===0" @click="paymentOffset=Math.max(0,paymentOffset-paymentLimit);loadPayments()">←</button>{{Math.floor(paymentOffset/paymentLimit)+1}} / {{paymentPages}}<button :disabled="paymentOffset+paymentLimit>=paymentTotal" @click="paymentOffset+=paymentLimit;loadPayments()">→</button></div>
      </section>
      <section class="ops-panel">
        <div class="ops-panel-head"><div><p class="eyebrow">PAYMENT SERVICE</p><h2>Payment Intent abrufen</h2></div></div>
        <p class="ops-help">Der Payment Service bestimmt den Betrag ausschließlich aus dem akzeptierten Order Snapshot. Die Karteingabe und Bestätigung benötigen zusätzlich Stripe.js und einen Publishable Key.</p>
        <form class="ops-inline" @submit.prevent="loadPayment"><input v-model.trim="paymentIdInput" required placeholder="Payment UUID"/><button class="ops-primary" :disabled="paymentBusy">Abrufen</button></form>
        <p v-if="paymentError" class="ops-error">{{paymentError}}</p>
      </section>
      <section v-if="paymentRecord" class="ops-panel ops-detail">
        <div class="ops-panel-head"><div><p class="eyebrow">PAYMENT DETAILS</p><h2>{{paymentRecord.paymentId}}</h2></div><span class="ops-status">{{label(paymentRecord.status)}}</span></div>
        <div class="ops-facts"><div><small>Auftrag</small><b>{{paymentRecord.orderId}}</b></div><div><small>Betrag</small><b>{{moneyMinor(paymentRecord.amountMinor,paymentRecord.currency)}}</b></div><div><small>Provider</small><b>{{paymentRecord.provider || 'Stripe'}}</b></div><div><small>Status</small><b>{{label(paymentRecord.status)}}</b></div></div>
        <div v-if="paymentRecord.clientSecret && ['pending','requires_action'].includes(paymentRecord.status)" class="ops-checkout">
          <p class="eyebrow">SECURE STRIPE CHECKOUT</p><h3>Zahlungsmethode</h3>
          <div ref="checkoutMount" class="stripe-payment-element"></div>
          <p v-if="checkoutError" class="ops-error">{{checkoutError}}</p>
          <button class="ops-primary" :disabled="checkoutBusy || !stripePublicKey" @click="confirmCardPayment">{{checkoutBusy ? 'Zahlung wird bestätigt …' : 'Zahlung sicher bestätigen'}}</button>
          <p class="ops-help">Kartendaten werden direkt von Stripe Elements verarbeitet. AutoFlow speichert keine Kartennummern oder CVC-Daten.</p>
        </div>
        <div class="ops-actions">
          <button v-if="paymentRecord.status==='authorized'" :disabled="paymentBusy" @click="paymentAction('capture')">Einziehen (Capture)</button>
          <button v-if="['pending','requires_action','authorized'].includes(paymentRecord.status)" :disabled="paymentBusy" @click="paymentAction('cancel')">Payment stornieren</button>
        </div>
        <div v-if="['captured','partially_refunded'].includes(paymentRecord.status)" class="ops-inline"><input v-model="refundAmount" type="number" min="1" step="1" placeholder="Refund amount (minor units)"/><button :disabled="paymentBusy || Number(refundAmount)<=0" @click="paymentAction('refund')">Refund ausführen</button></div>
        <p class="ops-help">Capture, Cancel und Refund werden vom Payment Service gegen den aktuellen Provider- und Payment-Status validiert. Alle Mutationen verwenden Idempotency Keys.</p>
      </section>
    </template>

    <template v-else>
      <section class="ops-panel">
        <div class="ops-panel-head"><div><p class="eyebrow">PRICING SERVICE</p><h2>Gespeicherte Quotes</h2></div><button class="ops-ghost" @click="loadQuotes">↻ Aktualisieren</button></div>
        <p v-if="quoteError" class="ops-error">{{quoteError}}</p>
        <p v-else-if="quoteLoading" class="ops-empty">Quotes werden geladen …</p>
        <p v-else-if="!quoteRows.length" class="ops-empty">Keine Quotes gefunden oder keine Berechtigung.</p>
        <div v-else class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Quote ID</th><th>Vehicle</th><th>Rate</th><th>Duration</th><th>Valid until</th><th></th></tr></thead><tbody><tr v-for="quote in quoteRows" :key="quote.quoteId"><td>{{quote.quoteId}}</td><td>{{quote.vehicleId}}</td><td>{{quote.monthlyAmount}} {{quote.currency}}</td><td>{{quote.durationMonths}} Monate</td><td>{{date(quote.expiresAt)}}</td><td><button class="ops-danger" @click="deleteQuote(quote.quoteId)">Löschen</button></td></tr></tbody></table></div>
        <div class="ops-pagination"><button :disabled="quoteOffset===0" @click="quoteOffset=Math.max(0,quoteOffset-quoteLimit);loadQuotes()">←</button><span>Offset {{quoteOffset}}</span><button :disabled="quoteRows.length<quoteLimit" @click="quoteOffset+=quoteLimit;loadQuotes()">→</button></div>
      </section>
    </template>
  </section>
</template>

<style scoped>
.ops{display:grid;gap:18px}.ops-help{font-size:11px;color:#91a1a9;line-height:1.65}.operations-tabs{border-bottom:1px solid var(--line)}.operations-tabs :deep(.v-tab){font-size:11px;font-weight:700;letter-spacing:0;text-transform:none;min-height:48px}.ops-ghost{background:transparent;border:1px solid #40525c;color:#b9c6cb;border-radius:7px;padding:9px 12px;font-size:10px;cursor:pointer}.ops-columns{display:grid;grid-template-columns:.9fr 1.1fr;gap:16px}.ops-panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:22px;min-width:0}.ops-panel-head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:10px}.ops-panel h2{font:700 19px Manrope;margin:0;overflow-wrap:anywhere}.ops-panel h3{font:700 14px Manrope}.ops-count,.ops-status{font-size:9px;color:var(--accent);background:#c8f16912;border:1px solid #c8f16935;border-radius:20px;padding:6px 9px;white-space:nowrap}.ops-form{display:grid;gap:11px;margin-top:16px}.ops-form label{display:grid;gap:6px;color:#aebbc1;font-size:10px}.ops-form input,.ops-inline input{background:#101a22;border:1px solid #354751;border-radius:7px;padding:11px;color:#edf2f4;min-width:0}.ops-primary{background:var(--accent);border:0;color:#18232a;border-radius:7px;padding:11px 14px;font-size:10px;font-weight:700;cursor:pointer}.ops-primary:disabled,.ops-actions button:disabled{opacity:.5;cursor:wait}.ops-list{display:grid;gap:7px;max-height:440px;overflow:auto}.ops-row{display:flex;justify-content:space-between;align-items:center;gap:12px;text-align:left;background:#101a22;border:1px solid #2d404a;border-radius:8px;padding:12px;color:#eaf0f2;cursor:pointer}.ops-row.chosen{border-color:var(--accent)}.ops-row span:first-child{min-width:0}.ops-row b,.ops-row small{display:block;overflow-wrap:anywhere}.ops-row b{font:600 10px monospace}.ops-row small{color:#82939b;font-size:9px;margin-top:6px}.ops-pagination{display:flex;justify-content:center;align-items:center;gap:12px;margin-top:16px;color:#aebbc1;font-size:10px}.ops-pagination button{background:transparent;border:1px solid #40525c;color:#dce5e8;border-radius:6px;padding:6px 10px}.ops-pagination button:disabled{opacity:.3}.ops-empty{padding:25px 8px;text-align:center;color:#91a1a9;font-size:11px}.ops-error{color:#f3a6a9;background:#432c30;padding:11px;border-radius:8px;font-size:10px}.ops-detail{display:grid;gap:12px}.ops-checkout{display:grid;gap:12px;padding:16px;border:1px solid #40525c;border-radius:9px;background:#101a22}.ops-checkout h3{margin:0;font:700 17px Manrope}.stripe-payment-element{min-height:80px;padding:12px;background:#17242d;border-radius:7px}.ops-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.ops-facts div{min-width:0;display:grid;gap:6px}.ops-facts small{font-size:9px;color:#82939b}.ops-facts b{font-size:10px;overflow-wrap:anywhere}.ops-actions{display:flex;flex-wrap:wrap;gap:8px}.ops-actions button,.ops-inline button{background:#263943;border:1px solid #52616a;color:#eaf0f2;border-radius:6px;padding:9px 11px;font-size:10px;cursor:pointer}.ops-inline{display:flex;gap:8px;align-items:center}.ops-inline input{flex:1}.ops-history{border-top:1px solid var(--line);padding-top:8px}.ops-history-row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #ffffff10;padding:10px 0;font-size:10px}.ops-history-row small{color:#82939b}.ops-table-wrap{overflow:auto}.ops-table{width:100%;border-collapse:collapse;font-size:10px}.ops-table th,.ops-table td{text-align:left;padding:11px 9px;border-bottom:1px solid var(--line);white-space:nowrap}.ops-table th{color:#91a1a9}.ops-danger{background:transparent;border:1px solid #74494c;color:#f3a6a9;border-radius:5px;padding:6px 8px;font-size:9px;cursor:pointer}@media(max-width:850px){.ops-columns{grid-template-columns:1fr}.ops-facts{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.ops-panel{padding:15px}.ops-row{align-items:flex-start;flex-direction:column}.ops-inline{flex-direction:column;align-items:stretch}.ops-history-row{flex-direction:column}}
</style>
