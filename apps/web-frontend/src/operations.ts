import { createApp } from 'vue';
import Keycloak from 'keycloak-js';
import OperationsApp from './OperationsApp.vue';
import { setAuth } from './api';
import './style.css';

const keycloak = new Keycloak({
  url: import.meta.env.VITE_KEYCLOAK_URL || 'http://localhost:8080',
  realm: import.meta.env.VITE_KEYCLOAK_REALM || 'autoflow',
  clientId: import.meta.env.VITE_KEYCLOAK_CLIENT_ID || 'autoflow-local-client',
});

async function start() {
  try {
    await keycloak.init({ onLoad: 'login-required', pkceMethod: 'S256', checkLoginIframe: false });
    setAuth(keycloak);
    window.setInterval(() => keycloak.updateToken(45).catch(() => keycloak.login()), 20_000);
    createApp(OperationsApp).provide('keycloak', keycloak).mount('#app');
  } catch (error) {
    console.error(error);
    document.querySelector('#app')!.textContent = 'AutoFlow konnte Keycloak nicht erreichen.';
  }
}

void start();
