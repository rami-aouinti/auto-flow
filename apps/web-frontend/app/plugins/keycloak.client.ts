import Keycloak from 'keycloak-js';
import { setAuth } from '../../src/api';

export default defineNuxtPlugin(async () => {
  const keycloak = new Keycloak({
    url: import.meta.env.VITE_KEYCLOAK_URL || 'http://localhost:8080',
    realm: import.meta.env.VITE_KEYCLOAK_REALM || 'autoflow',
    clientId: import.meta.env.VITE_KEYCLOAK_CLIENT_ID || 'autoflow-local-client',
  });

  await keycloak.init({
    onLoad: 'login-required',
    pkceMethod: 'S256',
    checkLoginIframe: false,
  });

  setAuth(keycloak);
  window.setInterval(() => {
    keycloak.updateToken(45).catch(() => keycloak.login());
  }, 20_000);

  return {
    provide: { keycloak },
  };
});
