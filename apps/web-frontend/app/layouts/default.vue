<script setup lang="ts">
import type Keycloak from 'keycloak-js';

const route = useRoute();
const drawer = ref(false);
const { mdAndUp } = useDisplay();
const { $keycloak } = useNuxtApp() as unknown as { $keycloak: Keycloak };

const sections = [
  {
    title: 'ÜBERSICHT',
    items: [
      { title: 'Kontrollzentrum', subtitle: 'Kennzahlen & Schnellzugriff', icon: 'mdi-view-dashboard-outline', to: '/admin' },
    ],
  },
  {
    title: 'GESCHÄFTSPROZESSE',
    items: [
      { title: 'Aufträge', subtitle: 'Bestellungen & Status', icon: 'mdi-clipboard-text-outline', to: '/operations/orders' },
      { title: 'Payments', subtitle: 'Zahlungsvorgänge', icon: 'mdi-credit-card-outline', to: '/operations/payments' },
      { title: 'Pricing Quotes', subtitle: 'Kalkulationen', icon: 'mdi-calculator-variant-outline', to: '/operations/pricing' },
      { title: 'CRM · Anträge', subtitle: 'Kundenanträge & Beratung', icon: 'mdi-account-tie-outline', to: '/crm/applications' },
      { title: 'Service Operations', subtitle: 'Contracts, Billing, Stock & Fulfillment', icon: 'mdi-application-cog-outline', to: '/operations/services' },
      { title: 'Business Workflows', subtitle: 'Contracts, stock & delivery actions', icon: 'mdi-workflow-outline', to: '/operations/workflow' },
    ],
  },
  {
    title: 'STAMMDATEN',
    items: [
      { title: 'Fahrzeugkatalog', subtitle: 'Marken, Modelle & Varianten', icon: 'mdi-car-cog', to: '/admin/catalog/manufacturers' },
      { title: 'Kunden', subtitle: 'Profile, Adressen & Einwilligungen', icon: 'mdi-account-group-outline', to: '/admin/customers' },
    ],
  },
];
const pageTitle = computed(() => {
  if (route.path === '/admin') return 'Kontrollzentrum';
  if (route.path.startsWith('/admin/catalog')) return 'Fahrzeugkatalog';
  if (route.path.startsWith('/admin/customers')) return 'Kunden';
  if (route.path.startsWith('/crm')) return 'CRM · Anträge';
  if (route.path.startsWith('/operations/workflow')) return 'Business Workflows';
  if (route.path.startsWith('/operations/services')) return 'Service Operations';
  if (route.path.startsWith('/operations/orders')) return route.query.tab === 'payments' ? 'Payments' : route.query.tab === 'quotes' ? 'Pricing Quotes' : 'Aufträge';
  return 'Workspace';
});
const username = computed(() => $keycloak.tokenParsed?.preferred_username || 'AutoFlow User');

function isActive(to: string) {
  if (to === '/admin') return route.path === to;
  if (to === '/operations/payments') return route.path === '/operations/orders' && route.query.tab === 'payments';
  if (to === '/operations/pricing') return route.path === '/operations/orders' && route.query.tab === 'quotes';
  if (to === '/operations/orders') return route.path === to && (!route.query.tab || route.query.tab === 'orders');
  if (to === '/admin/catalog/manufacturers') return route.path.startsWith('/admin/catalog');
  return route.path === to || route.path.startsWith(`${to}/`);
}
function logout() {
  void $keycloak.logout({ redirectUri: window.location.origin });
}
</script>

