<script setup lang="ts">
import { api, errorMessage } from '../../../src/api';

definePageMeta({ layout: 'default' });
useHead({
  title: 'Platform services | AutoFlow',
  meta: [{ name: 'description', content: 'Analytics, audit, configuration, documents, integrations and search workspace.' }],
});

type Row = Record<string, any>;
type Tab = 'overview' | 'analytics' | 'audit' | 'configuration' | 'documents' | 'integrations' | 'search' | 'workflow';
const tab = ref<Tab>('overview');
const loading = ref(false);
const busy = ref('');
const error = ref('');
const notice = ref('');
const analytics = ref<Row | null>(null);
const auditRows = ref<Row[]>([]);
const configurations = ref<Row[]>([]);
const flags = ref<Row[]>([]);
const documents = ref<Row[]>([]);
const providers = ref<string[]>([]);
const deliveries = ref<Row[]>([]);
const webhookEvents = ref<Row[]>([]);
const searchRows = ref<Row[]>([]);
const workflowHealth = ref<Row | null>(null);
const query = ref('');
const days = ref(30);
const auditFilter = ref({ eventType: '', resourceType: '', resourceId: '' });
const configForm = ref({ namespace: 'checkout', key: 'default_currency', value: 'EUR', description: '', reason: '' });
const flagForm = ref({ key: 'new-checkout', enabled: false, rolloutPercentage: 0, description: '' });
const integrationForm = ref({ provider: 'demo', targetUrl: '', eventType: 'offer.published', payload: '{"offerId":"offer-demo-001","status":"published"}' });
const uploadForm = ref({ title: '', category: 'other', description: '' });
const uploadFile = ref<File | null>(null);

const tabs: { title: string; value: Tab; icon: string }[] = [
  { title: 'Overview', value: 'overview', icon: 'mdi-view-dashboard-outline' },
  { title: 'Analytics', value: 'analytics', icon: 'mdi-chart-box-outline' },
  { title: 'Audit trail', value: 'audit', icon: 'mdi-shield-search-outline' },
  { title: 'Configuration', value: 'configuration', icon: 'mdi-tune-variant' },
  { title: 'Documents', value: 'documents', icon: 'mdi-file-document-multiple-outline' },
  { title: 'Integrations', value: 'integrations', icon: 'mdi-connection' },
  { title: 'Search', value: 'search', icon: 'mdi-magnify' },
  { title: 'Workflow', value: 'workflow', icon: 'mdi-transit-connection-variant' },
];

const services = [
  { name: 'Audit', prefix: '/audit', endpoint: '/health/ready', scope: 'audit:read', icon: 'mdi-shield-search-outline' },
  { name: 'Analytics', prefix: '/analytics', endpoint: '/health/ready', scope: 'analytics:read', icon: 'mdi-chart-box-outline' },
  { name: 'Configuration', prefix: '/configuration', endpoint: '/health/ready', scope: 'configuration:read', icon: 'mdi-tune-variant' },
  { name: 'Document', prefix: '/documents', endpoint: '/health/ready', scope: 'documents:read', icon: 'mdi-file-document-multiple-outline' },
  { name: 'Integration', prefix: '/integrations', endpoint: '/health/ready', scope: 'integrations:read', icon: 'mdi-connection' },
  { name: 'Search', prefix: '/search', endpoint: '/health/ready', scope: 'search:read', icon: 'mdi-magnify' },
  { name: 'Workflow', prefix: '/workflow', endpoint: '/health/ready', scope: '—', icon: 'mdi-transit-connection-variant' },
];
const serviceStates = ref(services.map((service) => ({ ...service, status: 'unknown', detail: 'Not checked' })));

