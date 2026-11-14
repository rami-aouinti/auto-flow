import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/fulfillments', process.env.FULFILLMENT_PROXY_TARGET || 'http://fulfillment-service:8080'),
);
