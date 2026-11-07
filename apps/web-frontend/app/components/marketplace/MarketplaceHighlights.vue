<script setup lang="ts">
type Manufacturer = { manufacturerId: string; name: string; code?: string };
defineProps<{ manufacturers: Manufacturer[] }>();
const emit = defineEmits<{
  manufacturer: [id: string];
  category: [key: 'fuelType' | 'bodyStyle', value: string];
}>();
const categories = [
  { title: 'Elektro & Hybrid', caption: 'Effizient unterwegs', icon: 'mdi-lightning-bolt', key: 'fuelType', value: 'electric', tone: 'electric' },
  { title: 'SUV & Crossover', caption: 'Platz für neue Wege', icon: 'mdi-car-estate', key: 'bodyStyle', value: 'suv', tone: 'suv' },
  { title: 'Kombis', caption: 'Raum für alles', icon: 'mdi-car-side', key: 'bodyStyle', value: 'estate', tone: 'estate' },
  { title: 'Automatik', caption: 'Entspannt fahren', icon: 'mdi-car-shift-pattern', key: 'transmission', value: 'automatic', tone: 'automatic' },
] as const;
</script>

<template>
  <section class="market-discovery">
    <div class="discovery-intro">
      <div><span class="eyebrow">SCHNELL ZUM PASSENDEN FAHRZEUG</span><h2>Wonach suchst du?</h2></div>
      <span class="intro-note">Entdecke den Katalog nach deinen Vorlieben.</span>
    </div>
    <div class="category-grid">
      <button v-for="item in categories" :key="item.title" class="category-tile" type="button"
        @click="item.key === 'transmission' ? emit('category', 'bodyStyle', '') : emit('category', item.key as 'fuelType' | 'bodyStyle', item.value)">
        <span class="category-icon"><v-icon :icon="item.icon" size="25" /></span>
        <span class="category-text"><strong>{{ item.title }}</strong><small>{{ item.caption }}</small></span>
        <v-icon class="category-arrow" icon="mdi-arrow-up-right" size="18" />
      </button>
    </div>

    <div class="brand-discovery">
      <div><span class="eyebrow">BELIEBTE HERSTELLER</span><h3>Marken entdecken</h3></div>
      <div class="brand-list">
        <button v-for="brand in manufacturers.slice(0, 8)" :key="brand.manufacturerId" type="button" @click="emit('manufacturer', brand.manufacturerId)">{{ brand.name }}</button>
        <a href="#fahrzeuge">Alle Marken <v-icon icon="mdi-arrow-right" size="14" /></a>
      </div>
    </div>

    <div id="so-funktionierts" class="benefit-strip">
      <article><v-icon icon="mdi-magnify" /><strong>Finden</strong><span>Suche Fahrzeuge nach Marke, Modell und Ausstattung.</span></article>
      <article><v-icon icon="mdi-scale-balance" /><strong>Vergleichen</strong><span>Behalte technische Merkmale und Varianten im Blick.</span></article>
      <article><v-icon icon="mdi-calculator-variant-outline" /><strong>Kalkulieren</strong><span>Berechne deine Rate über den AutoFlow Pricing Service.</span></article>
      <article><v-icon icon="mdi-file-document-check-outline" /><strong>Weitergehen</strong><span>Erstelle und verwalte deine Angebote digital.</span></article>
    </div>
  </section>
</template>

<style scoped>
.market-discovery{padding-top:34px}
.discovery-intro{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:16px}
.eyebrow{display:block;margin-bottom:7px;color:#668674;font-size:9px;font-weight:900;letter-spacing:1.35px}
.discovery-intro h2,.brand-discovery h3{margin:0;color:#172b40;font:800 clamp(23px,3vw,30px)/1.1 Manrope;letter-spacing:-.8px}
.intro-note{color:#8794a0;font-size:10px}
.category-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.category-tile{display:flex;align-items:center;gap:12px;min-width:0;padding:17px 15px;border:1px solid #e5eaee;border-radius:14px;background:#fff;text-align:left;cursor:pointer;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}
.category-tile:hover{transform:translateY(-3px);border-color:#b8c9c7;box-shadow:0 12px 24px rgba(25,51,73,.07)}
.category-icon{display:grid;place-items:center;width:43px;height:43px;flex:0 0 auto;border-radius:12px;background:#edf4ef;color:#2e6c53}
.category-text{display:grid;gap:4px;min-width:0}
.category-text strong{color:#19354e;font-size:11px;font-weight:800}
.category-text small{color:#8794a0;font-size:9px}
.category-arrow{margin-left:auto;color:#9aa7b1}
.brand-discovery{display:flex;align-items:center;justify-content:space-between;gap:22px;margin-top:35px;padding:23px 0;border-top:1px solid #e5eaee;border-bottom:1px solid #e5eaee}
.brand-discovery h3{font-size:20px}
.brand-list{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:8px}
.brand-list button,.brand-list a{display:inline-flex;align-items:center;gap:5px;padding:9px 12px;border:1px solid #e2e8ec;border-radius:999px;background:#fff;color:#526779;text-decoration:none;font-size:10px;font-weight:700;cursor:pointer}
.brand-list button:hover,.brand-list a:hover{border-color:#8eaa9b;color:#1b6b55}
.benefit-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;padding:27px 0 8px}
.benefit-strip article{display:grid;gap:7px;align-content:start;padding:15px 16px;border-radius:13px;background:#edf2f2}
.benefit-strip article :deep(.v-icon){color:#367257;font-size:22px}
.benefit-strip strong{color:#19354e;font:800 12px Manrope}
.benefit-strip span{color:#788895;font-size:10px;line-height:1.6}
@media(max-width:900px){.category-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.brand-discovery{align-items:flex-start;flex-direction:column}.brand-list{justify-content:flex-start}.benefit-strip{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.discovery-intro{align-items:flex-start;flex-direction:column}.category-grid{grid-template-columns:1fr}.category-tile{padding:13px}.brand-discovery{margin-top:25px}.benefit-strip{grid-template-columns:1fr 1fr;gap:8px}.benefit-strip article{padding:12px}.benefit-strip span{font-size:9px}}
</style>