/**
 * Password rules (rule: min 10 chars, reject common passwords).
 * Returns i18n KEYS under `validation.*` — never UI strings.
 */

export const MIN_PASSWORD_LENGTH = 10;

const COMMON_PASSWORDS = new Set(
  [
    'password',
    'password1',
    'password123',
    'p@ssw0rd',
    'p@ssword',
    'passw0rd',
    '123456',
    '12345678',
    '123456789',
    '1234567890',
    '12345678910',
    '111111',
    '123123',
    '1234567',
    '12345',
    '1234',
    '654321',
    '666666',
    '999999',
    '000000',
    'qwerty',
    'qwertyuiop',
    'qwerty123',
    'qazwsx',
    'qazwsxedc',
    'zaq12wsx',
    '1qaz2wsx',
    'abc123',
    'abcd1234',
    'iloveyou',
    'monkey',
    'dragon',
    'letmein',
    'welcome',
    'welcome1',
    'admin',
    'admin123',
    'administrator',
    'root',
    'toor',
    'login',
    'master',
    'shadow',
    'sunshine',
    'princess',
    'football',
    'baseball',
    'soccer',
    'hockey',
    'superman',
    'batman',
    'spiderman',
    'starwars',
    'trustno1',
    'mustang',
    'michael',
    'jennifer',
    'jordan23',
    'charlie',
    'thomas',
    'robert',
    'daniel',
    'hunter',
    'ranger',
    'buster',
    'summer',
    'winter',
    'autumn',
    'spring',
    'changeme',
    'secret',
    'temp123',
    'test1234',
    'testing123',
    'default',
    'pass1234',
    'letmein123',
    'iloveyou1',
    'football123',
    'google',
    'facebook',
    'amazon',
    'oracle',
    'mysql',
    'postgres',
    'sqlserver',
    'computer',
    'internet',
    'samsung',
    'iphone',
    'android',
    'nihao',
    'woaini',
    'iloveu',
    'love1234',
    'happy123',
    'freedom',
    'whatever',
    'nothing',
    'nopass',
    'nopassword',
    'passwort',
    'contraseña',
    'zxcvbnm',
    'asdfghjkl',
    'asdfgh',
    'poiuytrewq',
    'lkjhgfdsa',
  ].map((p) => p.toLowerCase()),
);

export type PasswordErrorKey = 'passwordTooShort' | 'passwordCommon' | 'passwordPersonal';

/**
 * Validates a candidate password. `email`/`name` are optional personal values
 * the password must not simply repeat (case-insensitive containment).
 * Returns an i18n key when invalid, null when acceptable.
 */
export function passwordError(
  password: string,
  opts: { email?: string; name?: string } = {},
): PasswordErrorKey | null {
  if (password.length < MIN_PASSWORD_LENGTH) return 'passwordTooShort';
  const lower = password.toLowerCase();
  if (COMMON_PASSWORDS.has(lower)) return 'passwordCommon';

  const localPart = (opts.email ?? '').split('@')[0]?.toLowerCase() ?? '';
  if (localPart.length >= 4 && lower.includes(localPart)) return 'passwordPersonal';
  const name = (opts.name ?? '').toLowerCase().trim();
  if (name.length >= 4 && lower.includes(name)) return 'passwordPersonal';
  return null;
}

const TEMP_ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/** Cryptographically random temporary password (14 chars, no ambiguous glyphs). */
export function generateTemporaryPassword(): string {
  const bytes = new Uint32Array(14);
  globalThis.crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => TEMP_ALPHABET[b % TEMP_ALPHABET.length]).join('');
}
