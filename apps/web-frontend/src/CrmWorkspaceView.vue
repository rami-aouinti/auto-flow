<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { crm, customers, errorMessage } from './api';
import PageHeader from '../app/components/shared/PageHeader.vue';
import MetricCard from '../app/components/shared/MetricCard.vue';

type Application = {
  application_id: string; customer_id: string; offer_id?: string | null; quote_id?: string | null;
  vehicle_id?: string | null; status: string; eligibility_status: string;
  assigned_advisor_id?: string | null; applicant_message?: string | null;
  orderId?: string | null; version?: number; created_at?: string;
};
type CustomerOption = { customer_id: string; customer_type: string; first_name?: string | null; last_name?: string | null; legal_name?: string | null; email?: string | null };
type Task = { task_id: string; title: string; description?: string | null; assigned_to: string; due_at?: string | null; status: string };
type Interaction = { interaction_id: string; channel: string; subject: string; body?: string | null; actor_id: string; occurred_at: string };
type AuditEvent = { event_id: string; event_type: string; actor_id: string; occurred_at: string; payload?: Record<string, unknown> };

const applications = ref<Application[]>([]);
const total = ref(0);
const offset = ref(0);
const limit = 20;
const loading = ref(false);
const error = ref('');
const notice = ref('');
const statusFilter = ref('');
const eligibilityFilter = ref('');
const selected = ref<Application | null>(null);
const detailLoading = ref(false);
const tasks = ref<Task[]>([]);
const interactions = ref<Interaction[]>([]);
const history = ref<AuditEvent[]>([]);
const detailError = ref('');
const customerOptions = ref<CustomerOption[]>([]);
const customerLoading = ref(false);
const createDialog = ref(false);
const createBusy = ref(false);
const createError = ref('');
const createForm = ref({ customerId: '', offerId: '', quoteId: '', vehicleId: '', message: '' });
const advisorId = ref('');
const eligibilityStatus = ref('pending');
const eligibilityJson = ref('{}');
const eligibilityBusy = ref(false);
const statusBusy = ref(false);
const convertBusy = ref(false);
const taskBusy = ref(false);
const taskForm = ref({ title: '', assignedTo: '', description: '', dueAt: '' });
const interactionBusy = ref(false);
const interactionForm = ref({ channel: 'note', subject: '', body: '' });
const taskStatus = ['open', 'in_progress', 'completed', 'cancelled'];
const eligibilityOptions = ['pending', 'in_review', 'eligible', 'ineligible', 'manual_review'];
const pages = computed(() => Math.max(1, Math.ceil(total.value / limit)));
const counts = computed(() => ({
  submitted: applications.value.filter(x => x.status === 'submitted').length,
  review: applications.value.filter(x => ['under_review', 'information_required'].includes(x.status)).length,
  approved: applications.value.filter(x => x.status === 'approved').length,
}));

const idempotencyKey = () => globalThis.crypto?.randomUUID?.() ?? `crm-${Date.now()}-${Math.random().toString(16).slice(2)}`;
const date = (value?: string | null) => value ? new Date(value).toLocaleString('de-DE') : '–';
const customerName = (c: CustomerOption) => c.customer_type === 'company'
  ? c.legal_name || 'Unternehmen'
  : [c.first_name, c.last_name].filter(Boolean).join(' ') || 'Kunde';
const customerTitle = (c: CustomerOption) => [customerName(c), c.email].filter(Boolean).join(' · ');
const label = (value?: string | null) => (value || '–').replaceAll('_', ' ');
const statusColors: Record<string, string> = {
  submitted: 'info', under_review: 'warning', information_required: 'warning',
  eligible: 'success', approved: 'success', declined: 'error', withdrawn: 'default',
  converted: 'success', pending: 'default', in_review: 'warning', ineligible: 'error',
  manual_review: 'warning',
};
const statusColor = (value: string) => statusColors[value] || 'default';

