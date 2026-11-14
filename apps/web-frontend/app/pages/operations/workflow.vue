<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { billing, contracts, fulfillments, inventory, reservations, errorMessage } from '../../../src/api';

definePageMeta({ layout: 'default' });
useHead({ title: 'Business workflows | AutoFlow' });

type Row = Record<string, any>;
const tab = ref('contracts');
const loading = ref(false);
const busy = ref('');
const error = ref('');
const notice = ref('');
const contractRows = ref<Row[]>([]);
const fulfillmentRows = ref<Row[]>([]);
const inventoryRows = ref<Row[]>([]);
const reservationRows = ref<Row[]>([]);
const invoiceRows = ref<Row[]>([]);
const creditNoteRows = ref<Row[]>([]);
const billingSummary = ref<Row | null>(null);

const contractForm = ref({ orderId: '', contractType: 'vehicle_lease', contractVersion: 1 });
const inventoryForm = ref({ vehicleVariantId: '', vin: '', dealerId: '', locationCode: '', countryCode: 'DE', condition: 'new' });
const reservationForm = ref({ vehicleUnitId: '', vehicleVariantId: '', orderId: '', expiresAt: '' });
const inventoryStatus = ref('blocked');
const inventoryReason = ref('');
const scheduleForm = ref({ scheduledFor: '', carrier: '', trackingReference: '' });
const recipientName = ref('');
const deliveryFailureReason = ref('');

const key = () => globalThis.crypto?.randomUUID?.() ?? `af-${Date.now()}-${Math.random().toString(16).slice(2)}`;
function items(data: any): Row[] { return Array.isArray(data) ? data : (Array.isArray(data?.items) ? data.items : []); }
function idOf(row: Row, field: string): string { const camel = field.replace(/_([a-z])/g, (_, letter: string) => letter.toUpperCase()); return String(row[field] ?? row[camel] ?? row.id ?? ''); }
function clearMessages() { error.value = ''; notice.value = ''; }

async function loadAll() {
  loading.value = true; clearMessages();
  const jobs = await Promise.allSettled([
    contracts.list(50, 0), fulfillments.list(50, 0), inventory.units(50, 0), reservations.list(50, 0), billing.invoices(50, 0), billing.creditNotes(50, 0), billing.summary(),
  ]);
  const targets = [contractRows, fulfillmentRows, inventoryRows, reservationRows, invoiceRows, creditNoteRows];
  jobs.forEach((job, index) => {
    if (job.status === 'fulfilled') targets[index].value = items(job.value.data);
    else targets[index].value = [];
  });
  billingSummary.value = jobs[6]?.status === 'fulfilled' ? jobs[6].value.data as Row : null;
  const failed = jobs.filter((job) => job.status === 'rejected');
  if (failed.length) error.value = 'Some services could not be loaded. Check your permissions and service health, then refresh.';
  loading.value = false;
}

async function run(label: string, action: () => Promise<unknown>) {
  busy.value = label; clearMessages();
  try { await action(); await loadAll(); notice.value = 'Command completed successfully.'; }
  catch (e) { error.value = errorMessage(e); }
  finally { busy.value = ''; }
}

