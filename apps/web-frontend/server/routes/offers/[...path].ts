import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/offers', process.env.OFFER_PROXY_TARGET || 'http://offer-service:8080'),
);
