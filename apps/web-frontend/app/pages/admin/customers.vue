<script setup lang="ts">
import CustomerManagementView from '../../../src/CustomerManagementView.vue';

definePageMeta({ layout: 'default' });
useHead({
  title: 'Kundenverwaltung | AutoFlow',
  meta: [{ name: 'description', content: 'Kundenprofile, Adressen, Einwilligungen und Audit-Verläufe verwalten.' }],
});
</script>

<template>
  <CustomerManagementView />
</template>
