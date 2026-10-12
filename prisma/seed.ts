import { existsSync, readFileSync } from 'node:fs';
import { hash } from '@node-rs/argon2';
import type { Prisma } from '@prisma/client';

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

type SettingSeed = { key: string; value: unknown };

const SETTINGS: SettingSeed[] = [
  { key: 'clinic_name', value: 'Vision Eye Clinic' },
  { key: 'currency', value: 'ETB' },
  { key: 'timezone', value: 'Africa/Addis_Ababa' },
  {
    key: 'working_hours',
    value: {
      mon: { open: '08:00', close: '17:00', closed: false },
      tue: { open: '08:00', close: '17:00', closed: false },
      wed: { open: '08:00', close: '17:00', closed: false },
      thu: { open: '08:00', close: '17:00', closed: false },
      fri: { open: '08:00', close: '17:00', closed: false },
      sat: { open: '08:00', close: '12:00', closed: false },
      sun: { open: '08:00', close: '12:00', closed: true },
    },
  },
  { key: 'reminder_send_time', value: '14:00' },
  { key: 'reminder_send_by_offset_minutes', value: 60 },
  { key: 'followup_lead_days', value: 3 },
  { key: 'recall_months', value: 12 },
  { key: 'review_link', value: '' },
  { key: 'sms_mode', value: 'MANUAL_LINK' },
  { key: 'reception_can_send_campaigns', value: false },
  { key: 'campaign_max_recipients', value: 300 },
  { key: 'digest_enabled', value: false },
  { key: 'digest_email', value: '' },
  { key: 'default_language', value: 'en' },
  { key: 'group_sms_allow_for_filtered', value: false },
  { key: 'group_sms_max_recipients', value: 15 },
];

