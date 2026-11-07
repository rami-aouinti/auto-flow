import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/pricing', process.env.PRICING_PROXY_TARGET || 'http://pricing-service:8080'),
);
