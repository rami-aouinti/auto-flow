<script setup lang="ts">
import { useAutoFlowTheme } from '../../composables/useAutoFlowTheme';
const { isDark, toggle } = useAutoFlowTheme();
</script>

<template>
  <v-btn
    icon
    variant="text"
    size="large"
    :aria-label="isDark ? 'Light Mode aktivieren' : 'Dark Mode aktivieren'"
    :title="isDark ? 'Light Mode aktivieren' : 'Dark Mode aktivieren'"
    @click="toggle"
  >
    <v-icon :icon="isDark ? 'mdi-white-balance-sunny' : 'mdi-weather-night'" />
  </v-btn>
</template>
