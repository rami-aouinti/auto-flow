import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/ai', process.env.AI_PROXY_TARGET || 'http://ai-service:8080'),
);