function rows(value: any): Row[] {
  if (Array.isArray(value)) return value;
  if (Array.isArray(value?.data)) return value.data;
  if (Array.isArray(value?.items)) return value.items;
  return [];
}
function payload(value: any) { return value?.data ?? value ?? {}; }
function pretty(value: unknown) { return JSON.stringify(value ?? {}, null, 2); }
function showError(value: unknown) { error.value = errorMessage(value); notice.value = ''; }
async function run(key: string, action: () => Promise<unknown>, success: string) {
  busy.value = key; error.value = ''; notice.value = '';
  try { await action(); notice.value = success; await refreshCurrent(); }
  catch (e) { showError(e); }
  finally { busy.value = ''; }
}
async function checkServices() {
  await Promise.all(serviceStates.value.map(async (service) => {
    try {
      const response = await api.get(service.prefix + service.endpoint, { timeout: 5000 });
      service.status = response.status >= 200 && response.status < 300 ? 'healthy' : 'degraded';
      service.detail = response.data?.status ?? 'HTTP ' + response.status;
    } catch (e) {
      service.status = 'unavailable';
      service.detail = errorMessage(e);
    }
  }));
}
async function loadAnalytics() {
  const response = await api.get('/analytics/api/v1/analytics/overview', { params: { days: days.value } });
  analytics.value = payload(response.data);
}
async function loadAudit() {
  const params: Record<string, string | number> = { page: 1, limit: 50 };
  Object.entries(auditFilter.value).forEach(([key, value]) => { if (value.trim()) params[key] = value.trim(); });
  auditRows.value = rows((await api.get('/audit/api/v1/audit/events', { params })).data);
}
async function loadConfiguration() {
  const [configResponse, flagResponse] = await Promise.all([
    api.get('/configuration/api/configurations'),
    api.get('/configuration/api/feature-flags'),
  ]);
  configurations.value = rows(configResponse.data);
  flags.value = rows(flagResponse.data);
}
async function loadDocuments() {
  documents.value = rows((await api.get('/documents/api/v1/documents', { params: { page: 1, limit: 50 } })).data);
}
async function loadIntegrations() {
  const [providerResponse, deliveryResponse, eventResponse] = await Promise.all([
    api.get('/integrations/api/v1/integrations/providers'),
    api.get('/integrations/api/v1/integrations/deliveries'),
    api.get('/integrations/api/v1/integrations/webhook-events', { params: { limit: 50 } }),
  ]);
  providers.value = rows(providerResponse.data).map((item: any) => typeof item === 'string' ? item : String(item.name ?? item.provider ?? ''));
  deliveries.value = rows(deliveryResponse.data);
  webhookEvents.value = rows(eventResponse.data);
}
async function runSearch() {
  if (!query.value.trim()) { searchRows.value = []; return; }
  searchRows.value = rows((await api.get('/search/api/v1/search/vehicles', { params: { q: query.value.trim(), page: 1, limit: 25 } })).data);
}
async function loadWorkflow() {
  workflowHealth.value = (await api.get('/workflow/health/ready')).data;
}
async function refreshCurrent() {
  error.value = '';
  if (tab.value === 'overview') await checkServices();
  if (tab.value === 'analytics') await loadAnalytics();
  if (tab.value === 'audit') await loadAudit();
  if (tab.value === 'configuration') await loadConfiguration();
  if (tab.value === 'documents') await loadDocuments();
  if (tab.value === 'integrations') await loadIntegrations();
  if (tab.value === 'search') await runSearch();
  if (tab.value === 'workflow') await loadWorkflow();
}
async function refresh() {
  loading.value = true;
  try { await refreshCurrent(); } catch (e) { showError(e); }
  finally { loading.value = false; }
}
watch(tab, () => { void refresh(); });
watch(days, () => { if (tab.value === 'analytics') void refresh(); });
onMounted(() => { void refresh(); });

