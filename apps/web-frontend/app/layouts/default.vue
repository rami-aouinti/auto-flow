<script setup lang="ts">
import type Keycloak from 'keycloak-js';

const route = useRoute();
const drawer = ref(false);
const { $keycloak } = useNuxtApp() as unknown as { $keycloak: Keycloak };

const navigation = [
  { title: 'Fahrzeugkatalog', subtitle: 'Fahrzeuge & Angebote', icon: 'mdi-car-outline', to: '/' },
  { title: 'Aufträge', subtitle: 'Order Management', icon: 'mdi-clipboard-list-outline', to: '/operations/orders' },
  { title: 'Katalogverwaltung', subtitle: 'Stammdaten & Varianten', icon: 'mdi-database-cog-outline', to: '/admin/catalog' },
];

const username = computed(() => $keycloak.tokenParsed?.preferred_username || 'AutoFlow User');

function logout() {
  void $keycloak.logout({ redirectUri: window.location.origin });
}
</script>

<template>
  <v-app>
    <v-navigation-drawer v-model="drawer" color="surface" border="end" width="272">
      <div class="brand-lockup">
        <v-avatar color="primary" rounded="lg" size="42">
          <span class="brand-mark">A</span>
        </v-avatar>
        <div>
          <div class="brand-name">AUTOFLOW</div>
          <div class="brand-caption">MOBILITY PLATFORM</div>
        </div>
      </div>

      <v-divider class="my-4" />

      <div class="nav-section-label">WORKSPACE</div>
      <v-list nav density="comfortable" bg-color="transparent" class="px-3">
        <v-list-item
          v-for="item in navigation"
          :key="item.to"
          :to="item.to"
          :active="route.path === item.to"
          color="primary"
          rounded="lg"
          class="mb-1"
        >
          <template #prepend>
            <v-icon :icon="item.icon" />
          </template>
          <v-list-item-title>{{ item.title }}</v-list-item-title>
          <v-list-item-subtitle>{{ item.subtitle }}</v-list-item-subtitle>
        </v-list-item>
      </v-list>

      <template #append>
        <div class="drawer-footer">
          <v-chip color="success" size="small" variant="tonal" prepend-icon="mdi-shield-check">
            Authenticated
          </v-chip>
          <div class="drawer-version">AUTOFLOW PLATFORM · LOCAL ENVIRONMENT</div>
        </div>
      </template>
    </v-navigation-drawer>

    <v-app-bar color="surface" flat border="bottom" height="72">
      <v-app-bar-nav-icon aria-label="Navigation öffnen" @click="drawer = !drawer" />
      <v-toolbar-title class="topbar-title">
        <span class="topbar-overline">AUTOFLOW WORKSPACE</span>
        <span class="topbar-heading">{{ navigation.find((item) => item.to === route.path)?.title || 'Operations' }}</span>
      </v-toolbar-title>
      <v-spacer />
      <v-chip class="mr-3" color="success" variant="tonal" size="small" prepend-icon="mdi-circle">
        System verbunden
      </v-chip>
      <v-menu>
        <template #activator="{ props }">
          <v-btn v-bind="props" variant="text" class="account-button" prepend-icon="mdi-account-circle-outline" append-icon="mdi-chevron-down">
            {{ username }}
          </v-btn>
        </template>
        <v-list density="compact" min-width="190">
          <v-list-item prepend-icon="mdi-logout" title="Abmelden" @click="logout" />
        </v-list>
      </v-menu>
    </v-app-bar>

    <v-main>
      <v-container fluid class="workspace-content">
        <slot />
      </v-container>
    </v-main>

    <v-footer app color="surface" class="workspace-footer">
      <span>AUTOFLOW MOBILITY PLATFORM</span>
      <v-spacer />
      <span>SECURE OPERATIONS · {{ new Date().getFullYear() }}</span>
    </v-footer>
  </v-app>
</template>

<style scoped>
.brand-lockup{display:flex;align-items:center;gap:12px;padding:25px 22px 8px}.brand-mark{font:800 23px Manrope;color:#17232d}.brand-name{font:800 14px Manrope;letter-spacing:2px;color:#f1f5f6}.brand-caption{font-size:8px;letter-spacing:1.7px;color:#82939b;margin-top:4px}.nav-section-label{padding:12px 25px 5px;color:#71838c;font-size:9px;font-weight:700;letter-spacing:1.8px}.drawer-footer{padding:18px 20px 22px}.drawer-version{margin-top:13px;color:#71838c;font-size:8px;letter-spacing:1px}.topbar-title{display:flex;flex-direction:column;line-height:1.25}.topbar-overline{font-size:8px;letter-spacing:1.7px;color:#82939b}.topbar-heading{font:700 14px Manrope;color:#eef3f4}.account-button{font-size:11px;text-transform:none;letter-spacing:0}.workspace-content{max-width:1480px;padding:34px clamp(18px,3vw,48px) 72px}.workspace-footer{padding:13px 26px;color:#71838c;font-size:8px;letter-spacing:1.2px}.v-list-item--active{background:#c8f16912}.v-list-item-title{font-size:12px!important;font-weight:600}.v-list-item-subtitle{font-size:9px!important;color:#82939b!important}
</style>
