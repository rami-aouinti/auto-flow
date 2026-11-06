<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { catalog, errorMessage } from './api';

type Resource = 'manufacturers' | 'models' | 'generations' | 'variants';
type RecordRow = Record<string, any> & { id: string; status: string };
const resources: { key: Resource; label: string }[] = [
  { key: 'manufacturers', label: 'Fabricants' },
  { key: 'models', label: 'Modèles' },
  { key: 'generations', label: 'Générations' },
  { key: 'variants', label: 'Variantes' },
];
const resource = ref<Resource>('manufacturers');
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
watch(resource, () => { resetForm(); void load(); });
onMounted(() => { form.value = emptyForm(); void load(); });
</script>

<template>
  <section class="catalog-admin">
    <div class="admin-heading">
      <div><p class="eyebrow">AUTOFLOW · CATALOG ADMIN</p><h1>Fahrzeugkatalog <em>verwalten.</em></h1><p>Stammdaten für Hersteller, Modellreihen, Generationen und technische Varianten.</p></div>
      <a class="admin-back" href="/">← Zum Fahrzeugkatalog</a>
    </div>
    <div class="admin-tabs">
      <button v-for="item in resources" :key="item.key" :class="{ active: resource === item.key }" @click="resource = item.key">{{ item.label }}</button>
    </div>
    <p v-if="error" class="admin-error">{{ error }}</p>
    <p v-if="notice" class="admin-notice">{{ notice }}</p>
    <div class="admin-layout">
      <section class="admin-panel">
        <div class="admin-panel-title"><div><p class="eyebrow">{{ editingId ? 'BEARBEITEN' : 'NEUER EINTRAG' }}</p><h2>{{ editingId ? 'Eintrag ändern' : title + ' hinzufügen' }}</h2></div><button v-if="editingId" class="admin-secondary" @click="resetForm">Abbrechen</button></div>
        <form class="admin-form" @submit.prevent="save">
          <template v-if="resource === 'manufacturers'">
            <label>Code<input v-model.trim="form.code" required maxlength="40" placeholder="VOLKSWAGEN" /></label>
            <label>Name<input v-model.trim="form.name" required maxlength="120" placeholder="Volkswagen" /></label>
          </template>
          <template v-else-if="resource === 'models'">
            <label>Hersteller<select v-model="form.manufacturerId" required><option value="" disabled>Hersteller auswählen</option><option v-for="item in manufacturers" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
            <label>Modellname<input v-model.trim="form.name" required maxlength="120" placeholder="Golf" /></label>
          </template>
          <template v-else-if="resource === 'generations'">
            <label>Modell<select v-model="form.modelId" required><option value="" disabled>Modell auswählen</option><option v-for="item in models" :key="item.id" :value="item.id">{{ item.manufacturerName }} · {{ item.name }}</option></select></label>
            <label>Generation<input v-model.trim="form.name" required maxlength="80" placeholder="VIII" /></label>
            <div class="admin-two"><label>Baujahr ab<input v-model="form.productionStartYear" type="number" min="1886" max="2100" /></label><label>Baujahr bis<input v-model="form.productionEndYear" type="number" min="1886" max="2100" /></label></div>
          </template>
          <template v-else>
            <label>Generation<select v-model="form.generationId" required><option value="" disabled>Generation auswählen</option><option v-for="item in generations" :key="item.id" :value="item.id">{{ item.manufacturerName }} · {{ item.modelName }} · {{ item.name }}</option></select></label>
            <label>Variantenname<input v-model.trim="form.name" required maxlength="160" placeholder="2.0 TDI DSG" /></label>
            <label>Hersteller-Referenzcode<input v-model.trim="form.manufacturerCode" maxlength="64" placeholder="Optional" /></label>
            <div class="admin-two">
              <label>Kraftstoff<select v-model="form.fuelType"><option v-for="v in ['petrol','diesel','electric','hybrid','plug_in_hybrid','hydrogen','lpg','cng','other']" :key="v" :value="v">{{ v }}</option></select></label>
              <label>Antrieb<select v-model="form.powertrain"><option v-for="v in ['ice','mhev','hev','phev','bev','fcev','other']" :key="v" :value="v">{{ v }}</option></select></label>
              <label>Karosserie<select v-model="form.bodyStyle"><option v-for="v in ['suv','sedan','hatchback','estate','coupe','convertible','van','pickup','other']" :key="v" :value="v">{{ v }}</option></select></label>
              <label>Getriebe<select v-model="form.transmission"><option v-for="v in ['manual','automatic','single_speed','other']" :key="v" :value="v">{{ v }}</option></select></label>
            </div>
            <div class="admin-two">
              <label>Leistung (kW)<input v-model="form.powerKw" type="number" min="1" max="10000" /></label>
              <label>Hubraum (cc)<input v-model="form.displacementCc" type="number" min="1" max="20000" /></label>
              <label>Sitze<input v-model="form.seats" type="number" min="1" max="20" /></label>
              <label>Türen<input v-model="form.doors" type="number" min="1" max="8" /></label>
              <label>Gewicht (kg)<input v-model="form.curbWeightKg" type="number" min="1" /></label>
              <label>CO₂ (g/km)<input v-model="form.co2EmissionGPerKm" type="number" min="0" max="2000" /></label>
              <label>Länge (mm)<input v-model="form.lengthMm" type="number" min="1" /></label>
              <label>Breite (mm)<input v-model="form.widthMm" type="number" min="1" /></label>
              <label>Höhe (mm)<input v-model="form.heightMm" type="number" min="1" /></label>
            </div>
          </template>
          <label v-if="editingId">Status<select v-model="form.status"><option value="draft">draft</option><option value="published">published</option><option value="discontinued">discontinued</option><option value="archived">archived</option></select></label>
          <div class="admin-actions"><button class="admin-primary" :disabled="saving">{{ saving ? 'Speichern …' : editingId ? 'Änderungen speichern' : 'Eintrag erstellen' }}</button><button v-if="editingId" type="button" class="admin-secondary" @click="resetForm">Zurücksetzen</button></div>
        </form>
        <p class="admin-hint">Neue Datensätze werden zunächst als Entwurf angelegt. Archivieren ist ein Soft-Delete, damit bestehende Aufträge und Angebote ihre Fahrzeugreferenzen behalten.</p>
      </section>
      <section class="admin-panel">
        <div class="admin-panel-title"><div><p class="eyebrow">MASTER DATA</p><h2>{{ title }} <small>({{ total }})</small></h2></div><button class="admin-secondary" :disabled="loading" @click="load">↻ Aktualisieren</button></div>
        <p v-if="loading" class="admin-empty">Einträge werden geladen …</p>
        <p v-else-if="!rows.length" class="admin-empty">Noch keine Einträge vorhanden.</p>
        <div v-else class="admin-records">
          <article v-for="row in rows" :key="row.id" class="admin-record" :class="{ selected: editingId === row.id }">
            <div class="admin-record-main"><b>{{ displayName(row) }}</b><small>{{ row.manufacturerName || row.modelName || row.code || row.id }}</small><span class="admin-status">{{ row.status }}</span></div>
            <div class="admin-record-actions"><button @click="startEdit(row)">Bearbeiten</button><button class="archive" :disabled="row.status === 'archived'" @click="archive(row)">Archivieren</button></div>
          </article>
        </div>
        <p class="admin-hint">Die Administration benötigt den Keycloak-Scope <code>catalog:manage</code>. Der Backend-Service prüft die Berechtigung für jede Änderung.</p>
      </section>
    </div>
  </section>
