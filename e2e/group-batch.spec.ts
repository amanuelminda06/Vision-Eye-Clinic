import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { login } from './helpers';

/**
 * Slice 6B group-chat E2E (runs on the `mobile` project — the clinic sends
 * from a handset).
 *
 * Seeds exactly 40 tagged, consented patients, then drives the message wizard
 * through a "One group chat" campaign and verifies the recipient splitting
 * (40 → 15 / 15 / 10) appears in the campaign queue.
 */

const TAG = 'grp-e2e-2026';
const SEED = join(process.cwd(), 'prisma', 'seed-group-e2e.ts');

function seedGroup(mode: 'block' | 'allow') {
  execFileSync('npx', ['tsx', SEED, mode], { cwd: process.cwd(), stdio: 'pipe' });
}

async function fillTagAudience(page: import('@playwright/test').Page) {
  await page.goto('/messages/new');
  await page.locator('input[name="tags"]').fill(TAG);
  // The second "Preview" button is the audience preview (the first is the
  // patient type-ahead). Clicking it fills the preview stats below.
  await page.getByRole('button', { name: 'Preview' }).last().click();
  await expect(page.getByText(/can receive the message/)).toBeVisible();
}

async function reachDeliveryStep(page: import('@playwright/test').Page) {
  await page.getByRole('button', { name: 'Next' }).last().click(); // Audience → Message
  await page.getByRole('button', { name: 'Next' }).last().click(); // Message → How to send
  await expect(page.getByText('How to send')).toBeVisible();
}

async function selectGroupChat(page: import('@playwright/test').Page) {
  await page.getByLabel('One group chat').check();
}

test.describe('group chat delivery (mobile)', () => {
  test('default policy blocks a filtered audience for group chat', async ({ page }) => {
    seedGroup('block');
    await login(page, 'OWNER');
    await fillTagAudience(page);
    await reachDeliveryStep(page);
    await selectGroupChat(page);

    await expect(page.getByText('One group chat is restricted for this audience')).toBeVisible();

    const createButton = page.getByRole('button', { name: 'New message' });
    await expect(createButton).toBeDisabled();
  });

  test('with override, 40 recipients are split into 15/15/10 and marked sent', async ({
    page,
  }) => {
    seedGroup('allow');
    await login(page, 'OWNER');
    await fillTagAudience(page);
    await expect(page.getByText('40 can receive the message.')).toBeVisible();

    await reachDeliveryStep(page);
    await selectGroupChat(page);
    await expect(page.getByText('This audience is safe for one group chat.')).toBeVisible();

    await page.getByRole('checkbox').check();

    // Clicking "Send this batch" opens an sms: link — intercepted so the page
    // stays put and the confirm/skip modal can be driven.
    await page.route('sms:**', (route) => route.abort());

    await page.getByRole('button', { name: 'New message' }).click();
    await page.waitForURL(/\/messages\/campaigns\/[\w-]+/);

    // Splitting is deterministic: 15 / 15 / 10.
    await expect(page.getByText('Batch 1 — 15 recipients')).toBeVisible();
    await expect(page.getByText('Batch 2 — 15 recipients')).toBeVisible();
    await expect(page.getByText('Batch 3 — 10 recipients')).toBeVisible();

    // Drive the first batch to SENT.
    await page.getByRole('button', { name: 'Send this batch' }).first().click();
    await expect(page.getByText('Your group chat opened with the numbers above. Send it, then confirm here.')).toBeVisible();
    await page.getByRole('button', { name: 'Yes, sent' }).click();

    // After handling, the batch flips to SENT.
    await expect(page.getByText('Sent').first()).toBeVisible();
  });

  test.afterAll(async () => {
    seedGroup('block');
  });
});