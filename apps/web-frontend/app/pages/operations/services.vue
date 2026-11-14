<script setup lang="ts">
import { onMounted, ref } from 'vue';
import {
  billing,
  contracts,
  crm,
  customers,
  errorMessage,
  fulfillments,
  inventory,
  reservations,
} from '../../../src/api';

definePageMeta({ layout: 'default' });
useHead({
  title: 'Service Operations | AutoFlow',
  meta: [{ name: 'description', content: 'Cross-service operations overview for AutoFlow.' }],
});

type Row = Record<string, unknown>;
type Panel = {
  key: string;
  title: string;
  description: string;
  icon: string;
  rows: Row[];
  total: number;
  loading: boolean;
  error: string;
};

const panels = ref<Panel[]>([
  { key: 'customers', title: 'Customers', description: 'Customer profiles and lifecycle', icon: 'mdi-account-group-outline', rows: [], total: 0, loading: true, error: '' },
  { key: 'crm', title: 'CRM applications', description: 'Leasing applications and advisor workflow', icon: 'mdi-account-tie-outline', rows: [], total: 0, loading: true, error: '' },
  { key: 'contracts', title: 'Contracts', description: 'Preparation and electronic signatures', icon: 'mdi-file-sign', rows: [], total: 0, loading: true, error: '' },
  { key: 'billing', title: 'Invoices', description: 'Invoices and credit notes', icon: 'mdi-receipt-text-outline', rows: [], total: 0, loading: true, error: '' },
  { key: 'fulfillments', title: 'Fulfillment', description: 'Vehicle preparation and handover', icon: 'mdi-truck-delivery-outline', rows: [], total: 0, loading: true, error: '' },
  { key: 'inventory', title: 'Inventory', description: 'Physical vehicle units and stock status', icon: 'mdi-warehouse', rows: [], total: 0, loading: true, error: '' },
  { key: 'reservations', title: 'Reservations', description: 'Vehicle stock allocation lifecycle', icon: 'mdi-calendar-check-outline', rows: [], total: 0, loading: true, error: '' },
]);

const requests: Record<string, () => Promise<{ data: unknown }>> = {
  customers: () => customers.list(5, 0),
  crm: () => crm.applications(5, 0),
  contracts: () => contracts.list(5, 0),
  billing: () => billing.invoices(5, 0),
  fulfillments: () => fulfillments.list(5, 0),
  inventory: () => inventory.units(5, 0),
  reservations: () => reservations.list(5, 0),
};

function normalize(data: unknown): { rows: Row[]; total: number } {
  if (Array.isArray(data)) return { rows: data.filter(isRow), total: data.length };
  if (!isRow(data)) return { rows: [], total: 0 };
  const items = Array.isArray(data.items) ? data.items : [];
  const rows = items.filter(isRow);
  return { rows, total: typeof data.total === 'number' ? data.total : rows.length };
}

function isRow(value: unknown): value is Row {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

async function refresh() {
  await Promise.all(panels.value.map(async (panel) => {
    panel.loading = true;
    panel.error = '';
    try {
      const response = await requests[panel.key]();
      const result = normalize(response.data);
      panel.rows = result.rows;
      panel.total = result.total;
    } catch (error) {
      panel.rows = [];
      panel.total = 0;
      panel.error = errorMessage(error);
    } finally {
      panel.loading = false;
    }
  }));
}

function primaryValue(row: Row): string {
  const value = row.application_id ?? row.contract_id ?? row.invoice_id ?? row.fulfillment_id
    ?? row.inventory_unit_id ?? row.reservation_id ?? row.customer_id ?? row.id;
  return typeof value === 'string' ? value : 'Record';
}

function secondaryValue(row: Row): string {
  const fields = ['status', 'eligibility_status', 'customer_id', 'order_id', 'vehicle_unit_id', 'invoice_number', 'contract_type'];
  return fields
    .filter((key) => row[key] !== undefined && row[key] !== null && row[key] !== '')
    .slice(0, 3)
    .map((key) => `${key.replaceAll('_', ' ')}: ${String(row[key])}`)
    .join(' · ') || 'No additional summary';
}

onMounted(refresh);
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-6">
      <div>
        <div class="text-overline text-medium-emphasis">AUTOFLOW · OPERATIONS</div>
        <h1 class="text-h4 font-weight-bold">Service operations</h1>
        <p class="text-body-2 text-medium-emphasis mt-2">Live read-only overview across the business microservices. Backend permissions remain authoritative.</p>
      </div>
      <v-btn color="primary" prepend-icon="mdi-refresh" variant="tonal" :loading="panels.some((panel) => panel.loading)" @click="refresh">Refresh all</v-btn>
    </div>

    <v-row>
      <v-col v-for="panel in panels" :key="panel.key" cols="12" md="6" xl="4">
        <v-card rounded="xl" border elevation="0" class="h-100">
          <v-card-item>
            <template #prepend><v-avatar color="primary" variant="tonal" rounded="lg"><v-icon :icon="panel.icon" /></v-avatar></template>
            <v-card-title class="text-subtitle-1 font-weight-bold">{{ panel.title }}</v-card-title>
            <v-card-subtitle>{{ panel.description }}</v-card-subtitle>
            <template #append><v-chip size="small" variant="tonal">{{ panel.loading ? '…' : panel.total }}</v-chip></template>
          </v-card-item>
          <v-divider />
          <v-card-text>
            <v-progress-linear v-if="panel.loading" indeterminate color="primary" />
            <v-alert v-else-if="panel.error" type="warning" variant="tonal" density="compact">{{ panel.error }}</v-alert>
            <v-list v-else-if="panel.rows.length" density="compact" bg-color="transparent">
              <v-list-item v-for="(row, index) in panel.rows" :key="primaryValue(row) + index" :title="primaryValue(row)" :subtitle="secondaryValue(row)">
                <template #prepend><v-icon icon="mdi-circle-small" color="primary" /></template>
              </v-list-item>
            </v-list>
            <v-empty-state v-else icon="mdi-database-search-outline" title="No records" text="No records are currently available for this tenant." />
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>
