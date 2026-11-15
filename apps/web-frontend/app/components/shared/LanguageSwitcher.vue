<script setup lang="ts">
import { useAutoFlowLocale, type AutoFlowLocale } from '../../composables/useAutoFlowLocale';

const { locale, localeInfo, locales, setLocale } = useAutoFlowLocale();
function choose(code: AutoFlowLocale) { setLocale(code); }
</script>

<template>
  <v-menu location="bottom end" :close-on-content-click="true">
    <template #activator="{ props }">
      <v-btn v-bind="props" variant="text" size="small" class="locale-switcher" :aria-label="`Language: ${localeInfo.name}`" :title="localeInfo.name">
        <span class="locale-flag" aria-hidden="true">{{ localeInfo.flag }}</span>
        <span class="locale-code">{{ locale.toUpperCase() }}</span>
        <v-icon icon="mdi-chevron-down" size="small" />
      </v-btn>
    </template>
    <v-list density="compact" min-width="170" nav>
      <v-list-item v-for="item in locales" :key="item.code" :active="locale === item.code" rounded="lg" @click="choose(item.code)">
        <template #prepend><span class="locale-option-flag">{{ item.flag }}</span></template>
        <v-list-item-title>{{ item.name }}</v-list-item-title>
        <template #append><v-icon v-if="locale === item.code" icon="mdi-check" size="small" color="primary" /></template>
      </v-list-item>
    </v-list>
  </v-menu>
</template>

<style scoped>
.locale-switcher{min-width:66px;padding-inline:8px}
.locale-flag,.locale-option-flag{font-size:19px;line-height:1}
.locale-code{font-size:10px;font-weight:800;letter-spacing:.5px}
.locale-option-flag{width:28px}
</style>