async function main(): Promise<void> {
  // Fail fast on bad/missing env (validated by src/env.ts).
  const { env } = await import('../src/env');
  const { PrismaClient } = await import('@prisma/client');
  const { seedPatientRows, TAG_NAMES, tagColorFor } = await import('./seed-patients');
  const { seedServicesAndSales } = await import('./seed-services');
  const { seedHr } = await import('./seed-hr');
  const { seedAppointments } = await import('./seed-appointments');
    const { seedMessageTemplates } = await import('./seed-messages');
  const { seedMessageLibrary, seedAudiences } = await import('./seed-library');
    const { seedSiteContent } = await import('./seed-content');
  const prisma = new PrismaClient();

  try {
    for (const setting of SETTINGS) {
      await prisma.setting.upsert({
        where: { key: setting.key },
        update: {},
        create: { key: setting.key, value: setting.value as Prisma.InputJsonValue },
      });
    }
    console.log(`✓ ${SETTINGS.length} settings`);

    const doctor = await prisma.staff.upsert({
      where: { id: 'staff-doctor-1' },
      update: { fullName: 'Dr. Abebe Kebede', jobTitle: 'Ophthalmologist', isDoctor: true },
      create: {
        id: 'staff-doctor-1',
        fullName: 'Dr. Abebe Kebede',
        jobTitle: 'Ophthalmologist',
        isDoctor: true,
        specialty: 'Ophthalmology',
        showOnWebsite: true,
      },
    });
    console.log(`✓ staff: ${doctor.fullName}`);

    const users = [
      {
        email: env.OWNER_EMAIL.toLowerCase(),
        name: 'Clinic Owner',
        role: 'OWNER' as const,
        password: env.OWNER_PASSWORD,
        staffId: null,
        mustChangePassword: false,
      },
      {
        email: env.RECEPTION_EMAIL.toLowerCase(),
        name: 'Reception Desk',
        role: 'RECEPTION' as const,
        password: env.RECEPTION_PASSWORD,
        staffId: null,
        mustChangePassword: false,
      },
      {
        email: env.STAFF_EMAIL.toLowerCase(),
        name: 'Dr. Abebe Kebede',
        role: 'STAFF' as const,
        password: env.STAFF_PASSWORD,
        staffId: doctor.id,
        mustChangePassword: false,
      },
    ];

    for (const user of users) {
      const passwordHash = await hash(user.password);
      const data = {
        name: user.name,
        passwordHash,
        role: user.role,
        staffId: user.staffId,
        active: true,
        mustChangePassword: user.mustChangePassword,
        failedLoginCount: 0,
        lockedUntil: null,
      };
      await prisma.user.upsert({
        where: { email: user.email },
        update: data,
        create: { email: user.email, ...data },
      });
      console.log(`✓ user ${user.email} (${user.role})`);
    }

    // --- Tags + demo patients (idempotent, phones normalized by src/lib/phone) ---
    const owner = await prisma.user.findFirst({
      where: { role: 'OWNER' },
      orderBy: { createdAt: 'asc' },
      select: { id: true },
    });

    const tagIds = new Map<string, string>();
    for (const name of TAG_NAMES) {
      const tag = await prisma.tag.upsert({
        where: { name },
        update: {},
        create: { name, color: tagColorFor(name) },
      });
      tagIds.set(name, tag.id);
    }

    const consentAt = new Date('2026-09-01T08:30:00.000Z');
    const rows = seedPatientRows();
    let consented = 0;
    for (const row of rows) {
      const data = {
        fullName: row.fullName,
        phoneE164: row.phoneE164,
        sex: row.sex,
        birthDate: row.birthDateDate,
        preferredLanguage: row.preferredLanguage,
        notesNonClinical: row.notesNonClinical ?? null,
        consentSms: row.consentSms,
        consentDate: row.consentSms ? consentAt : null,
        consentRecordedBy: row.consentSms ? (owner?.id ?? null) : null,
        doNotMessage: row.doNotMessage ?? false,
        doNotMessageReason: row.doNotMessageReason ?? null,
        active: row.active ?? true,
        createdBy: owner?.id ?? 'seed',
      };
      const patient = await prisma.patient.upsert({
        where: { phoneE164: row.phoneE164 },
        update: data,
        create: data,
      });
      if (row.consentSms) consented += 1;

      for (const tagName of row.tags) {
        const tagId = tagIds.get(tagName);
        if (!tagId) continue;
        await prisma.patientTag.upsert({
          where: { patientId_tagId: { patientId: patient.id, tagId } },
          update: {},
          create: { patientId: patient.id, tagId },
        });
      }
    }

    // Birthday (month/day) lookup index — expression index, safe to re-run.
    await prisma.$executeRawUnsafe(
      'CREATE INDEX IF NOT EXISTS "Patient_birthday_md_idx" ON "Patient" ' +
        '((EXTRACT(MONTH FROM "birthDate") * 100 + EXTRACT(DAY FROM "birthDate")))',
    );
    console.log(
      `✓ ${rows.length} patients (${consented} with SMS consent), ${tagIds.size} tags, birthday index`,
    );

    // --- Services + demo sales (idempotent) ---
    await seedServicesAndSales(prisma);

    // --- HR: staff, shifts, templates, leave (idempotent) ---
    await seedHr(prisma);

    // --- Appointments, website requests, follow-ups (idempotent) ---
    await seedAppointments(prisma);

    // --- SMS templates: English + Amharic drafts (idempotent) ---
    const templates = await seedMessageTemplates(prisma);
    console.log(`✓ ${templates} message templates (en + am)`);

    // --- Message library + saved audiences (idempotent) ---
    const library = await seedMessageLibrary(prisma, owner?.id ?? 'seed');
    const audiences = await seedAudiences(prisma, owner?.id ?? 'seed');
    console.log(`✓ ${library} library messages, ${audiences} saved audiences`);

    // --- Public website content + public services (idempotent) ---
    const content = await seedSiteContent(prisma);
    console.log(`✓ ${content.keys} site-content keys, ${content.publicServices} public services`);

    console.log('Seed complete.');
  } finally {
    await prisma.$disconnect();
  }
}

main().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});
