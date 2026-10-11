import { defineConfig, devices } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// Playwright does not load .env; make the seeded credentials + secret login
// path available to the specs (helpers.ts) without adding a dependency.
function loadDotEnv() {
  try {
    const raw = readFileSync(join(process.cwd(), '.env'), 'utf8');
    for (const line of raw.split('\n')) {
      const match = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
      if (!match) continue;
      const key = match[1];
      let value = match[2].trim();
      if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
        value = value.slice(1, -1);
      }
      if (process.env[key] === undefined) process.env[key] = value;
    }
  } catch {
    // No .env — the specs will fail fast with a clear message.
  }
}
loadDotEnv();

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://localhost:3000',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
      testIgnore: /group-batch\.spec\.ts/,
    },
    {
      // The group-chat batch flow is exercised on a phone viewport (the clinic
      // sends from its handset via the `sms:` link), so the dedicated spec runs
      // only under this mobile project.
      name: 'mobile',
      use: { ...devices['Pixel 7'] },
      testMatch: /group-batch\.spec\.ts/,
    },
  ],
  webServer: {
    // E2E runs against the production build: `next dev` wedges the login form
    // under Playwright (Next 16 instant-shell), so we test the built app.
    command: 'npm run start',
    port: 3000,
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
});
