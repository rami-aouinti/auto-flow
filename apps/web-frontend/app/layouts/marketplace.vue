<script setup lang="ts">
import type Keycloak from 'keycloak-js';

const route = useRoute();
const { $keycloak } = useNuxtApp() as unknown as { $keycloak: Keycloak };
const drawer = ref(false);
const username = computed(() => $keycloak.tokenParsed?.preferred_username || 'Mein Konto');

function logout() {
  void $keycloak.logout({ redirectUri: window.location.origin });
}
</script>

<template>
  <v-app theme="marketplace">
    <v-app-bar class="market-header" flat height="76">
      <v-container class="market-header-inner d-flex align-center" fluid>
        <NuxtLink to="/" class="market-brand" aria-label="AutoFlow Startseite">
          <span class="market-brand-mark"><v-icon icon="mdi-car-electric" size="25" /></span>
          <span><strong>AutoFlow</strong><small>DEIN WEG ZUM WUNSCHAUTO</small></span>
        </NuxtLink>

        <nav class="market-nav d-none d-md-flex" aria-label="Hauptnavigation">
          <NuxtLink to="/#fahrzeuge" :class="{ active: route.path === '/' }">Fahrzeuge</NuxtLink>
          <NuxtLink to="/?fuelType=electric">Elektroautos</NuxtLink>
          <NuxtLink to="/operations/orders">Mein Bereich</NuxtLink>
        </nav>

        <v-spacer />
        <v-btn class="workspace-link d-none d-sm-flex" to="/operations/orders" variant="text" prepend-icon="mdi-view-dashboard-outline">
          Arbeitsbereich
        </v-btn>
        <v-menu>
          <template #activator="{ props }">
            <v-btn v-bind="props" class="account-menu" variant="tonal" color="primary" prepend-icon="mdi-account-circle-outline" append-icon="mdi-chevron-down">
              <span class="account-label">{{ username }}</span>
            </v-btn>
          </template>
          <v-list density="comfortable" min-width="210">
            <v-list-item to="/operations/orders" prepend-icon="mdi-clipboard-list-outline" title="Bestellungen" />
            <v-list-item to="/admin/catalog" prepend-icon="mdi-database-cog-outline" title="Katalogverwaltung" />
            <v-divider class="my-1" />
            <v-list-item prepend-icon="mdi-logout" title="Abmelden" @click="logout" />
          </v-list>
        </v-menu>
        <v-btn class="d-md-none ml-1" icon="mdi-menu" variant="text" aria-label="Menü öffnen" @click="drawer = !drawer" />
      </v-container>
    </v-app-bar>

    <v-navigation-drawer v-model="drawer" temporary location="right" width="300">
      <div class="mobile-drawer-brand">AutoFlow</div>
      <v-list nav>
        <v-list-item to="/" prepend-icon="mdi-car-search" title="Fahrzeuge entdecken" />
        <v-list-item to="/?fuelType=electric" prepend-icon="mdi-lightning-bolt" title="Elektroautos" />
        <v-list-item to="/operations/orders" prepend-icon="mdi-view-dashboard-outline" title="Arbeitsbereich" />
        <v-list-item to="/admin/catalog" prepend-icon="mdi-database-cog-outline" title="Katalogverwaltung" />
      </v-list>
    </v-navigation-drawer>

    <v-main class="market-main">
      <slot />
    </v-main>

    <v-footer class="market-footer" color="surface">
      <v-container fluid class="market-footer-inner">
        <div class="footer-brand"><strong>AutoFlow</strong><span>Mobilität. Einfacher gedacht.</span></div>
        <div class="footer-links">
          <NuxtLink to="/">Fahrzeuge</NuxtLink>
          <NuxtLink to="/operations/orders">Mein Bereich</NuxtLink>
          <NuxtLink to="/admin/catalog">Administration</NuxtLink>
        </div>
        <span class="footer-copy">© {{ new Date().getFullYear() }} AutoFlow Mobility Platform</span>
      </v-container>
    </v-footer>
  </v-app>
</template>

<style scoped>
.market-header{border-bottom:1px solid #e8edf1!important;background:rgba(255,255,255,.96)!important;backdrop-filter:blur(16px);position:sticky}
.market-header-inner{max-width:1440px;margin:0 auto;padding:0 clamp(16px,4vw,56px)}
.market-brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#172b40;min-width:210px}
.market-brand-mark{display:grid;place-items:center;width:43px;height:43px;border-radius:13px;background:#17334d;color:#d5f078}
.market-brand strong{display:block;font:800 19px Manrope,sans-serif;letter-spacing:-.6px}
.market-brand small{display:block;margin-top:2px;font-size:8px;font-weight:800;letter-spacing:1.25px;color:#718195}
.market-nav{align-items:center;gap:32px;margin-left:48px}
.market-nav a,.footer-links a{font-size:12px;font-weight:700;color:#506174;text-decoration:none;transition:color .2s}
.market-nav a:hover,.market-nav a.active,.footer-links a:hover{color:#1b6b55}
.workspace-link{margin-right:8px;font-size:11px;font-weight:700;text-transform:none;letter-spacing:0}
.account-menu{font-size:11px;font-weight:700;text-transform:none;letter-spacing:0}
.account-label{max-width:145px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.market-main{background:#f5f7f8}
.market-footer{border-top:1px solid #e6ebef!important}
.market-footer-inner{max-width:1320px;margin:auto;display:flex;align-items:center;gap:30px;padding:24px clamp(16px,4vw,40px)}
.footer-brand{display:grid;gap:3px}
.footer-brand strong{font:800 17px Manrope;color:#172b40}
.footer-brand span,.footer-copy{font-size:10px;color:#8190a0}
.footer-links{display:flex;gap:22px;margin-left:auto}
.mobile-drawer-brand{padding:25px 20px 12px;font:800 23px Manrope;color:#17334d}
@media(max-width:700px){.market-brand{min-width:0}.market-brand strong{font-size:17px}.market-brand small{font-size:7px}.market-footer-inner{align-items:flex-start;flex-direction:column;gap:15px}.footer-links{margin-left:0;flex-wrap:wrap}.footer-copy{margin-top:3px}}
</style>