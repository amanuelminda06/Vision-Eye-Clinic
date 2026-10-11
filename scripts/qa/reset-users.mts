import { PrismaClient } from '@prisma/client';
import { hash } from '@node-rs/argon2';
import { readFileSync } from 'node:fs';

const dotenv = readFileSync('.env', 'utf8');
const val = (k: string): string => {
  const m = dotenv.split('\n').find((l) => l.startsWith(`${k}=`));
  return m ? m.slice(k.length + 1).replace(/^"|"$/g, '') : '';
};

const prisma = new PrismaClient();

const users = [
  { email: val('OWNER_EMAIL'), password: val('OWNER_PASSWORD'), mustChangePassword: false },
  { email: val('RECEPTION_EMAIL'), password: val('RECEPTION_PASSWORD'), mustChangePassword: true },
  { email: val('STAFF_EMAIL'), password: val('STAFF_PASSWORD'), mustChangePassword: true },
];

for (const u of users) {
  if (!u.email) throw new Error(`missing env for ${u.email}`);
  const passwordHash = await hash(u.password);
  await prisma.user.upsert({
    where: { email: u.email.toLowerCase() },
    update: { passwordHash, mustChangePassword: u.mustChangePassword, failedLoginCount: 0, lockedUntil: null, active: true },
    create: { email: u.email.toLowerCase(), passwordHash, name: u.email.split('@')[0], role: 'OWNER', mustChangePassword: u.mustChangePassword },
  });
  console.log(`reset ${u.email} (mustChange=${u.mustChangePassword})`);
}

await prisma.$disconnect();