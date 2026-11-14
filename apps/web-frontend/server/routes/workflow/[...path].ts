import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/workflow', process.env.WORKFLOW_PROXY_TARGET || 'http://api-gateway'),
);
