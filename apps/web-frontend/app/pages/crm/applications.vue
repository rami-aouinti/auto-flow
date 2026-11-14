<script setup lang="ts">
import CrmWorkspaceView from '../../../src/CrmWorkspaceView.vue';

definePageMeta({ layout: 'default' });
useHead({
  title: 'CRM · Anträge | AutoFlow',
  meta: [{ name: 'description', content: 'Kundenanträge, Eligibility-Prüfungen, Aufgaben und Kundeninteraktionen verwalten.' }],
});
</script>

<template>
  <CrmWorkspaceView />
</template>
