import { expect, type Page } from '@playwright/test';

/**
 * Shared E2E helpers.
 *
 * Credentials come from the same `.env` as the app (loaded in
 * playwright.config.ts). Seeded RECEPTION and STAFF accounts are created with
 * `mustChangePassword: true`, so `login()` transparently completes the forced
 * change once and remembers the derived password on later runs.
 */

export type RoleKey = 'OWNER' | 'RECEPTION' | 'STAFF';

const LOGIN_PATH = process.env.LOGIN_SECRET_PATH ?? '';

const CREDENTIALS: Record<RoleKey, { email: string; password: string }> = {
  OWNER: {
    email: process.env.OWNER_EMAIL ?? '',
    password: process.env.OWNER_PASSWORD ?? '',
  },
  RECEPTION: {
    email: process.env.RECEPTION_EMAIL ?? '',
    password: process.env.RECEPTION_PASSWORD ?? '',
  },
  STAFF: {
    email: process.env.STAFF_EMAIL ?? '',
    password: process.env.STAFF_PASSWORD ?? '',
  },
};

/** Password used after the forced first-login change (deterministic per role). */
function derivedPassword(role: RoleKey): string {
  return `${CREDENTIALS[role].password}-e2e1`;
}

function assertConfigured(role: RoleKey) {
  const { email, password } = CREDENTIALS[role];
  if (!LOGIN_PATH || !email || !password) {
    throw new Error(
      `E2E not configured: set LOGIN_SECRET_PATH and ${role}_EMAIL/${role}_PASSWORD in .env (or .env.example).`,
    );
  }
}

async function submitCredentials(page: Page, email: string, password: string) {
  // In dev, the first navigation is followed by an HMR tree swap that can
  // transiently leave the form inputs disabled; retry until they settle.
  await expect(async () => {
    const emailInput = page.locator('#email');
    const passwordInput = page.locator('#password');
    await expect(emailInput).toBeEnabled();
    await expect(passwordInput).toBeEnabled();
    await emailInput.fill(email);
    await passwordInput.fill(password);
  }).toPass({ timeout: 30_000 });
  await page.getByRole('button', { name: /sign in/i }).click();
}

async function completeForcedChange(page: Page, currentPassword: string, newPassword: string) {
  await page.fill('#currentPassword', currentPassword);
  await page.fill('#newPassword', newPassword);
  await page.fill('#confirmPassword', newPassword);
  await page.getByRole('button', { name: /save|change/i }).click();
}

/**
 * Sign in and land on /dashboard, completing the forced password change when
 * the account still requires it. Idempotent: a second run uses the derived
 * password directly.
 */
export async function login(page: Page, role: RoleKey = 'OWNER') {
  assertConfigured(role);
  const { email, password } = CREDENTIALS[role];
  const derived = derivedPassword(role);

  await page.goto(`/login/${LOGIN_PATH}`);
  await submitCredentials(page, email, password);

  const outcome = await Promise.race([
    page
      .waitForURL(/\/(dashboard|change-password)/, { timeout: 20_000 })
      .then(() => 'navigated' as const),
    page
      .getByRole('alert')
      .waitFor({ timeout: 20_000 })
      .then(() => 'error' as const),
  ]).catch(() => 'timeout' as const);

  if (outcome === 'error' || outcome === 'timeout') {
    // A previous run already changed the password to the derived value.
    await submitCredentials(page, email, derived);
    await page.waitForURL(/\/(dashboard|change-password)/, { timeout: 20_000 });
  }

  if (page.url().includes('/change-password')) {
    await completeForcedChange(page, password, derived);
    await page.waitForURL(/\/dashboard/, { timeout: 20_000 });
  }

  await expect(page).toHaveURL(/\/dashboard/);
}

/** Assert the current page redirected to the friendly 403 page. */
export async function expectForbidden(page: Page, path: string) {
  await page.goto(path);
  await expect(page).toHaveURL(/\/403/);
}
