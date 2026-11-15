<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { billing, catalog, contracts, crm, customers, fulfillments, inventory, orders, payments, pricing, reservations } from '../../../src/api';
import PageHeader from '../shared/PageHeader.vue';
import MetricCard from '../shared/MetricCard.vue';
import { useAutoFlowLocale } from '../../composables/useAutoFlowLocale';
const { t, locale } = useAutoFlowLocale();

type Metric = { key: string; label: string; value: string; caption: string; icon: string; tone: string; to: string };
const loading = ref(true);
const loadError = ref('');
const metrics = ref<Metric[]>([
  { key: 'vehicles', label: t('dashboard.metricVariants'), value: '—', caption: t('dashboard.metricVehicleData'), icon: 'mdi-car-multiple', tone: 'primary', to: '/admin/catalog/manufacturers' },
  { key: 'customers', label: t('nav.customers'), value: '—', caption: t('dashboard.metricCustomerAdmin'), icon: 'mdi-account-group-outline', tone: 'info', to: '/admin/customers' },
  { key: 'crm', label: t('dashboard.metricCrm'), value: '—', caption: t('nav.crmHelp'), icon: 'mdi-account-tie-outline', tone: 'warning', to: '/crm/applications' },
  { key: 'orders', label: t('nav.orders'), value: '—', caption: t('dashboard.metricOrders'), icon: 'mdi-clipboard-text-outline', tone: 'info', to: '/operations/orders' },
  { key: 'payments', label: t('nav.payments'), value: '—', caption: t('dashboard.metricPayments'), icon: 'mdi-credit-card-outline', tone: 'success', to: '/operations/payments' },
  { key: 'quotes', label: t('nav.pricing'), value: '—', caption: t('nav.calculations'), icon: 'mdi-calculator-variant-outline', tone: 'warning', to: '/operations/pricing' },
  { key: 'contracts', label: t('dashboard.metricContracts'), value: '—', caption: t('dashboard.metricContractLife'), icon: 'mdi-file-sign', tone: 'primary', to: '/operations/workflow?tab=contracts' },
  { key: 'invoices', label: t('dashboard.metricInvoices'), value: '—', caption: t('dashboard.metricBilling'), icon: 'mdi-receipt-text-outline', tone: 'success', to: '/operations/workflow?tab=billing' },
  { key: 'fulfillments', label: t('dashboard.metricFulfilments'), value: '—', caption: t('dashboard.metricDelivery'), icon: 'mdi-truck-delivery-outline', tone: 'info', to: '/operations/workflow?tab=fulfillment' },
  { key: 'inventory', label: t('dashboard.metricInventory'), value: '—', caption: t('dashboard.metricPhysicalVehicles'), icon: 'mdi-warehouse', tone: 'warning', to: '/operations/workflow?tab=inventory' },
  { key: 'reservations', label: t('dashboard.metricReservations'), value: '—', caption: t('dashboard.metricVehicleReservations'), icon: 'mdi-calendar-check-outline', tone: 'info', to: '/operations/workflow?tab=reservations' },
]);

function count(data: any): number {
  if (Array.isArray(data)) return data.length;
  if (typeof data?.total === 'number') return data.total;
  if (Array.isArray(data?.items)) return data.items.length;
  return 0;
}

async function loadDashboard() {
  loading.value = true;
  loadError.value = '';
  const results = await Promise.allSettled([
    catalog.adminList('variants', 1, 0),
    customers.list({ limit: 1, offset: 0 }),
    crm.listApplications({ limit: 1, offset: 0 }),
    orders.list(1, 0),
    payments.list(1, 0),
    pricing.quotes(1, 0),
    contracts.list(1, 0),
    billing.invoices(1, 0),
    fulfillments.list(1, 0),
    inventory.units(1, 0),
    reservations.list(1, 0),
  ]);
  const keys = ['vehicles', 'customers', 'crm', 'orders', 'payments', 'quotes', 'contracts', 'invoices', 'fulfillments', 'inventory', 'reservations'];
  results.forEach((result, index) => {
    const metric = metrics.value.find((item) => item.key === keys[index]);
    if (!metric) return;
    if (result.status === 'fulfilled') metric.value = count(result.value.data).toLocaleString(locale.value === 'fr' ? 'fr-FR' : locale.value === 'en' ? 'en-GB' : 'de-DE');
    else metric.value = '—';
  });
  const failures = results.filter((result) => result.status === 'rejected');
  if (failures.length) loadError.value = t('dashboard.loadError');
  loading.value = false;
}

