import { test, expect } from '@playwright/test';
import { login } from './helpers';

test.describe('messages workspace', () => {
  test('owner opens the outbox and template list', async ({ page }) => {
    await login(page, 'OWNER');

    await page.goto('/messages');
    await expect(page.getByRole('heading', { name: "Today's Messages" })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Templates' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Reports' })).toBeVisible();

    await page.getByRole('link', { name: 'Templates' }).click();
    await expect(page).toHaveURL(/\/messages\/templates/);
    await expect(page.getByRole('heading', { name: /templates/i })).toBeVisible();
  });
});
