import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/crm', process.env.CRM_PROXY_TARGET || 'http://crm-service:8080'),
);