async function saveConfiguration() {
  let value: unknown = configForm.value.value;
  try { value = JSON.parse(configForm.value.value); } catch { /* keep string configuration values as strings */ }
  await run('configuration', () => api.put('/configuration/api/configurations/' + encodeURIComponent(configForm.value.namespace) + '/' + encodeURIComponent(configForm.value.key), {
    value, description: configForm.value.description, reason: configForm.value.reason,
  }), 'Configuration saved.');
}
async function saveFlag() {
  await run('flag', () => api.put('/configuration/api/feature-flags/' + encodeURIComponent(flagForm.value.key), {
    enabled: flagForm.value.enabled, rolloutPercentage: Number(flagForm.value.rolloutPercentage), description: flagForm.value.description,
  }), 'Feature flag saved.');
}
async function uploadDocument() {
  if (!uploadFile.value || !uploadForm.value.title.trim()) { error.value = 'Select a file and enter a document title.'; return; }
  const form = new FormData();
  form.append('file', uploadFile.value);
  form.append('title', uploadForm.value.title);
  form.append('category', uploadForm.value.category);
  if (uploadForm.value.description) form.append('description', uploadForm.value.description);
  await run('upload', () => api.post('/documents/api/v1/documents', form, { headers: { 'Content-Type': 'multipart/form-data' } }), 'Document uploaded and accepted by Document Service.');
  uploadFile.value = null;
}
async function downloadDocument(id: string) {
  busy.value = 'download-' + id; error.value = '';
  try {
    const response = await api.get('/documents/api/v1/documents/' + encodeURIComponent(id) + '/content', { responseType: 'blob' });
    const url = URL.createObjectURL(response.data);
    const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'autoflow-document-' + id; anchor.click();
    URL.revokeObjectURL(url);
  } catch (e) { showError(e); } finally { busy.value = ''; }
}
async function createDelivery() {
  let data: unknown;
  try { data = JSON.parse(integrationForm.value.payload); } catch { error.value = 'Payload must be valid JSON.'; return; }
  await run('delivery', () => api.post('/integrations/api/v1/integrations/deliveries', {
    provider: integrationForm.value.provider, targetUrl: integrationForm.value.targetUrl,
    eventType: integrationForm.value.eventType, payload: data,
  }), 'Outbound delivery queued.');
}
async function downloadAnalyticsCsv() {
  try {
    const response = await api.get('/analytics/api/v1/analytics/export.csv', { responseType: 'blob' });
    const url = URL.createObjectURL(response.data); const anchor = document.createElement('a');
    anchor.href = url; anchor.download = 'autoflow-analytics.csv'; anchor.click(); URL.revokeObjectURL(url);
  } catch (e) { showError(e); }
}
</script>

