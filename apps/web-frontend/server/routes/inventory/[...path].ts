import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/inventory', process.env.INVENTORY_PROXY_TARGET || 'http://inventory-service:8080'),
);