async function loadCustomers() {
  customerLoading.value = true;
  try {
    const response = await customers.list({ limit: 100, offset: 0, status: 'active' });
    customerOptions.value = response.data.items ?? [];
  } catch (e) { createError.value = errorMessage(e); }
  finally { customerLoading.value = false; }
}
async function loadApplications() {
  loading.value = true; error.value = '';
  try {
    const response = await crm.listApplications({
      limit, offset: offset.value,
      ...(statusFilter.value ? { status: statusFilter.value } : {}),
      ...(eligibilityFilter.value ? { eligibilityStatus: eligibilityFilter.value } : {}),
    });
    applications.value = response.data.items ?? [];
    total.value = response.data.total ?? applications.value.length;
  } catch (e) { error.value = errorMessage(e); }
  finally { loading.value = false; }
}
function openCreate() {
  createForm.value = { customerId: '', offerId: '', quoteId: '', vehicleId: '', message: '' };
  createError.value = ''; createDialog.value = true;
}
async function createApplication() {
  createError.value = '';
  if (!createForm.value.customerId) {
    createError.value = 'Bitte wähle einen aktiven Kunden aus.';
    return;
  }
  createBusy.value = true;
  try {
    await crm.createApplication({
      customerId: createForm.value.customerId,
      offerId: createForm.value.offerId.trim() || null,
      quoteId: createForm.value.quoteId.trim() || null,
      vehicleId: createForm.value.vehicleId.trim() || null,
      message: createForm.value.message.trim() || null,
    }, idempotencyKey());
    createDialog.value = false;
    notice.value = 'Antrag wurde eingereicht.';
    await loadApplications();
  } catch (e) { createError.value = errorMessage(e); }
  finally { createBusy.value = false; }
}
async function openDetail(application: Application) {
  selected.value = application; detailLoading.value = true; detailError.value = '';
  tasks.value = []; interactions.value = []; history.value = [];
  advisorId.value = application.assigned_advisor_id || '';
  eligibilityStatus.value = application.eligibility_status || 'pending';
  eligibilityJson.value = '{}';
  try {
    const [profile, taskResponse, interactionResponse, auditResponse] = await Promise.all([
      crm.getApplication(application.application_id),
      crm.listTasks(application.application_id),
      crm.listInteractions(application.application_id),
      crm.history(application.application_id),
    ]);
    selected.value = profile.data;
    tasks.value = taskResponse.data.items ?? [];
    interactions.value = interactionResponse.data.items ?? [];
    history.value = auditResponse.data.items ?? [];
    advisorId.value = profile.data.assigned_advisor_id || '';
    eligibilityStatus.value = profile.data.eligibility_status || 'pending';
    eligibilityJson.value = JSON.stringify(profile.data.eligibility_result ?? {}, null, 2);
  } catch (e) { detailError.value = errorMessage(e); }
  finally { detailLoading.value = false; }
}
async function changeStatus(status: string) {
  const current = selected.value;
  if (!current) return;
  statusBusy.value = true; detailError.value = '';
  try {
    await crm.transition(current.application_id, status);
    await loadApplications();
    const fresh = applications.value.find(x => x.application_id === current.application_id) ?? current;
    await openDetail(fresh);
    notice.value = `Antragsstatus auf „${label(status)}“ gesetzt.`;
  } catch (e) { detailError.value = errorMessage(e); }
  finally { statusBusy.value = false; }
}
async function assignAdvisor() {
  if (!selected.value || !advisorId.value.trim()) return;
  try {
    const response = await crm.assignAdvisor(selected.value.application_id, advisorId.value.trim());
    selected.value = response.data; notice.value = 'Beraterzuordnung gespeichert.';
    await loadApplications();
  } catch (e) { detailError.value = errorMessage(e); }
}
async function saveEligibility() {
  const current = selected.value;
  if (!current) return;
  eligibilityBusy.value = true; detailError.value = '';
  try {
    const result = JSON.parse(eligibilityJson.value || '{}');
    if (!result || Array.isArray(result) || typeof result !== 'object') throw new Error('Das Prüfergebnis muss ein JSON-Objekt sein.');
    const response = await crm.setEligibility(current.application_id, eligibilityStatus.value, result);
    selected.value = response.data; notice.value = 'Bonitäts-/Eligibility-Prüfung aktualisiert.';
    await loadApplications();
  } catch (e) { detailError.value = errorMessage(e); }
  finally { eligibilityBusy.value = false; }
}
async function convertApplication() {
  const current = selected.value;
  if (!current) return;
  convertBusy.value = true; detailError.value = '';
  try {
    const response = await crm.convert(current.application_id);
    notice.value = response.data.orderId ? `In Auftrag ${response.data.orderId} umgewandelt.` : 'Antrag wurde umgewandelt.';
    await loadApplications();
    await openDetail(current);
  } catch (e) { detailError.value = errorMessage(e); }
  finally { convertBusy.value = false; }
}
async function addTask() {
  const current = selected.value;
  if (!current) return;
  taskBusy.value = true; detailError.value = '';
  try {
    await crm.createTask(current.application_id, {
      title: taskForm.value.title.trim(), assignedTo: taskForm.value.assignedTo.trim(),
      description: taskForm.value.description.trim() || null,
      dueAt: taskForm.value.dueAt ? new Date(taskForm.value.dueAt).toISOString() : null,
    });
    taskForm.value = { title: '', assignedTo: advisorId.value, description: '', dueAt: '' };
    tasks.value = (await crm.listTasks(current.application_id)).data.items ?? [];
  } catch (e) { detailError.value = errorMessage(e); }
  finally { taskBusy.value = false; }
}
async function updateTask(task: Task, status: string) {
  const current = selected.value;
  if (!current) return;
  try {
    await crm.updateTask(current.application_id, task.task_id, status);
    tasks.value = (await crm.listTasks(current.application_id)).data.items ?? [];
  } catch (e) { detailError.value = errorMessage(e); }
}
async function addInteraction() {
  const current = selected.value;
  if (!current) return;
  interactionBusy.value = true; detailError.value = '';
  try {
    await crm.addInteraction(current.application_id, {
      channel: interactionForm.value.channel,
      subject: interactionForm.value.subject.trim(),
      body: interactionForm.value.body.trim() || null,
    });
    interactionForm.value = { channel: 'note', subject: '', body: '' };
    interactions.value = (await crm.listInteractions(current.application_id)).data.items ?? [];
  } catch (e) { detailError.value = errorMessage(e); }
  finally { interactionBusy.value = false; }
}
function allowedTransitions(status: string): string[] {
  const map: Record<string, string[]> = {
    submitted: ['under_review', 'withdrawn'],
    under_review: ['information_required', 'eligible', 'declined', 'withdrawn'],
    information_required: ['under_review', 'withdrawn'],
    eligible: ['approved', 'declined', 'withdrawn'],
    approved: [],
    declined: [], converted: [], withdrawn: [],
  };
  return map[status] ?? [];
}
onMounted(() => { void loadApplications(); void loadCustomers(); });
</script>

