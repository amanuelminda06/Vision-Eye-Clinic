import { test, expect } from '@playwright/test';
import { login } from './helpers';

/**
 * A public booking request (unauthenticated POST) shows up in the reception
 * requests queue and can be converted to an appointment.
 */
test.describe('request → appointment', () => {
  test('public request appears in the queue and converts', async ({ page, request }) => {
    const stamp = Date.now().toString().slice(-8);
    const name = `E2E Request ${stamp}`;
    const phone = `09${stamp}`;
    const preferredDate = new Date().toISOString().slice(0, 10);

    const response = await request.post('/api/public/requests', {
      data: {
        name,
        phone,
        preferredDate,
        preferredTimeWindow: 'any',
        serviceInterest: '',
        message: 'E2E automated booking request',
        language: 'en',
        consent: true,
        website: '',
      },
    });
    expect(response.ok()).toBeTruthy();

    await login(page, 'RECEPTION');
    await page.goto('/requests');
    await expect(page.getByText(name)).toBeVisible({ timeout: 15_000 });

    await page.getByRole('link', { name: /convert/i }).first().click();
    await expect(page.getByText(/book the appointment/i)).toBeVisible();
  });
});
