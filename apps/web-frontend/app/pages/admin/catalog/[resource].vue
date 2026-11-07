<script setup lang="ts">
import AdminCatalogView from '../../../../src/AdminCatalogView.vue';

const route = useRoute();
const supportedResources = ['manufacturers', 'models', 'generations', 'variants'] as const;
type CatalogResource = typeof supportedResources[number];
const resource = computed<CatalogResource>(() => {
  const value = String(route.params.resource ?? '');
  return supportedResources.includes(value as CatalogResource) ? value as CatalogResource : 'manufacturers';
});
if (!supportedResources.includes(String(route.params.resource) as CatalogResource)) {
  await navigateTo('/admin/catalog/manufacturers', { replace: true });
}
definePageMeta({ layout: 'default' });
useHead({ title: 'Katalogverwaltung | AutoFlow' });
</script>

<template>
  <AdminCatalogView :initial-resource="resource" />
</template>
