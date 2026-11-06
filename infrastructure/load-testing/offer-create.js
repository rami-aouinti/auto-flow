import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const failures = new Rate('autoflow_request_failures');
const offerCreateDuration = new Trend('autoflow_offer_create_duration', true);
const baseUrl = (__ENV.OFFER_BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const token = __ENV.ACCESS_TOKEN || '';
const vehicleId = __ENV.VEHICLE_ID || '';

export const options = {
  scenarios: { offer_creation: { executor: 'ramping-vus', startVUs: Number(__ENV.START_VUS || 1), stages: [
    { duration: __ENV.RAMP_UP || '30s', target: Number(__ENV.TARGET_VUS || 20) },
    { duration: __ENV.HOLD || '2m', target: Number(__ENV.TARGET_VUS || 20) },
    { duration: __ENV.RAMP_DOWN || '30s', target: 0 },
  ], gracefulRampDown: '30s' } },
  thresholds: { http_req_failed: ['rate<0.02'], http_req_duration: ['p(95)<1500','p(99)<3000'], autoflow_request_failures: ['rate<0.02'], autoflow_offer_create_duration: ['p(95)<1500'] },
};

export function setup() {
  if (!token || !vehicleId) throw new Error('ACCESS_TOKEN and VEHICLE_ID are required.');
  const response = http.get(baseUrl + '/health/ready', { timeout: '10s' });
  check(response, { 'Offer Service readiness is HTTP 200': (r) => r.status === 200 });
  if (response.status !== 200) throw new Error('Offer Service is not ready (HTTP ' + response.status + ').');
  return { token, vehicleId };
}

export default function (data) {
  const payload = JSON.stringify({ vehiclePrice: __ENV.VEHICLE_PRICE || '32000.00', downPayment: __ENV.DOWN_PAYMENT || '4000.00', residualValue: __ENV.RESIDUAL_VALUE || '12000.00', currency: 'EUR', durationMonths: Number(__ENV.DURATION_MONTHS || 48), annualInterestRateBasisPoints: Number(__ENV.INTEREST_RATE_BPS || 425) });
  const response = http.post(baseUrl + '/api/offers/' + encodeURIComponent(data.vehicleId), payload, { headers: { Authorization: 'Bearer ' + data.token, 'Content-Type': 'application/json', Accept: 'application/json' }, timeout: '15s', tags: { service: 'offer-service', operation: 'create-offer' } });
  offerCreateDuration.add(response.timings.duration);
  const success = check(response, { 'offer creation returns 2xx': (r) => r.status >= 200 && r.status < 300, 'offer response is JSON': (r) => (r.headers['Content-Type'] || '').includes('application/json') });
  failures.add(!success);
  sleep(Number(__ENV.THINK_TIME_SECONDS || 1));
}