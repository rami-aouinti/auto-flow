import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/integrations', process.env.INTEGRATION_PROXY_TARGET || 'http://api-gateway'),
);
