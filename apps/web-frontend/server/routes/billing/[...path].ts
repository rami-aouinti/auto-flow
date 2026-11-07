import { proxyToService } from '../../utils/proxy';
export default defineEventHandler((event) =>
  proxyToService(event, '/billing', process.env.BILLING_PROXY_TARGET || 'http://billing-service:8080'),
);
