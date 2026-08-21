import { expect, test } from '@playwright/test';
import {
  clearState,
  CLIENT,
  loginCustomer,
  logoutCustomer,
  readOtpForPhone,
  registerCustomer,
  saveState,
  uniqueCreds,
} from './helpers';

/**
 * Critical client auth flows: registration, OTP verification (real code from the API's
 * log-based SMS driver), dashboard, logout, and login with a fresh OTP challenge.
 */
test.describe('client auth', () => {
  test('registers with OTP and logs in with a fresh OTP', async ({ page }) => {
    test.setTimeout(480_000);
    clearState();
    const creds = uniqueCreds();

    await registerCustomer(page, creds);
    await expect(page.getByText('Welcome back')).toBeVisible();
    await expect(page.getByText(creds.email)).toBeVisible();
    await expect(page.getByText('Verified', { exact: true })).toBeVisible();

    saveState({
      customer: creds,
      app1: { id: 0, nDemande: '' },
      app2: { id: 0, nDemande: '' },
      app3: { id: 0, nDemande: '' },
    });

    await logoutCustomer(page);

    await loginCustomer(page, creds);
    await expect(page.getByText('Welcome back')).toBeVisible();

    await logoutCustomer(page);
    await page.goto(`${CLIENT}/login`);
    await page.locator('#identifier').fill(creds.email);
    await page.locator('#password').fill(creds.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL('**/login/verify-otp', { timeout: 30_000 });
    await page.locator('#otp_code').fill('000000');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByText(/incorrect|invalid|wrong/i)).toBeVisible({ timeout: 20_000 });

    await page.goto(`${CLIENT}/login`);
    await page.locator('#identifier').fill(creds.email);
    await page.locator('#password').fill(creds.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL('**/login/verify-otp', { timeout: 60_000 });
    const realCode = await readOtpForPhone(creds.phone);
    await page.locator('#otp_code').fill(realCode);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/dashboard', { timeout: 60_000 });
  });
});