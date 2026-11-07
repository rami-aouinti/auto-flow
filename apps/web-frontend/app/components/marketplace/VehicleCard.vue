<script setup lang="ts">
defineProps<{ vehicle: Record<string, any> }>();
const emit = defineEmits<{ select: [vehicle: Record<string, any>] }>();

function label(value?: string) {
  if (!value) return 'Nicht angegeben';
  return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}
function power(value?: number) {
  return value ? `${value} kW` : 'Leistung n. a.';
}
</script>

<template>
  <v-card class="vehicle-card" rounded="xl" elevation="0" border @click="emit('select', vehicle)">
    <div class="vehicle-visual">
      <div class="visual-topline"><v-chip size="x-small" color="white" variant="flat">KATALOGFAHRZEUG</v-chip><span>{{ label(vehicle.bodyStyle) }}</span></div>
      <div class="vehicle-art"><v-icon icon="mdi-car-side" /></div>
      <div class="visual-bottomline"><span>{{ vehicle.manufacturer?.code || 'AF' }}</span><span>TECHNISCHE DATEN</span></div>
    </div>
    <v-card-text class="vehicle-content">
      <div class="vehicle-make">{{ vehicle.manufacturer?.name }} <span>·</span> {{ vehicle.model?.name }}</div>
      <h3>{{ vehicle.name }}</h3>
      <p class="vehicle-generation">{{ vehicle.generation?.name || 'Modellgeneration' }}</p>
      <div class="vehicle-specs">
        <v-chip size="small" variant="tonal" prepend-icon="mdi-engine-outline">{{ power(vehicle.powerKw) }}</v-chip>
        <v-chip size="small" variant="tonal" prepend-icon="mdi-gas-station-outline">{{ label(vehicle.fuelType) }}</v-chip>
        <v-chip size="small" variant="tonal" prepend-icon="mdi-car-shift-pattern">{{ label(vehicle.transmission) }}</v-chip>
      </div>
      <v-divider class="my-4" />
      <div class="vehicle-card-footer">
        <div><strong>Individuell kalkulierbar</strong><span>Rate mit AutoFlow berechnen</span></div>
        <v-btn color="primary" icon="mdi-arrow-up-right" size="large" variant="flat" aria-label="Fahrzeugdetails öffnen" />
      </div>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.vehicle-card{height:100%;overflow:hidden;border-color:#e5eaee!important;background:#fff;cursor:pointer;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.vehicle-card:hover{transform:translateY(-5px);border-color:#b9cbd0!important;box-shadow:0 18px 38px rgba(25,51,73,.1)!important}
.vehicle-visual{position:relative;display:flex;align-items:center;justify-content:center;height:190px;overflow:hidden;background:radial-gradient(ellipse at 50% 80%,#d8e6e4 0%,#edf2f2 45%,#e7edf1 100%)}
.vehicle-visual:before,.vehicle-visual:after{position:absolute;content:'';border:1px solid rgba(30,67,81,.1);border-radius:50%;width:290px;height:110px;transform:rotate(-12deg)}
.vehicle-visual:after{width:350px;height:140px;transform:rotate(14deg)}
.visual-topline,.visual-bottomline{position:absolute;z-index:2;left:15px;right:15px;display:flex;align-items:center;justify-content:space-between}
.visual-topline{top:14px}.visual-topline>span{font-size:8px;letter-spacing:.9px;color:#687b88;font-weight:800;text-transform:uppercase}
.visual-bottomline{bottom:13px;color:#71828e;font-size:8px;font-weight:800;letter-spacing:1.2px}
.vehicle-art{position:relative;z-index:1;display:grid;place-items:center;width:100%;height:100%}
.vehicle-art :deep(.v-icon){font-size:clamp(105px,12vw,150px)!important;color:#25465a;filter:drop-shadow(0 15px 8px rgba(30,58,70,.18))}
.vehicle-content{padding:20px}
.vehicle-make{font-size:10px;font-weight:800;letter-spacing:.5px;color:#668071;text-transform:uppercase}
.vehicle-make span{color:#aab5bd;padding:0 3px}
h3{margin:8px 0 3px;color:#172b40;font:800 20px/1.2 Manrope;letter-spacing:-.5px}
.vehicle-generation{margin:0;color:#8794a1;font-size:11px}
.vehicle-specs{display:flex;flex-wrap:wrap;gap:6px;margin-top:17px}
.vehicle-specs :deep(.v-chip){font-size:9px!important;color:#496073!important;background:#f0f4f5!important}
.vehicle-card-footer{display:flex;align-items:center;justify-content:space-between;gap:12px}
.vehicle-card-footer>div{display:grid;gap:4px}
.vehicle-card-footer strong{font-size:11px;color:#17334d}
.vehicle-card-footer span{font-size:9px;color:#84919d}
.vehicle-card-footer :deep(.v-btn){width:42px;height:42px}
</style>