async function createContract() {
  await run('contract-create', async () => {
    await contracts.create(contractForm.value, key());
    contractForm.value = { orderId: '', contractType: 'vehicle_lease', contractVersion: 1 };
  });
}
async function contractAction(row: Row, action: 'prepare' | 'cancel' | 'sendForSignature' | 'generateDocument' | 'downloadDocument') {
  const id = idOf(row, 'contract_id');
  await run(`contract-${id}`, async () => {
    if (action === 'prepare') await contracts.prepare(id, key());
    if (action === 'cancel') await contracts.cancel(id, key());
    if (action === 'sendForSignature') await contracts.sendForSignature(id, key());
    if (action === 'generateDocument') await contracts.generateDocument(id);
    if (action === 'downloadDocument') {
      const response = await contracts.downloadDocument(id);
      const url = URL.createObjectURL(response.data);
      const anchor = document.createElement('a'); anchor.href = url; anchor.download = `contract-${id}.pdf`; anchor.click(); URL.revokeObjectURL(url);
    }
  });
}
async function createInventory() {
  await run('inventory-create', async () => {
    const payload: Record<string, unknown> = { ...inventoryForm.value };
    if (!payload.dealerId) delete payload.dealerId;
    if (!payload.locationCode) delete payload.locationCode;
    await inventory.create(payload, key());
    inventoryForm.value = { vehicleVariantId: '', vin: '', dealerId: '', locationCode: '', countryCode: 'DE', condition: 'new' };
  });
}
async function changeInventory(row: Row) {
  const id = idOf(row, 'inventory_unit_id');
  await run(`inventory-${id}`, () => inventory.changeStatus(id, inventoryStatus.value, Number(row.version ?? 1), key(), inventoryReason.value || undefined));
  inventoryReason.value = '';
}
async function createReservation() {
  await run('reservation-create', async () => {
    await reservations.create({ ...reservationForm.value, expiresAt: new Date(reservationForm.value.expiresAt).toISOString() }, key());
    reservationForm.value = { vehicleUnitId: '', vehicleVariantId: '', orderId: '', expiresAt: '' };
  });
}
async function reservationAction(row: Row, action: 'confirm' | 'release' | 'fulfill') {
  const id = idOf(row, 'reservation_id');
  await run(`reservation-${id}`, () => reservations.transition(id, action, Number(row.version ?? 1), key()));
}
async function fulfillmentAction(row: Row, command: string, payload: Record<string, unknown> = {}) {
  const id = idOf(row, 'fulfillment_id');
  await run(`fulfillment-${id}`, () => fulfillments.command(id, command, Number(row.version ?? 1), key(), payload));
}
async function schedule(row: Row) {
  await fulfillmentAction(row, 'schedule', {
    scheduledFor: new Date(scheduleForm.value.scheduledFor).toISOString(),
    carrier: scheduleForm.value.carrier,
    trackingReference: scheduleForm.value.trackingReference || null,
  });
}
onMounted(loadAll);
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <div class="text-overline text-medium-emphasis">AUTOFLOW · BUSINESS OPERATIONS</div>
        <h1 class="text-h4 font-weight-bold">Business workflows</h1>
        <p class="text-body-2 text-medium-emphasis mt-2">Contracts, physical stock, reservations and vehicle delivery — connected to their real APIs.</p>
      </div>
      <v-btn color="primary" prepend-icon="mdi-refresh" :loading="loading" variant="tonal" @click="loadAll">Refresh data</v-btn>
    </div>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable @click:close="error = ''">{{ error }}</v-alert>
    <v-alert v-if="notice" type="success" variant="tonal" class="mb-4" closable @click:close="notice = ''">{{ notice }}</v-alert>

    <v-tabs v-model="tab" color="primary" class="mb-4" show-arrows>
      <v-tab value="contracts" prepend-icon="mdi-file-sign">Contracts</v-tab>
      <v-tab value="inventory" prepend-icon="mdi-warehouse">Inventory</v-tab>
      <v-tab value="reservations" prepend-icon="mdi-calendar-check">Reservations</v-tab>
      <v-tab value="fulfillment" prepend-icon="mdi-truck-delivery">Fulfillment</v-tab>
      <v-tab value="billing" prepend-icon="mdi-receipt-text-outline">Billing</v-tab>
    </v-tabs>

    <v-window v-model="tab">
      <v-window-item value="contracts">
        <v-card rounded="xl" border elevation="0" class="mb-4">
          <v-card-title>Create contract draft</v-card-title>
          <v-card-text>
            <v-row>
              <v-col cols="12" md="6"><v-text-field v-model="contractForm.orderId" label="Accepted order UUID" hint="The contract service retrieves the legal snapshot from Order Service." persistent-hint /></v-col>
              <v-col cols="12" md="3"><v-select v-model="contractForm.contractType" label="Contract type" :items="[{title:'Vehicle lease',value:'vehicle_lease'},{title:'Vehicle purchase',value:'vehicle_purchase'},{title:'Vehicle subscription',value:'vehicle_subscription'}]" /></v-col>
              <v-col cols="12" md="2"><v-text-field v-model.number="contractForm.contractVersion" type="number" min="1" label="Version" /></v-col>
              <v-col cols="12" md="1" class="d-flex align-center"><v-btn color="primary" block :loading="busy === 'contract-create'" :disabled="!contractForm.orderId" @click="createContract">Create</v-btn></v-col>
            </v-row>
          </v-card-text>
        </v-card>
        <v-card rounded="xl" border elevation="0">
          <v-card-title>Contracts <v-spacer /><v-chip>{{ contractRows.length }}</v-chip></v-card-title>
          <v-table>
            <thead><tr><th>Contract</th><th>Order</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody><tr v-for="row in contractRows" :key="idOf(row,'contract_id')">
              <td class="text-caption">{{ idOf(row,'contract_id') }}</td><td class="text-caption">{{ row.order_id }}</td><td>{{ row.contract_type }}</td><td><v-chip size="small" variant="tonal">{{ row.status }}</v-chip></td>
              <td><div class="d-flex flex-wrap ga-1">
                <v-btn v-if="row.status === 'draft'" size="small" variant="tonal" :loading="busy === 'contract-'+idOf(row,'contract_id')" @click="contractAction(row,'prepare')">Prepare</v-btn>
                <v-btn v-if="row.status === 'prepared'" size="small" variant="tonal" @click="contractAction(row,'generateDocument')">Generate PDF</v-btn>
                <v-btn v-if="row.status === 'prepared'" size="small" variant="tonal" @click="contractAction(row,'sendForSignature')">Send signature</v-btn>
                <v-btn v-if="row.document_checksum" size="small" variant="text" @click="contractAction(row,'downloadDocument')">Download</v-btn>
                <v-btn v-if="['draft','prepared'].includes(row.status)" size="small" color="error" variant="text" @click="contractAction(row,'cancel')">Cancel</v-btn>
              </div></td>
            </tr><tr v-if="!contractRows.length"><td colspan="5" class="text-center text-medium-emphasis py-6">No contracts found or read permission unavailable.</td></tr></tbody>
          </v-table>
        </v-card>
      </v-window-item>

      <v-window-item value="inventory">
        <v-card rounded="xl" border elevation="0" class="mb-4">
          <v-card-title>Register physical vehicle</v-card-title>
          <v-card-text><v-row>
            <v-col cols="12" md="4"><v-text-field v-model="inventoryForm.vehicleVariantId" label="Vehicle variant UUID" /></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="inventoryForm.vin" label="VIN (17 characters)" /></v-col>
            <v-col cols="12" md="2"><v-text-field v-model="inventoryForm.locationCode" label="Location code" /></v-col>
            <v-col cols="12" md="1"><v-text-field v-model="inventoryForm.countryCode" label="Country" /></v-col>
            <v-col cols="12" md="2"><v-btn block color="primary" :loading="busy === 'inventory-create'" :disabled="!inventoryForm.vehicleVariantId || inventoryForm.vin.length !== 17" @click="createInventory">Register</v-btn></v-col>
          </v-row></v-card-text>
        </v-card>
        <v-card rounded="xl" border elevation="0">
          <v-card-title>Physical stock <v-spacer /><v-chip>{{ inventoryRows.length }}</v-chip></v-card-title>
          <v-table><thead><tr><th>VIN</th><th>Variant</th><th>Location</th><th>Status</th><th>Version</th><th>Change status</th></tr></thead>
            <tbody><tr v-for="row in inventoryRows" :key="idOf(row,'inventory_unit_id')">
              <td class="text-caption">{{ row.vin }}</td><td class="text-caption">{{ row.vehicle_variant_id }}</td><td>{{ row.location_code || '—' }}</td><td><v-chip size="small" variant="tonal">{{ row.status }}</v-chip></td><td>{{ row.version }}</td>
              <td><div class="d-flex align-center ga-1"><v-select v-model="inventoryStatus" density="compact" hide-details :items="['available','blocked','in_transit','delivered','sold','maintenance','quarantined']" style="max-width:150px" /><v-btn size="small" color="primary" variant="tonal" :loading="busy === 'inventory-'+idOf(row,'inventory_unit_id')" @click="changeInventory(row)">Apply</v-btn></div></td>
            </tr><tr v-if="!inventoryRows.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No inventory units found.</td></tr></tbody>
          </v-table>
          <v-card-text><v-text-field v-model="inventoryReason" label="Reason for next stock status change (optional)" /></v-card-text>
        </v-card>
      </v-window-item>

      <v-window-item value="reservations">
        <v-card rounded="xl" border elevation="0" class="mb-4">
          <v-card-title>Hold a vehicle unit</v-card-title>
          <v-card-text><v-row>
            <v-col cols="12" md="3"><v-text-field v-model="reservationForm.vehicleUnitId" label="Inventory unit UUID" /></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="reservationForm.vehicleVariantId" label="Variant UUID" /></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="reservationForm.orderId" label="Order UUID" /></v-col>
            <v-col cols="12" md="2"><v-text-field v-model="reservationForm.expiresAt" type="datetime-local" label="Expires at" /></v-col>
            <v-col cols="12" md="1" class="d-flex align-center"><v-btn block color="primary" :loading="busy === 'reservation-create'" :disabled="!reservationForm.vehicleUnitId || !reservationForm.vehicleVariantId || !reservationForm.orderId || !reservationForm.expiresAt" @click="createReservation">Hold</v-btn></v-col>
          </v-row></v-card-text>
        </v-card>
        <v-card rounded="xl" border elevation="0">
          <v-card-title>Vehicle reservations <v-spacer /><v-chip>{{ reservationRows.length }}</v-chip></v-card-title>
          <v-table><thead><tr><th>Reservation</th><th>Vehicle unit</th><th>Order</th><th>Status</th><th>Expires</th><th>Actions</th></tr></thead>
            <tbody><tr v-for="row in reservationRows" :key="idOf(row,'reservation_id')">
              <td class="text-caption">{{ idOf(row,'reservation_id') }}</td><td class="text-caption">{{ row.vehicle_unit_id || row.vehicleUnitId }}</td><td class="text-caption">{{ row.order_id || row.orderId }}</td><td><v-chip size="small" variant="tonal">{{ row.status }}</v-chip></td><td>{{ row.expires_at || row.expiresAt }}</td>
              <td class="d-flex ga-1"><v-btn v-if="row.status === 'held'" size="small" variant="tonal" @click="reservationAction(row,'confirm')">Confirm</v-btn><v-btn v-if="['held','confirmed'].includes(row.status)" size="small" color="error" variant="text" @click="reservationAction(row,'release')">Release</v-btn><v-btn v-if="row.status === 'confirmed'" size="small" variant="tonal" @click="reservationAction(row,'fulfill')">Fulfill</v-btn></td>
            </tr><tr v-if="!reservationRows.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No reservations found.</td></tr></tbody>
          </v-table>
        </v-card>
      </v-window-item>

      <v-window-item value="billing">
        <v-row class="mb-2">
          <v-col cols="12" md="6"><v-card rounded="xl" border elevation="0"><v-card-title>Issued invoices</v-card-title><v-card-text class="text-h4 font-weight-bold">{{ billingSummary?.invoices?.invoice_count ?? invoiceRows.length }}</v-card-text><v-card-subtitle>Gross invoiced: {{ ((billingSummary?.invoices?.invoiced_gross_minor ?? 0) / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) }}</v-card-subtitle></v-card></v-col>
          <v-col cols="12" md="6"><v-card rounded="xl" border elevation="0"><v-card-title>Credit notes</v-card-title><v-card-text class="text-h4 font-weight-bold">{{ billingSummary?.creditNotes?.credit_note_count ?? creditNoteRows.length }}</v-card-text><v-card-subtitle>Gross credited: {{ ((billingSummary?.creditNotes?.credited_gross_minor ?? 0) / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) }}</v-card-subtitle></v-card></v-col>
        </v-row>
        <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Invoices</v-card-title>
          <v-table><thead><tr><th>Invoice</th><th>Order</th><th>Gross</th><th>Status</th><th>Issued</th></tr></thead><tbody>
            <tr v-for="row in invoiceRows" :key="row.invoice_id"><td>{{ row.invoice_number || row.invoice_id }}</td><td class="text-caption">{{ row.order_id }}</td><td>{{ ((Number(row.gross_minor) || 0) / 100).toLocaleString('de-DE', { style: 'currency', currency: row.currency || 'EUR' }) }}</td><td><v-chip size="small" variant="tonal">{{ row.status }}</v-chip></td><td>{{ row.issued_at }}</td></tr>
            <tr v-if="!invoiceRows.length"><td colspan="5" class="text-center text-medium-emphasis py-6">No invoices found.</td></tr>
          </tbody></v-table>
        </v-card>
        <v-card rounded="xl" border elevation="0"><v-card-title>Credit notes</v-card-title>
          <v-table><thead><tr><th>Credit note</th><th>Invoice</th><th>Order</th><th>Gross</th><th>Reason</th></tr></thead><tbody>
            <tr v-for="row in creditNoteRows" :key="row.credit_note_id"><td>{{ row.credit_note_number || row.credit_note_id }}</td><td class="text-caption">{{ row.invoice_id }}</td><td class="text-caption">{{ row.order_id }}</td><td>{{ ((Number(row.gross_minor) || 0) / 100).toLocaleString('de-DE', { style: 'currency', currency: row.currency || 'EUR' }) }}</td><td>{{ row.reason }}</td></tr>
            <tr v-if="!creditNoteRows.length"><td colspan="5" class="text-center text-medium-emphasis py-6">No credit notes found.</td></tr>
          </tbody></v-table>
        </v-card>
        <div class="text-caption text-medium-emphasis mt-3">Billing records are generated from payment and refund events. This workspace is intentionally read-only.</div>
      </v-window-item>
      <v-window-item value="fulfillment">
        <v-card rounded="xl" border elevation="0">
          <v-card-title>Vehicle fulfillment <v-spacer /><v-chip>{{ fulfillmentRows.length }}</v-chip></v-card-title>
          <v-card-text class="text-body-2 text-medium-emphasis">Commands use the latest record version through If-Match. Refresh after a 412 response before retrying.</v-card-text>
          <v-table><thead><tr><th>Fulfillment</th><th>Order</th><th>Status</th><th>Delivery</th><th>Actions</th></tr></thead>
            <tbody><tr v-for="row in fulfillmentRows" :key="idOf(row,'fulfillment_id')">
              <td class="text-caption">{{ idOf(row,'fulfillment_id') }}<div class="text-caption text-medium-emphasis">v{{ row.version }}</div></td><td class="text-caption">{{ row.order_id || row.orderId }}</td><td><v-chip size="small" variant="tonal">{{ row.status }}</v-chip></td><td>{{ row.scheduled_for || row.scheduledFor || '—' }}</td>
              <td><div class="d-flex flex-wrap ga-1">
                <v-btn v-if="row.status === 'ready_for_preparation'" size="small" variant="tonal" @click="fulfillmentAction(row,'preparation/start')">Start prep</v-btn>
                <v-btn v-if="row.status === 'preparing'" size="small" variant="tonal" @click="fulfillmentAction(row,'preparation/complete')">Complete prep</v-btn>
                <v-btn v-if="['ready_for_dispatch','delivery_failed'].includes(row.status)" size="small" variant="tonal" :disabled="!scheduleForm.scheduledFor || !scheduleForm.carrier" @click="schedule(row)">Schedule</v-btn>
                <v-btn v-if="row.status === 'scheduled'" size="small" variant="tonal" @click="fulfillmentAction(row,'dispatch')">Dispatch</v-btn>
                <v-btn v-if="row.status === 'in_transit'" size="small" color="success" variant="tonal" @click="fulfillmentAction(row,'delivery/confirm',{recipientName:recipientName || 'Customer'})">Confirm delivery</v-btn>
                <v-btn v-if="row.status === 'in_transit'" size="small" color="error" variant="text" @click="fulfillmentAction(row,'delivery/fail',{reason:deliveryFailureReason || 'Delivery failed'})">Failed</v-btn>
                <v-btn v-if="!['in_transit','delivered','cancelled'].includes(row.status)" size="small" color="error" variant="text" @click="fulfillmentAction(row,'cancel')">Cancel</v-btn>
              </div></td>
            </tr><tr v-if="!fulfillmentRows.length"><td colspan="5" class="text-center text-medium-emphasis py-6">No fulfillment records found.</td></tr></tbody>
          </v-table>
          <v-divider />
          <v-card-text><v-row>
            <v-col cols="12" md="4"><v-text-field v-model="scheduleForm.scheduledFor" type="datetime-local" label="Scheduled delivery time" /></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="scheduleForm.carrier" label="Carrier" /></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="scheduleForm.trackingReference" label="Tracking reference" /></v-col>
            <v-col cols="12" md="2"><v-text-field v-model="recipientName" label="Recipient name" /></v-col>
          </v-row><v-text-field v-model="deliveryFailureReason" label="Delivery failure reason (optional)" /></v-card-text>
        </v-card>
      </v-window-item>
    </v-window>
    <div class="text-caption text-medium-emphasis mt-4">All operations are tenant-scoped and remain subject to Keycloak scopes and backend state-transition rules.</div>
  </div>
</template>
