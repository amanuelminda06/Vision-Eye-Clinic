import { test, expect } from '@playwright/test';
import { login } from './helpers';

/**
 * A public website booking (unauthenticated POST) is auto-approved: it becomes
 * a CONFIRMED appointment that reception sees straight away. There is no
 * request queue to clear.
 */
test.describe('public booking → approved appointment', () => {
  test('website booking appears as an appointment in the ERP', async ({ page, request }) => {
    const stamp = Date.now().toString().slice(-8);
    const name = `E2E Booking ${stamp}`;
    const phone = `09${stamp}`;
    // A few days out keeps the slot in the future regardless of when this runs.
    const preferredDate = new Date(Date.now() + 3 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);

    const response = await request.post('/api/public/requests', {
      data: {
        name,
        phone,
        preferredDate,
        preferredTimeWindow: 'any',
        serviceInterest: '',
        message: 'E2E automated booking',
        language: 'en',
        consent: true,
        website: '',
      },
    });
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.ok).toBe(true);
    expect(body.appointment).toMatchObject({ date: preferredDate });
    expect(body.appointment.doctor).toBeTruthy();

    // The booking is already a real appointment — reception just sees it.
    await login(page, 'RECEPTION');
    await page.goto('/appointments');
    await expect(page.getByText(name)).toBeVisible({ timeout: 15_000 });
  });
});
