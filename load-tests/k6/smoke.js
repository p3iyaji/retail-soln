import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const SEARCH_P95_MS = Number(__ENV.SEARCH_P95_MS || 200);
const ADMIN_EMAIL = __ENV.ADMIN_EMAIL || 'admin@retail.local';
const ADMIN_PASSWORD = __ENV.ADMIN_PASSWORD || 'password';

const productSearchDuration = new Trend('product_search_duration', true);

export const options = {
  stages: [
    { duration: '20s', target: 5 },
    { duration: '40s', target: 10 },
    { duration: '20s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{endpoint:health}': ['p(95)<500'],
    'http_req_duration{endpoint:ready}': ['p(95)<1000'],
    product_search_duration: [`p(95)<${SEARCH_P95_MS}`],
  },
};

export function setup() {
  const loginRes = http.post(
    `${BASE_URL}/api/v1/auth/login`,
    JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASSWORD }),
    { headers: { 'Content-Type': 'application/json' }, tags: { endpoint: 'login' } },
  );

  check(loginRes, {
    'login status 200': (r) => r.status === 200,
    'login returns token': (r) => Boolean(r.json('token')),
  });

  return { token: loginRes.json('token') };
}

export default function (data) {
  const headers = {
    Authorization: `Bearer ${data.token}`,
    'Content-Type': 'application/json',
  };

  const health = http.get(`${BASE_URL}/api/v1/health`, { tags: { endpoint: 'health' } });
  check(health, { 'health ok': (r) => r.status === 200 && r.json('status') === 'ok' });

  const ready = http.get(`${BASE_URL}/api/v1/health/ready`, { tags: { endpoint: 'ready' } });
  check(ready, { 'ready ok': (r) => r.status === 200 });

  const searchStart = Date.now();
  const search = http.get(`${BASE_URL}/api/v1/products/search?q=CHIP`, { headers, tags: { endpoint: 'search' } });
  productSearchDuration.add(Date.now() - searchStart);

  check(search, {
    'search status 200': (r) => r.status === 200,
    'search returns data array': (r) => Array.isArray(r.json('data')),
  });

  sleep(1);
}
