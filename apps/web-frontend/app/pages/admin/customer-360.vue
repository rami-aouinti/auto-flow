<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { billing, contracts, customers, fulfillments, orders, reservations, errorMessage } from '../../../src/api';
definePageMeta({ layout: 'default' });
useHead({ title: 'Customer 360 | AutoFlow' });
type Row = Record<string, any>;
const customersList = ref<Row[]>([]);
const selectedId = ref('');
const profile = ref<Row|null>(null);
const addresses = ref<Row[]>([]);
const consents = ref<Row[]>([]);
const history = ref<Row[]>([]);
const orderRows = ref<Row[]>([]);
const contractRows = ref<Row[]>([]);
const fulfillmentRows = ref<Row[]>([]);
const reservationRows = ref<Row[]>([]);
const invoiceRows = ref<Row[]>([]);
const loading = ref(false);
const saving = ref(false);
const identityBusy = ref(false);
const identitySubject = ref('');
const error = ref('');
const notice = ref('');
const search = ref('');
const edit = ref({ firstName:'', lastName:'', legalName:'', email:'', phone:'', locale:'de-DE' });
const selectedLabel = computed(() => profile.value?.customer_type === 'company' ? profile.value?.legal_name : [profile.value?.first_name,profile.value?.last_name].filter(Boolean).join(' '));
function list(data:any):Row[]{return Array.isArray(data)?data:Array.isArray(data?.items)?data.items:[];}
function field(row:Row,key:string){const camel=key.replace(/_([a-z])/g,(_,c:string)=>c.toUpperCase());return row?.[key]??row?.[camel];}
function fmt(value?:string){return value?new Date(value).toLocaleString('de-DE'):'—';}
function key(){return globalThis.crypto?.randomUUID?.()??`af-${Date.now()}`;}
async function loadCustomers(){try{customersList.value=list((await customers.list({limit:100,offset:0,status:'active'})).data);if(!selectedId.value&&customersList.value[0])selectedId.value=String(customersList.value[0].customer_id);}catch(e){error.value=errorMessage(e);}}
async function loadCustomer(){
 if(!selectedId.value)return;loading.value=true;error.value='';notice.value='';
 try{
  const id=selectedId.value;
  const [p,a,c,h,o,cs,fs,rs,ins]=await Promise.all([
   customers.get(id),customers.addresses(id),customers.consents(id),customers.history(id),
   orders.list(100,0),contracts.list(100,0),fulfillments.list(100,0),reservations.list(100,0),billing.invoices(100,0),
  ]);
  profile.value=p.data;addresses.value=list(a.data);consents.value=list(c.data);history.value=list(h.data);
  orderRows.value=list(o.data).filter(x=>field(x,'customer_id')===id);
  contractRows.value=list(cs.data).filter(x=>field(x,'customer_id')===id);
  fulfillmentRows.value=list(fs.data).filter(x=>orderRows.value.some(order=>field(order,'order_id')===field(x,'order_id')));
  reservationRows.value=list(rs.data).filter(x=>orderRows.value.some(order=>field(order,'order_id')===field(x,'order_id')));
  invoiceRows.value=list(ins.data).filter(x=>orderRows.value.some(order=>field(order,'order_id')===field(x,'order_id')));
  edit.value={firstName:p.data.first_name??'',lastName:p.data.last_name??'',legalName:p.data.legal_name??'',email:p.data.email??'',phone:p.data.phone??'',locale:p.data.locale??'de-DE'};
 }catch(e){error.value=errorMessage(e);}finally{loading.value=false;}
}
async function saveProfile(){
 if(!profile.value)return;saving.value=true;error.value='';notice.value='';
 try{const payload=profile.value.customer_type==='company'?{legalName:edit.value.legalName.trim(),email:edit.value.email.trim()||null,phone:edit.value.phone.trim()||null,locale:edit.value.locale}:{firstName:edit.value.firstName.trim(),lastName:edit.value.lastName.trim(),email:edit.value.email.trim()||null,phone:edit.value.phone.trim()||null,locale:edit.value.locale};
  profile.value=(await customers.update(selectedId.value,payload)).data;notice.value='Kundenprofil gespeichert.';
 }catch(e){error.value=errorMessage(e);}finally{saving.value=false;}
}
async function linkIdentity(){if(!selectedId.value||!identitySubject.value.trim())return;identityBusy.value=true;error.value='';notice.value='';try{await customers.linkIdentity(selectedId.value,identitySubject.value.trim());identitySubject.value='';notice.value='Keycloak-Konto wurde sicher mit diesem Kundenprofil verknüpft.';}catch(e){error.value=errorMessage(e);}finally{identityBusy.value=false;}}
async function consent(purpose:string,granted:boolean){try{await customers.setConsent(selectedId.value,{purpose,granted,source:'autoflow-customer-workspace'});await loadCustomer();notice.value='Einwilligung aktualisiert.';}catch(e){error.value=errorMessage(e);}}
watch(selectedId,()=>{void loadCustomer();});
onMounted(loadCustomers);
</script>
<template>
 <div>
  <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5"><div><div class="text-overline text-medium-emphasis">AUTOFLOW · CUSTOMER EXPERIENCE</div><h1 class="text-h4 font-weight-bold">Customer 360</h1><p class="text-body-2 text-medium-emphasis">Kundenprofil, laufende Aufträge, Dokumente, Kommunikationseinwilligungen und Serviceverlauf an einem Ort.</p></div><v-btn prepend-icon="mdi-refresh" variant="tonal" :loading="loading" @click="loadCustomer">Aktualisieren</v-btn></div>
  <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable>{{error}}</v-alert><v-alert v-if="notice" type="success" variant="tonal" class="mb-4">{{notice}}</v-alert>
  <v-card rounded="xl" border elevation="0" class="mb-5"><v-card-text><v-row align="center"><v-col cols="12" md="8"><v-autocomplete v-model="selectedId" :items="customersList" item-title="email" item-value="customer_id" label="Kunde auswählen" :loading="loading" clearable /></v-col><v-col cols="12" md="4" class="text-caption text-medium-emphasis">Tenant-scoped · Zugriff gemäß Keycloak-Berechtigungen</v-col></v-row></v-card-text></v-card>
  <v-progress-linear v-if="loading" indeterminate color="primary"/>
  <template v-if="profile">
   <v-row class="mb-3">
    <v-col cols="12" md="3"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Aufträge</div><div class="text-h4 font-weight-bold">{{orderRows.length}}</div></v-card-text></v-card></v-col>
    <v-col cols="12" md="3"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Verträge</div><div class="text-h4 font-weight-bold">{{contractRows.length}}</div></v-card-text></v-card></v-col>
    <v-col cols="12" md="3"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Lieferungen</div><div class="text-h4 font-weight-bold">{{fulfillmentRows.length}}</div></v-card-text></v-card></v-col>
    <v-col cols="12" md="3"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Rechnungen</div><div class="text-h4 font-weight-bold">{{invoiceRows.length}}</div></v-card-text></v-card></v-col>
   </v-row>
   <v-row>
    <v-col cols="12" md="5">
     <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Stammdaten · {{selectedLabel}}</v-card-title><v-card-text><v-form @submit.prevent="saveProfile">
      <template v-if="profile.customer_type==='company'"><v-text-field v-model="edit.legalName" label="Firma" required/></template>
      <template v-else><v-row><v-col cols="6"><v-text-field v-model="edit.firstName" label="Vorname" required/></v-col><v-col cols="6"><v-text-field v-model="edit.lastName" label="Nachname" required/></v-col></v-row></template>
      <v-text-field v-model="edit.email" label="E-Mail" type="email"/><v-text-field v-model="edit.phone" label="Telefon"/><v-text-field v-model="edit.locale" label="Sprache / Locale"/>
      <v-btn type="submit" color="primary" :loading="saving">Profil speichern</v-btn>
     </v-form></v-card-text></v-card>
     <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Keycloak-Konto verknüpfen</v-card-title><v-card-text><p class="text-body-2 text-medium-emphasis mb-3">Nur ein Administrator mit customers:manage kann den verifizierten Keycloak-Subject einem Kunden zuordnen.</p><v-text-field v-model="identitySubject" label="Keycloak Subject (sub)" hint="UUID aus dem Keycloak-Benutzerkonto" persistent-hint /><v-btn color="primary" variant="tonal" :loading="identityBusy" :disabled="!identitySubject.trim()" @click="linkIdentity">Konto verknüpfen</v-btn></v-card-text></v-card>
     <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Adressen</v-card-title><v-list><v-list-item v-for="address in addresses" :key="field(address,'address_id')" :title="address.label || address.address_type" :subtitle="`${address.line1}, ${address.postal_code} ${address.city}, ${address.country_code}`"><template #append><v-chip v-if="address.is_default" size="small" color="success">Standard</v-chip></template></v-list-item><v-list-item v-if="!addresses.length" title="Keine Adressen hinterlegt"/></v-list></v-card>
     <v-card rounded="xl" border elevation="0"><v-card-title>Einwilligungen</v-card-title><v-list><v-list-item v-for="purpose in ['marketing.email','marketing.sms']" :key="purpose" :title="purpose"><template #append><v-switch :model-value="consents.find(c=>c.purpose===purpose)?.granted ?? false" color="success" hide-details density="compact" @update:model-value="consent(purpose,Boolean($event))"/></template></v-list-item></v-list></v-card>
    </v-col>
    <v-col cols="12" md="7">
     <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Auftragsübersicht</v-card-title><v-table><thead><tr><th>Auftrag</th><th>Status</th><th>Fahrzeug</th><th>Erstellt</th><th/></tr></thead><tbody><tr v-for="o in orderRows" :key="field(o,'order_id')"><td>{{field(o,'order_id')}}</td><td><v-chip size="small" variant="tonal">{{field(o,'status')}}</v-chip></td><td>{{field(o,'vehicle_id')||'—'}}</td><td>{{fmt(field(o,'created_at'))}}</td><td><v-btn size="small" variant="text" :to="`/operations/order/${field(o,'order_id')}`">Dossier</v-btn></td></tr><tr v-if="!orderRows.length"><td colspan="5" class="text-center text-medium-emphasis">Keine Aufträge gefunden.</td></tr></tbody></v-table></v-card>
     <v-card rounded="xl" border elevation="0" class="mb-4"><v-card-title>Verträge & Dokumente</v-card-title><v-list><v-list-item v-for="c in contractRows" :key="field(c,'contract_id')" prepend-icon="mdi-file-sign" :title="field(c,'contract_id')" :subtitle="`${field(c,'status')} · ${field(c,'document_checksum')?'Dokument vorhanden':'Dokument noch nicht erstellt'}`" :to="{path:'/operations/workflow',query:{tab:'contracts'}}"/><v-list-item v-if="!contractRows.length" title="Keine Verträge vorhanden"/></v-list></v-card>
     <v-card rounded="xl" border elevation="0"><v-card-title>Service- und Auditverlauf</v-card-title><v-timeline v-if="history.length" density="compact" side="end"><v-timeline-item v-for="(event,i) in history" :key="field(event,'event_id')||i" size="small"><div class="font-weight-medium">{{field(event,'event_type')}}</div><div class="text-caption text-medium-emphasis">{{fmt(field(event,'occurred_at'))}}</div></v-timeline-item></v-timeline><v-card-text v-else class="text-medium-emphasis">Noch keine Kundenereignisse.</v-card-text></v-card>
    </v-col>
   </v-row>
  </template>
 </div>
</template>
