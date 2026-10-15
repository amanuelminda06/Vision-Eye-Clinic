import { z } from 'zod';

/**
 * Fail-fast environment validation (runs from instrumentation.ts at server
 * startup, and again from auth.ts / prisma/seed.ts on import). Never log the
 * values — only which keys are missing/invalid.
 */

const requiredEmail = (label: string) =>
  z
    .string()
    .min(1, `${label} is required`)
    .regex(/^[^@\s]+@[^@\s]+\.[^@\s]+$/, `${label} must be a valid email address`);

const requiredSecret = (label: string, min: number) =>
  z.string().min(1, `${label} is required`).min(min, `${label} must be at least ${min} characters`);

const schema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  DATABASE_URL: z
    .string()
    .min(1, 'DATABASE_URL is required')
    .regex(/^postgres(ql)?:\/\//, 'DATABASE_URL must be a postgresql:// connection string'),
  AUTH_SECRET: requiredSecret('AUTH_SECRET', 32),
  LOGIN_SECRET_PATH: z
    .string()
    .min(8, 'LOGIN_SECRET_PATH must be at least 8 characters')
    .regex(/^[A-Za-z0-9_-]+$/, 'LOGIN_SECRET_PATH may only contain letters, numbers, - and _'),
  OWNER_EMAIL: requiredEmail('OWNER_EMAIL'),
  OWNER_PASSWORD: requiredSecret('OWNER_PASSWORD', 6),
  RECEPTION_EMAIL: requiredEmail('RECEPTION_EMAIL'),
  RECEPTION_PASSWORD: requiredSecret('RECEPTION_PASSWORD', 6),
  STAFF_EMAIL: requiredEmail('STAFF_EMAIL'),
  STAFF_PASSWORD: requiredSecret('STAFF_PASSWORD', 6),
  UPLOAD_DIR: z.string().min(1).default('storage/uploads'),
  /** Storage backend: `db` (Postgres blob) or `local` (ephemeral disk). */
  STORAGE_DRIVER: z.enum(['db', 'local']).default('db'),
  /** Optional bearer token for POST /api/jobs/messages (external cron). */
  CRON_SECRET: z.string().min(16, 'CRON_SECRET must be at least 16 characters').optional(),
  /** Canonical origin for the public website (metadata, JSON-LD, sitemap). */
  PUBLIC_BASE_URL: z.string().url('PUBLIC_BASE_URL must be a valid URL').optional(),
  /** Optional Cloudflare Turnstile — disabled when blank. */
  TURNSTILE_SECRET_KEY: z.string().min(1).optional(),
  NEXT_PUBLIC_TURNSTILE_SITE_KEY: z.string().min(1).optional(),
});

const result = schema.safeParse(process.env);

if (!result.success) {
  const issues = result.error.issues
    .map((issue) => `  - ${issue.path.join('.') || '(root)'}: ${issue.message}`)
    .join('\n');
  throw new Error(
    `Invalid environment configuration:\n${issues}\n\nCompare your .env with .env.example and fix the values above.`,
  );
}

export const env = result.data;
