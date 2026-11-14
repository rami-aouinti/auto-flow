<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { customers, errorMessage } from '../../src/api';

definePageMeta({ layout: 'default' });
useHead({ title: 'Mein Kundenkonto | AutoFlow' });
type Row = Record<string, any>;
type AddressForm = {
  addressType: string; label: string; line1: string; line2: string; postalCode: string;
  city: string; region: string; countryCode: string; isDefault: boolean;
};
const emptyAddress = (): AddressForm => ({
  addressType: 'residential', label: '', line1: '', line2: '', postalCode: '',
  city: '', region: '', countryCode: 'DE', isDefault: false,
});
const profile = ref<Row | null>(null);
const addresses = ref<Row[]>([]);
const consents = ref<Row[]>([]);
const edit = ref({ firstName: '', lastName: '', email: '', phone: '', locale: 'de-DE' });
const loading = ref(false);
const saving = ref(false);
const addressSaving = ref(false);
const error = ref('');
const notice = ref('');
const unlinked = ref(false);
const addressDialog = ref(false);
const deleteDialog = ref(false);
const editingAddressId = ref('');
const deletingAddress = ref<Row | null>(null);
const addressForm = ref<AddressForm>(emptyAddress());

function list(data: any): Row[] { return Array.isArray(data) ? data : Array.isArray(data?.items) ? data.items : []; }
function addressPayload() {
  return {
    addressType: addressForm.value.addressType, label: addressForm.value.label.trim() || null,
    line1: addressForm.value.line1.trim(), line2: addressForm.value.line2.trim() || null,
    postalCode: addressForm.value.postalCode.trim(), city: addressForm.value.city.trim(),
    region: addressForm.value.region.trim() || null, countryCode: addressForm.value.countryCode.trim().toUpperCase(),
    isDefault: addressForm.value.isDefault,
  };
}
async function load() {
  loading.value = true;
  error.value = '';
  unlinked.value = false;
  try {
    const [p, a, c] = await Promise.all([customers.portalMe(), customers.portalAddresses(), customers.portalConsents()]);
    profile.value = p.data;
    addresses.value = list(a.data);
    consents.value = list(c.data);
    edit.value = { firstName: p.data.first_name ?? '', lastName: p.data.last_name ?? '', email: p.data.email ?? '', phone: p.data.phone ?? '', locale: p.data.locale ?? 'de-DE' };
  } catch (cause) {
    const status = (cause as { response?: { status?: number } })?.response?.status;
    if (status === 404) {
      profile.value = null;
      addresses.value = [];
      consents.value = [];
      unlinked.value = true;
    } else {
      error.value = errorMessage(cause);
    }
  } finally {
    loading.value = false;
  }
}
async function save() {
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    const payload: Record<string, unknown> = {
      email: edit.value.email.trim(), phone: edit.value.phone.trim() || null, locale: edit.value.locale.trim(),
    };
    if (profile.value?.customer_type === 'individual') {
      payload.firstName = edit.value.firstName.trim();
      payload.lastName = edit.value.lastName.trim();
    }
    profile.value = (await customers.portalUpdate(payload)).data;
    notice.value = 'Deine Profildaten wurden gespeichert.';
  } catch (cause) {
    error.value = errorMessage(cause);
  } finally {
    saving.value = false;
  }
}
function openNewAddress() {
  editingAddressId.value = '';
  addressForm.value = emptyAddress();
  addressDialog.value = true;
}
function openEditAddress(address: Row) {
  editingAddressId.value = String(address.address_id);
  addressForm.value = {
    addressType: address.address_type ?? 'residential', label: address.label ?? '',
    line1: address.line1 ?? '', line2: address.line2 ?? '', postalCode: address.postal_code ?? '',
    city: address.city ?? '', region: address.region ?? '', countryCode: address.country_code ?? 'DE',
    isDefault: Boolean(address.is_default),
  };
  addressDialog.value = true;
}
async function saveAddress() {
  addressSaving.value = true;
  error.value = '';
  notice.value = '';
  try {
    const payload = addressPayload();
    if (editingAddressId.value) await customers.portalUpdateAddress(editingAddressId.value, payload);
    else await customers.portalCreateAddress(payload);
    addressDialog.value = false;
    notice.value = 'Deine Adresse wurde gespeichert.';
    await load();
  } catch (cause) {
    error.value = errorMessage(cause);
  } finally {
    addressSaving.value = false;
  }
}
async function confirmDeleteAddress() {
  if (!deletingAddress.value) return;
  addressSaving.value = true;
  error.value = '';
  try {
    await customers.portalDeleteAddress(String(deletingAddress.value.address_id));
    deleteDialog.value = false;
    deletingAddress.value = null;
    notice.value = 'Die Adresse wurde entfernt.';
    await load();
  } catch (cause) {
    error.value = errorMessage(cause);
  } finally {
    addressSaving.value = false;
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
    await load();
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
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable>{{ error }}</v-alert>
    <v-alert v-if="notice" type="success" variant="tonal" class="mb-4" closable>{{ notice }}</v-alert>
    <v-progress-linear v-if="loading" indeterminate color="primary" />
    <template v-if="profile">
      <v-row>
        <v-col cols="12" md="7">
          <v-card rounded="xl" border elevation="0">
            <v-card-title>{{ profile.customer_type === 'company' ? 'Unternehmen & Kontakt' : 'Persönliche Daten' }}</v-card-title>
            <v-card-text>
              <v-form @submit.prevent="save">
                <v-row dense>
                  <template v-if="profile.customer_type === 'individual'">
                    <v-col cols="12" sm="6"><v-text-field v-model="edit.firstName" label="Vorname" required /></v-col>
                    <v-col cols="12" sm="6"><v-text-field v-model="edit.lastName" label="Nachname" required /></v-col>
                  </template>
                  <v-col v-else cols="12"><v-text-field :model-value="profile.legal_name" label="Unternehmen" readonly /></v-col>
                  <v-col cols="12"><v-text-field v-model="edit.email" label="E-Mail" type="email" required /></v-col>
                  <v-col cols="12" sm="8"><v-text-field v-model="edit.phone" label="Telefon" /></v-col>
                  <v-col cols="12" sm="4"><v-text-field v-model="edit.locale" label="Sprache" /></v-col>
                </v-row>
                <v-btn color="primary" type="submit" :loading="saving">Änderungen speichern</v-btn>
              </v-form>
            </v-card-text>
          </v-card>
        </v-col>
        <v-col cols="12" md="5">
          <v-card rounded="xl" border elevation="0" class="mb-4">
            <v-card-title class="d-flex align-center">Meine Adressen <v-spacer /><v-btn size="small" prepend-icon="mdi-plus" color="primary" variant="tonal" @click="openNewAddress">Hinzufügen</v-btn></v-card-title>
            <v-list>
              <v-list-item v-for="address in addresses" :key="address.address_id" :title="address.label || address.address_type" :subtitle="address.line1 + ', ' + address.postal_code + ' ' + address.city + ', ' + address.country_code">
                <template #append>
                  <v-chip v-if="address.is_default" size="small" color="success" variant="tonal" class="mr-2">Standard</v-chip>
                  <v-btn icon="mdi-pencil-outline" size="small" variant="text" :aria-label="'Adresse bearbeiten ' + address.address_id" @click="openEditAddress(address)" />
                  <v-btn icon="mdi-delete-outline" size="small" variant="text" color="error" :aria-label="'Adresse entfernen ' + address.address_id" @click="deletingAddress = address; deleteDialog = true" />
                </template>
              </v-list-item>
              <v-list-item v-if="!addresses.length" title="Noch keine Adresse hinterlegt." />
            </v-list>
          </v-card>
          <v-card rounded="xl" border elevation="0">
            <v-card-title>Kommunikation</v-card-title>
            <v-card-text class="text-body-2 text-medium-emphasis">Du kannst deine freiwilligen Marketing-Einwilligungen jederzeit ändern. Vertrags- und Serviceinformationen sind davon unabhängig.</v-card-text>
            <v-list>
              <v-list-item title="Marketing per E-Mail"><template #append><v-switch :model-value="Boolean(consents.find(item => item.purpose === 'marketing.email')?.granted)" color="success" hide-details density="compact" aria-label="Marketing per E-Mail" @update:model-value="setConsent('marketing.email', Boolean($event))" /></template></v-list-item>
              <v-list-item title="Marketing per SMS"><template #append><v-switch :model-value="Boolean(consents.find(item => item.purpose === 'marketing.sms')?.granted)" color="success" hide-details density="compact" aria-label="Marketing per SMS" @update:model-value="setConsent('marketing.sms', Boolean($event))" /></template></v-list-item>
            </v-list>
          </v-card>
        </v-col>
      </v-row>
    </template>
    <v-card v-else-if="!loading && !error && unlinked" rounded="xl" border elevation="0"><v-card-text class="py-8 text-center">Dein Kundenkonto ist noch nicht mit deinem Login verbunden. Bitte wende dich an deinen AutoFlow-Berater.</v-card-text></v-card>

    <v-dialog v-model="addressDialog" max-width="720">
      <v-card rounded="xl">
        <v-card-title>{{ editingAddressId ? 'Adresse bearbeiten' : 'Adresse hinzufügen' }}</v-card-title>
        <v-card-text>
          <v-form @submit.prevent="saveAddress">
            <v-row dense>
              <v-col cols="12" sm="6"><v-select v-model="addressForm.addressType" label="Adresstyp" :items="[{title:'Privat',value:'residential'},{title:'Rechnung',value:'billing'},{title:'Lieferung',value:'shipping'},{title:'Geschäftlich',value:'business'}]" /></v-col>
              <v-col cols="12" sm="6"><v-text-field v-model="addressForm.label" label="Bezeichnung (optional)" /></v-col>
              <v-col cols="12"><v-text-field v-model="addressForm.line1" label="Straße und Hausnummer" required /></v-col>
              <v-col cols="12"><v-text-field v-model="addressForm.line2" label="Adresszusatz" /></v-col>
              <v-col cols="12" sm="4"><v-text-field v-model="addressForm.postalCode" label="Postleitzahl" required /></v-col>
              <v-col cols="12" sm="8"><v-text-field v-model="addressForm.city" label="Stadt" required /></v-col>
              <v-col cols="12" sm="8"><v-text-field v-model="addressForm.region" label="Region / Bundesland" /></v-col>
              <v-col cols="12" sm="4"><v-text-field v-model="addressForm.countryCode" label="Land (ISO)" maxlength="2" required /></v-col>
              <v-col cols="12"><v-checkbox v-model="addressForm.isDefault" label="Als Standardadresse für diesen Typ festlegen" hide-details /></v-col>
            </v-row>
            <div class="d-flex justify-end ga-2 mt-3"><v-btn variant="text" @click="addressDialog = false">Abbrechen</v-btn><v-btn color="primary" type="submit" :loading="addressSaving">Speichern</v-btn></div>
          </v-form>
        </v-card-text>
      </v-card>
    </v-dialog>
    <v-dialog v-model="deleteDialog" max-width="440">
      <v-card rounded="xl"><v-card-title>Adresse entfernen?</v-card-title><v-card-text>Die Adresse wird aus deinem Kundenkonto entfernt. Historische Vertragsdaten bleiben davon unberührt.</v-card-text><v-card-actions><v-spacer /><v-btn variant="text" @click="deleteDialog = false">Abbrechen</v-btn><v-btn color="error" variant="tonal" :loading="addressSaving" @click="confirmDeleteAddress">Entfernen</v-btn></v-card-actions></v-card>
    </v-dialog>
  </div>
</template>
