<script setup lang="ts">
import AdminDashboard from '../../components/admin/AdminDashboard.vue';

definePageMeta({ layout: 'default' });
useHead({
  title: 'Administration | AutoFlow',
  meta: [{ name: 'description', content: 'AutoFlow administration and service overview.' }],
});
</script>

<template>
  <AdminDashboard />
</template>
