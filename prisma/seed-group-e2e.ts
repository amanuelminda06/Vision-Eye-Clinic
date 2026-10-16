import { existsSync, readFileSync } from 'node:fs';

/** tsx does not load .env automatically — minimal loader for seed runs. */
function loadEnv(): void {
  const url = new URL('../.env', import.meta.url);
  if (!existsSync(url)) return;
  for (const line of readFileSync(url, 'utf8').split('\n')) {
    const match = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
    if (!match) continue;
    let value = match[2];
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    if (!(match[1] in process.env)) process.env[match[1]] = value;
  }
}

loadEnv();

/**
 * Deterministic E2E fixture for the group-chat batch flow: exactly 40 active,
 * English-speaking patients tagged with a unique marker, plus
 * toggling the OWNER-only "allow group for filtered audiences" override.
 *
 * USAGE:
 *   tsx prisma/seed-group-e2e.ts <block|allow>
 */
const TAG = 'grp-e2e-2026';
const PATIENT_COUNT = 40;

function phoneFor(index: number): string {
  const digits = (900000000 + index).toString(); // 900000000..900000039 → +2519…
  return `+251${digits}`;
}

async function main(): Promise<void> {
  const { PrismaClient } = await import('@prisma/client');
  const prisma = new PrismaClient();
  const mode = process.argv[2] ?? 'block';

  try {
    await prisma.setting.upsert({
      where: { key: 'group_sms_allow_for_filtered' },
      update: { value: mode === 'allow' },
      create: { key: 'group_sms_allow_for_filtered', value: mode === 'allow' },
    });
    await prisma.setting.upsert({
      where: { key: 'group_sms_max_recipients' },
      update: { value: 15 },
      create: { key: 'group_sms_max_recipients', value: 15 },
    });

    if (mode === 'allow') {
      const tag = await prisma.tag.upsert({
        where: { name: TAG },
        update: {},
        create: { name: TAG, color: '#0ea5e9' },
      });

      for (let index = 0; index < PATIENT_COUNT; index += 1) {
        const phoneE164 = phoneFor(index);
        const data = {
          fullName: `Group E2E Patient ${index + 1}`,
          phoneE164,
          sex: index % 2 === 0 ? ('FEMALE' as const) : ('MALE' as const),
          preferredLanguage: 'en',
          doNotMessage: false,
          active: true,
          createdBy: 'e2e-seed',
        };
        const patient = await prisma.patient.upsert({
          where: { phoneE164 },
          update: data,
          create: { id: `grp-e2e-${String(index).padStart(3, '0')}`, ...data },
        });
        await prisma.patientTag.upsert({
          where: { patientId_tagId: { patientId: patient.id, tagId: tag.id } },
          update: {},
          create: { patientId: patient.id, tagId: tag.id },
        });
      }
      console.log(`✓ ${PATIENT_COUNT} tagged patients (tag: ${TAG})`);
    } else {
      console.log(`✓ group override set to block; tagged patients left intact`);
    }
  } finally {
    await prisma.$disconnect();
  }

  console.log(`✓ group_sms_allow_for_filtered = ${mode === 'allow'}`);
}

main().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});