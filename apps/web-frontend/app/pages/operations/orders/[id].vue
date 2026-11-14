<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { billing, contracts, fulfillments, inventory, offers, orders, payments, reservations, errorMessage } from '../../../../src/api';

definePageMeta({ layout: 'default' });
const route = useRoute();
const router = useRouter();
type Row = Record<string, any>;
const orderId = String(route.params.id ?? '');
const order = ref<Row | null>(null);
const history = ref<Row[]>([]);
const related = ref({ contracts: [] as Row[], payments: [] as Row[], fulfillments: [] as Row[], reservations: [] as Row[], invoices: [] as Row[] });
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const rejectReason = ref('');
const key = () => globalThis.crypto?.randomUUID?.() ?? `af-${Date.now()}-${Math.random().toString(16).slice(2)}`;
function list(data: any): Row[] { return Array.isArray(data) ? data : Array.isArray(data?.items) ? data.items : []; }
function val(row: Row, snake: string, camel?: string) { return row?.[camel ?? snake.replace(/_([a-z])/g, (_, c: string) => c.toUpperCase())] ?? row?.[snake]; }
function date(value?: string) { return value ? new Date(value).toLocaleString('de-DE') : '—'; }
function label(value?: string) { return String(value ?? '—').replaceAll('_', ' '); }
function download(data: Blob, filename: string) {
  const url = URL.createObjectURL(data); const anchor = document.createElement('a');
  anchor.href = url; anchor.download = filename; anchor.click(); URL.revokeObjectURL(url);
}
const status = computed(() => String(order.value?.status ?? 'draft'));
const steps = [
  { key: 'draft', title: 'Entwurf', description: 'Auftrag angelegt' },
  { key: 'submitted', title: 'Eingereicht', description: 'Antrag an die Bearbeitung übergeben' },
  { key: 'under_review', title: 'Prüfung', description: 'Auftrag wird geprüft' },
  { key: 'accepted', title: 'Angenommen', description: 'Auftrag fachlich bestätigt' },
  { key: 'contract_pending', title: 'Vertrag', description: 'Vertragserstellung und Signatur' },
  { key: 'completed', title: 'Abgeschlossen', description: 'Auftrag erfolgreich beendet' },
];
const currentStep = computed(() => {
  if (status.value === 'rejected' || status.value === 'cancelled') return -1;
  return steps.findIndex(step => step.key === status.value);
});
async function load() {
  loading.value = true; error.value = '';
  try {
    const [detail, audit, contractRows, paymentRows, fulfillmentRows, reservationRows, invoiceRows] = await Promise.all([
      orders.get(orderId), orders.history(orderId), contracts.list(100, 0), payments.list(100, 0),
      fulfillments.list(100, 0), reservations.list(100, 0), billing.invoices(100, 0),
    ]);
    order.value = detail.data;
    history.value = list(audit.data);
    related.value = {
      contracts: list(contractRows.data).filter(row => val(row, 'order_id') === orderId),
      payments: list(paymentRows.data).filter(row => val(row, 'order_id') === orderId),
      fulfillments: list(fulfillmentRows.data).filter(row => val(row, 'order_id') === orderId),
      reservations: list(reservationRows.data).filter(row => val(row, 'order_id') === orderId),
      invoices: list(invoiceRows.data).filter(row => val(row, 'order_id') === orderId),
    };
  } catch (e) { error.value = errorMessage(e); }
  finally { loading.value = false; }
}
async function transition(action: 'submit'|'review'|'accept'|'reject'|'cancel'|'contract-pending'|'complete') {
  if (!order.value) return;
  busy.value = true; error.value = ''; notice.value = '';
  try {
    await orders.transition(orderId, action, key(), action === 'reject' ? { reason: rejectReason.value.trim() } : undefined);
    notice.value = 'Auftragsstatus aktualisiert.';
    await load();
  } catch (e) { error.value = errorMessage(e); }
  finally { busy.value = false; }
}
async function downloadOffer() {
  try { const response = await offers.downloadPdf(String(order.value?.offerId ?? order.value?.offer_id)); download(response.data, `offer-${orderId}.pdf`); }
  catch (e) { error.value = errorMessage(e); }
}
async function downloadContract(contract: Row) {
  try { const id = String(val(contract, 'contract_id')); const response = await contracts.downloadDocument(id); download(response.data, `contract-${id}.pdf`); }
  catch (e) { error.value = errorMessage(e); }
}
onMounted(load);
</script>

