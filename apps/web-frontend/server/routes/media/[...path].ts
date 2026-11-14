import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/media', process.env.MEDIA_PROXY_TARGET || 'http://media-service:8080'),
);
