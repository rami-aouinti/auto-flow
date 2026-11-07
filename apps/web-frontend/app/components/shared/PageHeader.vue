<script setup lang="ts">
withDefaults(defineProps<{ eyebrow: string; title: string; description?: string }>(), { description: '' });
</script>

<template>
  <header class="page-header">
    <div class="min-w-0">
      <div class="text-overline text-medium-emphasis font-weight-bold">{{ eyebrow }}</div>
      <h1 class="text-h4 text-md-h3 font-weight-bold mt-1">{{ title }}</h1>
      <p v-if="description" class="text-body-2 text-medium-emphasis mt-2">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="page-header-actions"><slot name="actions" /></div>
  </header>
</template>

<style scoped>
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:28px}
.page-header h1{letter-spacing:-1.1px;line-height:1.12}
.page-header p{max-width:760px}
.page-header-actions{display:flex;align-items:center;gap:8px;flex-shrink:0}
@media(max-width:600px){.page-header{flex-direction:column}.page-header-actions{width:100%}}
</style>