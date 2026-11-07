import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/orders', process.env.ORDER_PROXY_TARGET || 'http://order-service:8080'),
);
