import Keycloak from 'keycloak-js';
import { setAuth } from '../../src/api';

export default defineNuxtPlugin(async () => {
  let keycloak: Keycloak;
  if (import.meta.env.VITE_E2E_TEST_MODE === 'true') {
    keycloak = {
      token: 'autoflow-playwright-token',
      tokenParsed: { preferred_username: 'playwright' },
      logout: async () => undefined,
    } as unknown as Keycloak;
  } else {
    keycloak = new Keycloak({
      url: import.meta.env.VITE_KEYCLOAK_URL || 'http://localhost:8080',
      realm: import.meta.env.VITE_KEYCLOAK_REALM || 'autoflow',
      clientId: import.meta.env.VITE_KEYCLOAK_CLIENT_ID || 'autoflow-local-client',
    });
    await keycloak.init({
      onLoad: 'login-required',
      pkceMethod: 'S256',
      checkLoginIframe: false,
    });
    window.setInterval(() => {
      keycloak.updateToken(45).catch(() => keycloak.login());
    }, 20_000);
  }

  setAuth(keycloak);
  return { provide: { keycloak } };
});