</template>

<style scoped>
.catalog-admin{display:grid;gap:18px}.admin-heading{display:flex;justify-content:space-between;align-items:end;gap:20px}.admin-heading h1{font:800 clamp(32px,4vw,48px) Manrope;margin:0;letter-spacing:-2px}.admin-heading h1 em{font-style:normal;color:#82939b}.admin-heading p:last-child{font-size:11px;color:#91a1a9}.admin-back{color:#c4d0d5;text-decoration:none;font-size:11px;border:1px solid #40525c;padding:10px 12px;border-radius:7px}.admin-tabs{display:flex;gap:8px;flex-wrap:wrap}.admin-tabs button,.admin-secondary{background:transparent;border:1px solid #40525c;color:#c4d0d5;border-radius:7px;padding:9px 12px;font-size:10px;cursor:pointer}.admin-tabs button.active{background:var(--accent);border-color:var(--accent);color:#17232a}.admin-layout{display:grid;grid-template-columns:minmax(280px,.8fr) minmax(360px,1.2fr);gap:16px;align-items:start}.admin-panel{background:#18252e;border:1px solid var(--line);border-radius:12px;padding:20px;min-width:0}.admin-panel-title{display:flex;justify-content:space-between;align-items:center;gap:12px}.admin-panel-title h2{font:700 19px Manrope;margin:0}.admin-panel-title h2 small{color:#82939b;font-size:11px}.admin-form{display:grid;gap:12px;margin-top:18px}.admin-form label{display:grid;gap:6px;color:#aebbc1;font-size:10px}.admin-form input,.admin-form select{background:#101a22;border:1px solid #354751;border-radius:7px;padding:10px;color:#edf2f4;min-width:0;width:100%}.admin-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.admin-actions{display:flex;gap:8px}.admin-primary{background:var(--accent);border:0;color:#18232a;border-radius:7px;padding:11px 14px;font-size:10px;font-weight:700;cursor:pointer}.admin-primary:disabled{opacity:.5}.admin-hint,.admin-empty{font-size:10px;line-height:1.6;color:#91a1a9}.admin-records{display:grid;gap:8px;max-height:650px;overflow:auto;margin-top:12px}.admin-record{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:11px;background:#101a22;border:1px solid #2d404a;border-radius:8px}.admin-record.selected{border-color:var(--accent)}.admin-record-main{min-width:0;display:grid;gap:5px}.admin-record-main b{font-size:11px;overflow-wrap:anywhere}.admin-record-main small{font-size:9px;color:#82939b;overflow-wrap:anywhere}.admin-status{font-size:9px;color:#c5d2d7}.admin-record-actions{display:flex;gap:6px;flex-shrink:0}.admin-record-actions button{background:transparent;border:1px solid #40525c;color:#dce5e8;border-radius:5px;padding:6px 8px;font-size:9px;cursor:pointer}.admin-record-actions .archive{border-color:#74494c;color:#f3a6a9}.admin-error,.admin-notice{padding:11px;border-radius:7px;font-size:10px}.admin-error{background:#432c30;color:#f3a6a9}.admin-notice{background:#233a31;color:#b6e2c7}@media(max-width:900px){.admin-layout{grid-template-columns:1fr}.admin-heading{align-items:start;flex-direction:column}}@media(max-width:560px){.admin-panel{padding:14px}.admin-record{align-items:flex-start;flex-direction:column}.admin-two{grid-template-columns:1fr}.admin-record-actions{width:100%}}
</style>
