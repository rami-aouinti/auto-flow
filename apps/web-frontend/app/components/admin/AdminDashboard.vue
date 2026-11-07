<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { catalog, orders, payments, pricing } from '../../../src/api';
import PageHeader from '../shared/PageHeader.vue';
import MetricCard from '../shared/MetricCard.vue';

type Metric = { key: string; label: string; value: string; caption: string; icon: string; tone: string; to: string };
const loading = ref(true);
const loadError = ref('');
const metrics = ref<Metric[]>([
  { key: 'vehicles', label: 'Katalogvarianten', value: '—', caption: 'Technische Fahrzeugdaten', icon: 'mdi-car-multiple', tone: 'primary', to: '/admin/catalog' },
  { key: 'orders', label: 'Aufträge', value: '—', caption: 'Auftragsübersicht', icon: 'mdi-clipboard-text-outline', tone: 'info', to: '/operations/orders' },
  { key: 'payments', label: 'Zahlungen', value: '—', caption: 'Payment Lifecycle', icon: 'mdi-credit-card-outline', tone: 'success', to: '/operations/payments' },
  { key: 'quotes', label: 'Pricing Quotes', value: '—', caption: 'Kalkulationen', icon: 'mdi-calculator-variant-outline', tone: 'warning', to: '/operations/pricing' },
]);

function count(data: any): number {
  if (Array.isArray(data)) return data.length;
  if (typeof data?.total === 'number') return data.total;
  if (Array.isArray(data?.items)) return data.items.length;
  return 0;
}

onMounted(async () => {
  const results = await Promise.allSettled([
    catalog.adminList('variants', 1, 0),
    orders.list(1, 0),
    payments.list(1, 0),
    pricing.quotes(1, 0),
  ]);
  const keys = ['vehicles', 'orders', 'payments', 'quotes'];
  results.forEach((result, index) => {
    const metric = metrics.value.find((item) => item.key === keys[index]);
    if (!metric) return;
    if (result.status === 'fulfilled') metric.value = count(result.value.data).toLocaleString('de-DE');
    else metric.value = '—';
  });
  const failures = results.filter((result) => result.status === 'rejected');
  if (failures.length) loadError.value = 'Einige Kennzahlen konnten wegen fehlender Berechtigungen oder nicht erreichbarer Services nicht geladen werden.';
  loading.value = false;
});
</script>

<template>
  <div class="admin-dashboard">
    <PageHeader eyebrow="AUTOFLOW · ADMINISTRATION" title="Kontrollzentrum" description="Verwalte Fahrzeugstammdaten und behalte die wichtigsten Geschäftsprozesse im Blick.">
      <template #actions><v-chip color="success" variant="tonal" prepend-icon="mdi-shield-check-outline">Geschützter Bereich</v-chip></template>
    </PageHeader>

    <v-alert v-if="loadError" type="warning" variant="tonal" density="comfortable" class="mb-6">{{ loadError }}</v-alert>

    <v-row>
      <v-col v-for="metric in metrics" :key="metric.key" cols="12" sm="6" xl="3">
        <MetricCard :label="metric.label" :value="loading ? '…' : metric.value" :caption="metric.caption" :icon="metric.icon" :tone="metric.tone" :to="metric.to" />
      </v-col>
    </v-row>

    <v-row class="mt-2">
      <v-col cols="12" lg="7">
        <v-card rounded="xl" border elevation="0" class="h-100">
          <v-card-title class="pa-5 pb-2 text-h6 font-weight-bold">Arbeitsbereiche</v-card-title>
          <v-card-subtitle class="px-5">Direkter Zugriff auf die zentralen Verwaltungsfunktionen.</v-card-subtitle>
          <v-list class="pa-3" bg-color="transparent">
            <v-list-item to="/admin/catalog" prepend-icon="mdi-database-cog-outline" title="Fahrzeugkatalog" subtitle="Hersteller, Modelle, Generationen und Varianten verwalten" append-icon="mdi-chevron-right" rounded="lg" />
            <v-list-item to="/operations/orders" prepend-icon="mdi-clipboard-list-outline" title="Aufträge & Payments" subtitle="Auftragsstatus, Zahlungsvorgänge und Audit-Verläufe" append-icon="mdi-chevron-right" rounded="lg" />
            <v-list-item to="/operations/pricing" prepend-icon="mdi-calculator-variant-outline" title="Pricing Quotes" subtitle="Kalkulationen und gespeicherte Angebote einsehen" append-icon="mdi-chevron-right" rounded="lg" />
          </v-list>
        </v-card>
      </v-col>
      <v-col cols="12" lg="5">
        <v-card rounded="xl" border elevation="0" class="h-100">
          <v-card-title class="pa-5 pb-2 text-h6 font-weight-bold">Betriebsprinzipien</v-card-title>
          <v-card-text class="pt-2">
            <div class="principle-row"><v-icon color="success" icon="mdi-lock-check-outline" /><div><strong>Berechtigungen im Backend</strong><span>Jede schreibende Aktion wird serverseitig autorisiert.</span></div></div>
            <v-divider class="my-4" />
            <div class="principle-row"><v-icon color="info" icon="mdi-archive-check-outline" /><div><strong>Referenzen bleiben erhalten</strong><span>Katalogeinträge werden archiviert statt physisch gelöscht.</span></div></div>
            <v-divider class="my-4" />
            <div class="principle-row"><v-icon color="warning" icon="mdi-history" /><div><strong>Nachvollziehbare Prozesse</strong><span>Aufträge und Zahlungen besitzen eigene Status- und Verlaufsansichten.</span></div></div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
    <div class="text-caption text-medium-emphasis mt-5">Kennzahlen werden live aus den angebundenen Services geladen. Ein Gedankenstrich bedeutet, dass der jeweilige Endpunkt nicht verfügbar oder nicht autorisiert war.</div>
  </div>
</template>

<style scoped>
.principle-row{display:flex;align-items:flex-start;gap:14px}
.principle-row strong,.principle-row span{display:block}
.principle-row strong{font-size:13px;font-weight:700}
.principle-row span{margin-top:4px;color:rgba(var(--v-theme-on-surface),.62);font-size:12px;line-height:1.55}
</style>