<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { catalog, errorMessage } from './api';
import PageHeader from '../app/components/shared/PageHeader.vue';
import MetricCard from '../app/components/shared/MetricCard.vue';

type Resource = 'manufacturers' | 'models' | 'generations' | 'variants';
const props = withDefaults(defineProps<{ initialResource?: Resource }>(), { initialResource: 'manufacturers' });
const route = useRoute();
const router = useRouter();
type RecordRow = Record<string, any> & { id: string; status: string };
const resources: { key: Resource; label: string }[] = [
  { key: 'manufacturers', label: 'Fabricants' },
  { key: 'models', label: 'Modèles' },
  { key: 'generations', label: 'Générations' },
  { key: 'variants', label: 'Variantes' },
];
const resource = ref<Resource>(props.initialResource);
const rows = ref<RecordRow[]>([]);
const manufacturers = ref<RecordRow[]>([]);
const models = ref<RecordRow[]>([]);
const generations = ref<RecordRow[]>([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const editingId = ref('');
const form = ref<Record<string, any>>({});
const total = ref(0);
const limit = 100;
const title = computed(() => resources.find((item) => item.key === resource.value)?.label ?? 'Catalogue');
const search = ref('');
const filteredRows = computed(() => {
  const term = search.value.trim().toLocaleLowerCase('de');
  if (!term) return rows.value;
  return rows.value.filter((row) => Object.values(row).some((value) => String(value ?? '').toLocaleLowerCase('de').includes(term)));
});

function emptyForm(): Record<string, any> {
  if (resource.value === 'manufacturers') return { code: '', name: '', status: 'draft' };
  if (resource.value === 'models') return { manufacturerId: '', name: '', status: 'draft' };
  if (resource.value === 'generations') return { modelId: '', name: '', productionStartYear: '', productionEndYear: '', status: 'draft' };
  return {
    generationId: '', manufacturerCode: '', name: '', fuelType: 'petrol', powertrain: 'ice',
    bodyStyle: 'sedan', transmission: 'manual', powerKw: '', displacementCc: '',
    seats: '', doors: '', curbWeightKg: '', lengthMm: '', widthMm: '', heightMm: '',
    co2EmissionGPerKm: '', mediaReferences: [], status: 'draft',
  };
}
function resetForm() { editingId.value = ''; form.value = emptyForm(); }
function cleanPayload(): Record<string, unknown> {
  const allowed: Record<Resource, string[]> = {
    manufacturers: ['code', 'name'],
    models: ['manufacturerId', 'name'],
    generations: ['modelId', 'name', 'productionStartYear', 'productionEndYear'],
    variants: [
      'generationId', 'manufacturerCode', 'name', 'fuelType', 'powertrain', 'bodyStyle', 'transmission',
      'powerKw', 'displacementCc', 'seats', 'doors', 'curbWeightKg', 'lengthMm', 'widthMm', 'heightMm',
      'co2EmissionGPerKm', 'mediaReferences',
    ],
  };
  const payload: Record<string, unknown> = {};
  for (const key of allowed[resource.value]) payload[key] = form.value[key];

  if (editingId.value) payload.status = form.value.status;

  if (resource.value === 'generations') {
    payload.productionStartYear = payload.productionStartYear === '' ? null : Number(payload.productionStartYear);
    payload.productionEndYear = payload.productionEndYear === '' ? null : Number(payload.productionEndYear);
  }
  if (resource.value === 'variants') {
    for (const key of ['powerKw','displacementCc','seats','doors','curbWeightKg','lengthMm','widthMm','heightMm','co2EmissionGPerKm']) {
      payload[key] = payload[key] === '' || payload[key] === undefined ? null : Number(payload[key]);
    }
    if (typeof payload.manufacturerCode === 'string' && payload.manufacturerCode.trim() === '') payload.manufacturerCode = null;
  }
  return payload;
}
async function loadParents() {
  try {
    const [m, mo, g] = await Promise.all([
      catalog.adminList('manufacturers', 100, 0),
      catalog.adminList('models', 100, 0),
      catalog.adminList('generations', 100, 0),
    ]);
    manufacturers.value = m.data.items ?? [];
    models.value = mo.data.items ?? [];
    generations.value = g.data.items ?? [];
  } catch {
    // Parent options are loaded again when the selected resource is refreshed.
  }
}
async function load() {
  loading.value = true; error.value = ''; notice.value = '';
  try {
    const response = await catalog.adminList(resource.value, limit, 0);
    rows.value = response.data.items ?? [];
    total.value = response.data.total ?? rows.value.length;
    await loadParents();
  } catch (e) { error.value = errorMessage(e); }
  finally { loading.value = false; }
}
function startEdit(row: RecordRow) {
  editingId.value = row.id;
  const source: Record<string, any> = { ...row };
  delete source.id;
  if (resource.value === 'generations') {
    source.productionStartYear ??= '';
    source.productionEndYear ??= '';
  }
  if (resource.value === 'variants') {
    for (const key of ['powerKw','displacementCc','seats','doors','curbWeightKg','lengthMm','widthMm','heightMm','co2EmissionGPerKm']) source[key] ??= '';
    if (typeof source.mediaReferences === 'string') {
      try { source.mediaReferences = JSON.parse(source.mediaReferences); } catch { source.mediaReferences = []; }
    }
  }
  form.value = { ...emptyForm(), ...source };
  notice.value = '';
}
async function save() {
  saving.value = true; error.value = ''; notice.value = '';
  try {
    const payload = cleanPayload();
    if (editingId.value) {
      await catalog.adminUpdate(resource.value, editingId.value, payload);
      notice.value = 'Eintrag aktualisiert.';
    } else {
      if (resource.value === 'manufacturers') await catalog.createManufacturer(payload as { code: string; name: string });
      else if (resource.value === 'models') await catalog.createModel(payload as { manufacturerId: string; name: string });
      else if (resource.value === 'generations') await catalog.createGeneration(payload);
      else await catalog.createVariant(payload);
      notice.value = 'Eintrag angelegt (Entwurf).';
    }
    resetForm();
    await load();
  } catch (e) { error.value = errorMessage(e); }
  finally { saving.value = false; }
}
async function archive(row: RecordRow) {
  if (!window.confirm('Diesen Eintrag archivieren? Referenzen bleiben erhalten.')) return;
  error.value = ''; notice.value = '';
  try {
    await catalog.adminArchive(resource.value, row.id);
    notice.value = 'Eintrag archiviert.';
    if (editingId.value === row.id) resetForm();
    await load();
  } catch (e) { error.value = errorMessage(e); }
}
function displayName(row: RecordRow) {
  return row.name || row.code || row.id;
}
watch(resource, (value) => {
  resetForm();
  search.value = '';
  void load();
  if (route.path.startsWith('/admin/catalog') && route.path !== `/admin/catalog/${value}`) {
    void router.replace(`/admin/catalog/${value}`);
  }
});
watch(() => props.initialResource, (value) => { resource.value = value; });
onMounted(() => { form.value = emptyForm(); void load(); });
</script>

<template>
  <div class="catalog-admin-page">
    <PageHeader eyebrow="STAMMDATEN · VEHICLE CATALOG" title="Fahrzeugkatalog" description="Verwalte die Datenhierarchie von Herstellern bis zu technischen Fahrzeugvarianten.">
      <template #actions><v-btn to="/admin" variant="tonal" prepend-icon="mdi-arrow-left" color="primary">Zur Übersicht</v-btn></template>
    </PageHeader>

    <v-row class="mb-2">
      <v-col cols="12" sm="4"><MetricCard label="Aktueller Bereich" :value="title" caption="Gewählter Datentyp" icon="mdi-domain" tone="primary" /></v-col>
      <v-col cols="12" sm="4"><MetricCard label="Gefundene Einträge" :value="total.toLocaleString('de-DE')" caption="Gesamt im Service" icon="mdi-database-outline" tone="info" /></v-col>
      <v-col cols="12" sm="4"><MetricCard label="Datenintegrität" value="Referenzsicher" caption="Archivierung statt Hard Delete" icon="mdi-shield-check-outline" tone="success" /></v-col>
    </v-row>

    <v-card rounded="xl" border elevation="0" class="overflow-hidden">
      <div class="catalog-toolbar">
        <div class="px-5 pt-5">
          <div class="text-overline text-medium-emphasis font-weight-bold">MASTER DATA MANAGEMENT</div>
          <div class="text-h6 font-weight-bold">Katalogstruktur</div>
          <div class="text-caption text-medium-emphasis mt-1">Wähle einen Datentyp, um Einträge zu durchsuchen oder zu bearbeiten.</div>
        </div>
        <v-tabs v-model="resource" color="primary" class="catalog-tabs px-3 mt-3" show-arrows>
          <v-tab v-for="item in resources" :key="item.key" :value="item.key" :prepend-icon="item.key === 'manufacturers' ? 'mdi-domain' : item.key === 'models' ? 'mdi-car-side' : item.key === 'generations' ? 'mdi-calendar-range' : 'mdi-cog-outline'">{{ item.label }}</v-tab>
        </v-tabs>
      </div>
      <v-divider />
      <v-alert v-if="error" type="error" variant="tonal" density="comfortable" class="ma-4">{{ error }}</v-alert>
      <v-alert v-if="notice" type="success" variant="tonal" density="comfortable" class="ma-4">{{ notice }}</v-alert>

      <v-row class="ma-0">
        <v-col cols="12" xl="4" class="pa-4 pa-md-6">
          <div class="d-flex align-center justify-space-between mb-4">
            <div><div class="text-overline text-medium-emphasis font-weight-bold">{{ editingId ? 'DATENSATZ BEARBEITEN' : 'NEUER DATENSATZ' }}</div><div class="text-h6 font-weight-bold">{{ editingId ? 'Eintrag bearbeiten' : title + ' hinzufügen' }}</div></div>
            <v-btn v-if="editingId" icon="mdi-close" variant="text" size="small" aria-label="Bearbeitung abbrechen" @click="resetForm" />
          </div>
          <form class="d-grid ga-3" @submit.prevent="save">
            <template v-if="resource === 'manufacturers'">
              <v-text-field v-model.trim="form.code" label="Hersteller-Code" placeholder="VOLKSWAGEN" maxlength="40" prepend-inner-icon="mdi-identifier" required />
              <v-text-field v-model.trim="form.name" label="Herstellername" placeholder="Volkswagen" maxlength="120" prepend-inner-icon="mdi-domain" required />
            </template>
            <template v-else-if="resource === 'models'">
              <v-select v-model="form.manufacturerId" :items="manufacturers" item-title="name" item-value="id" label="Hersteller" prepend-inner-icon="mdi-domain" required />
              <v-text-field v-model.trim="form.name" label="Modellname" placeholder="Golf" prepend-inner-icon="mdi-car-side" required />
            </template>
            <template v-else-if="resource === 'generations'">
              <v-select v-model="form.modelId" :items="models" :item-title="(item: any) => [item.manufacturerName, item.name].filter(Boolean).join(' · ')" item-value="id" label="Modell" prepend-inner-icon="mdi-car-side" required />
              <v-text-field v-model.trim="form.name" label="Generationsbezeichnung" placeholder="VIII" required />
              <v-row dense>
                <v-col cols="6"><v-text-field v-model="form.productionStartYear" label="Baujahr ab" type="number" min="1886" max="2100" /></v-col>
                <v-col cols="6"><v-text-field v-model="form.productionEndYear" label="Baujahr bis" type="number" min="1886" max="2100" /></v-col>
              </v-row>
            </template>
            <template v-else>
              <v-select v-model="form.generationId" :items="generations" :item-title="(item: any) => [item.manufacturerName, item.modelName, item.name].filter(Boolean).join(' · ')" item-value="id" label="Modellgeneration" prepend-inner-icon="mdi-car-cog" required />
              <v-text-field v-model.trim="form.name" label="Variantenname" placeholder="2.0 TDI DSG" required />
              <v-text-field v-model.trim="form.manufacturerCode" label="Hersteller-Referenzcode" />
              <div class="text-subtitle-2 font-weight-bold mt-1">Antrieb & Fahrzeugtyp</div>
              <v-row dense>
                <v-col cols="6"><v-select v-model="form.fuelType" :items="['petrol','diesel','electric','hybrid','plug_in_hybrid','hydrogen','lpg','cng','other']" label="Kraftstoff" /></v-col>
                <v-col cols="6"><v-select v-model="form.powertrain" :items="['ice','mhev','hev','phev','bev','fcev','other']" label="Antriebsart" /></v-col>
                <v-col cols="6"><v-select v-model="form.bodyStyle" :items="['suv','sedan','hatchback','estate','coupe','convertible','van','pickup','other']" label="Karosserie" /></v-col>
                <v-col cols="6"><v-select v-model="form.transmission" :items="['manual','automatic','single_speed','other']" label="Getriebe" /></v-col>
              </v-row>
              <div class="text-subtitle-2 font-weight-bold mt-1">Technische Daten</div>
              <v-row dense>
                <v-col cols="6"><v-text-field v-model="form.powerKw" label="Leistung (kW)" type="number" min="1" /></v-col>
                <v-col cols="6"><v-text-field v-model="form.displacementCc" label="Hubraum (cc)" type="number" min="1" /></v-col>
                <v-col cols="6"><v-text-field v-model="form.seats" label="Sitze" type="number" min="1" /></v-col>
                <v-col cols="6"><v-text-field v-model="form.doors" label="Türen" type="number" min="1" /></v-col>
                <v-col cols="6"><v-text-field v-model="form.curbWeightKg" label="Gewicht (kg)" type="number" min="1" /></v-col>
                <v-col cols="6"><v-text-field v-model="form.co2EmissionGPerKm" label="CO₂ (g/km)" type="number" min="0" /></v-col>
                <v-col cols="4"><v-text-field v-model="form.lengthMm" label="Länge" type="number" min="1" /></v-col>
                <v-col cols="4"><v-text-field v-model="form.widthMm" label="Breite" type="number" min="1" /></v-col>
                <v-col cols="4"><v-text-field v-model="form.heightMm" label="Höhe" type="number" min="1" /></v-col>
              </v-row>
            </template>
            <v-select v-if="editingId" v-model="form.status" :items="['draft','published','discontinued','archived']" label="Status" />
            <div class="d-flex flex-wrap ga-2 mt-2">
              <v-btn type="submit" color="primary" :loading="saving" prepend-icon="mdi-content-save-outline">{{ editingId ? 'Änderungen speichern' : 'Eintrag erstellen' }}</v-btn>
              <v-btn v-if="editingId" variant="text" @click="resetForm">Abbrechen</v-btn>
            </div>
          </form>
          <v-alert type="info" variant="tonal" density="compact" class="mt-5 text-caption">Neue Datensätze werden als Entwurf angelegt. Archivieren ist ein Soft-Delete, damit bestehende Angebote und Aufträge ihre Referenzen behalten.</v-alert>
        </v-col>

        <v-divider vertical class="d-none d-xl-flex" />
        <v-col cols="12" xl="8" class="pa-4 pa-md-6 catalog-list-column">
          <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-4">
            <div><div class="text-overline text-medium-emphasis font-weight-bold">DATENSATZÜBERSICHT</div><div class="text-h6 font-weight-bold">{{ title }} <span class="text-medium-emphasis">({{ total }})</span></div></div>
            <v-btn variant="tonal" color="primary" prepend-icon="mdi-refresh" :loading="loading" @click="load">Aktualisieren</v-btn>
          </div>
          <v-text-field v-model="search" label="Einträge durchsuchen" placeholder="Name, Code oder ID ..." prepend-inner-icon="mdi-magnify" clearable hide-details class="mb-4" />
          <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-3" />
          <div v-if="!loading && filteredRows.length === 0" class="empty-state">
            <v-icon icon="mdi-database-search-outline" size="42" color="medium-emphasis" />
            <div class="text-subtitle-1 font-weight-bold mt-3">{{ rows.length ? 'Keine passenden Einträge' : 'Noch keine Einträge vorhanden' }}</div>
            <div class="text-body-2 text-medium-emphasis mt-1">{{ rows.length ? 'Passe deinen Suchbegriff an.' : 'Lege den ersten Datensatz über das Formular an.' }}</div>
          </div>
          <v-table v-else density="comfortable" hover class="catalog-table">
            <thead><tr><th>Eintrag</th><th>Zuordnung</th><th>Status</th><th class="text-right">Aktionen</th></tr></thead>
            <tbody>
              <tr v-for="row in filteredRows" :key="row.id" :class="{ 'selected-row': editingId === row.id }">
                <td><div class="font-weight-bold">{{ displayName(row) }}</div><div class="text-caption text-medium-emphasis text-truncate record-id">{{ row.id }}</div></td>
                <td><span class="text-body-2">{{ row.manufacturerName || row.modelName || row.code || '—' }}</span></td>
                <td><v-chip size="small" :color="row.status === 'published' ? 'success' : row.status === 'archived' ? 'default' : 'warning'" variant="tonal">{{ row.status || 'unknown' }}</v-chip></td>
                <td class="text-right text-no-wrap">
                  <v-btn icon="mdi-pencil-outline" size="small" variant="text" color="primary" :aria-label="'Bearbeiten: ' + displayName(row)" @click="startEdit(row)" />
                  <v-btn icon="mdi-archive-outline" size="small" variant="text" color="error" :disabled="row.status === 'archived'" :aria-label="'Archivieren: ' + displayName(row)" @click="archive(row)" />
                </td>
              </tr>
            </tbody>
          </v-table>
          <div class="d-flex align-center justify-space-between mt-4 flex-wrap ga-2">
            <span class="text-caption text-medium-emphasis">Anzeige: {{ filteredRows.length }} von {{ total }} geladenen Datensätzen</span>
            <v-chip size="small" color="info" variant="tonal" prepend-icon="mdi-lock-check-outline">Scope: catalog:manage</v-chip>
          </div>
        </v-col>
      </v-row>
    </v-card>
  </div>
</template>

<style scoped>
.catalog-admin-page{max-width:1500px;margin:0 auto}
.catalog-toolbar{background:rgba(var(--v-theme-surface-variant),.18)}
.catalog-tabs{min-height:54px}
.catalog-list-column{min-width:0}
.catalog-table{background:transparent}
.catalog-table th{font-size:10px!important;font-weight:800!important;letter-spacing:.6px;color:rgba(var(--v-theme-on-surface),.65)!important;text-transform:uppercase}
.catalog-table td{height:66px!important}
.record-id{max-width:260px}
.selected-row{background:rgba(var(--v-theme-primary),.07)}
.empty-state{min-height:260px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;border:1px dashed rgba(var(--v-theme-on-surface),.2);border-radius:14px;padding:25px}
:deep(.v-field){border-radius:10px}
@media(max-width:600px){.catalog-tabs :deep(.v-tab){font-size:10px;padding-inline:10px}}
</style>
