import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/configuration', process.env.CONFIGURATION_PROXY_TARGET || 'http://api-gateway'),
);
