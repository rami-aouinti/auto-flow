<script setup lang="ts">
import { inject } from 'vue';
import type Keycloak from 'keycloak-js';
import OperationsView from './OperationsView.vue';

const keycloak = inject<Keycloak>('keycloak');
</script>

<template>
  <div class="shell">
    <header>
      <a class="logo" href="/">A<span>AUTOFLOW<small>MOBILITY PLATFORM</small></span></a>
      <nav>
        <a href="/">Fahrzeuge</a>
        <a href="/#offers">Meine Angebote</a>
        <a href="/operations.html">Aufträge & Payments</a>
      </nav>
      <div class="account">
        <i></i>{{ keycloak?.tokenParsed?.preferred_username || 'Account' }}
        <button @click="keycloak?.logout()">Abmelden</button>
      </div>
    </header>
    <main><OperationsView /></main>
    <div class="bottom">AUTOFLOW MOBILITY <span>ORDER MANAGEMENT · PAYMENT · PRICING</span></div>
  </div>
</template>
