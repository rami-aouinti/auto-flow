<script setup lang="ts">
import { inject } from 'vue';
import type Keycloak from 'keycloak-js';
import AdminCatalogView from './AdminCatalogView.vue';
const keycloak = inject<Keycloak>('keycloak');
</script>
<template>
  <div class="shell">
    <header>
      <a class="logo" href="/">A<span>AUTOFLOW<small>MOBILITY PLATFORM</small></span></a>
      <nav><a href="/">Fahrzeuge</a><a href="/operations.html">Aufträge & Payments</a><a href="/admin-catalog.html">Katalogverwaltung</a></nav>
      <div class="account"><i></i>{{ keycloak?.tokenParsed?.preferred_username || 'Account' }}<button @click="keycloak?.logout()">Abmelden</button></div>
    </header>
    <main><AdminCatalogView /></main>
    <div class="bottom">AUTOFLOW MOBILITY <span>CATALOGUE ADMINISTRATION</span></div>
  </div>
</template>