<template>
  <v-app>
    <v-navigation-drawer
      :model-value="mdAndUp || drawer"
      :permanent="mdAndUp"
      :temporary="!mdAndUp"
      color="surface"
      border="end"
      width="284"
      @update:model-value="drawer = $event"
    >
      <div class="brand-lockup">
        <v-avatar color="primary" rounded="lg" size="43"><v-icon icon="mdi-car-electric" color="on-primary" size="25" /></v-avatar>
        <div><div class="brand-name">AUTOFLOW</div><div class="brand-caption">MOBILITY OPERATIONS</div></div>
      </div>

      <div class="workspace-label"><span>WORKSPACE</span><v-chip size="x-small" color="success" variant="tonal">LOCAL</v-chip></div>
      <template v-for="section in sections" :key="section.title">
        <div class="nav-section-label">{{ section.title }}</div>
        <v-list nav density="comfortable" bg-color="transparent" class="px-3 pt-1 pb-2">
          <v-list-item
            v-for="item in section.items"
            :key="item.to"
            :to="item.to"
            :active="isActive(item.to)"
            color="primary"
            rounded="lg"
            class="workspace-nav-item mb-1"
            @click="drawer = false"
          >
            <template #prepend><v-icon :icon="item.icon" /></template>
            <v-list-item-title>{{ item.title }}</v-list-item-title>
            <v-list-item-subtitle>{{ item.subtitle }}</v-list-item-subtitle>
          </v-list-item>
        </v-list>
      </template>

      <template #append>
        <div class="drawer-footer">
          <div class="d-flex align-center ga-2"><v-icon color="success" size="small" icon="mdi-shield-check-outline" /><span class="security-label">Authentifizierte Sitzung</span></div>
          <div class="drawer-version">AUTOFLOW PLATFORM · V2</div>
        </div>
      </template>
    </v-navigation-drawer>

    <v-app-bar color="surface" flat border="bottom" height="72">
      <v-app-bar-nav-icon v-if="!mdAndUp" aria-label="Navigation öffnen" @click="drawer = !drawer" />
      <v-toolbar-title class="topbar-title">
        <span class="topbar-overline">AUTOFLOW / WORKSPACE</span>
        <span class="topbar-heading">{{ pageTitle }}</span>
      </v-toolbar-title>
      <v-spacer />
      <div class="environment-indicator d-none d-sm-flex"><span class="environment-dot" /> Services verbunden</div>
      <v-divider vertical inset class="mx-4 d-none d-sm-flex" />
      <v-menu>
        <template #activator="{ props }">
          <v-btn v-bind="props" variant="text" class="account-button" prepend-icon="mdi-account-circle-outline" append-icon="mdi-chevron-down">
            {{ username }}
          </v-btn>
        </template>
        <v-list density="comfortable" min-width="210">
          <v-list-item prepend-icon="mdi-account-outline" :title="username" subtitle="Angemeldetes Konto" />
          <v-divider class="my-1" />
          <v-list-item prepend-icon="mdi-storefront-outline" title="Marketplace öffnen" to="/" />
          <v-list-item prepend-icon="mdi-logout" title="Abmelden" @click="logout" />
        </v-list>
      </v-menu>
    </v-app-bar>

    <v-main>
      <v-container fluid class="workspace-content"><slot /></v-container>
    </v-main>

    <v-footer app color="surface" class="workspace-footer">
      <span>AUTOFLOW MOBILITY PLATFORM</span><v-spacer />
      <span>SECURE OPERATIONS · {{ new Date().getFullYear() }}</span>
    </v-footer>
  </v-app>
</template>

<style scoped>
.brand-lockup{display:flex;align-items:center;gap:13px;padding:25px 22px 23px}
.brand-name{font:800 15px Manrope,sans-serif;letter-spacing:2px;color:#f1f5f6}
.brand-caption{font-size:8px;letter-spacing:1.5px;color:#82939b;margin-top:4px}
.workspace-label{display:flex;align-items:center;justify-content:space-between;padding:12px 23px 15px;border-top:1px solid rgba(255,255,255,.06);border-bottom:1px solid rgba(255,255,255,.06)}
.workspace-label>span,.nav-section-label{color:#71838c;font-size:9px;font-weight:800;letter-spacing:1.55px}
.nav-section-label{padding:20px 25px 3px}
.workspace-nav-item{min-height:57px}
.workspace-nav-item :deep(.v-list-item-title){font-size:12px;font-weight:700}
.workspace-nav-item :deep(.v-list-item-subtitle){font-size:9px;color:#82939b;margin-top:3px}
.drawer-footer{padding:18px 22px 22px;border-top:1px solid rgba(255,255,255,.06)}
.security-label{font-size:10px;color:#a9bac0}
.drawer-version{margin-top:12px;color:#71838c;font-size:8px;letter-spacing:1px}
.topbar-title{display:flex;flex-direction:column;line-height:1.25}
.topbar-overline{font-size:8px;letter-spacing:1.7px;color:#82939b}
.topbar-heading{font:700 15px Manrope;color:#eef3f4}
.environment-indicator{align-items:center;gap:8px;color:#a9bac0;font-size:10px}
.environment-dot{width:7px;height:7px;border-radius:50%;background:#75c89a;box-shadow:0 0 0 4px rgba(117,200,154,.12)}
.account-button{font-size:11px;text-transform:none;letter-spacing:0}
.workspace-content{max-width:1600px;padding:clamp(22px,3vw,42px) clamp(18px,3vw,48px) 76px}
.workspace-footer{padding:13px 26px;color:#71838c;font-size:8px;letter-spacing:1.2px}
</style>