<script setup lang="ts">
import type Keycloak from 'keycloak-js';

const route = useRoute();
const { $keycloak } = useNuxtApp() as unknown as { $keycloak: Keycloak };
const drawer = ref(false);
const username = computed(() => $keycloak.tokenParsed?.preferred_username || 'Mein Konto');

const navigation = [
  { label: 'Fahrzeuge', to: '/#fahrzeuge', icon: 'mdi-car-search' },
  { label: 'Elektroautos', to: '/?fuelType=electric', icon: 'mdi-lightning-bolt' },
  { label: 'Angebote', to: '/#angebote', icon: 'mdi-tag-outline' },
];

function logout() {
  void $keycloak.logout({ redirectUri: window.location.origin });
}
</script>

<template>
  <v-app theme="marketplace">
    <div class="market-topline">
      <v-container class="market-topline-inner" fluid>
        <span><v-icon icon="mdi-shield-check-outline" size="14" /> Fahrzeugdaten und Angebotsprozesse – transparent an einem Ort</span>
        <NuxtLink to="/operations/orders">Zum persönlichen Bereich <v-icon icon="mdi-arrow-up-right" size="13" /></NuxtLink>
      </v-container>
    </div>

    <v-app-bar class="market-header" flat height="78">
      <v-container class="market-header-inner d-flex align-center" fluid>
        <NuxtLink to="/" class="market-brand" aria-label="AutoFlow Startseite">
          <span class="market-brand-mark"><v-icon icon="mdi-car-electric" size="25" /></span>
          <span><strong>AutoFlow</strong><small>MOBILITY MADE SIMPLE</small></span>
        </NuxtLink>

        <nav class="market-nav d-none d-md-flex" aria-label="Hauptnavigation">
          <NuxtLink v-for="item in navigation" :key="item.label" :to="item.to" :class="{ active: item.label === 'Fahrzeuge' && route.path === '/' }">
            {{ item.label }}
          </NuxtLink>
          <v-menu open-on-hover>
            <template #activator="{ props }">
              <button v-bind="props" class="nav-dropdown">Mehr entdecken <v-icon icon="mdi-chevron-down" size="15" /></button>
            </template>
            <v-list rounded="lg" min-width="220" density="comfortable">
              <v-list-item to="/operations/orders" prepend-icon="mdi-clipboard-list-outline" title="Meine Angebote" />
              <v-list-item to="/admin/catalog" prepend-icon="mdi-database-cog-outline" title="Katalogverwaltung" />
            </v-list>
          </v-menu>
        </nav>

        <v-spacer />
        <v-btn class="header-search d-none d-sm-flex" to="/#fahrzeuge" variant="flat" color="primary" prepend-icon="mdi-magnify">Auto finden</v-btn>
        <v-menu>
          <template #activator="{ props }">
            <v-btn v-bind="props" class="account-menu d-none d-sm-flex" variant="text" prepend-icon="mdi-account-circle-outline" append-icon="mdi-chevron-down">
              <span class="account-label">{{ username }}</span>
            </v-btn>
          </template>
          <v-list density="comfortable" min-width="220">
            <v-list-item to="/operations/orders" prepend-icon="mdi-clipboard-list-outline" title="Meine Angebote" />
            <v-list-item to="/admin/catalog" prepend-icon="mdi-database-cog-outline" title="Administration" />
            <v-divider class="my-1" />
            <v-list-item prepend-icon="mdi-logout" title="Abmelden" @click="logout" />
          </v-list>
        </v-menu>
        <v-btn class="d-md-none ml-1" icon="mdi-menu" variant="text" aria-label="Menü öffnen" @click="drawer = !drawer" />
      </v-container>
    </v-app-bar>

    <v-navigation-drawer v-model="drawer" temporary location="right" width="320" class="market-mobile-drawer">
      <div class="mobile-drawer-head">
        <NuxtLink to="/" class="market-brand" @click="drawer = false">
          <span class="market-brand-mark"><v-icon icon="mdi-car-electric" /></span>
          <span><strong>AutoFlow</strong><small>MOBILITY MADE SIMPLE</small></span>
        </NuxtLink>
        <v-btn icon="mdi-close" variant="text" aria-label="Menü schließen" @click="drawer = false" />
      </div>
      <v-divider />
      <v-list nav class="pa-3">
        <v-list-item v-for="item in navigation" :key="item.label" :to="item.to" :prepend-icon="item.icon" :title="item.label" rounded="lg" @click="drawer = false" />
        <v-divider class="my-3" />
        <v-list-item to="/operations/orders" prepend-icon="mdi-clipboard-list-outline" title="Meine Angebote" rounded="lg" @click="drawer = false" />
        <v-list-item to="/admin/catalog" prepend-icon="mdi-database-cog-outline" title="Katalogverwaltung" rounded="lg" @click="drawer = false" />
        <v-list-item prepend-icon="mdi-logout" title="Abmelden" rounded="lg" @click="logout" />
      </v-list>
    </v-navigation-drawer>

    <v-main class="market-main">
      <slot />
    </v-main>

    <v-footer class="market-footer" color="surface">
      <v-container fluid class="market-footer-inner">
        <div class="footer-main">
          <div class="footer-about">
            <NuxtLink to="/" class="market-brand footer-brand-link">
              <span class="market-brand-mark"><v-icon icon="mdi-car-electric" size="24" /></span>
              <span><strong>AutoFlow</strong><small>MOBILITY MADE SIMPLE</small></span>
            </NuxtLink>
            <p>Fahrzeuge entdecken, vergleichen und Angebote transparent kalkulieren – mit einer digitalen Plattform für moderne Mobilität.</p>
          </div>
          <div class="footer-column">
            <strong>Fahrzeuge</strong>
            <NuxtLink to="/#fahrzeuge">Alle Fahrzeuge</NuxtLink>
            <NuxtLink to="/?fuelType=electric">Elektroautos</NuxtLink>
            <NuxtLink to="/?bodyStyle=suv">SUV entdecken</NuxtLink>
          </div>
          <div class="footer-column">
            <strong>Mein AutoFlow</strong>
            <NuxtLink to="/operations/orders">Meine Angebote</NuxtLink>
            <NuxtLink to="/operations/orders">Bestellungen & Status</NuxtLink>
            <NuxtLink to="/admin/catalog">Katalogverwaltung</NuxtLink>
          </div>
          <div class="footer-column">
            <strong>Service</strong>
            <a href="mailto:service@autoflow.local">Kontakt & Support</a>
            <NuxtLink to="/#so-funktionierts">So funktioniert AutoFlow</NuxtLink>
            <NuxtLink to="/#faq">Häufige Fragen</NuxtLink>
          </div>
        </div>
        <v-divider class="footer-divider" />
        <div class="footer-bottom">
          <span>© {{ new Date().getFullYear() }} AutoFlow Mobility Platform</span>
          <span>Eine moderne Plattform für deinen nächsten Fahrzeugschritt.</span>
          <NuxtLink to="/admin/catalog">Systemverwaltung</NuxtLink>
        </div>
      </v-container>
    </v-footer>
  </v-app>
