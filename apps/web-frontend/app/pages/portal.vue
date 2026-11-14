<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { customers, errorMessage } from '../../src/api';

definePageMeta({ layout: 'default' });
useHead({ title: 'Mein Kundenkonto | AutoFlow' });
type Row = Record<string, any>;
const profile = ref<Row | null>(null);
const addresses = ref<Row[]>([]);
const consents = ref<Row[]>([]);
const edit = ref({ firstName: '', lastName: '', email: '', phone: '', locale: 'de-DE' });
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');

function list(data: any): Row[] { return Array.isArray(data) ? data : Array.isArray(data?.items) ? data.items : []; }
async function load() {
  loading.value = true;
  error.value = '';
  try {
    const [p, a, c] = await Promise.all([customers.portalMe(), customers.portalAddresses(), customers.portalConsents()]);
    profile.value = p.data;
    addresses.value = list(a.data);
    consents.value = list(c.data);
    edit.value = { firstName: p.data.first_name ?? '', lastName: p.data.last_name ?? '', email: p.data.email ?? '', phone: p.data.phone ?? '', locale: p.data.locale ?? 'de-DE' };
  } catch (cause) {
    error.value = errorMessage(cause);
  } finally {
    loading.value = false;
  }
}
async function save() {
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    profile.value = (await customers.portalUpdate({
      firstName: edit.value.firstName.trim(), lastName: edit.value.lastName.trim(),
      email: edit.value.email.trim(), ...(edit.value.phone.trim() ? { phone: edit.value.phone.trim() } : {}), locale: edit.value.locale.trim(),
    })).data;
    notice.value = 'Deine Profildaten wurden gespeichert.';
  } catch (cause) {
    error.value = errorMessage(cause);
  } finally {
    saving.value = false;
  }
}
async function setConsent(purpose: string, granted: boolean) {
  error.value = '';
  try {
    await customers.portalSetConsent(purpose, granted);
    const current = consents.value.find((item) => item.purpose === purpose);
    if (current) current.granted = granted;
    else consents.value.push({ purpose, granted, source: 'customer-self-service' });
    notice.value = 'Deine Einwilligung wurde aktualisiert.';
  } catch (cause) {
    error.value = errorMessage(cause);
  }
}
onMounted(() => { void load(); });
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div><div class="text-overline text-medium-emphasis">AUTOFLOW · CUSTOMER SELF-SERVICE</div><h1 class="text-h4 font-weight-bold">Mein Kundenkonto</h1><p class="text-body-2 text-medium-emphasis">Deine persönlichen Kontaktdaten, Adressen und Kommunikationseinwilligungen.</p></div>
      <v-btn prepend-icon="mdi-refresh" variant="tonal" :loading="loading" @click="load">Aktualisieren</v-btn>
    </div>
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>
    <v-alert v-if="notice" type="success" variant="tonal" class="mb-4" closable>{{ notice }}</v-alert>
    <v-progress-linear v-if="loading" indeterminate color="primary" />
    <template v-if="profile">
      <v-row>
        <v-col cols="12" md="7">
          <v-card rounded="xl" border elevation="0"><v-card-title>Persönliche Daten</v-card-title><v-card-text>
            <v-form @submit.prevent="save"><v-row dense>
              <v-col cols="12" sm="6"><v-text-field v-model="edit.firstName" label="Vorname" required /></v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="edit.lastName" label="Nachname" required /></v-col>
              <v-col cols="12"><v-text-field v-model="edit.email" label="E-Mail" type="email" required /></v-col>
              <v-col cols="12" sm="8"><v-text-field v-model="edit.phone" label="Telefon" /></v-col>
              <v-col cols="12" sm="4"><v-text-field v-model="edit.locale" label="Sprache" /></v-col>
            </v-row><v-btn color="primary" type="submit" :loading="saving">Änderungen speichern</v-btn></v-form>
          </v-card-text></v-card>
        </v-col>
        <v-col cols="12" md="5">
          <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Meine Adressen</v-card-title><v-list>
            <v-list-item v-for="address in addresses" :key="address.address_id" :title="address.label || address.address_type" :subtitle="address.line1 + ', ' + address.postal_code + ' ' + address.city + ', ' + address.country_code"><template #append><v-chip v-if="address.is_default" size="small" color="success" variant="tonal">Standard</v-chip></template></v-list-item>
            <v-list-item v-if="!addresses.length" title="Noch keine Adresse hinterlegt." />
          </v-list></v-card>
          <v-card rounded="xl" border elevation="0"><v-card-title>Kommunikation</v-card-title><v-card-text class="text-body-2 text-medium-emphasis">Du kannst deine freiwilligen Marketing-Einwilligungen jederzeit ändern. Vertrags- und Serviceinformationen sind davon unabhängig.</v-card-text><v-list>
            <v-list-item title="Marketing per E-Mail"><template #append><v-switch :model-value="Boolean(consents.find(item => item.purpose === 'marketing.email')?.granted)" color="success" hide-details density="compact" @update:model-value="setConsent('marketing.email', Boolean($event))" /></template></v-list-item>
            <v-list-item title="Marketing per SMS"><template #append><v-switch :model-value="Boolean(consents.find(item => item.purpose === 'marketing.sms')?.granted)" color="success" hide-details density="compact" @update:model-value="setConsent('marketing.sms', Boolean($event))" /></template></v-list-item>
          </v-list></v-card>
        </v-col>
      </v-row>
    </template>
    <v-card v-else-if="!loading && !error" rounded="xl" border elevation="0"><v-card-text class="py-8 text-center">Dein Kundenkonto ist noch nicht mit deinem Login verbunden. Bitte wende dich an deinen AutoFlow-Berater.</v-card-text></v-card>
  </div>
</template>
