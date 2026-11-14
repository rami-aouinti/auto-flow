import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/documents', process.env.DOCUMENT_PROXY_TARGET || 'http://api-gateway'),
);
