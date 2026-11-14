<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { customers, errorMessage } from './api';
import PageHeader from '../app/components/shared/PageHeader.vue';
import MetricCard from '../app/components/shared/MetricCard.vue';

type Customer = {
  customer_id: string;
  customer_type: 'individual' | 'company';
  first_name?: string | null;
  last_name?: string | null;
  legal_name?: string | null;
  email?: string | null;
  phone?: string | null;
  vat_id?: string | null;
  locale?: string;
  status: string;
  version?: number;
  created_at?: string;
  addresses?: Address[];
  consents?: Consent[];
};
type Address = {
  address_id: string; address_type: string; label?: string | null;
  line1: string; line2?: string | null; postal_code: string; city: string;
  region?: string | null; country_code: string; is_default: boolean;
};
type Consent = { purpose: string; granted: boolean; source?: string; recorded_at?: string };
type HistoryEvent = { event_id: string; event_type: string; actor_id: string; occurred_at: string; payload?: Record<string, unknown> };

const rows = ref<Customer[]>([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const search = ref('');
const statusFilter = ref('active');
const typeFilter = ref('');
const offset = ref(0);
const limit = 20;
const pages = computed(() => Math.max(1, Math.ceil(total.value / limit)));
const dialog = ref(false);
const editingId = ref('');
const detailDialog = ref(false);
const selected = ref<Customer | null>(null);
const history = ref<HistoryEvent[]>([]);
const detailLoading = ref(false);
const addressDialog = ref(false);
const editingAddressId = ref('');
const addressSaving = ref(false);
const addressError = ref('');
const addressForm = ref({ addressType: 'billing', label: '', line1: '', line2: '', postalCode: '', city: '', region: '', countryCode: 'DE', isDefault: true });
const consentPurpose = ref('marketing.email');
const consentGranted = ref(false);
const consentSaving = ref(false);
const form = ref({
  customerType: 'individual',
  firstName: '',
  lastName: '',
  legalName: '',
  email: '',
  phone: '',
  vatId: '',
  locale: 'de-DE',
});

const displayName = (customer: Customer) => customer.customer_type === 'company'
  ? customer.legal_name || 'Unternehmen'
  : [customer.first_name, customer.last_name].filter(Boolean).join(' ') || 'Privatkunde';
const idempotencyKey = () => globalThis.crypto?.randomUUID?.() ?? `customer-${Date.now()}-${Math.random().toString(16).slice(2)}`;
const formatDate = (value?: string) => value ? new Date(value).toLocaleString('de-DE') : '–';

function resetForm() {
  editingId.value = '';
  form.value = { customerType: 'individual', firstName: '', lastName: '', legalName: '', email: '', phone: '', vatId: '', locale: 'de-DE' };
}
async function load() {
  loading.value = true; error.value = ''; notice.value = '';
  try {
    const response = await customers.list({
      limit, offset: offset.value,
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
      ...(statusFilter.value ? { status: statusFilter.value } : {}),
      ...(typeFilter.value ? { type: typeFilter.value } : {}),
    });
    rows.value = response.data.items ?? [];
    total.value = response.data.total ?? rows.value.length;
  } catch (e) { error.value = errorMessage(e); }
  finally { loading.value = false; }
}
function openCreate() {
  resetForm(); error.value = ''; dialog.value = true;
}
function openEdit(customer: Customer) {
  editingId.value = customer.customer_id;
  form.value = {
    customerType: customer.customer_type,
    firstName: customer.first_name ?? '',
    lastName: customer.last_name ?? '',
    legalName: customer.legal_name ?? '',
    email: customer.email ?? '',
    phone: customer.phone ?? '',
    vatId: customer.vat_id ?? '',
    locale: customer.locale ?? 'de-DE',
  };
  error.value = ''; dialog.value = true;
}
async function save() {
  saving.value = true; error.value = ''; notice.value = '';
  try {
    if (editingId.value) {
      const payload: Record<string, unknown> = {};
      for (const key of ['firstName', 'lastName', 'legalName', 'email', 'phone', 'vatId', 'locale'] as const) {
        const value = form.value[key].trim();
        payload[key] = value || null;
      }
      await customers.update(editingId.value, payload);
      notice.value = 'Kundenprofil aktualisiert.';
    } else {
      const payload = {
        customerType: form.value.customerType,
        firstName: form.value.customerType === 'individual' ? form.value.firstName.trim() : null,
        lastName: form.value.customerType === 'individual' ? form.value.lastName.trim() : null,
        legalName: form.value.customerType === 'company' ? form.value.legalName.trim() : null,
        email: form.value.email.trim() || null,
        phone: form.value.phone.trim() || null,
        vatId: form.value.vatId.trim() || null,
        locale: form.value.locale,
      };
      await customers.create(payload, idempotencyKey());
      notice.value = 'Kunde angelegt.';
    }
    dialog.value = false; resetForm(); await load();
  } catch (e) { error.value = errorMessage(e); }
  finally { saving.value = false; }
}
async function openDetail(customer: Customer) {
  detailDialog.value = true; detailLoading.value = true; error.value = '';
  selected.value = customer; history.value = [];
  try {
    const [profile, audit] = await Promise.all([
      customers.get(customer.customer_id),
      customers.history(customer.customer_id),
    ]);
    selected.value = profile.data;
    history.value = audit.data.items ?? [];
  } catch (e) { error.value = errorMessage(e); }
  finally { detailLoading.value = false; }
}
async function archive(customer: Customer) {
  if (!window.confirm(`Kunden ${displayName(customer)} archivieren? Der Datensatz bleibt aus Audit-Gründen erhalten.`)) return;
  error.value = '';
  try { await customers.archive(customer.customer_id); notice.value = 'Kunde archiviert.'; await load(); }
  catch (e) { error.value = errorMessage(e); }
}
function openAddress(address?: Address) {
  editingAddressId.value = address?.address_id ?? '';
  addressForm.value = address ? {
    addressType: address.address_type, label: address.label ?? '', line1: address.line1,
    line2: address.line2 ?? '', postalCode: address.postal_code, city: address.city,
    region: address.region ?? '', countryCode: address.country_code, isDefault: address.is_default,
  } : { addressType: 'billing', label: '', line1: '', line2: '', postalCode: '', city: '', region: '', countryCode: 'DE', isDefault: true };
  addressError.value = ''; addressDialog.value = true;
}
async function saveAddress() {
  if (!selected.value) return;
  addressSaving.value = true; addressError.value = '';
  try {
    const payload = { ...addressForm.value, line2: addressForm.value.line2 || null, region: addressForm.value.region || null };
    if (editingAddressId.value) await customers.updateAddress(selected.value.customer_id, editingAddressId.value, payload);
    else await customers.addAddress(selected.value.customer_id, payload);
    const response = await customers.get(selected.value.customer_id);
    selected.value = response.data; addressDialog.value = false;
  } catch (e) { addressError.value = errorMessage(e); }
  finally { addressSaving.value = false; }
}
async function removeAddress(address: Address) {
  if (!selected.value || !window.confirm('Diese Adresse entfernen?')) return;
  try {
    await customers.deleteAddress(selected.value.customer_id, address.address_id);
    selected.value = (await customers.get(selected.value.customer_id)).data;
  } catch (e) { error.value = errorMessage(e); }
}
async function saveConsent() {
  if (!selected.value) return;
  consentSaving.value = true; error.value = '';
  try {
    await customers.setConsent(selected.value.customer_id, { purpose: consentPurpose.value, granted: consentGranted.value, source: 'autoflow-admin' });
    selected.value = (await customers.get(selected.value.customer_id)).data;
  } catch (e) { error.value = errorMessage(e); }
  finally { consentSaving.value = false; }
}
onMounted(() => { void load(); });
</script>

<template>
  <div class="customer-workspace">
    <PageHeader eyebrow="AUTOFLOW · CUSTOMER MANAGEMENT" title="Kunden" description="Verwalte tenant-scoped Kundenprofile, Kontaktinformationen, Adressen, Einwilligungen und Audit-Verläufe.">
      <template #actions>
        <v-btn variant="tonal" prepend-icon="mdi-refresh" :loading="loading" @click="load">Aktualisieren</v-btn>
        <v-btn color="primary" prepend-icon="mdi-account-plus-outline" @click="openCreate">Kunde anlegen</v-btn>
      </template>
    </PageHeader>

    <v-row class="mb-3">
      <v-col cols="12" md="4"><MetricCard label="Kunden auf aktueller Seite" :value="String(rows.length)" caption="Geladene Datensätze" icon="mdi-account-group-outline" tone="primary" /></v-col>
      <v-col cols="12" md="4"><MetricCard label="Gesamt" :value="String(total)" caption="Gefilterte Treffer" icon="mdi-database-outline" tone="info" /></v-col>
      <v-col cols="12" md="4"><MetricCard label="Seite" :value="`${Math.floor(offset / limit) + 1} / ${pages}`" caption="Seitennavigation" icon="mdi-page-next-outline" tone="success" /></v-col>
    </v-row>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable @click:close="error = ''">{{ error }}</v-alert>
    <v-alert v-if="notice" type="success" variant="tonal" class="mb-4" closable @click:close="notice = ''">{{ notice }}</v-alert>

    <v-card rounded="xl" border elevation="0">
      <v-card-title class="d-flex flex-wrap align-center ga-3 pa-4">
        <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" label="Name, E-Mail oder Firma suchen" hide-details density="comfortable" variant="outlined" max-width="360" @keydown.enter="offset = 0; load()" />
        <v-select v-model="statusFilter" :items="[{ title: 'Aktiv', value: 'active' }, { title: 'Inaktiv', value: 'inactive' }, { title: 'Alle Status', value: '' }]" label="Status" hide-details density="comfortable" variant="outlined" max-width="170" @update:model-value="offset = 0; load()" />
        <v-select v-model="typeFilter" :items="[{ title: 'Alle Typen', value: '' }, { title: 'Privatkunde', value: 'individual' }, { title: 'Unternehmen', value: 'company' }]" label="Kundentyp" hide-details density="comfortable" variant="outlined" max-width="190" @update:model-value="offset = 0; load()" />
        <v-spacer />
        <v-btn variant="text" prepend-icon="mdi-filter-remove" @click="search = ''; statusFilter = ''; typeFilter = ''; offset = 0; load()">Filter zurücksetzen</v-btn>
      </v-card-title>
      <v-divider />
      <v-progress-linear v-if="loading" indeterminate color="primary" />
      <v-table v-else density="comfortable">
        <thead><tr><th>Kunde</th><th>Typ</th><th>E-Mail</th><th>Telefon</th><th>Status</th><th>Aktualisiert</th><th class="text-end">Aktionen</th></tr></thead>
        <tbody>
          <tr v-for="customer in rows" :key="customer.customer_id">
            <td><button class="customer-link" @click="openDetail(customer)">{{ displayName(customer) }}</button><div class="text-caption text-medium-emphasis">{{ customer.customer_id }}</div></td>
            <td><v-chip size="small" variant="tonal">{{ customer.customer_type === 'company' ? 'Unternehmen' : 'Privatkunde' }}</v-chip></td>
            <td>{{ customer.email || '–' }}</td><td>{{ customer.phone || '–' }}</td>
            <td><v-chip size="small" :color="customer.status === 'active' ? 'success' : 'default'" variant="tonal">{{ customer.status }}</v-chip></td>
            <td>{{ formatDate(customer.created_at) }}</td>
            <td class="text-end">
              <v-btn icon="mdi-pencil-outline" size="small" variant="text" aria-label="Bearbeiten" @click="openEdit(customer)" />
              <v-btn v-if="customer.status !== 'archived'" icon="mdi-archive-outline" size="small" variant="text" color="error" aria-label="Archivieren" @click="archive(customer)" />
            </td>
          </tr>
          <tr v-if="!rows.length"><td colspan="7" class="text-center text-medium-emphasis py-8">{{ loading ? 'Lädt …' : 'Keine Kunden gefunden.' }}</td></tr>
        </tbody>
      </v-table>
      <v-divider />
      <div class="d-flex align-center justify-end ga-3 pa-3">
        <span class="text-caption text-medium-emphasis">{{ total }} Treffer</span>
        <v-btn icon="mdi-chevron-left" variant="text" :disabled="offset === 0" aria-label="Vorherige Seite" @click="offset = Math.max(0, offset - limit); load()" />
        <span class="text-caption">{{ Math.floor(offset / limit) + 1 }} / {{ pages }}</span>
        <v-btn icon="mdi-chevron-right" variant="text" :disabled="offset + limit >= total" aria-label="Nächste Seite" @click="offset += limit; load()" />
      </div>
    </v-card>

    <v-dialog v-model="dialog" max-width="680" persistent>
      <v-card rounded="xl">
        <v-card-title class="pa-5">{{ editingId ? 'Kundenprofil bearbeiten' : 'Neuen Kunden anlegen' }}</v-card-title>
        <v-card-text>
          <v-form @submit.prevent="save">
            <v-select v-if="!editingId" v-model="form.customerType" :items="[{ title: 'Privatkunde', value: 'individual' }, { title: 'Unternehmen', value: 'company' }]" label="Kundentyp" variant="outlined" />
            <v-row v-if="form.customerType === 'individual'">
              <v-col cols="12" sm="6"><v-text-field v-model="form.firstName" label="Vorname" variant="outlined" :rules="[v => !!v || 'Vorname ist erforderlich']" /></v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="form.lastName" label="Nachname" variant="outlined" :rules="[v => !!v || 'Nachname ist erforderlich']" /></v-col>
            </v-row>
            <v-text-field v-else v-model="form.legalName" label="Firmenname / Legal Name" variant="outlined" :rules="[v => !!v || 'Firmenname ist erforderlich']" />
            <v-row>
              <v-col cols="12" sm="6"><v-text-field v-model="form.email" label="E-Mail" type="email" variant="outlined" /></v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="form.phone" label="Telefon" variant="outlined" /></v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="form.vatId" label="USt-IdNr." variant="outlined" /></v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="form.locale" label="Locale" variant="outlined" /></v-col>
            </v-row>
            <v-alert v-if="error" type="error" variant="tonal" density="compact">{{ error }}</v-alert>
            <div class="d-flex justify-end ga-2 mt-3"><v-btn variant="text" @click="dialog = false">Abbrechen</v-btn><v-btn color="primary" type="submit" :loading="saving">{{ editingId ? 'Speichern' : 'Kunde anlegen' }}</v-btn></div>
          </v-form>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="detailDialog" max-width="1050" scrollable>
      <v-card rounded="xl">
        <v-card-title class="d-flex align-center pa-5"><div><div class="text-h6">{{ selected ? displayName(selected) : 'Kundendetails' }}</div><div class="text-caption text-medium-emphasis">{{ selected?.customer_id }}</div></div><v-spacer /><v-btn icon="mdi-close" variant="text" aria-label="Schließen" @click="detailDialog = false" /></v-card-title>
        <v-divider />
        <v-card-text v-if="selected">
          <v-progress-linear v-if="detailLoading" indeterminate />
          <v-row>
            <v-col cols="12" md="6"><v-card variant="tonal" rounded="lg"><v-card-title class="text-subtitle-1">Profil</v-card-title><v-card-text><div>{{ selected.email || 'Keine E-Mail' }}</div><div>{{ selected.phone || 'Keine Telefonnummer' }}</div><div>Locale: {{ selected.locale || '–' }} · Version {{ selected.version || 1 }}</div><div>Status: {{ selected.status }}</div></v-card-text></v-card></v-col>
            <v-col cols="12" md="6"><v-card variant="tonal" rounded="lg"><v-card-title class="text-subtitle-1">Einwilligungen</v-card-title><v-card-text><div v-for="consent in selected.consents || []" :key="consent.purpose" class="d-flex justify-space-between mb-2"><span>{{ consent.purpose }}</span><v-chip size="x-small" :color="consent.granted ? 'success' : 'warning'">{{ consent.granted ? 'Erteilt' : 'Widerrufen' }}</v-chip></div><div class="d-flex ga-2 align-center mt-3"><v-text-field v-model="consentPurpose" label="Zweck" density="compact" hide-details /><v-switch v-model="consentGranted" label="Erteilt" color="success" hide-details /><v-btn size="small" color="primary" :loading="consentSaving" @click="saveConsent">Speichern</v-btn></div></v-card-text></v-card></v-col>
          </v-row>
          <div class="d-flex align-center mt-5 mb-2"><h3 class="text-subtitle-1 font-weight-bold">Adressen</h3><v-spacer /><v-btn size="small" variant="tonal" prepend-icon="mdi-plus" @click="openAddress()">Adresse hinzufügen</v-btn></div>
          <v-row>
            <v-col v-for="address in selected.addresses || []" :key="address.address_id" cols="12" md="6"><v-card border rounded="lg"><v-card-text><div class="d-flex justify-space-between"><strong>{{ address.label || address.address_type }}</strong><v-chip v-if="address.is_default" size="x-small" color="success">Standard</v-chip></div><div>{{ address.line1 }} {{ address.line2 }}</div><div>{{ address.postal_code }} {{ address.city }}</div><div>{{ address.country_code }}</div><div class="d-flex justify-end ga-1"><v-btn size="x-small" variant="text" @click="openAddress(address)">Bearbeiten</v-btn><v-btn size="x-small" color="error" variant="text" @click="removeAddress(address)">Entfernen</v-btn></div></v-card-text></v-card></v-col>
            <v-col v-if="!(selected.addresses || []).length" cols="12"><p class="text-body-2 text-medium-emphasis">Noch keine Adressen hinterlegt.</p></v-col>
          </v-row>
          <h3 class="text-subtitle-1 font-weight-bold mt-5 mb-2">Audit-Verlauf</h3>
          <v-timeline density="compact" side="end">
            <v-timeline-item v-for="event in history" :key="event.event_id" dot-color="primary" size="x-small"><div class="text-body-2 font-weight-medium">{{ event.event_type }}</div><div class="text-caption text-medium-emphasis">{{ formatDate(event.occurred_at) }} · {{ event.actor_id }}</div></v-timeline-item>
          </v-timeline>
          <p v-if="!history.length" class="text-caption text-medium-emphasis">Keine Audit-Ereignisse vorhanden.</p>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="addressDialog" max-width="620">
      <v-card rounded="xl"><v-card-title class="pa-5">{{ editingAddressId ? 'Adresse bearbeiten' : 'Adresse hinzufügen' }}</v-card-title><v-card-text>
        <v-form @submit.prevent="saveAddress">
          <v-select v-model="addressForm.addressType" :items="['billing','shipping','residential','business']" label="Adresstyp" variant="outlined" />
          <v-text-field v-model="addressForm.label" label="Bezeichnung" variant="outlined" />
          <v-text-field v-model="addressForm.line1" label="Straße und Hausnummer" variant="outlined" :rules="[v => !!v || 'Pflichtfeld']" />
          <v-text-field v-model="addressForm.line2" label="Adresszusatz" variant="outlined" />
          <v-row><v-col cols="4"><v-text-field v-model="addressForm.postalCode" label="PLZ" variant="outlined" /></v-col><v-col cols="8"><v-text-field v-model="addressForm.city" label="Ort" variant="outlined" /></v-col></v-row>
          <v-row><v-col cols="6"><v-text-field v-model="addressForm.region" label="Region" variant="outlined" /></v-col><v-col cols="6"><v-text-field v-model="addressForm.countryCode" label="Land (ISO)" variant="outlined" /></v-col></v-row>
          <v-switch v-model="addressForm.isDefault" label="Standardadresse" color="success" />
          <v-alert v-if="addressError" type="error" variant="tonal">{{ addressError }}</v-alert>
          <div class="d-flex justify-end ga-2"><v-btn variant="text" @click="addressDialog = false">Abbrechen</v-btn><v-btn type="submit" color="primary" :loading="addressSaving">{{ editingAddressId ? 'Änderungen speichern' : 'Adresse speichern' }}</v-btn></div>
        </v-form>
      </v-card-text></v-card>
    </v-dialog>
  </div>
</template>

<style scoped>
.customer-link{padding:0;text-align:left;color:rgb(var(--v-theme-primary));font-weight:700;text-decoration:none}
.customer-link:hover{text-decoration:underline}
</style>
