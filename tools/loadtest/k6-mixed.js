// Mixed-workload load test for the Meydan API (k6: https://k6.io).
//
//   k6 run -e BASE_URL=http://localhost:8082/wp-json/meydan/v1 -e USERS=200 -e VUS=2000 tools/loadtest/k6-mixed.js
//
// Needs MEYDAN_DEV_OTP_CODE set on the target (local/staging only, never production) so that setup()
// can sign in USERS test accounts; the OTP/IP rate limits must be relaxed on the target for that step.
// Workload per virtual user: 60% reads (timeline / explore), 20% badge polls, 10% likes, 10% chat reads.
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://localhost:8082/wp-json/meydan/v1';
const OTP = __ENV.OTP || '482193';
const USERS = parseInt(__ENV.USERS || '100', 10);
const VUS = parseInt(__ENV.VUS || '500', 10);
const DURATION = __ENV.DURATION || '30m';

export const options = {
  scenarios: {
    mixed: {
      executor: 'ramping-vus',
      stages: [
        { duration: '5m', target: VUS },
        { duration: DURATION, target: VUS },
        { duration: '2m', target: 0 },
      ],
    },
  },
  thresholds: {
    'http_req_duration{kind:read}': ['p(95)<300'],
    'http_req_duration{kind:write}': ['p(95)<500'],
    http_req_failed: ['rate<0.005'],
  },
};

const json = { headers: { 'Content-Type': 'application/json' } };

export function setup() {
  const tokens = [];
  for (let i = 0; i < USERS; i++) {
    const phone = `+98912${String(1000000 + i).padStart(7, '0')}`;
    const c = http.post(`${BASE}/auth/otp/request`, JSON.stringify({ phone }), json);
    if (c.status !== 200) continue;
    const challenge = c.json('data.challenge_id');
    const v = http.post(`${BASE}/auth/otp/verify`, JSON.stringify({ challenge_id: challenge, code: OTP }), json);
    let access = v.json('data.access_token');
    if (!access && v.json('data.registration_token')) {
      const r = http.post(
        `${BASE}/auth/register/user`,
        JSON.stringify({ registration_token: v.json('data.registration_token'), full_name: `Load ${i}`, province_id: 1, city_id: 1 }),
        json,
      );
      access = r.json('data.access_token');
    }
    if (access) tokens.push(access);
  }
  if (tokens.length === 0) throw new Error('no test accounts could be signed in (check OTP / rate limits)');
  return { tokens };
}

function get(path, token, kind = 'read') {
  const headers = token ? { Authorization: `Bearer ${token}` } : {};
  return http.get(`${BASE}${path}`, { headers, tags: { kind } });
}

export default function (data) {
  const token = data.tokens[(__VU - 1) % data.tokens.length];
  const roll = Math.random();
  if (roll < 0.6) {
    const t = get('/timeline?mode=for_you&limit=20', token);
    check(t, { 'timeline 200': (r) => r.status === 200 });
    const next = t.json('meta.next_cursor');
    if (next) get(`/timeline?mode=for_you&limit=20&cursor=${encodeURIComponent(next)}`, token);
    if (Math.random() < 0.3) get('/explore/home', null);
  } else if (roll < 0.8) {
    check(get('/me/unread', token), { 'unread 200': (r) => r.status === 200 });
  } else if (roll < 0.9) {
    const t = get('/timeline?mode=for_you&limit=5', token);
    const items = t.json('data') || [];
    if (items.length) {
      const id = items[Math.floor(Math.random() * items.length)].id;
      http.put(`${BASE}/narratives/${id}/like`, null, { headers: { Authorization: `Bearer ${token}` }, tags: { kind: 'write' } });
    }
  } else {
    get('/me/shell', token);
    get('/chat/conversations', token);
  }
  sleep(1 + Math.random() * 4);
}
