import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const proxy = (target: string) => ({ target, changeOrigin: true, rewrite: (path: string) => path.replace(/^\\/(catalog|offers|pricing)/, '') });
  return { plugins: [vue()], server: { host: '0.0.0.0', port: 3000, proxy: {
    '/catalog': proxy(env.CATALOG_PROXY_TARGET || 'http://vehicle-catalog-service:8080'),
    '/offers': proxy(env.OFFER_PROXY_TARGET || 'http://offer-service:8080'),
    '/pricing': proxy(env.PRICING_PROXY_TARGET || 'http://pricing-service:8080')
  } } };
});