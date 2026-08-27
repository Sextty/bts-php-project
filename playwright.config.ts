import { defineConfig } from '@playwright/test';

/**
 * E2E suite for the BTS Bank platform. `npm run e2e:isolated` owns a dedicated MariaDB schema
 * and dedicated ports; global setup refuses a normal development environment.
 *
 * Prerequisites (see e2e/README or the hardening report): MySQL database `bts_e2e`, the API
 * served with APP_ENV=e2e (SMS_PROVIDER=log so OTP codes land in storage/logs/laravel.log),
 * `php artisan queue:work --tries=3 --backoff=2`, `php artisan reverb:start`, and the three
 * Next.js apps in dev mode.
 *
 * Runs fully serial (single worker): every spec shares one isolated database and builds on the
 * state created by the previous spec (customer account, submitted applications, decisions).
 */
export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  timeout: 300_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
