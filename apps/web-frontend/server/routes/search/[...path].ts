import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/search', process.env.SEARCH_PROXY_TARGET || 'http://api-gateway'),
);
