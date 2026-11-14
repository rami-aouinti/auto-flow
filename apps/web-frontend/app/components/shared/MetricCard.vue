<script setup lang="ts">
withDefaults(defineProps<{
  label: string;
  value: string;
  caption: string;
  icon: string;
  tone?: string;
  to?: string;
}>(), { tone: 'primary', to: undefined });
</script>

<template>
  <v-card :to="to" class="metric-card h-100" rounded="xl" border elevation="0">
    <v-card-text class="pa-5">
      <div class="d-flex align-center justify-space-between ga-3">
        <span class="text-body-2 text-medium-emphasis font-weight-medium">{{ label }}</span>
        <v-avatar :color="tone" variant="tonal" rounded="xl" size="44" class="metric-icon"><v-icon :icon="icon" size="22" /></v-avatar>
      </div>
      <div class="d-flex align-end ga-2 mt-5">
        <span class="text-h3 font-weight-bold metric-value">{{ value }}</span>
        <v-icon v-if="to && value !== '—' && value !== '…'" icon="mdi-arrow-top-right" color="medium-emphasis" size="small" class="mb-2" />
      </div>
      <div class="text-caption text-medium-emphasis mt-1">{{ caption }}</div>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.metric-card{position:relative;overflow:hidden;min-height:158px;transition:transform .22s ease,border-color .22s ease,box-shadow .22s ease}
.metric-card::before{position:absolute;inset:0 auto 0 0;width:3px;background:linear-gradient(180deg,#c8f169,#78a84c);content:"";opacity:0;transition:opacity .2s ease}
.metric-card:hover{transform:translateY(-4px);border-color:#d1dfd9!important;box-shadow:0 14px 30px rgba(19,42,61,.09)!important}
.metric-card:hover::before{opacity:1}
.metric-icon{box-shadow:inset 0 0 0 1px rgba(23,59,86,.04)}
.metric-value{letter-spacing:-1.8px;font-family:Manrope,sans-serif;font-variant-numeric:tabular-nums}
@media(max-width:600px){.metric-card{min-height:140px}.metric-value{font-size:2rem!important}}
</style>