import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/contracts', process.env.CONTRACT_PROXY_TARGET || 'http://contract-service:8080'),
);
