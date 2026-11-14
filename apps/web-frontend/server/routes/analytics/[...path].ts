import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/analytics', process.env.ANALYTICS_PROXY_TARGET || 'http://api-gateway'),
);
