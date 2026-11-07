import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/catalog', process.env.CATALOG_PROXY_TARGET || 'http://vehicle-catalog-service:8080'),
);
