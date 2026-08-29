import { expect, test } from '@playwright/test';
import { SECURITY, securityCreds, suspendedSecurityCreds } from './helpers';

async function fillLogin(page: import('@playwright/test').Page, credentials: { email: string; password: string }) {
  await page.goto(`${SECURITY}/login`);
  await page.locator('#security-email').fill(credentials.email);
  await page.locator('#security-password').fill(credentials.password);
  await page.getByRole('button', { name: 'Connexion Sécurisée' }).click();
}

test.describe('Security Center authentication and authorization', () => {
  test('keeps the active session across refresh, scopes it to the tab, then logs out', async ({ page, context }) => {
    await fillLogin(page, securityCreds);
    await page.waitForURL(/\/$/, { timeout: 30_000 });
    await expect(page.getByRole('heading', { name: /Security Operations Center/ })).toBeVisible();
    await expect.poll(() => page.evaluate(() => sessionStorage.getItem('bts_security_access_token'))).not.toBeNull();
    expect(await page.evaluate(() => localStorage.getItem('bts_security_access_token'))).toBeNull();

    await page.reload();
    await expect(page.getByRole('heading', { name: /Security Operations Center/ })).toBeVisible();

    const otherTab = await context.newPage();
    await otherTab.goto(`${SECURITY}/`);
    await otherTab.waitForURL('**/login', { timeout: 30_000 });

    await page.getByRole('button', { name: 'Déconnexion' }).click();
    await page.waitForURL('**/login', { timeout: 30_000 });
    expect(await page.evaluate(() => sessionStorage.getItem('bts_security_access_token'))).toBeNull();
  });

  test('rejects a suspended Security Center account', async ({ page }) => {
    await fillLogin(page, suspendedSecurityCreds);
    await expect(page.getByText('This account is suspended.')).toBeVisible({ timeout: 30_000 });
    await expect(page).toHaveURL(/\/login$/);
  });
});
