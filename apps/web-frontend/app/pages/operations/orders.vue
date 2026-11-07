<script setup lang="ts">
import OperationsView from '../../../src/OperationsView.vue';

definePageMeta({ layout: 'default' });
useHead({
  title: 'Aufträge & Payments | AutoFlow',
  script: [{ src: 'https://js.stripe.com/v3/', defer: true }],
});
</script>

<template>
  <OperationsView />
</template>