<template>
  <section>
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-6">
      <div>
        <div class="text-overline text-medium-emphasis">AUTOFLOW · PLATFORM CONTROL</div>
        <h1 class="text-h4 font-weight-bold">Platform services</h1>
        <p class="text-body-2 text-medium-emphasis mt-2">Central workspace for analytics, audit, configuration, documents, integrations and search.</p>
      </div>
      <v-btn color="primary" prepend-icon="mdi-refresh" variant="tonal" :loading="loading" @click="refresh">Refresh</v-btn>
    </div>

    <v-alert v-if="error" type="error" variant="tonal" closable class="mb-4" @click:close="error = ''">{{ error }}</v-alert>
    <v-alert v-if="notice" type="success" variant="tonal" closable class="mb-4" @click:close="notice = ''">{{ notice }}</v-alert>

    <v-card rounded="xl" border elevation="0" class="mb-6">
      <v-tabs v-model="tab" color="primary" show-arrows>
        <v-tab v-for="item in tabs" :key="item.value" :value="item.value" :prepend-icon="item.icon">{{ item.title }}</v-tab>
      </v-tabs>
    </v-card>

    <div v-if="tab === 'overview'">
      <v-row>
        <v-col v-for="service in serviceStates" :key="service.name" cols="12" sm="6" lg="4">
          <v-card rounded="xl" border elevation="0" class="h-100">
            <v-card-text class="d-flex align-center ga-3">
              <v-avatar color="primary" variant="tonal" rounded="lg"><v-icon :icon="service.icon" /></v-avatar>
              <div class="flex-grow-1 min-width-0"><div class="font-weight-bold">{{ service.name }} Service</div><div class="text-caption text-medium-emphasis text-truncate">{{ service.detail }}</div></div>
              <v-chip size="small" :color="service.status === 'healthy' ? 'success' : service.status === 'unavailable' ? 'error' : 'warning'" variant="tonal">{{ service.status }}</v-chip>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>
      <v-alert type="info" variant="tonal" class="mt-4">Health checks verify service readiness only. A healthy service does not imply that the current user has permission to every business operation.</v-alert>
    </div>

    <div v-else-if="tab === 'analytics'">
      <div class="d-flex flex-wrap align-center ga-3 mb-4">
        <v-select v-model="days" :items="[7, 14, 30, 60, 90, 180, 365]" label="Period (days)" density="comfortable" hide-details style="max-width:180px" />
        <v-spacer /><v-btn variant="tonal" prepend-icon="mdi-download" @click="downloadAnalyticsCsv">Export CSV</v-btn>
      </div>
      <v-row v-if="analytics">
        <v-col v-for="metric in [{label:'Offers created',value:analytics.offersCreated},{label:'Offers accepted',value:analytics.offersAccepted},{label:'Conversion rate',value:analytics.offerConversionRate == null ? '—' : analytics.offerConversionRate + '%'},{label:'Successful payments',value:analytics.successfulPayments}]" :key="metric.label" cols="12" sm="6" lg="3">
          <v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">{{ metric.label }}</div><div class="text-h4 font-weight-bold mt-2">{{ metric.value ?? '—' }}</div></v-card-text></v-card>
        </v-col>
      </v-row>
      <v-card rounded="xl" border elevation="0" class="mt-4"><v-card-title>Events by type</v-card-title><v-card-text><v-table density="comfortable"><tbody><tr v-for="(count, name) in analytics?.eventsByType ?? {}" :key="String(name)"><td>{{ name }}</td><td class="text-right font-weight-bold">{{ count }}</td></tr></tbody></v-table><div v-if="!analytics || !Object.keys(analytics.eventsByType ?? {}).length" class="text-medium-emphasis text-body-2">No analytics events for this period.</div></v-card-text></v-card>
    </div>

    <div v-else-if="tab === 'audit'">
      <v-row dense class="mb-3">
        <v-col cols="12" md="4"><v-text-field v-model="auditFilter.eventType" label="Event type" density="comfortable" hide-details /></v-col>
        <v-col cols="12" md="4"><v-text-field v-model="auditFilter.resourceType" label="Resource type" density="comfortable" hide-details /></v-col>
        <v-col cols="12" md="4"><v-text-field v-model="auditFilter.resourceId" label="Resource ID" density="comfortable" hide-details /></v-col>
      </v-row>
      <v-btn color="primary" prepend-icon="mdi-magnify" class="mb-4" :loading="loading" @click="refresh">Search audit trail</v-btn>
      <v-card rounded="xl" border elevation="0"><v-table><thead><tr><th>Occurred</th><th>Event</th><th>Actor</th><th>Resource</th><th>Integrity hash</th></tr></thead><tbody><tr v-for="(item, i) in auditRows" :key="item.event_id ?? i"><td>{{ item.occurred_at ?? '—' }}</td><td>{{ item.event_type ?? '—' }}</td><td>{{ item.actor_id ?? item.actor_type ?? '—' }}</td><td>{{ item.resource_type ?? '—' }} / {{ item.resource_id ?? '—' }}</td><td class="text-truncate" style="max-width:180px">{{ item.event_hash ?? '—' }}</td></tr></tbody></v-table><v-card-text v-if="!auditRows.length" class="text-medium-emphasis">No audit events found.</v-card-text></v-card>
    </div>

    <div v-else-if="tab === 'configuration'">
      <v-row>
        <v-col cols="12" lg="6"><v-card rounded="xl" border elevation="0"><v-card-title>Update configuration</v-card-title><v-card-text class="d-flex flex-column ga-3">
          <v-text-field v-model="configForm.namespace" label="Namespace" /><v-text-field v-model="configForm.key" label="Key" /><v-textarea v-model="configForm.value" label="Value (JSON or string)" rows="3" /><v-text-field v-model="configForm.description" label="Description" /><v-text-field v-model="configForm.reason" label="Change reason" />
          <v-btn color="primary" :loading="busy === 'configuration'" @click="saveConfiguration">Save new version</v-btn>
        </v-card-text></v-card></v-col>
        <v-col cols="12" lg="6"><v-card rounded="xl" border elevation="0"><v-card-title>Feature flag</v-card-title><v-card-text class="d-flex flex-column ga-3">
          <v-text-field v-model="flagForm.key" label="Flag key" /><v-switch v-model="flagForm.enabled" color="primary" label="Enabled" /><v-slider v-model="flagForm.rolloutPercentage" label="Rollout percentage" min="0" max="100" step="5" thumb-label /><v-text-field v-model="flagForm.description" label="Description" />
          <v-btn color="primary" :loading="busy === 'flag'" @click="saveFlag">Save feature flag</v-btn>
        </v-card-text></v-card></v-col>
      </v-row>
      <v-card rounded="xl" border elevation="0" class="mt-4"><v-card-title>Current configurations</v-card-title><v-table><thead><tr><th>Namespace</th><th>Key</th><th>Version</th><th>Value</th></tr></thead><tbody><tr v-for="(item,i) in configurations" :key="item.id ?? i"><td>{{ item.namespace ?? '—' }}</td><td>{{ item.key ?? '—' }}</td><td>{{ item.version ?? '—' }}</td><td>{{ pretty(item.value) }}</td></tr></tbody></v-table></v-card>
      <v-card rounded="xl" border elevation="0" class="mt-4"><v-card-title>Feature flags</v-card-title><v-table><thead><tr><th>Key</th><th>Enabled</th><th>Rollout</th><th>Version</th></tr></thead><tbody><tr v-for="(item,i) in flags" :key="item.key ?? i"><td>{{ item.key }}</td><td>{{ item.enabled ? 'Yes' : 'No' }}</td><td>{{ item.rolloutPercentage ?? 0 }}%</td><td>{{ item.version ?? '—' }}</td></tr></tbody></v-table></v-card>
    </div>

    <div v-else-if="tab === 'documents'">
      <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Upload document</v-card-title><v-card-text><v-row dense>
        <v-col cols="12" md="4"><v-text-field v-model="uploadForm.title" label="Document title" /></v-col>
        <v-col cols="12" md="3"><v-select v-model="uploadForm.category" :items="['other','contract','invoice','identity','vehicle','supporting']" label="Category" /></v-col>
        <v-col cols="12" md="5"><v-file-input label="File (max 25 MiB; server validates content)" accept=".pdf,.png,.jpg,.jpeg,.txt,.docx" @update:model-value="uploadFile = Array.isArray($event) ? $event[0] ?? null : $event" /></v-col>
        <v-col cols="12"><v-text-field v-model="uploadForm.description" label="Description (optional)" /></v-col>
      </v-row><v-btn color="primary" prepend-icon="mdi-cloud-upload-outline" :loading="busy === 'upload'" @click="uploadDocument">Upload securely</v-btn></v-card-text></v-card>
      <v-card rounded="xl" border elevation="0"><v-card-title>Document library</v-card-title><v-table><thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Size</th><th /></tr></thead><tbody><tr v-for="(item,i) in documents" :key="item.id ?? i"><td>{{ item.title ?? item.original_filename ?? item.id }}</td><td>{{ item.category ?? '—' }}</td><td>{{ item.status ?? '—' }}</td><td>{{ item.size_bytes ?? item.size ?? '—' }}</td><td class="text-right"><v-btn size="small" variant="tonal" :loading="busy === 'download-' + item.id" @click="downloadDocument(String(item.id))">Download</v-btn></td></tr></tbody></v-table><v-card-text v-if="!documents.length" class="text-medium-emphasis">No documents found.</v-card-text></v-card>
      <v-alert type="info" variant="tonal" class="mt-4">Document Service scans uploads and enforces tenant ownership, access grants, retention and legal holds. Downloads are available only after a clean scan.</v-alert>
    </div>

    <div v-else-if="tab === 'integrations'">
      <v-row><v-col cols="12" lg="5"><v-card rounded="xl" border elevation="0"><v-card-title>Queue outbound delivery</v-card-title><v-card-text class="d-flex flex-column ga-3">
        <v-select v-model="integrationForm.provider" :items="providers.length ? providers : ['demo']" label="Provider" /><v-text-field v-model="integrationForm.targetUrl" label="HTTPS target URL" /><v-text-field v-model="integrationForm.eventType" label="Event type" /><v-textarea v-model="integrationForm.payload" label="JSON payload" rows="5" />
        <v-btn color="primary" :loading="busy === 'delivery'" @click="createDelivery">Queue delivery</v-btn>
      </v-card-text></v-card></v-col><v-col cols="12" lg="7"><v-card rounded="xl" border elevation="0"><v-card-title>Providers</v-card-title><v-card-text><v-chip v-for="provider in providers" :key="provider" class="ma-1">{{ provider }}</v-chip><div v-if="!providers.length" class="text-medium-emphasis">No providers returned.</div></v-card-text></v-card>
      <v-card rounded="xl" border elevation="0" class="mt-4"><v-card-title>Outbound deliveries</v-card-title><v-table><thead><tr><th>Provider</th><th>Event</th><th>Status</th><th>Attempts</th><th>Next attempt</th></tr></thead><tbody><tr v-for="(item,i) in deliveries" :key="item.id ?? i"><td>{{ item.provider }}</td><td>{{ item.eventType ?? item.event_type }}</td><td>{{ item.status }}</td><td>{{ item.attempts ?? 0 }}</td><td>{{ item.nextAttemptAt ?? '—' }}</td></tr></tbody></v-table></v-card></v-col></v-row>
      <v-card rounded="xl" border elevation="0" class="mt-4"><v-card-title>Inbound webhook events</v-card-title><v-table><thead><tr><th>Provider</th><th>Event ID</th><th>Type</th><th>Received</th></tr></thead><tbody><tr v-for="(item,i) in webhookEvents" :key="item.eventId ?? i"><td>{{ item.provider }}</td><td>{{ item.eventId ?? item.event_id }}</td><td>{{ item.eventType ?? item.event_type }}</td><td>{{ item.receivedAt ?? item.received_at }}</td></tr></tbody></v-table></v-card>
      <v-alert type="warning" variant="tonal" class="mt-4">Outbound destinations must use HTTPS and an exact hostname allowlist configured server-side. This UI cannot bypass that policy.</v-alert>
    </div>

    <div v-else-if="tab === 'search'">
      <v-card rounded="xl" border elevation="0"><v-card-text><v-text-field v-model="query" label="Search vehicle catalogue" placeholder="Manufacturer, model, fuel type…" prepend-inner-icon="mdi-magnify" @keyup.enter="refresh" /><v-btn color="primary" prepend-icon="mdi-magnify" :loading="loading" @click="refresh">Search</v-btn></v-card-text><v-divider /><v-table><thead><tr><th>Vehicle</th><th>Manufacturer</th><th>Fuel</th><th>Power</th><th>Body</th></tr></thead><tbody><tr v-for="(item,i) in searchRows" :key="item.id ?? i"><td>{{ item.name ?? item.variantName ?? item.modelName ?? item.id }}</td><td>{{ item.manufacturer ?? item.manufacturerName ?? '—' }}</td><td>{{ item.fuelType ?? item.fuel_type ?? '—' }}</td><td>{{ item.powerKw ?? item.power_kw ?? '—' }}</td><td>{{ item.bodyStyle ?? item.body_style ?? '—' }}</td></tr></tbody></v-table><v-card-text v-if="!searchRows.length" class="text-medium-emphasis">Enter a search term to query Search Service.</v-card-text></v-card>
    </div>

    <div v-else-if="tab === 'workflow'">
      <v-card rounded="xl" border elevation="0"><v-card-title>Workflow orchestration service</v-card-title><v-card-text>
        <v-alert type="info" variant="tonal" title="Business API not implemented yet">The current Workflow Service exposes health endpoints only. Temporal workflow start, signal, cancel, retry and execution inspection are not available in this service. Existing order orchestration remains handled by the order workflow worker.</v-alert>
        <v-divider class="my-4" /><div class="text-caption text-medium-emphasis">Readiness response</div><pre class="text-body-2 mt-2 overflow-x-auto">{{ pretty(workflowHealth) }}</pre>
      </v-card-text></v-card>
    </div>
  </section>
</template>
