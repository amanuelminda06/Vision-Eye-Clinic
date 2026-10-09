import { test, expect } from '@playwright/test';
import { login } from './helpers';

/**
 * Staff requests leave and the owner approves it from the inbox.
 * Uses the seeded staff account (linked to the seeded doctor).
 */
test.describe('leave request → approval', () => {
  test('staff requests and owner approves', async ({ page }) => {
    const start = new Date();
    start.setDate(start.getDate() + 14);
    const end = new Date(start);
    end.setDate(end.getDate() + 2);
    const fmt = (d: Date) => d.toISOString().slice(0, 10);
    const reason = `E2E leave ${Date.now().toString().slice(-6)}`;

    await test.step('staff submits a request', async () => {
      await login(page, 'STAFF');
      await page.goto('/leave');
      await page.selectOption('#leave-type', 'ANNUAL');
      await page.fill('#leave-start', fmt(start));
      await page.fill('#leave-end', fmt(end));
      await page.fill('#leave-reason', reason);
      await page.getByRole('button', { name: 'Submit request' }).click();
      await expect(page.getByText(reason)).toBeVisible({ timeout: 15_000 });
    });

    await test.step('owner approves from the inbox', async () => {
      await login(page, 'OWNER');
      await page.goto('/leave/inbox');
      await expect(page.getByText(reason)).toBeVisible({ timeout: 15_000 });
      const row = page.locator('li, tr, .card').filter({ hasText: reason }).first();
      await row.getByRole('button', { name: 'Approve' }).click();
      await expect(row.getByRole('button', { name: 'Approve' })).toHaveCount(0, {
        timeout: 15_000,
      });
    });
  });
});
