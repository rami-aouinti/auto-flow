<script setup lang="ts">
const props = defineProps<{ modelValue: string; manufacturerCount: number; vehicleCount: number }>();
const emit = defineEmits<{ 'update:modelValue': [value: string]; search: [] }>();
</script>

<template>
  <section class="market-hero">
    <div class="hero-copy">
      <v-chip class="hero-kicker" color="primary" variant="tonal" size="small" prepend-icon="mdi-sparkles">
        DEIN NÄCHSTES KAPITEL BEGINNT HIER
      </v-chip>
      <h1>Finde das Auto,<br /><span>das zu dir passt.</span></h1>
      <p>Entdecke Modelle, vergleiche Ausstattungen und berechne deine individuelle Rate – transparent und Schritt für Schritt.</p>
      <div class="hero-proof">
        <div><v-icon icon="mdi-check-circle" color="success" size="18" /><span>Markenübergreifender Katalog</span></div>
        <div><v-icon icon="mdi-calculator-variant-outline" color="success" size="18" /><span>Individuelle Ratenberechnung</span></div>
      </div>
      <div class="hero-search">
        <v-icon icon="mdi-magnify" size="24" color="primary" />
        <v-text-field
          :model-value="props.modelValue"
          hide-details
          variant="plain"
          placeholder="Welche Marke oder welches Modell suchst du?"
          aria-label="Fahrzeuge suchen"
          @update:model-value="emit('update:modelValue', String($event ?? ''))"
          @keyup.enter="emit('search')"
        />
        <v-btn color="primary" class="search-button" size="large" append-icon="mdi-arrow-right" @click="emit('search')">Fahrzeuge finden</v-btn>
      </div>
      <div class="hero-metrics">
        <div><strong>{{ manufacturerCount || '–' }}</strong><span>Marken</span></div>
        <div><strong>{{ vehicleCount.toLocaleString('de-DE') }}</strong><span>Fahrzeugvarianten</span></div>
      </div>
    </div>
    <div class="hero-art" aria-hidden="true">
      <div class="art-orbit orbit-one"></div>
      <div class="art-orbit orbit-two"></div>
      <div class="art-glow"></div>
      <div class="art-label"><span>AUF DER SUCHE?</span><strong>Dein Auto.<br/>Deine Entscheidung.</strong></div>
      <div class="art-car"><v-icon icon="mdi-car-sports" /></div>
      <div class="art-road"></div>
      <div class="art-tag"><v-icon icon="mdi-lightning-bolt" size="17" /> Bereit für neue Wege</div>
    </div>
  </section>
</template>

<style scoped>
.market-hero{position:relative;overflow:hidden;display:grid;grid-template-columns:minmax(0,1.12fr) minmax(340px,.88fr);min-height:450px;border-radius:28px;background:linear-gradient(118deg,#102b43 0%,#173d57 62%,#205e60 100%);color:white;box-shadow:0 22px 55px rgba(20,48,67,.13)}
.hero-copy{position:relative;z-index:2;padding:clamp(30px,5vw,66px);max-width:760px}
.hero-kicker{font-size:9px;font-weight:800;letter-spacing:1.15px;background:rgba(211,240,120,.14)!important;color:#e1f59c!important}
h1{margin:24px 0 16px;font:800 clamp(38px,5.1vw,66px)/1.02 Manrope,sans-serif;letter-spacing:-2.8px}
h1 span{color:#d5ef85}
.hero-copy>p{max-width:570px;color:#c4d2dc;font-size:14px;line-height:1.75}
.hero-proof{display:flex;flex-wrap:wrap;gap:18px;margin-top:24px}
.hero-proof div{display:flex;align-items:center;gap:7px;color:#e2ebef;font-size:11px;font-weight:600}
.hero-search{display:flex;align-items:center;gap:10px;max-width:670px;margin-top:30px;padding:8px 9px 8px 17px;border:1px solid rgba(255,255,255,.25);border-radius:14px;background:white;color:#17334d;box-shadow:0 14px 35px rgba(0,0,0,.13)}
.hero-search :deep(.v-field__input){min-height:42px;padding:8px 0;color:#17334d;font-size:12px}
.hero-search :deep(.v-field__input input::placeholder){color:#8492a0;opacity:1}
.search-button{height:46px!important;white-space:nowrap;font-size:11px;font-weight:800;text-transform:none;letter-spacing:0}
.hero-metrics{display:flex;gap:36px;margin-top:28px}
.hero-metrics div{display:grid;gap:2px}
.hero-metrics strong{font:800 23px Manrope;color:#fff}
.hero-metrics span{font-size:10px;color:#aebfca}
.hero-art{position:relative;min-height:420px;overflow:hidden}
.art-glow{position:absolute;width:360px;height:360px;border-radius:50%;background:radial-gradient(circle,rgba(212,239,129,.28),rgba(212,239,129,0) 68%);right:3%;top:12%}
.art-orbit{position:absolute;border:1px solid rgba(220,240,245,.16);border-radius:50%;transform:rotate(-20deg)}
.orbit-one{width:500px;height:220px;right:-60px;top:100px}
.orbit-two{width:440px;height:180px;right:-20px;top:140px}
.art-label{position:absolute;z-index:2;right:10%;top:12%;display:grid;gap:8px;text-align:right}
.art-label span{font-size:8px;letter-spacing:2px;color:#b8d2d4;font-weight:800}
.art-label strong{font:800 21px/1.2 Manrope;color:#fff}
.art-car{position:absolute;z-index:2;right:0;bottom:48px;width:100%;display:flex;justify-content:center;transform:rotate(-3deg)}
.art-car :deep(.v-icon){font-size:clamp(170px,22vw,300px)!important;color:#d8e6e6;filter:drop-shadow(0 22px 18px rgba(0,0,0,.28))}
.art-road{position:absolute;left:4%;right:-15%;height:90px;bottom:5px;border-top:2px solid rgba(220,240,245,.28);border-radius:50%;transform:rotate(-9deg)}
.art-tag{position:absolute;z-index:3;right:8%;bottom:27%;display:flex;align-items:center;gap:7px;padding:11px 14px;border:1px solid rgba(255,255,255,.25);border-radius:10px;background:rgba(10,31,46,.6);backdrop-filter:blur(10px);font-size:10px;font-weight:700;color:#eaf3ef}
@media(max-width:900px){.market-hero{grid-template-columns:1fr}.hero-art{position:absolute;right:-7%;bottom:-12%;width:45%;min-height:330px;opacity:.55}.hero-copy{max-width:100%}.hero-search{max-width:100%}}
@media(max-width:600px){.market-hero{min-height:0;border-radius:19px}.hero-copy{padding:27px 20px 30px}h1{font-size:39px;letter-spacing:-1.7px}.hero-copy>p{font-size:12px}.hero-proof{display:grid;gap:9px}.hero-search{align-items:stretch;flex-wrap:wrap;padding:12px}.hero-search> :first-child{display:none}.hero-search :deep(.v-input){flex:1 1 100%}.search-button{width:100%}.hero-metrics{gap:25px}.hero-art{width:60%;right:-16%;bottom:-14%;opacity:.23}.art-label,.art-tag{display:none}}
</style>