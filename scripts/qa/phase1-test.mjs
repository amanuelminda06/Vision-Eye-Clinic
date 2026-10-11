import { readFileSync } from 'node:fs';
import { Client } from 'pg';

const dotenv = readFileSync('.env', 'utf8');
const val = (k) => {
  const m = dotenv.split('\n').find((l) => l.startsWith(`${k}=`));
  return m ? m.slice(k.length + 1).replace(/^"|"$/g, '') : '';
};

const BASE = 'http://127.0.0.1:3101';
const SECRET = val('LOGIN_SECRET_PATH');
const LOGIN = `/login/${SECRET}`;
const DB = 'postgresql://postgres:postgres@127.0.0.1:5433/vision_erp_test';

const jar = new Map();
const cookieHeader = () =>
  [...jar.entries()].map(([k, v]) => `${k}=${v.split(';')[0]}`).join('; ');

const setCookies = (res) => {
  const list = typeof res.headers.getSetCookie === 'function' ? res.headers.getSetCookie() : [];
  for (const raw of list) {
    const pair = raw.split(';')[0];
    const i = pair.indexOf('=');
    if (i < 0) continue;
    const name = pair.slice(0, i).trim();
    const value = pair.slice(i + 1).trim();
    if (/max-age=0/i.test(raw) || value === '') jar.delete(name);
    else jar.set(name, value);
  }
};

async function req(path, { method = 'GET', body, headers = {} } = {}) {
  const res = await fetch(BASE + path, {
    method,
    headers: { cookie: cookieHeader(), ...(body ? { 'content-type': 'application/x-www-form-urlencoded' } : {}), ...headers },
    body,
    redirect: 'manual',
  });
  setCookies(res);
  return res;
}

const db = new Client({ connectionString: DB });
await db.connect();

const receptIdRow = await db.query(`select id from "User" where email=$1`, [val('RECEPTION_EMAIL')]);
const RECEPTION_ID = receptIdRow.rows[0].id;

const auditCount = async (where) => {
  const r = await db.query(`select count(*)::int as n from "AuditLog" where ${where}`);
  return r.rows[0].n;
};

