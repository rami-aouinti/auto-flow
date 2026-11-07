<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { catalog, offers, errorMessage } from './api';

type Vehicle = Record<string, any> & { variantId: string; name: string };
type Offer = Record<string, any> & { offerId: string; status: string };

const route = useRoute();
const vehicles = ref<Vehicle[]>([]);
const manufacturers = ref<Array<{ manufacturerId: string; name: string; code?: string }>>([]);
const total = ref(0);
const loading = ref(false);
const catalogError = ref('');
const selected = ref<Vehicle | null>(null);
const selectedLoading = ref(false);
const showOffers = ref(false);
const pageSize = 12;
const offset = ref(0);
const sortBy = ref('relevance');
const requestSequence = ref(0);

const filters = reactive({
  q: '',
  manufacturerId: '',
  fuelType: '',
  bodyStyle: '',
  transmission: '',
  minPowerKw: '',
  maxPowerKw: '',
  minSeats: '',
});

const fuelOptions = [
  { title: 'Alle Antriebe', value: '' },
  { title: 'Benzin', value: 'petrol' },
  { title: 'Diesel', value: 'diesel' },
  { title: 'Elektro', value: 'electric' },
  { title: 'Hybrid', value: 'hybrid' },
  { title: 'Plug-in-Hybrid', value: 'plug_in_hybrid' },
  { title: 'Wasserstoff', value: 'hydrogen' },
];
const bodyOptions = [
  { title: 'Alle Karosserieformen', value: '' },
  { title: 'SUV / Geländewagen', value: 'suv' },
  { title: 'Limousine', value: 'sedan' },
  { title: 'Kompakt / Schrägheck', value: 'hatchback' },
  { title: 'Kombi', value: 'estate' },
  { title: 'Coupé', value: 'coupe' },
  { title: 'Cabrio', value: 'convertible' },
  { title: 'Van', value: 'van' },
  { title: 'Pickup', value: 'pickup' },
];
const transmissionOptions = [
  { title: 'Alle Getriebe', value: '' },
  { title: 'Automatik', value: 'automatic' },
  { title: 'Manuell', value: 'manual' },
  { title: 'Einstufig', value: 'single_speed' },
];
const sortOptions = [
  { title: 'Relevanz', value: 'relevance' },
  { title: 'Name A–Z', value: 'name' },
  { title: 'Leistung: hoch zuerst', value: 'power-desc' },
  { title: 'Leistung: niedrig zuerst', value: 'power-asc' },
];

const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize)));
const sortedVehicles = computed(() => {
  const copy = [...vehicles.value];
  if (sortBy.value === 'name') return copy.sort((a, b) => a.name.localeCompare(b.name, 'de'));
  if (sortBy.value === 'power-desc') return copy.sort((a, b) => Number(b.powerKw || 0) - Number(a.powerKw || 0));
  if (sortBy.value === 'power-asc') return copy.sort((a, b) => Number(a.powerKw || 0) - Number(b.powerKw || 0));
  return copy;
});
const activeFilters = computed(() => [
  filters.manufacturerId ? manufacturers.value.find((item) => item.manufacturerId === filters.manufacturerId)?.name : '',
  filters.fuelType ? label(filters.fuelType) : '',
  filters.bodyStyle ? label(filters.bodyStyle) : '',
  filters.transmission ? label(filters.transmission) : '',
  filters.minPowerKw ? `ab ${filters.minPowerKw} kW` : '',
  filters.maxPowerKw ? `bis ${filters.maxPowerKw} kW` : '',
  filters.minSeats ? `ab ${filters.minSeats} Sitze` : '',
].filter(Boolean));
const hasFilters = computed(() => Object.values(filters).some(Boolean));

const quote = ref<Record<string, any> | null>(null);
const quoteError = ref('');
const quoteBusy = ref(false);
const createdOffer = ref<Offer | null>(null);
const offerActionBusy = ref(false);
const offerActionError = ref('');
const pricingForm = reactive({
  vehiclePrice: '',
  downPayment: '0',
  residualValue: '0',
  currency: 'EUR',
  durationMonths: 36,
  annualInterestRateBasisPoints: 500,
});

const offerRows = ref<Offer[]>([]);
const offerTotal = ref(0);
const offerOffset = ref(0);
const offerStatus = ref('');
const offerSearch = ref('');
const offerLoading = ref(false);
const offerError = ref('');
const expandedOfferId = ref('');
const offerHistory = ref<Record<string, any[]>>({});
const offerHistoryLoading = ref('');
const offerHistoryError = ref('');

function label(value?: string) {
  if (!value) return '–';
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}
function money(value: string | number | undefined, currency = 'EUR') {
  if (value === undefined || value === null || value === '') return '–';
  return Number(value).toLocaleString('de-DE', { style: 'currency', currency });
}
function formatDate(value?: string) {
  return value ? new Date(value).toLocaleString('de-DE') : '–';
}
function statusLabel(status: string) {
  return ({
    draft: 'Entwurf', published: 'Veröffentlicht', accepted: 'Angenommen',
    rejected: 'Abgelehnt', expired: 'Abgelaufen', withdrawn: 'Zurückgezogen',
  } as Record<string, string>)[status] || label(status);
}
function pricingPayload() {
  return {
    ...pricingForm,
    vehiclePrice: Number(pricingForm.vehiclePrice).toFixed(2),
    downPayment: Number(pricingForm.downPayment).toFixed(2),
    residualValue: Number(pricingForm.residualValue).toFixed(2),
  };
}