<template>
  <div class="crm-workspace">
    <PageHeader eyebrow="AUTOFLOW · CUSTOMER RELATIONSHIP MANAGEMENT" title="CRM · Anträge" description="Verwalte Kundenanträge, Beraterzuordnung, Eligibility-Prüfungen, Aufgaben und Kundeninteraktionen.">
      <template #actions>
        <v-btn variant="tonal" prepend-icon="mdi-refresh" :loading="loading" @click="loadApplications">Aktualisieren</v-btn>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreate">Antrag erstellen</v-btn>
      </template>
    </PageHeader>

    <v-row class="mb-3">
      <v-col cols="12" sm="4"><MetricCard label="Eingereicht" :value="String(counts.submitted)" caption="Aktuelle Ergebnisseite" icon="mdi-inbox-arrow-down-outline" tone="info" /></v-col>
      <v-col cols="12" sm="4"><MetricCard label="In Bearbeitung" :value="String(counts.review)" caption="Prüfung oder Rückfrage" icon="mdi-progress-clock" tone="warning" /></v-col>
      <v-col cols="12" sm="4"><MetricCard label="Genehmigt" :value="String(counts.approved)" caption="Bereit zur Umwandlung" icon="mdi-check-circle-outline" tone="success" /></v-col>
    </v-row>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-3">{{ error }}</v-alert>
    <v-alert v-if="notice" type="success" variant="tonal" class="mb-3" closable @click:close="notice = ''">{{ notice }}</v-alert>
    <v-card rounded="xl" border elevation="0">
      <v-card-title class="d-flex flex-wrap align-center ga-3 pa-4">
        <v-select v-model="statusFilter" label="Antragsstatus" :items="[{title:'Alle',value:''}, ...['submitted','under_review','information_required','eligible','approved','declined','converted','withdrawn'].map(value=>({title:label(value),value}))]" variant="outlined" density="comfortable" hide-details max-width="230" @update:model-value="offset=0;loadApplications()" />
        <v-select v-model="eligibilityFilter" label="Eligibility" :items="[{title:'Alle',value:''}, ...eligibilityOptions.map(value=>({title:label(value),value}))]" variant="outlined" density="comfortable" hide-details max-width="210" @update:model-value="offset=0;loadApplications()" />
        <v-spacer /><span class="text-caption text-medium-emphasis">{{ total }} Anträge</span>
      </v-card-title>
      <v-divider />
      <v-progress-linear v-if="loading" indeterminate />
      <v-table v-else density="comfortable">
        <thead><tr><th>Antrag</th><th>Kunde</th><th>Status</th><th>Eligibility</th><th>Berater</th><th>Eingereicht</th><th></th></tr></thead>
        <tbody>
          <tr v-for="application in applications" :key="application.application_id">
            <td><strong>{{ application.application_id }}</strong><div class="text-caption text-medium-emphasis">v{{ application.version || 1 }}</div></td>
            <td class="text-caption">{{ application.customer_id }}</td>
            <td><v-chip size="small" :color="statusColor(application.status)" variant="tonal">{{ label(application.status) }}</v-chip></td>
            <td><v-chip size="small" :color="statusColor(application.eligibility_status)" variant="tonal">{{ label(application.eligibility_status) }}</v-chip></td>
            <td>{{ application.assigned_advisor_id || 'Nicht zugewiesen' }}</td>
            <td>{{ date(application.created_at) }}</td>
            <td class="text-end"><v-btn size="small" variant="tonal" @click="openDetail(application)">Öffnen</v-btn></td>
          </tr>
          <tr v-if="!applications.length"><td colspan="7" class="text-center text-medium-emphasis py-8">Keine Anträge gefunden.</td></tr>
        </tbody>
      </v-table>
      <v-divider />
      <div class="d-flex justify-end align-center ga-3 pa-3">
        <v-btn icon="mdi-chevron-left" variant="text" :disabled="offset===0" @click="offset=Math.max(0,offset-limit);loadApplications()" />
        <span class="text-caption">{{ Math.floor(offset/limit)+1 }} / {{ pages }}</span>
        <v-btn icon="mdi-chevron-right" variant="text" :disabled="offset+limit>=total" @click="offset+=limit;loadApplications()" />
      </div>
    </v-card>

    <v-dialog v-model="createDialog" max-width="760">
      <v-card rounded="xl"><v-card-title class="pa-5">Neuen Kundenantrag einreichen</v-card-title><v-card-text>
        <v-form @submit.prevent="createApplication">
          <v-autocomplete v-model="createForm.customerId" :items="customerOptions" item-value="customer_id" :item-title="customerTitle" :loading="customerLoading" label="Aktiver Kunde" variant="outlined" required />
          <v-row>
            <v-col cols="12" sm="4"><v-text-field v-model="createForm.offerId" label="Offer ID (optional)" variant="outlined" /></v-col>
            <v-col cols="12" sm="4"><v-text-field v-model="createForm.quoteId" label="Quote ID (optional)" variant="outlined" /></v-col>
            <v-col cols="12" sm="4"><v-text-field v-model="createForm.vehicleId" label="Vehicle ID (optional)" variant="outlined" /></v-col>
          </v-row>
          <v-textarea v-model="createForm.message" label="Nachricht des Antragstellers" variant="outlined" rows="3" />
          <v-alert v-if="createError" type="error" variant="tonal">{{ createError }}</v-alert>
          <div class="d-flex justify-end ga-2"><v-btn variant="text" @click="createDialog=false">Abbrechen</v-btn><v-btn color="primary" type="submit" :loading="createBusy">Antrag einreichen</v-btn></div>
        </v-form>
      </v-card-text></v-card>
    </v-dialog>

    <v-dialog :model-value="!!selected" max-width="1180" scrollable @update:model-value="value => { if (!value) selected = null; }">
      <v-card v-if="selected" rounded="xl">
        <v-card-title class="d-flex align-center pa-5"><div><div class="text-h6">Antrag verwalten</div><div class="text-caption text-medium-emphasis">{{ selected.application_id }} · Kunde {{ selected.customer_id }}</div></div><v-spacer /><v-chip :color="statusColor(selected.status)" variant="tonal">{{ label(selected.status) }}</v-chip><v-btn icon="mdi-close" variant="text" aria-label="Schließen" @click="selected=null" /></v-card-title>
        <v-divider />
        <v-card-text>
          <v-progress-linear v-if="detailLoading" indeterminate />
          <v-alert v-if="detailError" type="error" variant="tonal" class="mb-3">{{ detailError }}</v-alert>
          <v-row>
            <v-col cols="12" lg="6">
              <v-card variant="tonal" rounded="lg"><v-card-title class="text-subtitle-1">Antragsstatus</v-card-title><v-card-text>
                <div class="d-flex flex-wrap ga-2">
                  <v-btn v-for="next in allowedTransitions(selected.status)" :key="next" size="small" variant="tonal" :loading="statusBusy" @click="changeStatus(next)">{{ label(next) }}</v-btn>
                  <v-btn v-if="selected.status==='approved' && selected.eligibility_status==='eligible'" size="small" color="success" :loading="convertBusy" @click="convertApplication">In Auftrag umwandeln</v-btn>
                </div>
                <div v-if="selected.orderId" class="text-caption mt-3">Auftrag: {{ selected.orderId }}</div>
                <div class="text-caption mt-2">Offer: {{ selected.offer_id || '–' }} · Quote: {{ selected.quote_id || '–' }} · Fahrzeug: {{ selected.vehicle_id || '–' }}</div>
              </v-card-text></v-card>
              <v-card variant="tonal" rounded="lg" class="mt-4"><v-card-title class="text-subtitle-1">Beraterzuordnung</v-card-title><v-card-text>
                <v-text-field v-model="advisorId" label="Advisor ID" variant="outlined" density="comfortable" />
                <v-btn size="small" color="primary" :disabled="!advisorId.trim()" @click="assignAdvisor">Zuweisung speichern</v-btn>
              </v-card-text></v-card>
              <v-card variant="tonal" rounded="lg" class="mt-4"><v-card-title class="text-subtitle-1">Eligibility-Prüfung</v-card-title><v-card-text>
                <v-select v-model="eligibilityStatus" :items="eligibilityOptions.map(value=>({title:label(value),value}))" label="Prüfstatus" variant="outlined" density="comfortable" />
                <v-textarea v-model="eligibilityJson" label="Prüfergebnis (JSON)" variant="outlined" rows="4" />
                <v-btn size="small" color="primary" :loading="eligibilityBusy" @click="saveEligibility">Prüfung speichern</v-btn>
              </v-card-text></v-card>
            </v-col>
            <v-col cols="12" lg="6">
              <v-card border rounded="lg"><v-card-title class="text-subtitle-1">Aufgaben</v-card-title><v-card-text>
                <v-row><v-col cols="12" sm="6"><v-text-field v-model="taskForm.title" label="Aufgabe" density="compact" variant="outlined" /></v-col><v-col cols="12" sm="6"><v-text-field v-model="taskForm.assignedTo" label="Zugewiesen an" density="compact" variant="outlined" /></v-col></v-row>
                <v-text-field v-model="taskForm.dueAt" label="Fällig am" type="datetime-local" density="compact" variant="outlined" />
                <v-textarea v-model="taskForm.description" label="Beschreibung" rows="2" density="compact" variant="outlined" />
                <v-btn size="small" color="primary" :disabled="!taskForm.title.trim() || !taskForm.assignedTo.trim()" :loading="taskBusy" @click="addTask">Aufgabe hinzufügen</v-btn>
                <v-divider class="my-3" />
                <div v-for="task in tasks" :key="task.task_id" class="d-flex align-center ga-2 py-2"><div class="flex-grow-1"><strong class="text-body-2">{{ task.title }}</strong><div class="text-caption text-medium-emphasis">{{ task.assigned_to }} · {{ date(task.due_at) }}</div></div><v-select :model-value="task.status" :items="taskStatus.map(value=>({title:label(value),value}))" density="compact" variant="outlined" hide-details max-width="150" @update:model-value="value=>updateTask(task,value)" /></div>
                <p v-if="!tasks.length" class="text-caption text-medium-emphasis">Keine Aufgaben.</p>
              </v-card-text></v-card>
              <v-card border rounded="lg" class="mt-4"><v-card-title class="text-subtitle-1">Interaktionen</v-card-title><v-card-text>
                <v-row><v-col cols="4"><v-select v-model="interactionForm.channel" :items="['phone','email','meeting','note','system']" label="Kanal" density="compact" variant="outlined" /></v-col><v-col cols="8"><v-text-field v-model="interactionForm.subject" label="Betreff" density="compact" variant="outlined" /></v-col></v-row>
                <v-textarea v-model="interactionForm.body" label="Notiz / Inhalt" rows="2" density="compact" variant="outlined" />
                <v-btn size="small" color="primary" :disabled="!interactionForm.subject.trim()" :loading="interactionBusy" @click="addInteraction">Interaktion protokollieren</v-btn>
                <v-divider class="my-3" />
                <div v-for="item in interactions" :key="item.interaction_id" class="py-2"><div class="text-body-2 font-weight-medium">{{ item.subject }} · {{ label(item.channel) }}</div><div class="text-caption text-medium-emphasis">{{ date(item.occurred_at) }} · {{ item.actor_id }}</div><div v-if="item.body" class="text-body-2 mt-1">{{ item.body }}</div></div>
                <p v-if="!interactions.length" class="text-caption text-medium-emphasis">Keine Interaktionen.</p>
              </v-card-text></v-card>
            </v-col>
          </v-row>
          <v-card border rounded="lg" class="mt-4"><v-card-title class="text-subtitle-1">Audit-Verlauf</v-card-title><v-card-text>
            <v-timeline density="compact" side="end"><v-timeline-item v-for="event in history" :key="event.event_id" dot-color="primary" size="x-small"><div class="text-body-2 font-weight-medium">{{ event.event_type }}</div><div class="text-caption text-medium-emphasis">{{ date(event.occurred_at) }} · {{ event.actor_id }}</div></v-timeline-item></v-timeline>
            <p v-if="!history.length" class="text-caption text-medium-emphasis">Keine Audit-Ereignisse.</p>
          </v-card-text></v-card>
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>
