import { resolve } from 'node:path';
import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const proxy = (prefix: string, target: string) => ({
    target,
    changeOrigin: true,
    rewrite: (path: string) => path.slice(prefix.length),
  });

  return {
    plugins: [vue()],
    server: {
      host: '0.0.0.0',
      port: 3000,
      proxy: {
        '/catalog': proxy('/catalog', env.CATALOG_PROXY_TARGET || 'http://vehicle-catalog-service:8080'),
        '/offers': proxy('/offers', env.OFFER_PROXY_TARGET || 'http://offer-service:8080'),
        '/pricing': proxy('/pricing', env.PRICING_PROXY_TARGET || 'http://pricing-service:8080'),
        '/orders': proxy('/orders', env.ORDER_PROXY_TARGET || 'http://order-service:8080'),
        '/payments': proxy('/payments', env.PAYMENT_PROXY_TARGET || 'http://payment-service:8080'),
      },
    },
    build: {
      rollupOptions: {
        input: {
          main: resolve(process.cwd(), 'index.html'),
          operations: resolve(process.cwd(), 'operations.html'),
        },
      },
    },
  };
});
