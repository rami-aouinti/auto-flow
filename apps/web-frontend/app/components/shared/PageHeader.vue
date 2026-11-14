<script setup lang="ts">
withDefaults(defineProps<{ eyebrow: string; title: string; description?: string }>(), { description: '' });
</script>

<template>
  <header class="page-header">
    <div class="min-w-0">
      <div class="page-eyebrow">{{ eyebrow }}</div>
      <h1 class="text-h4 text-md-h3 font-weight-bold mt-1">{{ title }}</h1>
      <p v-if="description" class="text-body-2 text-medium-emphasis mt-2">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="page-header-actions"><slot name="actions" /></div>
  </header>
</template>

<style scoped>
.page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:30px;padding-bottom:22px;border-bottom:1px solid #e7edf1}
.page-eyebrow{display:flex;align-items:center;gap:9px;color:#718b65;font-size:9px;font-weight:800;letter-spacing:.16em}
.page-eyebrow::before{width:19px;height:2px;border-radius:2px;background:#a5c85b;content:""}
.page-header h1{margin-top:9px;letter-spacing:-1.4px;line-height:1.1;font-family:Manrope,sans-serif}
.page-header p{max-width:760px;line-height:1.65}
.page-header-actions{display:flex;align-items:center;gap:8px;flex-shrink:0}
@media(max-width:600px){.page-header{flex-direction:column;align-items:stretch}.page-header-actions{width:100%;justify-content:flex-start}}
</style>