watch(locale, () => {
  const labelKeys = ['dashboard.metricVariants','nav.customers','dashboard.metricCrm','nav.orders','nav.payments','nav.pricing','dashboard.metricContracts','dashboard.metricInvoices','dashboard.metricFulfilments','dashboard.metricInventory','dashboard.metricReservations'];
  const captionKeys = ['dashboard.metricVehicleData','dashboard.metricCustomerAdmin','nav.crmHelp','dashboard.metricOrders','dashboard.metricPayments','nav.calculations','dashboard.metricContractLife','dashboard.metricBilling','dashboard.metricDelivery','dashboard.metricPhysicalVehicles','dashboard.metricVehicleReservations'];
  metrics.value.forEach((metric, index) => { metric.label = t(labelKeys[index] ?? ''); metric.caption = t(captionKeys[index] ?? ''); });
});
onMounted(loadDashboard);
</script>

<template>
  <div class="admin-dashboard">
    <PageHeader eyebrow="AUTOFLOW · ADMINISTRATION" :title="t('dashboard.title')" :description="t('dashboard.description')">
      <template #actions><div class="d-flex align-center ga-2"><v-chip color="success" variant="tonal" prepend-icon="mdi-shield-check-outline">{{ t('dashboard.protected') }}</v-chip><v-btn icon="mdi-refresh" variant="tonal" :loading="loading"  :aria-label="t('common.refresh')" @click="loadDashboard" /></div></template>
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
          <v-card-title class="pa-5 pb-2 text-h6 font-weight-bold">{{ t('dashboard.workspaces') }}</v-card-title>
          <v-card-subtitle class="px-5">{{ t('dashboard.direct') }}</v-card-subtitle>
          <v-list class="pa-3" bg-color="transparent">
            <v-list-item to="/admin/catalog/manufacturers" prepend-icon="mdi-database-cog-outline"  :title="t('nav.catalog')" :subtitle="t('dashboard.catalogManage')" append-icon="mdi-chevron-right" rounded="lg" />
            <v-list-item to="/admin/customers" prepend-icon="mdi-account-group-outline"  :title="t('nav.customers')" :subtitle="t('dashboard.customerManage')" append-icon="mdi-chevron-right" rounded="lg" />
            <v-list-item to="/crm/applications" prepend-icon="mdi-account-tie-outline"  :title="t('nav.crm')" :subtitle="t('dashboard.crmManage')" append-icon="mdi-chevron-right" rounded="lg" />
            <v-list-item to="/operations/orders" prepend-icon="mdi-clipboard-list-outline"  :title="t('dashboard.ordersPayments')" :subtitle="t('dashboard.ordersPaymentsHelp')" append-icon="mdi-chevron-right" rounded="lg" />
            <v-list-item to="/operations/pricing" prepend-icon="mdi-calculator-variant-outline"  :title="t('nav.pricing')" :subtitle="t('dashboard.quotesManage')" append-icon="mdi-chevron-right" rounded="lg" />
            <v-list-item to="/operations/workflow" prepend-icon="mdi-workflow"  :title="t('nav.workflows')" :subtitle="t('dashboard.workflowManage')" append-icon="mdi-chevron-right" rounded="lg" />
          </v-list>
        </v-card>
      </v-col>
      <v-col cols="12" lg="5">
        <v-card rounded="xl" border elevation="0" class="h-100">
          <v-card-title class="pa-5 pb-2 text-h6 font-weight-bold">{{ t('dashboard.principles') }}</v-card-title>
          <v-card-text class="pt-2">
            <div class="principle-row"><v-icon color="success" icon="mdi-lock-check-outline" /><div><strong>{{ t('dashboard.permissions') }}</strong><span>{{ t('dashboard.permissionsText') }}</span></div></div>
            <v-divider class="my-4" />
            <div class="principle-row"><v-icon color="info" icon="mdi-archive-check-outline" /><div><strong>{{ t('dashboard.references') }}</strong><span>{{ t('dashboard.referencesText') }}</span></div></div>
            <v-divider class="my-4" />
            <div class="principle-row"><v-icon color="warning" icon="mdi-history" /><div><strong>{{ t('dashboard.traceable') }}</strong><span>{{ t('dashboard.traceableText') }}</span></div></div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
    <div class="text-caption text-medium-emphasis mt-5">{{ t('dashboard.live') }}</div>
  </div>
</template>

<style scoped>
.principle-row{display:flex;align-items:flex-start;gap:14px}
.principle-row strong,.principle-row span{display:block}
.principle-row strong{font-size:13px;font-weight:700}
.principle-row span{margin-top:4px;color:rgba(var(--v-theme-on-surface),.62);font-size:12px;line-height:1.55}
</style>