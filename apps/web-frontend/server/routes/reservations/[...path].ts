import { proxyToService } from '../../utils/proxy';

export default defineEventHandler((event) =>
  proxyToService(event, '/reservations', process.env.RESERVATION_PROXY_TARGET || 'http://vehicle-reservation-service:8080'),
);
