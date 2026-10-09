import { test, expect } from '@playwright/test';
import { login } from './helpers';

test.describe('smoke', () => {
  test('owner signs in and lands on the dashboard', async ({ page }) => {
    await login(page, 'OWNER');
    await expect(page.getByRole('link', { name: 'Dashboard' }).first()).toBeVisible();
  });

  test('wrong password is rejected', async ({ page }) => {
    const path = process.env.LOGIN_SECRET_PATH ?? '';
    await page.goto(`/login/${path}`);
    await page.fill('#email', process.env.OWNER_EMAIL ?? '');
    await page.fill('#password', 'definitely-wrong-password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page.getByRole('alert')).toContainText(/incorrect/i);
  });

  test('unknown login path 404s', async ({ page }) => {
    const response = await page.goto('/login/not-the-secret');
    expect(response?.status()).toBe(404);
  });
});
