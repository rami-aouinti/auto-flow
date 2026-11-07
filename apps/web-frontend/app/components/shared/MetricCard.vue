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
        <v-avatar :color="tone" variant="tonal" rounded="lg" size="42"><v-icon :icon="icon" /></v-avatar>
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
.metric-card{transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}
.metric-card:hover{transform:translateY(-3px);border-color:rgba(var(--v-theme-primary),.55)!important;box-shadow:0 12px 28px rgba(0,0,0,.12)!important}
.metric-value{letter-spacing:-1.5px}
</style>