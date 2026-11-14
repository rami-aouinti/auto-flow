<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { billing, contracts, fulfillments, inventory, offers, orders, reservations, errorMessage } from '../../../src/api';

definePageMeta({ layout: 'default' });
useHead({ title: 'Events & Fehler | AutoFlow' });

type Row = Record<string, any>;
type Activity = { id: string; source: string; event: string; occurredAt: string; reference: string; payload?: unknown; severity?: string };
type ServiceError = { source: string; message: string; at: string };

const events = ref<Activity[]>([]);
const errors = ref<ServiceError[]>([]);
const loading = ref(false);
const lastUpdated = ref('');
const search = ref('');
const sourceFilter = ref('all');
const selected = ref<Activity | null>(null);
const detailOpen = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;

const sources = computed(() => [...new Set(events.value.map((item) => item.source))].sort());
const filteredEvents = computed(() => {
  const needle = search.value.trim().toLocaleLowerCase();
  return events.value.filter((item) => {
    if (sourceFilter.value !== 'all' && item.source !== sourceFilter.value) return false;
    if (!needle) return true;
    return [item.source, item.event, item.reference, JSON.stringify(item.payload ?? {})]
      .some((value) => value.toLocaleLowerCase().includes(needle));
  });
});

function list(data: any): Row[] {
  return Array.isArray(data) ? data : Array.isArray(data?.items) ? data.items : [];
}
function get(row: Row, ...keys: string[]) {
  for (const key of keys) {
    const camel = key.replace(/_([a-z])/g, (_, c: string) => c.toUpperCase());
    if (row[key] !== undefined) return row[key];
    if (row[camel] !== undefined) return row[camel];
  }
  return undefined;
}
function addRows(source: string, rows: Row[], idKeys: string[], eventKeys: string[], dateKeys: string[]): Activity[] {
  return rows.map((row, index) => ({
    id: String(get(row, ...idKeys) ?? index),
    source,
    event: String(get(row, ...eventKeys) ?? get(row, 'status') ?? 'Record updated'),
    occurredAt: String(get(row, ...dateKeys) ?? ''),
    reference: String(get(row, 'order_id', 'contract_id', 'fulfillment_id', 'inventory_unit_id', 'reservation_id', 'invoice_id', 'offer_id') ?? get(row, 'id') ?? '—'),
    payload: row,
  }));
}
function sortEvents(rows: Activity[]) {
  return rows.sort((a, b) => {
    const left = Date.parse(a.occurredAt) || 0;
    const right = Date.parse(b.occurredAt) || 0;
    return right - left;
  }).slice(0, 150);
}
async function refresh() {
  loading.value = true;
  errors.value = [];
  try {
    const jobs = await Promise.allSettled([
      orders.list(30, 0), contracts.list(30, 0), fulfillments.list(30, 0),
      inventory.units(30, 0), reservations.list(30, 0), billing.invoices(30, 0),
      offers.list({ limit: 30, offset: 0 }),
    ]);
    const names = ['Orders', 'Contracts', 'Fulfillment', 'Inventory', 'Reservations', 'Billing', 'Offers'];
    const output: Activity[] = [];
    jobs.forEach((job, index) => {
      if (job.status === 'rejected') {
        errors.value.push({ source: names[index] ?? 'Service', message: errorMessage(job.reason), at: new Date().toISOString() });
        return;
      }
      const mapping = [
        ['order_id', ['event_type', 'to_status', 'status'], ['occurred_at', 'updated_at', 'created_at']],
        ['contract_id', ['event_type', 'status'], ['occurred_at', 'updated_at', 'created_at']],
        ['fulfillment_id', ['event_type', 'status'], ['occurred_at', 'updated_at', 'created_at']],
        ['inventory_unit_id', ['event_type', 'status'], ['occurred_at', 'updated_at', 'created_at']],
        ['reservation_id', ['event_type', 'status'], ['occurred_at', 'updated_at', 'created_at']],
        ['invoice_id', ['event_type', 'status'], ['issued_at', 'created_at', 'updated_at']],
        ['offer_id', ['event_type', 'status'], ['updated_at', 'created_at']],
      ][index] as [string, string[], string[]];
      output.push(...addRows(names[index] ?? 'Service', list(job.value.data), [mapping[0]], mapping[1], mapping[2]));
    });
    // Keep live messages between polling cycles instead of replacing them with snapshots.
    const live = events.value.filter((item) => item.source === 'Mercure');
    events.value = sortEvents([...live, ...output]);
    lastUpdated.value = new Date().toLocaleString('de-DE');
  } finally {
    loading.value = false;
  }
}
function onRealtime(event: Event) {
  const item = (event as CustomEvent).detail as { id: string; title: string; receivedAt: string; detail: string; type?: string };
  if (!item) return;
  const live: Activity = {
    id: item.id,
    source: 'Mercure',
    event: item.title,
    occurredAt: item.receivedAt,
    reference: item.detail,
    payload: item,
  };
  events.value = sortEvents([live, ...events.value.filter((entry) => !(entry.source === 'Mercure' && entry.id === live.id))]);
}
function showDetails(item: Activity) {
  selected.value = item;
  detailOpen.value = true;
}
function formatDate(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Zeitpunkt unbekannt' : date.toLocaleString('de-DE');
}
onMounted(() => {
  void refresh();
  timer = setInterval(() => void refresh(), 30_000);
  window.addEventListener('autoflow:realtime-event', onRealtime);
});
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
  window.removeEventListener('autoflow:realtime-event', onRealtime);
});
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <div class="text-overline text-medium-emphasis">AUTOFLOW · OBSERVABILITY</div>
        <h1 class="text-h4 font-weight-bold">Business Events & Errors</h1>
        <p class="text-body-2 text-medium-emphasis">Aktuelle Zustände aus den Business-Services, API-Fehler und Live-Ereignisse aus Mercure.</p>
      </div>
      <v-btn prepend-icon="mdi-refresh" variant="tonal" :loading="loading" @click="refresh">Jetzt aktualisieren</v-btn>
    </div>

    <v-row class="mb-3">
      <v-col cols="12" md="4"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Aktivitäten</div><div class="text-h4 font-weight-bold">{{ events.length }}</div></v-card-text></v-card></v-col>
      <v-col cols="12" md="4"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Servicefehler beim Abruf</div><div class="text-h4 font-weight-bold" :class="errors.length ? 'text-error' : ''">{{ errors.length }}</div></v-card-text></v-card></v-col>
      <v-col cols="12" md="4"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Letzte Synchronisierung</div><div class="text-body-1 font-weight-bold">{{ lastUpdated || '—' }}</div></v-card-text></v-card></v-col>
    </v-row>

    <v-alert v-if="errors.length" type="error" variant="tonal" class="mb-4" title="Einige Services konnten nicht gelesen werden">
      <div v-for="item in errors" :key="item.source" class="text-body-2"><strong>{{ item.source }}:</strong> {{ item.message }}</div>
    </v-alert>

    <v-card rounded="xl" border elevation="0">
      <v-card-title class="d-flex flex-wrap align-center ga-3">
        Business Activity Feed
        <v-spacer />
        <span class="text-caption text-medium-emphasis">Auto-Refresh alle 30 Sekunden</span>
      </v-card-title>
      <v-divider />
      <v-card-text class="pb-0">
        <v-row dense>
          <v-col cols="12" md="8"><v-text-field v-model="search" label="Aktivitäten durchsuchen" placeholder="Quelle, Ereignis oder Referenz" prepend-inner-icon="mdi-magnify" clearable hide-details density="comfortable" /></v-col>
          <v-col cols="12" md="4"><v-select v-model="sourceFilter" label="Quelle" :items="[{ title: 'Alle Quellen', value: 'all' }, ...sources.map((source) => ({ title: source, value: source }))]" hide-details density="comfortable" /></v-col>
        </v-row>
      </v-card-text>
      <v-progress-linear v-if="loading" indeterminate color="primary" />
      <v-table>
        <thead><tr><th>Quelle</th><th>Ereignis / Status</th><th>Referenz</th><th>Zeitpunkt</th><th class="text-right">Details</th></tr></thead>
        <tbody>
          <tr v-for="item in filteredEvents" :key="item.source + item.id">
            <td><v-chip size="small" variant="tonal" :color="item.source === 'Mercure' ? 'success' : undefined">{{ item.source }}</v-chip></td>
            <td>{{ item.event }}</td>
            <td class="text-caption">{{ item.reference }}</td>
            <td class="text-caption">{{ formatDate(item.occurredAt) }}</td>
            <td class="text-right"><v-btn size="small" variant="text" prepend-icon="mdi-code-json" @click="showDetails(item)">Ansehen</v-btn></td>
          </tr>
          <tr v-if="!filteredEvents.length"><td colspan="5" class="text-center text-medium-emphasis py-8">{{ events.length ? 'Keine Aktivitäten passen zu diesem Filter.' : 'Noch keine Aktivitäten vorhanden.' }}</td></tr>
        </tbody>
      </v-table>
      <v-card-text class="text-caption text-medium-emphasis">{{ filteredEvents.length }} von {{ events.length }} Aktivitäten angezeigt · maximal 150 Einträge</v-card-text>
    </v-card>

    <v-dialog v-model="detailOpen" max-width="760">
      <v-card rounded="xl">
        <v-card-title class="d-flex align-center">Ereignisdetails <v-spacer /><v-btn icon="mdi-close" variant="text" aria-label="Schließen" @click="detailOpen = false" /></v-card-title>
        <v-card-subtitle v-if="selected">{{ selected.source }} · {{ selected.event }} · {{ formatDate(selected.occurredAt) }}</v-card-subtitle>
        <v-divider />
        <v-card-text><pre class="event-payload">{{ JSON.stringify(selected?.payload ?? {}, null, 2) }}</pre></v-card-text>
        <v-card-actions><v-spacer /><v-btn variant="tonal" @click="detailOpen = false">Schließen</v-btn></v-card-actions>
      </v-card>
    </v-dialog>

    <p class="text-caption text-medium-emphasis mt-3">Hinweis: Dieser Feed kombiniert lesbare Service-Snapshots mit echten Mercure-Live-Nachrichten. Vollständige technische Logs und Traces bleiben in Grafana/Loki/Tempo.</p>
  </div>
</template>

<style scoped>
.event-payload{max-height:55vh;overflow:auto;padding:16px;border-radius:10px;background:rgba(var(--v-theme-on-surface),.06);font-size:12px;white-space:pre-wrap;overflow-wrap:anywhere}
</style>
