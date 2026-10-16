import { normalizePhone } from '../src/lib/phone';

/**
 * Fake demo patients for `prisma/seed.ts` (20 rows).
 * Phones are written in MIXED formats (09…, 9…, +251…, with spaces) and are
 * normalized to E.164 by `normalizePhone` — the same code path the app uses.
 */

export const TAG_NAMES = [
  'glasses',
  'braces',
  'contacts',
  'follow-up',
  'lens-order',
  'referral',
] as const;

export type SeedPatient = {
  fullName: string;
  /** Raw, not-yet-normalized phone (deliberately mixed formats). */
  phone: string;
  sex: 'MALE' | 'FEMALE' | 'OTHER';
  /** Calendar date `YYYY-MM-DD`. */
  birthDate: string;
  preferredLanguage: 'en' | 'am';
  doNotMessage?: boolean;
  doNotMessageReason?: string;
  active?: boolean;
  notesNonClinical?: string;
  tags: string[];
};

export const PATIENTS: SeedPatient[] = [
  {
    fullName: 'Almaz Tesfaye',
    phone: '0911223344',
    sex: 'FEMALE',
    birthDate: '1985-03-12',
    preferredLanguage: 'en',
    tags: ['glasses'],
  },
  {
    fullName: 'Bekele Wolde',
    phone: '911445566',
    sex: 'MALE',
    birthDate: '1978-11-02',
    preferredLanguage: 'en',
    tags: ['glasses', 'follow-up'],
  },
  {
    fullName: 'Tigist Alemu',
    phone: '+251912556677',
    sex: 'FEMALE',
    birthDate: '1992-07-25',
    preferredLanguage: 'am',
    tags: ['contacts'],
  },
  {
    fullName: 'Girma Hailu',
    phone: '0711223344',
    sex: 'MALE',
    birthDate: '1969-01-30',
    preferredLanguage: 'en',
    tags: [],
  },
  {
    fullName: 'Selamawit Girma',
    phone: '9 12 33 44 55',
    sex: 'FEMALE',
    birthDate: '2001-05-18',
    preferredLanguage: 'am',
    tags: ['braces'],
  },
  {
    fullName: 'Dawit Bekele',
    phone: '0911998877',
    sex: 'MALE',
    birthDate: '1988-09-09',
    preferredLanguage: 'en',
    tags: [],
  },
  {
    fullName: 'Hanna Mekonnen',
    phone: '+251913445566',
    sex: 'FEMALE',
    birthDate: '1995-12-24',
    preferredLanguage: 'am',
    tags: ['glasses'],
  },
  {
    fullName: 'Solomon Tadesse',
    phone: '0722334455',
    sex: 'MALE',
    birthDate: '1956-06-06',
    preferredLanguage: 'en',
    tags: ['referral'],
  },
  {
    fullName: 'Bereket Assefa',
    phone: '911776655',
    sex: 'MALE',
    birthDate: '1974-02-14',
    preferredLanguage: 'en',
    active: false,
    notesNonClinical: 'Deactivated after moving abroad.',
    tags: [],
  },
  {
    fullName: 'Ruth Gebre',
    phone: '0911554433',
    sex: 'FEMALE',
    birthDate: '1999-08-19',
    preferredLanguage: 'am',
    tags: ['contacts', 'follow-up'],
  },
  {
    fullName: 'Yonas Desta',
    phone: '0911667788',
    sex: 'MALE',
    birthDate: '1983-04-01',
    preferredLanguage: 'en',
    tags: ['glasses'],
  },
  {
    fullName: 'Fatuma Hassan',
    phone: '+251711665544',
    sex: 'FEMALE',
    birthDate: '1990-10-10',
    preferredLanguage: 'am',
    doNotMessage: true,
    doNotMessageReason: 'Asked to stop messages at the front desk.',
    tags: [],
  },
  {
    fullName: 'Mekdes Alemu',
    phone: '0911332211',
    sex: 'FEMALE',
    birthDate: '1987-05-05',
    preferredLanguage: 'en',
    tags: ['glasses'],
  },
  {
    // Same birth date + nearly identical name as above → the form warns (never blocks).
    fullName: 'Mekides Alemu',
    phone: '911332210',
    sex: 'FEMALE',
    birthDate: '1987-05-05',
    preferredLanguage: 'en',
    tags: [],
  },
  {
    fullName: 'Kaleb Mengistu',
    phone: '09 11 88 99 00',
    sex: 'MALE',
    birthDate: '2010-03-27',
    preferredLanguage: 'am',
    tags: ['braces'],
  },
  {
    fullName: 'Saba Tesfaye',
    phone: '0911001122',
    sex: 'FEMALE',
    birthDate: '1972-12-31',
    preferredLanguage: 'en',
    tags: ['lens-order'],
  },
  {
    fullName: 'Mulugeta Ayele',
    phone: '0711998877',
    sex: 'MALE',
    birthDate: '1965-08-08',
    preferredLanguage: 'en',
    tags: ['follow-up'],
  },
  {
    fullName: 'Helen Kebede',
    phone: '+251914556677',
    sex: 'FEMALE',
    birthDate: '1996-02-02',
    preferredLanguage: 'am',
    tags: [],
  },
  {
    fullName: 'Nahom Tsegaye',
    phone: '911887766',
    sex: 'MALE',
    birthDate: '2015-09-15',
    preferredLanguage: 'am',
    tags: ['braces'],
  },
  {
    fullName: 'Woinishet Getu',
    phone: '0911443322',
    sex: 'FEMALE',
    birthDate: '1981-06-20',
    preferredLanguage: 'en',
    notesNonClinical: 'Comes with a family member.',
    tags: ['glasses', 'contacts'],
  },
];

export type SeedPatientRow = SeedPatient & {
  phoneE164: string;
  birthDateDate: Date;
};

/** Normalizes every phone — throws loudly if seed data contains an invalid one. */
export function seedPatientRows(): SeedPatientRow[] {
  return PATIENTS.map((patient) => {
    const phoneE164 = normalizePhone(patient.phone);
    if (!phoneE164) throw new Error(`Seed patient has an invalid phone: ${patient.fullName}`);
    return { ...patient, phoneE164, birthDateDate: new Date(`${patient.birthDate}T00:00:00Z`) };
  });
}

export { tagColorFor } from '../src/modules/patients/tags';