</template>

<style scoped>
.market-topline{background:#102b43;color:#d6e1e7}
.market-topline-inner{max-width:1440px;margin:auto;padding:7px clamp(16px,4vw,56px);display:flex;align-items:center;justify-content:space-between;gap:16px;font-size:10px}
.market-topline-inner span,.market-topline-inner a{display:flex;align-items:center;gap:7px}
.market-topline-inner a{color:#d8ed9a;text-decoration:none;font-weight:700}
.market-header{border-bottom:1px solid #e8edf1!important;background:rgba(255,255,255,.97)!important;backdrop-filter:blur(16px);position:sticky}
.market-header-inner{max-width:1440px;margin:0 auto;padding:0 clamp(16px,4vw,56px)}
.market-brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#172b40;min-width:205px}
.market-brand-mark{display:grid;place-items:center;width:43px;height:43px;border-radius:13px;background:#17334d;color:#d5f078;flex:0 0 auto}
.market-brand strong{display:block;font:800 19px Manrope,sans-serif;letter-spacing:-.6px}
.market-brand small{display:block;margin-top:2px;font-size:8px;font-weight:800;letter-spacing:1.25px;color:#718195}
.market-nav{align-items:center;gap:29px;margin-left:38px;height:100%}
.market-nav a,.nav-dropdown{font:700 12px 'DM Sans',sans-serif;color:#506174;text-decoration:none;transition:color .2s;background:none;border:0;cursor:pointer;display:flex;align-items:center;gap:4px}
.market-nav a:hover,.market-nav a.active,.nav-dropdown:hover{color:#1b6b55}
.header-search{margin-right:10px;font-size:11px;font-weight:800;text-transform:none;letter-spacing:0;border-radius:9px}
.account-menu{font-size:11px;font-weight:700;text-transform:none;letter-spacing:0}
.account-label{max-width:125px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.market-main{background:#f5f7f8}
.market-mobile-drawer{background:#fff!important}
.mobile-drawer-head{display:flex;align-items:center;justify-content:space-between;padding:20px 16px}
.market-footer{display:block!important;border-top:1px solid #e6ebef!important;background:#fff!important;padding:0!important}
.market-footer-inner{max-width:1440px;margin:auto;padding:48px clamp(16px,4vw,56px) 22px!important;display:block!important}
.footer-main{display:grid;grid-template-columns:minmax(230px,1.6fr) repeat(3,minmax(130px,1fr));gap:40px;padding-bottom:38px}
.footer-about p{max-width:340px;margin:17px 0 0;color:#7c8b99;font-size:11px;line-height:1.8}
.footer-brand-link{min-width:0}
.footer-column{display:grid;align-content:start;gap:13px}
.footer-column strong{font:800 12px Manrope;color:#19354e;margin-bottom:4px}
.footer-column a{font-size:11px;color:#718091;text-decoration:none}
.footer-column a:hover,.footer-bottom a:hover{color:#1b6b55}
.footer-divider{border-color:#e9eef1!important}
.footer-bottom{display:flex;align-items:center;justify-content:space-between;gap:18px;padding-top:18px;color:#8996a1;font-size:9px}
.footer-bottom a{color:#718091;text-decoration:none}
@media(max-width:900px){.market-nav{gap:17px;margin-left:20px}.market-brand{min-width:175px}.footer-main{grid-template-columns:repeat(2,minmax(0,1fr));gap:30px}}
@media(max-width:600px){.market-topline-inner{justify-content:center;text-align:center}.market-topline-inner a{display:none}.market-brand{min-width:0}.market-brand strong{font-size:17px}.market-brand small{font-size:7px}.market-footer-inner{padding:32px 18px 18px!important}.footer-main{grid-template-columns:1fr 1fr;gap:25px 18px}.footer-about{grid-column:1/-1}.footer-about p{max-width:100%}.footer-bottom{align-items:flex-start;flex-direction:column;gap:8px}}
</style>