let pass = 0, fail = 0;
const check = (label, ok, extra = '') => {
  if (ok) pass++; else fail++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${extra ? `  (${extra})` : ''}`);
};

// ---- A: secret login URL is the only entry point ----
let r = await req(LOGIN);
check('A1 login page (secret URL) returns 200', r.status === 200, `status=${r.status}`);

r = await req('/login');
check('A2 /login returns real 404 (scanner defense)', r.status === 404, `status=${r.status}`);

r = await req('/login/guessme');
check('A3 /login/anything returns 404', r.status === 404, `status=${r.status}`);

// ---- B: unauthenticated access redirects to the secret URL ----
r = await req('/dashboard');
const loc = r.headers.get('location') || '';
check('B1 /dashboard without session redirects to secret login', [302, 307].includes(r.status) && loc === LOGIN, `status=${r.status} loc=${loc}`);

// ---- C: login as OWNER ----
const csrf = await (await req('/api/auth/csrf')).json();
const ownerLogin = async () =>
  req('/api/auth/callback/credentials', {
    method: 'POST',
    body: new URLSearchParams({
      csrfToken: csrf.csrfToken,
      email: val('OWNER_EMAIL'),
      password: val('OWNER_PASSWORD'),
      callbackUrl: BASE + '/dashboard',
    }).toString(),
  });

r = await ownerLogin();
const sc = r.headers.get('set-cookie') || '';
check('C1 owner login issues a session cookie', r.status === 302 && /authjs\.session-token/.test(sc), `status=${r.status}`);
check('C2 session cookie is HttpOnly', /session-token=[^;]+;.*HttpOnly/i.test(sc), '');
check('C3 session cookie SameSite=Lax', /SameSite=Lax/i.test(sc), '');
check('C4 session cookie Path=/', /Path=\//i.test(sc), '');
const maxAge = /Max-Age=(\d+)/i.exec(sc)?.[1];
check('C5 session cookie Max-Age≈12h', maxAge && +maxAge === 43200, `maxAge=${maxAge}`);

r = await req('/dashboard');
check('C6 owner reaches /dashboard with session', r.status === 200, `status=${r.status}`);

r = await req('/api/auth/session');
const sess = await r.json();
check('C7 session exposes OWNER role + no forced change', sess.user?.role === 'OWNER' && sess.user?.mustChangePassword === false, JSON.stringify(sess.user));

// ---- D: failed login (wrong password) never issues a session cookie ----
const beforeFail = await auditCount(`action='LOGIN_FAILED' and "entityId"='${RECEPTION_ID}'`);
let wrong;
for (let i = 0; i < 5; i++) {
  wrong = await req('/api/auth/callback/credentials', {
    method: 'POST',
    body: new URLSearchParams({
      csrfToken: csrf.csrfToken,
      email: val('RECEPTION_EMAIL'),
      password: 'definitely-wrong-password',
    }).toString(),
  });
}
check('D1 5 wrong passwords issue no session cookie', !(wrong.headers.get('set-cookie') || '').includes('session-token'), `status=${wrong.status}`);
const afterFail = await auditCount(`action='LOGIN_FAILED' and "entityId"='${RECEPTION_ID}'`);
check('D2 each failure is audited (+5)', afterFail - beforeFail === 5, `delta=${afterFail - beforeFail}`);

// ---- E: account lockout (5th failure) ----
const lock = await db.query(`select "failedLoginCount", "lockedUntil" from "User" where email=$1`, [val('RECEPTION_EMAIL')]);
check('E1 failedLoginCount=5', lock.rows[0].failedLoginCount === 5, `count=${lock.rows[0].failedLoginCount}`);
check('E2 lockedUntil set in the future', !!lock.rows[0].lockedUntil && new Date(lock.rows[0].lockedUntil).getTime() > Date.now());

const beforeLocked = await auditCount(`action='LOGIN_FAILED' and "entityId"='${RECEPTION_ID}'`);
const lockedLogin = await req('/api/auth/callback/credentials', {
  method: 'POST',
  body: new URLSearchParams({ csrfToken: csrf.csrfToken, email: val('RECEPTION_EMAIL'), password: val('RECEPTION_PASSWORD') }).toString(),
});
const afterLocked = await auditCount(`action='LOGIN_FAILED' and "entityId"='${RECEPTION_ID}'`);
check('E3 correct password still rejected while locked', (lockedLogin.headers.get('set-cookie') || '').includes('session-token') === false, `status=${lockedLogin.status}`);
check('E4 locked rejection is audited distinctly', afterLocked - beforeLocked >= 1, `delta=${afterLocked - beforeLocked}`);
const lk = await db.query(`select "newJson" from "AuditLog" where action='LOGIN_FAILED' and "entityId"=$1 order by "createdAt" desc limit 1`, [RECEPTION_ID]);
check('E5 audit records reason=locked', /locked/.test(JSON.stringify(lk.rows[0].newJson)), JSON.stringify(lk.rows[0].newJson));

// ---- F: per-IP login rate limiting (30 / 5 min window) ----
const ip = '203.0.113.77';
const beforeRate = await auditCount(`action='LOGIN_FAILED' and "entityId"='email:ghost@example.org'`);
for (let i = 0; i < 30; i++) {
  const c = await req('/api/auth/csrf', { headers: { 'x-forwarded-for': ip } });
  const ct = await c.json();
  await req('/api/auth/callback/credentials', {
    method: 'POST',
    headers: { 'x-forwarded-for': ip },
    body: new URLSearchParams({ csrfToken: ct.csrfToken, email: 'ghost@example.org', password: 'nope' }).toString(),
  });
}
const afterRate30 = await auditCount(`action='LOGIN_FAILED' and "entityId"='email:ghost@example.org'`);
check('F1 30 attempts are processed (audited)', afterRate30 - beforeRate === 30, `delta=${afterRate30 - beforeRate}`);

const c31 = await req('/api/auth/csrf', { headers: { 'x-forwarded-for': ip } });
const ct31 = await c31.json();
await req('/api/auth/callback/credentials', {
  method: 'POST',
  headers: { 'x-forwarded-for': ip },
  body: new URLSearchParams({ csrfToken: ct31.csrfToken, email: 'ghost@example.org', password: 'nope' }).toString(),
});
const afterRate31 = await auditCount(`action='LOGIN_FAILED' and "entityId"='email:ghost@example.org'`);
check('F2 the 31st attempt is rate-limited (no audit, no DB touch)', afterRate31 - afterRate30 === 0, `delta=${afterRate31 - afterRate30}`);

// ---- G: unlock reception, then force-change redirect ----
await db.query(`update "User" set "failedLoginCount"=0, "lockedUntil"=null where email=$1`, [val('RECEPTION_EMAIL')]);

let rc = await req('/api/auth/csrf');
const receptCsrf = (await rc.json()).csrfToken;
rc = await req('/api/auth/callback/credentials', {
  method: 'POST',
  body: new URLSearchParams({ csrfToken: receptCsrf, email: val('RECEPTION_EMAIL'), password: val('RECEPTION_PASSWORD') }).toString(),
});
check('G1 reception logs in after unlock', rc.status === 302 && (rc.headers.get('set-cookie') || '').includes('session-token'), `status=${rc.status}`);

r = await req('/api/auth/session');
const rs = await r.json();
check('G2 reception session flags forced password change', rs.user?.mustChangePassword === true, JSON.stringify(rs.user));

r = await req('/dashboard');
const rloc = r.headers.get('location') || '';
const body = await r.text();
const flightRedirect = (body.match(/NEXT_REDIRECT;replace;([^;\\\\"']+)/) || [])[1] || '';
check(
  'G3 forced-change user is pushed to /change-password',
  ([302, 307].includes(r.status) && rloc.endsWith('/change-password')) || flightRedirect.endsWith('/change-password'),
  `status=${r.status} loc=${rloc} flight=${flightRedirect}`,
);

// ---- G4: role gate precedence (mustChangePassword → change-password, not 403)
await db.query(`update "User" set "mustChangePassword"=true where email=$1`, [val('RECEPTION_EMAIL')]);
r = await req('/users');
const usersBody = await r.text();
const usersFlight = (usersBody.match(/NEXT_REDIRECT;replace;([^;\\\\"']+)/) || [])[1] || '';
check('G4 mustChangePassword takes precedence (redirects to /change-password before FORBIDDEN)',
  ([302, 307].includes(r.status) && (r.headers.get('location') || '').endsWith('/change-password')) || usersFlight.endsWith('/change-password'),
  `status=${r.status} flight=${usersFlight}`);

// ---- H: logout clears the session ----
rc = await req('/api/auth/signout', {
  method: 'POST',
  body: new URLSearchParams({ csrfToken: receptCsrf, callbackUrl: BASE + LOGIN }).toString(),
});
check('H1 sign-out clears the session cookie', !jar.has('authjs.session-token'), '');
r = await req('/dashboard');
check('H2 /dashboard protected again after logout', [302, 307].includes(r.status) && (r.headers.get('location') || '') === LOGIN, `status=${r.status}`);

await db.end();
console.log(`\nPHASE 1 HTTP AUTH: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);