async function loadVehicles(reset = false) {
  if (reset) offset.value = 0;
  const sequence = ++requestSequence.value;
  loading.value = true;
  catalogError.value = '';
  const params: Record<string, string | number> = { limit: pageSize, offset: offset.value };
  if (filters.q.trim()) params.q = filters.q.trim();
  if (filters.manufacturerId) params.manufacturerId = filters.manufacturerId;
  if (filters.fuelType) params.fuelType = filters.fuelType;
  if (filters.bodyStyle) params.bodyStyle = filters.bodyStyle;
  if (filters.transmission) params.transmission = filters.transmission;
  if (filters.minPowerKw) params.minPowerKw = Number(filters.minPowerKw);
  if (filters.maxPowerKw) params.maxPowerKw = Number(filters.maxPowerKw);
  if (filters.minSeats) params.minSeats = Number(filters.minSeats);
  try {
    const response = await catalog.list(params);
    if (sequence !== requestSequence.value) return;
    vehicles.value = response.data.items ?? [];
    total.value = Number(response.data.total ?? 0);
  } catch (error) {
    if (sequence === requestSequence.value) catalogError.value = errorMessage(error);
  } finally {
    if (sequence === requestSequence.value) loading.value = false;
  }
}
function clearFilters() {
  Object.assign(filters, {
    q: '', manufacturerId: '', fuelType: '', bodyStyle: '',
    transmission: '', minPowerKw: '', maxPowerKw: '', minSeats: '',
  });
  sortBy.value = 'relevance';
  void loadVehicles(true);
}
function quickFilter(key: 'fuelType' | 'bodyStyle', value: string) {
  filters[key] = filters[key] === value ? '' : value;
  void loadVehicles(true);
}
async function openVehicle(vehicle: Vehicle) {
  selectedLoading.value = true;
  catalogError.value = '';
  try {
    selected.value = (await catalog.detail(vehicle.variantId)).data;
    quote.value = null;
    quoteError.value = '';
    createdOffer.value = null;
    offerActionError.value = '';
    pricingForm.vehiclePrice = '';
    pricingForm.downPayment = '0';
    pricingForm.residualValue = '0';
  } catch (error) {
    catalogError.value = errorMessage(error);
  } finally {
    selectedLoading.value = false;
  }
}
function backToResults() {
  selected.value = null;
  quote.value = null;
  createdOffer.value = null;
}
async function calculateQuote() {
  if (!selected.value || Number(pricingForm.vehiclePrice) <= 0 || Number(pricingForm.downPayment) < 0 || Number(pricingForm.residualValue) < 0) {
    quoteError.value = 'Bitte gib einen gültigen Fahrzeugpreis sowie Anzahlung und Restwert an.';
    return;
  }
  quoteBusy.value = true;
  quoteError.value = '';
  try {
    quote.value = (await offers.quote(selected.value.variantId, pricingPayload())).data;
  } catch (error) {
    quoteError.value = errorMessage(error);
  } finally {
    quoteBusy.value = false;
  }
}
async function createOffer() {
  if (!selected.value) return;
  if (Number(pricingForm.vehiclePrice) <= 0) {
    offerActionError.value = 'Bitte zuerst einen gültigen Fahrzeugpreis eingeben.';
    return;
  }
  offerActionBusy.value = true;
  offerActionError.value = '';
  try {
    createdOffer.value = (await offers.create(selected.value.variantId, pricingPayload())).data;
    quote.value = createdOffer.value?.pricing ?? quote.value;
  } catch (error) {
    offerActionError.value = errorMessage(error);
  } finally {
    offerActionBusy.value = false;
  }
}
async function changeCreatedOffer(action: 'publish' | 'accept' | 'reject') {
  if (!createdOffer.value) return;
  offerActionBusy.value = true;
  offerActionError.value = '';
  try {
    createdOffer.value = (await offers[action](createdOffer.value.offerId)).data;
  } catch (error) {
    offerActionError.value = errorMessage(error);
  } finally {
    offerActionBusy.value = false;
  }
}
async function loadOffers() {
  offerLoading.value = true;
  offerError.value = '';
  try {
    const response = await offers.list({
      limit: 8,
      offset: offerOffset.value,
      status: offerStatus.value || undefined,
      search: offerSearch.value.trim() || undefined,
    });
    offerRows.value = response.data.items ?? [];
    offerTotal.value = Number(response.data.total ?? 0);
  } catch (error) {
    offerError.value = errorMessage(error);
  } finally {
    offerLoading.value = false;
  }
}
function openOffers() {
  showOffers.value = true;
  selected.value = null;
  offerOffset.value = 0;
  void loadOffers();
}
function openCatalogue() {
  showOffers.value = false;
  backToResults();
}
async function updateOffer(id: string, action: 'publish' | 'accept' | 'reject') {
  offerActionBusy.value = true;
  offerError.value = '';
  try {
    await offers[action](id);
    delete offerHistory.value[id];
    await loadOffers();
  } catch (error) {
    offerError.value = errorMessage(error);
  } finally {
    offerActionBusy.value = false;
  }
}
async function toggleOfferHistory(id: string) {
  if (expandedOfferId.value === id) {
    expandedOfferId.value = '';
    return;
  }
  expandedOfferId.value = id;
  offerHistoryError.value = '';
  if (offerHistory.value[id]) return;
  offerHistoryLoading.value = id;
  try {
    const response = await offers.history(id);
    offerHistory.value[id] = response.data.items ?? [];
  } catch (error) {
    offerHistoryError.value = errorMessage(error);
  } finally {
    offerHistoryLoading.value = '';
  }
}
async function downloadOfferPdf(id: string) {
  offerActionBusy.value = true;
  offerError.value = '';
  try {
    const response = await offers.downloadPdf(id);
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = `autoflow-offer-${id}.pdf`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (error) {
    offerError.value = errorMessage(error);
  } finally {
    offerActionBusy.value = false;
  }
}

onMounted(async () => {
  const requestedFuel = typeof route.query.fuelType === 'string' ? route.query.fuelType : '';
  if (fuelOptions.some((item) => item.value === requestedFuel)) filters.fuelType = requestedFuel;
  try {
    const response = await catalog.manufacturers();
    manufacturers.value = response.data.items ?? [];
  } catch {
    // The vehicle list remains usable if manufacturer suggestions cannot load.
  }
  await loadVehicles(true);
});
</script>

<template>
  <div class="marketplace-page">
    <v-container fluid class="market-container">
      <MarketplaceHero
        v-if="!selected && !showOffers"
        v-model="filters.q"
        :manufacturer-count="manufacturers.length"
        :vehicle-count="total"
        @search="loadVehicles(true)"
      />

      <template v-if="showOffers">
        <div class="section-heading offer-heading">
          <div>
            <div class="section-kicker">DEIN AUTOFLOW KONTO</div>
            <h1>Meine <span>Angebote.</span></h1>
            <p>Behalte deine kalkulierten und erstellten Angebote im Blick.</p>
          </div>
          <v-btn color="primary" variant="tonal" prepend-icon="mdi-arrow-left" @click="openCatalogue">Fahrzeuge entdecken</v-btn>
        </div>
        <v-card class="filter-bar offer-filters" rounded="xl" elevation="0">
          <v-text-field v-model="offerSearch" prepend-inner-icon="mdi-magnify" placeholder="Angebots-ID oder Fahrzeug suchen" hide-details density="comfortable" variant="outlined" @keyup.enter="offerOffset = 0; loadOffers()" />
          <v-select v-model="offerStatus" :items="[{title:'Alle Status',value:''},{title:'Entwurf',value:'draft'},{title:'Veröffentlicht',value:'published'},{title:'Angenommen',value:'accepted'},{title:'Abgelehnt',value:'rejected'},{title:'Abgelaufen',value:'expired'}]" item-title="title" item-value="value" hide-details density="comfortable" variant="outlined" @update:model-value="offerOffset = 0; loadOffers()" />
          <v-btn color="primary" prepend-icon="mdi-magnify" @click="offerOffset = 0; loadOffers()">Suchen</v-btn>
        </v-card>
        <v-alert v-if="offerError" type="error" variant="tonal" class="my-4" closable>{{ offerError }}</v-alert>
        <div v-if="offerLoading" class="state-panel"><v-progress-circular indeterminate color="primary" /><span>Angebote werden geladen …</span></div>
        <div v-else-if="!offerRows.length" class="state-panel"><v-icon icon="mdi-file-document-outline" size="42" /><strong>Noch keine Angebote vorhanden</strong><span>Entdecke ein Fahrzeug und berechne deine erste Rate.</span><v-btn color="primary" @click="openCatalogue">Fahrzeuge entdecken</v-btn></div>
        <div v-else class="offer-grid">
          <v-card v-for="offer in offerRows" :key="offer.offerId" class="offer-market-card" rounded="xl" elevation="0" border>
            <div class="offer-card-head"><span class="offer-ref">AUTOFLOW · {{ offer.offerId.slice(0, 8) }}</span><v-chip size="small" :color="offer.status === 'accepted' ? 'success' : offer.status === 'rejected' ? 'error' : 'primary'" variant="tonal">{{ statusLabel(offer.status) }}</v-chip></div>
            <h2>{{ offer.vehicleId }}</h2>
            <div class="offer-price"><strong>{{ money(offer.pricing?.monthlyAmount, offer.pricing?.currency) }}</strong><span>pro Monat</span></div>
            <div class="offer-facts"><div><span>Laufzeit</span><b>{{ offer.pricing?.durationMonths || '–' }} Monate</b></div><div><span>Erstellt am</span><b>{{ formatDate(offer.createdAt) }}</b></div><div><span>Gültig bis</span><b>{{ formatDate(offer.expiresAt) }}</b></div></div>
            <div class="offer-actions">
              <v-btn v-if="offer.status === 'draft'" size="small" color="primary" @click="updateOffer(offer.offerId, 'publish')" :disabled="offerActionBusy">Veröffentlichen</v-btn>
              <v-btn v-if="offer.status === 'published'" size="small" color="success" variant="tonal" @click="updateOffer(offer.offerId, 'accept')" :disabled="offerActionBusy">Annehmen</v-btn>
              <v-btn v-if="offer.status === 'published'" size="small" color="error" variant="text" @click="updateOffer(offer.offerId, 'reject')" :disabled="offerActionBusy">Ablehnen</v-btn>
              <v-spacer />
              <v-btn icon="mdi-file-pdf-box" size="small" variant="text" aria-label="PDF herunterladen" @click="downloadOfferPdf(offer.offerId)" :disabled="offerActionBusy" />
              <v-btn size="small" variant="text" append-icon="mdi-chevron-down" @click="toggleOfferHistory(offer.offerId)">{{ expandedOfferId === offer.offerId ? 'Schließen' : 'Verlauf' }}</v-btn>
            </div>
            <div v-if="expandedOfferId === offer.offerId" class="offer-history">
              <div v-if="offerHistoryLoading === offer.offerId" class="text-caption">Verlauf wird geladen …</div>
              <v-alert v-else-if="offerHistoryError" type="error" density="compact" variant="tonal">{{ offerHistoryError }}</v-alert>
              <div v-else-if="!offerHistory[offer.offerId]?.length" class="text-caption">Noch keine Ereignisse vorhanden.</div>
              <div v-for="event in offerHistory[offer.offerId]" :key="event.eventId" class="history-row">
                <v-icon icon="mdi-check-circle-outline" color="success" size="small" />
                <div><strong>{{ label(event.eventType) }}</strong><span>{{ formatDate(event.occurredAt) }}</span></div>
              </div>
            </div>
          </v-card>
        </div>
        <div v-if="offerTotal > 8" class="market-pagination">
          <v-btn variant="outlined" :disabled="offerOffset === 0" @click="offerOffset = Math.max(0, offerOffset - 8); loadOffers()">Zurück</v-btn>
          <span>{{ Math.floor(offerOffset / 8) + 1 }} / {{ Math.ceil(offerTotal / 8) }}</span>
          <v-btn variant="outlined" :disabled="offerOffset + 8 >= offerTotal" @click="offerOffset += 8; loadOffers()">Weiter</v-btn>
        </div>
      </template>

      <template v-else-if="selected">
        <div class="detail-back">
          <v-btn variant="text" prepend-icon="mdi-arrow-left" color="primary" @click="backToResults">Zurück zur Fahrzeugsuche</v-btn>
          <v-chip color="success" variant="tonal" prepend-icon="mdi-check-decagram-outline">Katalogdaten</v-chip>
        </div>
        <section class="vehicle-detail-hero">
          <div class="detail-visual"><v-icon icon="mdi-car-sports" /></div>
          <div class="detail-intro">
            <div class="section-kicker">{{ selected.manufacturer?.name }} · {{ selected.model?.name }}</div>
            <h1>{{ selected.name }}</h1>
            <p>{{ selected.generation?.name }} · {{ label(selected.bodyStyle) }}</p>
            <div class="detail-tags"><v-chip color="white" variant="outlined">{{ label(selected.fuelType) }}</v-chip><v-chip color="white" variant="outlined">{{ label(selected.transmission) }}</v-chip><v-chip color="white" variant="outlined">{{ selected.powerKw ? selected.powerKw + ' kW' : 'Leistung n. a.' }}</v-chip></div>
          </div>
        </section>
        <div class="detail-layout">
          <section class="detail-spec-panel">
            <div class="section-kicker">DAS FAHRZEUG IM ÜBERBLICK</div>
            <h2>Technische Daten</h2>
            <div class="spec-grid">
              <div><span>Hersteller</span><strong>{{ selected.manufacturer?.name || '–' }}</strong></div>
              <div><span>Modell</span><strong>{{ selected.model?.name || '–' }}</strong></div>
              <div><span>Generation</span><strong>{{ selected.generation?.name || '–' }}</strong></div>
              <div><span>Leistung</span><strong>{{ selected.powerKw ? selected.powerKw + ' kW' : '–' }}</strong></div>
              <div><span>Kraftstoff</span><strong>{{ label(selected.fuelType) }}</strong></div>
              <div><span>Antrieb</span><strong>{{ label(selected.powertrain) }}</strong></div>
              <div><span>Getriebe</span><strong>{{ label(selected.transmission) }}</strong></div>
              <div><span>Sitzplätze</span><strong>{{ selected.seats ?? '–' }}</strong></div>
              <div><span>Türen</span><strong>{{ selected.doors ?? '–' }}</strong></div>
              <div><span>Gewicht</span><strong>{{ selected.curbWeightKg ? selected.curbWeightKg + ' kg' : '–' }}</strong></div>
              <div><span>CO₂-Emission</span><strong>{{ selected.co2EmissionGPerKm !== null && selected.co2EmissionGPerKm !== undefined ? selected.co2EmissionGPerKm + ' g/km' : '–' }}</strong></div>
              <div><span>Hubraum</span><strong>{{ selected.displacementCc ? selected.displacementCc + ' cm³' : '–' }}</strong></div>
            </div>
            <v-alert class="catalog-disclaimer mt-6" type="info" variant="tonal" density="comfortable" icon="mdi-information-outline">
              Technische Katalogdaten. Preis, Verfügbarkeit und Liefertermin werden nicht durch den Fahrzeugkatalog verwaltet.
            </v-alert>
          </section>
          <aside class="calculator-panel">
            <div class="calculator-heading"><v-avatar color="primary" variant="tonal"><v-icon icon="mdi-calculator-variant-outline" /></v-avatar><div><div class="section-kicker">DEINE KALKULATION</div><h2>Rate berechnen</h2></div></div>
            <p class="calculator-copy">Gib deine Kalkulationsdaten ein. Der Pricing Service berechnet daraus ein individuelles Angebot.</p>
            <form class="calculator-form" @submit.prevent="calculateQuote">
              <v-text-field v-model="pricingForm.vehiclePrice" label="Fahrzeugpreis (€)" type="number" min="0.01" step="0.01" prefix="€" variant="outlined" density="comfortable" required />
              <div class="calculator-two">
                <v-text-field v-model="pricingForm.downPayment" label="Anzahlung (€)" type="number" min="0" step="0.01" prefix="€" variant="outlined" density="comfortable" />
                <v-text-field v-model="pricingForm.residualValue" label="Restwert (€)" type="number" min="0" step="0.01" prefix="€" variant="outlined" density="comfortable" />
              </div>
              <div class="calculator-two">
                <v-select v-model.number="pricingForm.durationMonths" label="Laufzeit" :items="[24,36,48,60]" suffix="Monate" variant="outlined" density="comfortable" />
                <v-text-field :model-value="(pricingForm.annualInterestRateBasisPoints / 100).toFixed(2)" label="Zins p.a." type="number" min="0" step="0.01" suffix="%" variant="outlined" density="comfortable" @update:model-value="pricingForm.annualInterestRateBasisPoints = Math.round(Number($event) * 100)" />
              </div>
              <v-btn type="submit" color="primary" size="large" block :loading="quoteBusy" prepend-icon="mdi-calculator">Monatsrate berechnen</v-btn>
              <v-btn type="button" color="secondary" variant="outlined" size="large" block :loading="offerActionBusy" prepend-icon="mdi-file-document-plus-outline" @click="createOffer">Angebot erstellen</v-btn>
            </form>
            <v-alert v-if="quoteError" type="error" variant="tonal" density="compact" class="mt-4">{{ quoteError }}</v-alert>
            <v-alert v-if="offerActionError" type="error" variant="tonal" density="compact" class="mt-4">{{ offerActionError }}</v-alert>
            <div v-if="quote" class="quote-result">
              <span>DEINE BERECHNETE MONATSRATE</span>
              <strong>{{ money(quote.monthlyAmount, quote.currency) }}</strong>
              <div><span>Gesamtkosten inkl. Anzahlung</span><b>{{ money(quote.totalCostIncludingDownPayment, quote.currency) }}</b></div>
            </div>
            <div v-if="createdOffer" class="created-offer-panel">
              <v-chip color="success" variant="tonal" size="small">Angebot angelegt</v-chip>
              <strong>{{ createdOffer.offerId }}</strong>
              <span>Status: {{ statusLabel(createdOffer.status) }}</span>
              <div class="offer-actions">
                <v-btn v-if="createdOffer.status === 'draft'" color="primary" size="small" @click="changeCreatedOffer('publish')" :disabled="offerActionBusy">Veröffentlichen</v-btn>
                <v-btn v-if="createdOffer.status === 'published'" color="success" size="small" @click="changeCreatedOffer('accept')" :disabled="offerActionBusy">Annehmen</v-btn>
                <v-btn v-if="createdOffer.status === 'published'" color="error" variant="text" size="small" @click="changeCreatedOffer('reject')" :disabled="offerActionBusy">Ablehnen</v-btn>
                <v-btn color="secondary" variant="text" size="small" @click="openOffers">Alle Angebote ansehen</v-btn>
              </div>
            </div>
          </aside>
        </div>
      </template>

      <template v-else>
        <section class="discovery-section" id="fahrzeuge">
          <div class="section-heading">
            <div><div class="section-kicker">ENTDECKE DEINE MÖGLICHKEITEN</div><h2>Finde deinen <span>Favoriten.</span></h2><p>Filtere den Fahrzeugkatalog nach den Kriterien, die dir wichtig sind.</p></div>
            <v-btn class="my-offers-button" variant="outlined" color="primary" prepend-icon="mdi-file-document-multiple-outline" @click="openOffers">Meine Angebote</v-btn>
          </div>
          <div class="quick-categories">
            <v-chip :color="filters.bodyStyle === 'suv' ? 'primary' : 'default'" :variant="filters.bodyStyle === 'suv' ? 'flat' : 'outlined'" prepend-icon="mdi-car-estate" @click="quickFilter('bodyStyle','suv')">SUV</v-chip>
            <v-chip :color="filters.fuelType === 'electric' ? 'primary' : 'default'" :variant="filters.fuelType === 'electric' ? 'flat' : 'outlined'" prepend-icon="mdi-lightning-bolt" @click="quickFilter('fuelType','electric')">Elektro</v-chip>
            <v-chip :color="filters.bodyStyle === 'estate' ? 'primary' : 'default'" :variant="filters.bodyStyle === 'estate' ? 'flat' : 'outlined'" prepend-icon="mdi-car-side" @click="quickFilter('bodyStyle','estate')">Kombi</v-chip>
            <v-chip :color="filters.transmission === 'automatic' ? 'primary' : 'default'" :variant="filters.transmission === 'automatic' ? 'flat' : 'outlined'" prepend-icon="mdi-car-shift-pattern" @click="filters.transmission = filters.transmission === 'automatic' ? '' : 'automatic'; loadVehicles(true)">Automatik</v-chip>
          </div>
          <div class="catalog-layout">
            <aside class="filter-sidebar">
              <div class="filter-title"><div><v-icon icon="mdi-filter-variant" color="primary" /><strong>Fahrzeuge filtern</strong></div><v-btn v-if="hasFilters" size="x-small" variant="text" color="primary" @click="clearFilters">Zurücksetzen</v-btn></div>
              <v-text-field v-model="filters.q" label="Marke, Modell oder Variante" prepend-inner-icon="mdi-magnify" variant="outlined" density="comfortable" hide-details @keyup.enter="loadVehicles(true)" />
              <v-select v-model="filters.manufacturerId" label="Marke" :items="[{title:'Alle Marken',value:''}, ...manufacturers.map((item) => ({title:item.name,value:item.manufacturerId}))]" item-title="title" item-value="value" variant="outlined" density="comfortable" hide-details />
              <v-select v-model="filters.fuelType" label="Kraftstoff" :items="fuelOptions" item-title="title" item-value="value" variant="outlined" density="comfortable" hide-details />
              <v-select v-model="filters.bodyStyle" label="Karosserie" :items="bodyOptions" item-title="title" item-value="value" variant="outlined" density="comfortable" hide-details />
              <v-select v-model="filters.transmission" label="Getriebe" :items="transmissionOptions" item-title="title" item-value="value" variant="outlined" density="comfortable" hide-details />
              <div class="filter-subtitle">Leistung</div>
              <div class="filter-range">
                <v-text-field v-model="filters.minPowerKw" label="Von kW" type="number" min="0" variant="outlined" density="comfortable" hide-details />
                <v-text-field v-model="filters.maxPowerKw" label="Bis kW" type="number" min="0" variant="outlined" density="comfortable" hide-details />
              </div>
              <v-select v-model="filters.minSeats" label="Mindestens Sitzplätze" :items="[{title:'Beliebig',value:''}, ...[2,4,5,6,7,8].map((value) => ({title:String(value),value:String(value)}))]" item-title="title" item-value="value" variant="outlined" density="comfortable" hide-details />
              <v-btn color="primary" block size="large" prepend-icon="mdi-magnify" @click="loadVehicles(true)">Fahrzeuge anzeigen</v-btn>
              <div v-if="hasFilters" class="active-filters"><span>Aktive Filter</span><v-chip size="small" color="primary" variant="tonal" @click="clearFilters">{{ activeFilters.length }} ausgewählt · Alle löschen</v-chip></div>
            </aside>

            <div class="results-column">
              <div class="results-toolbar">
                <div><strong>{{ total.toLocaleString('de-DE') }}</strong> Fahrzeuge gefunden <span v-if="loading">· Aktualisiere …</span></div>
                <v-select v-model="sortBy" :items="sortOptions" item-title="title" item-value="value" density="compact" variant="outlined" hide-details label="Sortieren" class="sort-select" />
              </div>
              <div v-if="activeFilters.length" class="active-filter-list">
                <v-chip v-for="filter in activeFilters" :key="filter" size="small" color="primary" variant="tonal">{{ filter }}</v-chip>
              </div>
              <v-alert v-if="catalogError" type="error" variant="tonal" class="mb-4" closable>{{ catalogError }} <template #append><v-btn size="small" variant="text" @click="loadVehicles()">Erneut versuchen</v-btn></template></v-alert>
              <div v-if="loading && !vehicles.length" class="state-panel"><v-progress-circular indeterminate color="primary" /><span>Wir suchen passende Fahrzeuge …</span></div>
              <div v-else-if="!sortedVehicles.length" class="state-panel"><v-icon icon="mdi-car-search" size="46" /><strong>Keine passenden Fahrzeuge gefunden</strong><span>Ändere deine Filter oder starte eine neue Suche.</span><v-btn color="primary" variant="tonal" @click="clearFilters">Alle Filter zurücksetzen</v-btn></div>
              <div v-else class="vehicle-grid">
                <VehicleCard v-for="vehicle in sortedVehicles" :key="vehicle.variantId" :vehicle="vehicle" @select="openVehicle" />
              </div>
              <div v-if="total > pageSize" class="market-pagination">
                <v-btn variant="outlined" prepend-icon="mdi-chevron-left" :disabled="offset === 0 || loading" @click="offset = Math.max(0, offset - pageSize); loadVehicles()">Zurück</v-btn>
                <span>Seite {{ Math.floor(offset / pageSize) + 1 }} von {{ pageCount }}</span>
                <v-btn variant="outlined" append-icon="mdi-chevron-right" :disabled="offset + pageSize >= total || loading" @click="offset += pageSize; loadVehicles()">Weiter</v-btn>
              </div>
              <div class="catalog-note"><v-icon icon="mdi-shield-check-outline" size="17" /> Fahrzeugdaten aus dem AutoFlow Vehicle Catalog. Preise und Verfügbarkeit werden separat ermittelt.</div>
            </div>
          </div>
        </section>
      </template>
    </v-container>
  </div>
</template>

<style scoped>
.marketplace-page{min-height:100%;background:#f5f7f8;color:#172b40;padding:28px 0 70px}
.market-container{max-width:1440px;margin:0 auto;padding:0 clamp(16px,4vw,56px)}
.discovery-section{padding-top:54px}
.section-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:22px}
.section-kicker{font-size:9px;font-weight:900;letter-spacing:1.6px;color:#678574;margin-bottom:9px}
.section-heading h1,.section-heading h2{margin:0;color:#172b40;font:800 clamp(29px,3.5vw,42px)/1.1 Manrope;letter-spacing:-1.5px}
.section-heading h1 span,.section-heading h2 span{color:#5d826e}
.section-heading p{margin:9px 0 0;color:#7c8b99;font-size:12px}
.my-offers-button{font-size:11px;font-weight:800;text-transform:none;letter-spacing:0}
.quick-categories{display:flex;flex-wrap:wrap;gap:9px;margin:0 0 19px}
.quick-categories :deep(.v-chip){height:36px;padding:0 14px;font-size:10px;font-weight:800;cursor:pointer;background:#fff;border-color:#e0e7eb}
.catalog-layout{display:grid;grid-template-columns:270px minmax(0,1fr);gap:23px;align-items:start}
.filter-sidebar{position:sticky;top:96px;display:grid;gap:15px;padding:20px;border:1px solid #e5eaee;border-radius:18px;background:#fff;box-shadow:0 8px 25px rgba(25,51,73,.035)}
.filter-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:2px}
.filter-title>div{display:flex;align-items:center;gap:8px}
.filter-title strong{font:800 14px Manrope;color:#19354e}
.filter-title :deep(.v-btn){font-size:9px}
.filter-sidebar :deep(.v-field){border-radius:10px;background:#fff}
.filter-sidebar :deep(.v-field__outline){--v-field-border-opacity:.16}
.filter-sidebar :deep(.v-label){font-size:11px}
.filter-subtitle{font-size:10px;font-weight:800;color:#506477;margin:1px 0 -7px}
.filter-range{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.filter-sidebar :deep(.v-btn){font-size:11px;font-weight:800;text-transform:none;letter-spacing:0}
.active-filters{display:grid;gap:8px;padding-top:12px;border-top:1px solid #edf0f2}
.active-filters>span{font-size:9px;color:#82909b;font-weight:800;text-transform:uppercase;letter-spacing:.8px}
.active-filters :deep(.v-chip){justify-self:start;font-size:9px}
.results-column{min-width:0}
.results-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:2px 0 15px;color:#718091;font-size:11px}
.results-toolbar strong{color:#19354e;font:800 22px Manrope;margin-right:4px}
.results-toolbar>div>span{color:#8a98a4}
.sort-select{max-width:190px;min-width:160px}
.sort-select :deep(.v-field){border-radius:10px;background:#fff}
.sort-select :deep(.v-field__input){font-size:10px}
.active-filter-list{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:15px}
.active-filter-list :deep(.v-chip){font-size:9px}
.vehicle-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:17px}
.state-panel{min-height:280px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:13px;padding:35px;border:1px dashed #d4dde3;border-radius:18px;background:#fff;text-align:center;color:#80909c}
.state-panel strong{font:800 17px Manrope;color:#19354e}
.state-panel span{font-size:11px}
.state-panel :deep(.v-btn){margin-top:5px;font-size:10px;text-transform:none}
.market-pagination{display:flex;align-items:center;justify-content:center;gap:18px;margin:28px 0;color:#6d7e8d;font-size:10px}
.market-pagination :deep(.v-btn){font-size:10px;text-transform:none}
.catalog-note{display:flex;align-items:center;justify-content:center;gap:7px;margin-top:22px;color:#85929d;font-size:9px}
.detail-back{display:flex;align-items:center;justify-content:space-between;margin:0 0 18px}
.detail-back :deep(.v-btn){font-size:11px;text-transform:none}
.vehicle-detail-hero{position:relative;display:flex;align-items:center;justify-content:space-between;min-height:260px;overflow:hidden;padding:36px 48px;border-radius:23px;background:linear-gradient(115deg,#102b43,#1c5360);color:white}
.detail-intro{position:relative;z-index:2}
.detail-intro .section-kicker{color:#c5e18c}
.detail-intro h1{margin:7px 0 8px;font:800 clamp(32px,4vw,48px) Manrope;letter-spacing:-1.8px}
.detail-intro p{margin:0;color:#c2d0d9;font-size:13px}
.detail-tags{display:flex;gap:8px;flex-wrap:wrap;margin-top:22px}
.detail-tags :deep(.v-chip){font-size:9px}
.detail-visual{position:absolute;right:6%;top:0;bottom:0;display:grid;place-items:center;width:38%;opacity:.2}
.detail-visual :deep(.v-icon){font-size:clamp(180px,24vw,320px)!important;color:#e5f2ef}
.detail-layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(340px,.8fr);gap:20px;align-items:start;margin-top:20px}
.detail-spec-panel,.calculator-panel{padding:27px;border:1px solid #e5eaee;border-radius:18px;background:#fff;box-shadow:0 8px 25px rgba(25,51,73,.035)}
.detail-spec-panel h2,.calculator-panel h2{margin:0;color:#19354e;font:800 21px Manrope}
.spec-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px 15px;margin-top:27px}
.spec-grid div{display:grid;gap:6px}
.spec-grid span{font-size:9px;color:#83919c}
.spec-grid strong{font-size:11px;color:#243c52}
.catalog-disclaimer{font-size:10px}
.calculator-heading{display:flex;align-items:center;gap:12px}
.calculator-heading .section-kicker{margin-bottom:4px}
.calculator-copy{margin:15px 0;color:#7b8995;font-size:11px;line-height:1.6}
.calculator-form{display:grid;gap:10px}
.calculator-form :deep(.v-field){border-radius:10px}
.calculator-form :deep(.v-field__input){font-size:12px}
.calculator-form :deep(.v-btn){font-size:11px;font-weight:800;text-transform:none;letter-spacing:0}
.calculator-two{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.quote-result{display:grid;gap:6px;margin-top:20px;padding:18px;border-radius:13px;background:#edf5ee}
.quote-result>span{font-size:8px;font-weight:900;letter-spacing:1px;color:#66816e}
.quote-result>strong{font:800 31px Manrope;color:#1c4939}
.quote-result>div{display:flex;justify-content:space-between;gap:12px;padding-top:9px;border-top:1px solid #d5e5d8;color:#657b6b;font-size:9px}
.quote-result b{color:#254d38}
.created-offer-panel{display:grid;gap:8px;margin-top:16px;padding:15px;border:1px solid #d9e9dc;border-radius:12px;background:#f6fbf6}
.created-offer-panel>strong{font:600 10px monospace;overflow-wrap:anywhere;color:#264c39}
.created-offer-panel>span{font-size:10px;color:#667c6c}
.offer-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:4px}
.offer-actions :deep(.v-btn){font-size:9px;text-transform:none}
.offer-heading{margin:35px 0 22px}
.offer-filters{display:grid;grid-template-columns:minmax(0,1fr) 220px auto;gap:12px;padding:15px;margin-bottom:20px}
.offer-filters :deep(.v-field){border-radius:10px}
.offer-filters :deep(.v-btn){font-size:10px;text-transform:none}
.offer-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.offer-market-card{padding:20px;border-color:#e5eaee!important}
.offer-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.offer-ref{font-size:8px;font-weight:900;letter-spacing:1px;color:#8795a1}
.offer-market-card h2{margin:16px 0 9px;font:800 17px Manrope;color:#1a354c}
.offer-price{display:flex;align-items:baseline;gap:7px;padding:12px 0;border-bottom:1px solid #edf0f2}
.offer-price strong{font:800 27px Manrope;color:#1c4939}
.offer-price span{font-size:10px;color:#81909b}
.offer-facts{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0}
.offer-facts div{display:grid;gap:5px}
.offer-facts span{font-size:8px;color:#85929e}
.offer-facts b{font-size:9px;color:#3d5265}
.offer-market-card .offer-actions{padding-top:10px;border-top:1px solid #edf0f2}
.offer-market-card .offer-actions :deep(.v-btn){font-size:9px}
.offer-history{display:grid;gap:10px;padding:14px 0 2px;border-top:1px solid #edf0f2}
.history-row{display:flex;align-items:center;gap:9px}
.history-row div{display:flex;flex:1;justify-content:space-between;gap:12px}
.history-row strong{font-size:9px;color:#4a5e70}
.history-row span{font-size:9px;color:#8a97a2}
@media(max-width:1150px){.vehicle-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.catalog-layout{grid-template-columns:250px minmax(0,1fr)}}
@media(max-width:900px){.catalog-layout{grid-template-columns:1fr}.filter-sidebar{position:static;grid-template-columns:repeat(2,minmax(0,1fr));align-items:end}.filter-title,.filter-subtitle,.filter-range,.active-filters{grid-column:1/-1}.detail-layout{grid-template-columns:1fr}.vehicle-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.marketplace-page{padding-top:14px}.discovery-section{padding-top:35px}.section-heading{align-items:flex-start;flex-direction:column}.section-heading h1,.section-heading h2{font-size:30px}.my-offers-button{width:100%}.filter-sidebar{grid-template-columns:1fr;padding:15px}.filter-title,.filter-subtitle,.filter-range,.active-filters{grid-column:auto}.vehicle-grid,.offer-grid{grid-template-columns:1fr}.results-toolbar{align-items:flex-start;flex-direction:column}.sort-select{max-width:none;width:100%}.vehicle-detail-hero{min-height:250px;padding:25px 20px}.detail-intro h1{font-size:34px}.detail-visual{right:-20%;width:70%;opacity:.12}.detail-layout{gap:13px}.detail-spec-panel,.calculator-panel{padding:19px}.spec-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 12px}.calculator-two{grid-template-columns:1fr}.offer-filters{grid-template-columns:1fr}.offer-heading{margin-top:20px}.offer-facts{grid-template-columns:1fr 1fr}.market-pagination{gap:9px}.market-pagination :deep(.v-btn){padding:0 10px}.catalog-note{align-items:flex-start;text-align:center;line-height:1.5}}
</style>