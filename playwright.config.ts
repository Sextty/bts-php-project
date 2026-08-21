import { defineConfig } from '@playwright/test';

/**
 * E2E suite for the BTS Bank platform (client :3000, staff :3001, admin :3002, API :8000,
 * Reverb :6001).
 *
 * Prerequisites (see e2e/README or the hardening report): MySQL database `bts_e2e`, the API
 * served with APP_ENV=e2e (SMS_PROVIDER=log so OTP codes land in storage/logs/laravel.log),
 * `php artisan queue:work --tries=3 --backoff=2`, `php artisan reverb:start`, and the three
 * Next.js apps in dev mode.
 *
 * Runs fully serial (single worker): every spec shares one MySQL database and builds on the
 * state created by the previous spec (customer account, submitted applications, decisions).
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 300_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});