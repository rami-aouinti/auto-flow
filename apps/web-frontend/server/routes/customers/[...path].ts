import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/customers', process.env.CUSTOMER_PROXY_TARGET || 'http://customer-service:8080'),
);
