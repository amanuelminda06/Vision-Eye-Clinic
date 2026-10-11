import path from 'node:path';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  test: {
    include: ['src/**/*.test.ts'],
    environment: 'node',
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json-summary'],
      include: [
        'src/lib/money.ts',
        'src/lib/phone.ts',
        'src/lib/datetime.ts',
        'src/lib/passwords.ts',
        'src/lib/login-policy.ts',
        'src/lib/roles.ts',
        'src/lib/require-role.ts',
        'src/lib/ratelimit.ts',
        'src/lib/ip.ts',
        'src/lib/audit.ts',
        'src/lib/audit-diff.ts',
        'src/lib/xlsx.ts',
        'src/modules/messaging/schedule.ts',
        'src/modules/messaging/render.ts',
        'src/modules/messaging/eligibility.ts',
        'src/modules/messaging/provider.ts',
        'src/modules/messaging/templates.ts',
        'src/modules/messaging/message-hash.ts',
        'src/modules/messaging/group-plan.ts',
        'src/modules/messaging/recipient-filter.ts',
        'src/modules/messaging/roles.ts',
      ],
      thresholds: {
        lines: 90,
        statements: 90,
        functions: 90,
        branches: 85,
      },
      exclude: ['src/**/*.test.ts'],
    },
  },
});