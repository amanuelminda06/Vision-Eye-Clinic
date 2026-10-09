import { test, expect } from '@playwright/test';
import { login, expectForbidden } from './helpers';

test.describe('role-based access', () => {
  test('owner sees the revenue link', async ({ page }) => {
    await login(page, 'OWNER');
    await expect(page.getByRole('link', { name: 'Revenue' }).first()).toBeVisible();
  });

  test('reception has no revenue link and is redirected from /revenue', async ({ page }) => {
    await login(page, 'RECEPTION');
    await expect(page.getByRole('link', { name: 'Revenue' })).toHaveCount(0);
    await expectForbidden(page, '/revenue');
  });

  test('staff cannot open patient records', async ({ page }) => {
    await login(page, 'STAFF');
    await expectForbidden(page, '/patients');
  });
});
