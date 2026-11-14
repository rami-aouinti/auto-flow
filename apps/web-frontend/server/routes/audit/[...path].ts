import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/audit', process.env.AUDIT_PROXY_TARGET || 'http://api-gateway'),
);
