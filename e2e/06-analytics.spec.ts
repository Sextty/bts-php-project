import { expect, test } from '@playwright/test';
import { ADMIN, API, loginAdmin, loginStaff, STAFF } from './helpers';

test.describe('analytics dashboards', () => {
  test('renders aggregate admin and staff dashboards and enforces branch isolation', async ({ page, context }) => {
    await loginAdmin(page);
    await page.goto(`${ADMIN}/analytics`);
    await expect(page.getByRole('heading', { name: 'Analytics crédit & réseau' })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('Montant sollicité')).toBeVisible();
    await expect(page.getByText('Comparaison des agences')).toBeVisible();

    const adminToken = await page.evaluate(() => sessionStorage.getItem('bts_staff_access_token'));
    expect(adminToken).toBeTruthy();
    const globalResponse = await page.request.get(`${API}/api/staff/analytics/overview`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${adminToken}` },
    });
    expect(globalResponse.ok()).toBeTruthy();
    const globalPayload = await globalResponse.json();

    const staffPage = await context.newPage();
    await loginStaff(staffPage);
    await staffPage.goto(`${STAFF}/analytics`);
    await expect(staffPage.getByRole('heading', { name: 'Analytics opérationnels' })).toBeVisible({ timeout: 30_000 });
    await expect(staffPage.getByText('Montant sollicité')).toBeVisible();
    await expect(staffPage.getByText('Indicateurs agrégés limités à votre agence')).toBeVisible();

    const staffToken = await staffPage.evaluate(() => sessionStorage.getItem('bts_staff_access_token'));
    expect(staffToken).toBeTruthy();
    const ownResponse = await staffPage.request.get(`${API}/api/staff/analytics/overview`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${staffToken}` },
    });
    expect(ownResponse.ok()).toBeTruthy();
    const ownBranch = (await ownResponse.json()).data.meta.branch_id as number;
    const otherBranch = (globalPayload.data.branches as { branch_id: number }[])
      .find((branch) => branch.branch_id !== ownBranch)?.branch_id;
    expect(otherBranch).toBeTruthy();

    const tampered = await staffPage.request.get(`${API}/api/staff/analytics/overview?branch_id=${otherBranch}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${staffToken}` },
    });
    expect(tampered.status()).toBe(403);
  });
});
