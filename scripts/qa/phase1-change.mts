import { PrismaClient } from '@prisma/client';
import { readFileSync } from 'node:fs';
import { changeUserPassword } from '../../src/modules/auth';

const dotenv = readFileSync('.env', 'utf8');
const val = (k: string): string => {
  const m = dotenv.split('\n').find((l) => l.startsWith(`${k}=`));
  return m ? m.slice(k.length + 1).replace(/^"|"$/g, '') : '';
};

const BASE = process.env.BASE || 'http://127.0.0.1:3101';
const prisma = new PrismaClient();
const email = val('RECEPTION_EMAIL');
const user = { id: '', email, name: 'reception' };

let pass = 0, fail = 0;
const check = (label: string, ok: boolean, extra = '') => {
  if (ok) pass++; else fail++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${extra ? `  (${extra})` : ''}`);
};

const expectValidation = async (label: string, current: string, next: string) => {
  try {
    await changeUserPassword(user, current, next);
    check(label, false, 'did not throw');
  } catch (e) {
    const err = e as Error;
    check(label, err.name === 'ValidationError', err.name || err.constructor.name || String(e));
  }
};

const u = await prisma.user.findUnique({ where: { email } });
if (!u) {
  console.error(`user not found: ${email}`);
  process.exit(1);
}
user.id = u.id;

await expectValidation('K1 wrong current password rejected', 'wrong-current', 'Fresh-Pass-77#Xq');
await expectValidation('K2 new password too short rejected', val('RECEPTION_PASSWORD'), 'short');
await expectValidation('K3 common password rejected', val('RECEPTION_PASSWORD'), 'password123');
await expectValidation('K4 personal (email-derived) password rejected', val('RECEPTION_PASSWORD'), `Super${email.split('@')[0]}99!`);

const newPass = 'Qw9!dune-Vault#44';
await changeUserPassword(user, val('RECEPTION_PASSWORD'), newPass);
check('K5 password change succeeds (resolves)', true);

const after = await prisma.user.findUnique({ where: { id: u.id } });
check('K6 mustChangePassword cleared', after?.mustChangePassword === false);

const oldHash = u.passwordHash;
const aud = await prisma.auditLog.findFirst({
  where: { action: 'PASSWORD_CHANGED', entityId: u.id },
  orderBy: { createdAt: 'desc' },
});
check('K7 PASSWORD_CHANGED audit written', !!aud);
const json = JSON.stringify(aud?.oldJson ?? '') + JSON.stringify(aud?.newJson ?? '');
check('K8 password hashes redacted in audit', !json.includes(oldHash) && /REDACTED|\[REDACTED\]/.test(json), json.slice(0, 60));

const login = async (password: string): Promise<boolean> => {
  const csrf = await (await fetch(`${BASE}/api/auth/csrf`)).json();
  const res = await fetch(`${BASE}/api/auth/callback/credentials`, {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ csrfToken: csrf.csrfToken, email, password }).toString(),
    redirect: 'manual',
  });
  return (res.headers.get('set-cookie') || '').includes('session-token');
};

check('K9 old password no longer works', (await login(val('RECEPTION_PASSWORD'))) === false);
check('K10 new password works', (await login(newPass)) === true);

// cleanup: restore the seeded password + forced-change flag for repeat runs
import { hash } from '@node-rs/argon2';
const seedHash = await hash(val('RECEPTION_PASSWORD'));
await prisma.user.update({ where: { id: u.id }, data: { passwordHash: seedHash, mustChangePassword: true } });

await prisma.$disconnect();
console.log(`\nPHASE 1 CHANGE-PASSWORD: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);