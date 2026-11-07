import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/payments', process.env.PAYMENT_PROXY_TARGET || 'http://payment-service:8080'),
);
