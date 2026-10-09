import { test, expect } from '@playwright/test';
import { login } from './helpers';

/**
 * Reception creates a patient, then books a walk-in sale and reaches the
 * receipt. Requires seeded services (prisma/seed.ts) and a running dev server.
 */
test.describe('patient → sale', () => {
  test('reception creates a patient and records a sale', async ({ page }) => {
    await login(page, 'RECEPTION');

    const stamp = Date.now().toString().slice(-8);
    const fullName = `E2E Patient ${stamp}`;
    const phone = `09${stamp}`;

    await test.step('create patient', async () => {
      await page.goto('/patients/new');
      await page.fill('#fullName', fullName);
      await page.fill('#phone', phone);
      await page.getByRole('button', { name: 'Create patient' }).click();
      await page.waitForURL(/\/patients\/[^/]+$/, { timeout: 15_000 });
      await expect(page.getByText(fullName).first()).toBeVisible();
    });

    await test.step('record walk-in sale', async () => {
      await page.goto('/sales/new');
      await page.getByRole('button', { name: 'Walk-in' }).click();

      const serviceInput = page.getByPlaceholder('Type a service, press Enter to add');
      await serviceInput.click();
      await page.getByRole('option').first().click();

      await page.getByRole('button', { name: 'Confirm sale' }).click();
      await page.waitForURL(/\/sales\/[^/]+$/, { timeout: 15_000 });
      await expect(page.getByText(/receipt/i).first()).toBeVisible();
    });
  });
});
