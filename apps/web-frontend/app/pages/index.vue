<script setup lang="ts">
import App from '../../src/App.vue';

definePageMeta({ layout: 'marketplace' });
useHead({ title: 'Fahrzeuge entdecken | AutoFlow', meta: [{ name: 'description', content: 'Entdecke Fahrzeugmodelle, vergleiche technische Daten und berechne deine individuelle Rate mit AutoFlow.' }] });
</script>

<template>
  <App />
</template>