<template>
  <div>
    <div class="d-flex align-center flex-wrap ga-3 mb-5">
      <v-btn icon="mdi-arrow-left" variant="text" aria-label="Zurück" @click="router.push('/operations/orders')" />
      <div class="flex-grow-1"><div class="text-overline text-medium-emphasis">AUTOFLOW · ORDER DOSSIER</div><h1 class="text-h4 font-weight-bold">Auftrag {{ orderId }}</h1></div>
      <v-btn prepend-icon="mdi-refresh" variant="tonal" :loading="loading" @click="load">Aktualisieren</v-btn>
    </div>
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable>{{ error }}</v-alert>
    <v-alert v-if="notice" type="success" variant="tonal" class="mb-4">{{ notice }}</v-alert>
    <v-progress-linear v-if="loading" indeterminate color="primary" />
    <template v-if="order">
      <v-card rounded="xl" border elevation="0" class="mb-5">
        <v-card-text class="pa-5">
          <div class="d-flex align-center flex-wrap ga-2 mb-4"><v-chip color="primary" variant="tonal">{{ label(status) }}</v-chip><span class="text-caption text-medium-emphasis">Erstellt {{ date(val(order,'created_at')) }} · Aktualisiert {{ date(val(order,'updated_at')) }}</span></div>
          <v-timeline direction="horizontal" side="start" truncate-line="both" density="compact" class="d-none d-md-flex">
            <v-timeline-item v-for="(step,index) in steps" :key="step.key" :dot-color="index <= currentStep ? 'success' : 'grey'" :icon="index < currentStep ? 'mdi-check' : undefined" size="small">
              <div class="text-caption font-weight-bold">{{ step.title }}</div><div class="text-caption text-medium-emphasis">{{ step.description }}</div>
            </v-timeline-item>
          </v-timeline>
          <v-alert v-if="status === 'rejected' || status === 'cancelled'" type="warning" variant="tonal" class="mt-3">Dieser Auftrag ist {{ label(status) }} und kann nicht weitergeführt werden.</v-alert>
          <div class="d-flex flex-wrap ga-2 mt-4">
            <v-btn v-if="status==='draft'" color="primary" :loading="busy" @click="transition('submit')">Einreichen</v-btn>
            <v-btn v-if="status==='submitted'" color="primary" :loading="busy" @click="transition('review')">Prüfung starten</v-btn>
            <v-btn v-if="['submitted','under_review'].includes(status)" color="success" :loading="busy" @click="transition('accept')">Annehmen</v-btn>
            <v-btn v-if="['submitted','under_review'].includes(status)" color="error" variant="tonal" :disabled="!rejectReason.trim()" :loading="busy" @click="transition('reject')">Ablehnen</v-btn>
            <v-btn v-if="!['completed','cancelled','rejected'].includes(status)" color="error" variant="text" :loading="busy" @click="transition('cancel')">Stornieren</v-btn>
            <v-btn v-if="status==='accepted'" color="primary" variant="tonal"  :to="{ path:'/operations/workflow', query:{tab:'contracts',orderId:orderId} }>Vertrag vorbereiten</v-btn>
            <v-btn v-if="status==='contract_pending'" color="success" :loading="busy" @click="transition('complete')">Abschließen</v-btn>
          </div>
          <v-text-field v-if="['submitted','under_review'].includes(status)" v-model="rejectReason" label="Ablehnungsgrund" class="mt-3" hide-details="auto" />
        </v-card-text>
      </v-card>
      <v-row>
        <v-col cols="12" md="7">
          <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Auftragsdaten</v-card-title><v-card-text><v-row dense>
            <v-col cols="6"><div class="text-caption text-medium-emphasis">Kunde</div><div class="font-weight-medium">{{ val(order,'customer_id') || val(order,'customer_snapshot')?.customerId || '—' }}</div></v-col>
            <v-col cols="6"><div class="text-caption text-medium-emphasis">Fahrzeug</div><div class="font-weight-medium">{{ val(order,'vehicle_id') || '—' }}</div></v-col>
            <v-col cols="6"><div class="text-caption text-medium-emphasis">Angebot</div><div class="font-weight-medium">{{ val(order,'offer_id') || '—' }}</div></v-col>
            <v-col cols="6"><div class="text-caption text-medium-emphasis">Quote</div><div class="font-weight-medium">{{ val(order,'quote_id') || '—' }}</div></v-col>
          </v-row></v-card-text></v-card>
          <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Dokumente</v-card-title><v-list>
            <v-list-item v-if="val(order,'offer_id')" prepend-icon="mdi-file-pdf-box" title="Angebots-PDF" subtitle="Aus dem Offer Service"><template #append><v-btn size="small" variant="tonal" @click="downloadOffer">Herunterladen</v-btn></template></v-list-item>
            <v-list-item v-for="contract in related.contracts" :key="val(contract,'contract_id')" prepend-icon="mdi-file-sign" :title="'Vertrag · '+val(contract,'contract_id')" :subtitle="label(val(contract,'status'))+' · '+(val(contract,'document_checksum') ? 'Dokument verfügbar' : 'Noch kein Dokument')">
              <template #append><v-btn v-if="val(contract,'document_checksum')" size="small" variant="tonal" @click="downloadContract(contract)">PDF</v-btn><v-btn size="small" variant="text" :to="{path:'/operations/workflow',query:{tab:'contracts'}}">Öffnen</v-btn></template>
            </v-list-item>
            <v-list-item v-if="!val(order,'offer_id') && !related.contracts.length" title="Noch keine Dokumente" subtitle="Angebot und Vertrag erscheinen hier, sobald sie erstellt wurden." />
          </v-list></v-card>
          <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Verknüpfte Prozesse</v-card-title><v-list density="comfortable">
            <v-list-item prepend-icon="mdi-credit-card-outline" title="Payments" :subtitle="`${related.payments.length} Vorgänge`" :to="{path:'/operations/orders',query:{tab:'payments'}}" />
            <v-list-item prepend-icon="mdi-truck-delivery-outline" title="Fulfillment" :subtitle="`${related.fulfillments.length} Vorgänge`" :to="{path:'/operations/workflow',query:{tab:'fulfillment'}}" />
            <v-list-item prepend-icon="mdi-car-clock" title="Reservierungen" :subtitle="`${related.reservations.length} Vorgänge`" :to="{path:'/operations/workflow',query:{tab:'reservations'}}" />
            <v-list-item prepend-icon="mdi-receipt-text-outline" title="Rechnungen" :subtitle="`${related.invoices.length} Vorgänge`" :to="{path:'/operations/workflow',query:{tab:'billing'}}" />
          </v-list></v-card>
        </v-col>
        <v-col cols="12" md="5">
          <v-card rounded="xl" border elevation="0"><v-card-title>Audit-Timeline</v-card-title><v-card-text v-if="!history.length" class="text-medium-emphasis">Noch keine Audit-Ereignisse.</v-card-text>
            <v-timeline v-else density="compact" side="end"><v-timeline-item v-for="(event,index) in history" :key="val(event,'event_id') || index" size="small" dot-color="primary">
              <div class="font-weight-medium">{{ val(event,'event_type') || val(event,'to_status') || val(event,'status') || 'Order event' }}</div>
              <div class="text-caption text-medium-emphasis">{{ date(val(event,'occurred_at') || val(event,'created_at')) }} · {{ val(event,'actor_type') || val(event,'actor') || 'System' }}</div>
              <pre v-if="event.payload" class="text-caption text-wrap">{{ JSON.stringify(event.payload,null,2) }}</pre>
            </v-timeline-item></v-timeline>
          </v-card>
        </v-col>
      </v-row>
    </template>
  </div>
</template>
