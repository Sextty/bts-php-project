import { expect, test } from '@playwright/test';
import { ADMIN, API, CLIENT, loadState, loginAdmin, loginCustomer, loginStaff, STAFF } from './helpers';

/**
 * Admin portal: login, dashboard KPIs, final approval of the staff-approved applications,
 * appointment auto-proposal + customer acceptance (app1), the appointment lock path after 3
 * rejections (app3), live report chat over Reverb (a message sent by the customer appears in
 * the staff portal without a reload), the admin audit surface, and the broadcasting auth guard.
 */
test.describe('admin approval & realtime', () => {
  test('final-approves, customer accepts the appointment, chat is delivered live', async ({ page, context }) => {
    const state = loadState();

    // --- Admin: login + dashboard ---
    await loginAdmin(page);
    await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();
    await expect(page.getByText('Total applications')).toBeVisible();
    await expect(page.getByText('Awaiting final decision')).toBeVisible();

    // Grant final approval to app1 (staff-approved).
    await page.getByRole('link', { name: `Open application ${state.app1.nDemande}` }).click();
    await page.waitForURL('**/applications/*', { timeout: 30_000 });
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.getByText(/APPROVED|Decision recorded/i).first()).toBeVisible({ timeout: 30_000 });

    // Grant final approval to app3 too — it will take the lock + report path.
    await page.goto(`${ADMIN}/`);
    await page.getByRole('link', { name: `Open application ${state.app3.nDemande}` }).click();
    await page.waitForURL('**/applications/*', { timeout: 30_000 });
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.getByText(/APPROVED|Decision recorded/i).first()).toBeVisible({ timeout: 30_000 });

    const customerPage = await context.newPage();
    await loginCustomer(customerPage, state.customer);

    // --- app1: the approval auto-proposed an appointment — the customer accepts it. ---
    await customerPage.goto(`${CLIENT}/applications`);
    const app1Card = customerPage.getByText(state.app1.nDemande).locator('xpath=ancestor::a[1]');
    await app1Card.click();
    await customerPage.waitForURL('**/applications/*/appointment', { timeout: 30_000 });
    await customerPage.getByRole('button', { name: 'Accept this time' }).click();
    await expect(customerPage.getByText('Your appointment is confirmed.')).toBeVisible({ timeout: 30_000 });

    // --- app3: the customer rejects all 3 proposals; the application locks and the report opens. ---
    await customerPage.goto(`${CLIENT}/applications/${state.app3.id}/appointment`);
    for (let attempt = 0; attempt < 3; attempt++) {
      await customerPage.getByRole('button', { name: 'Reject', exact: true }).click();
      await customerPage.getByRole('button', { name: 'Yes, reject' }).click();
    }
    await expect(customerPage.getByRole('button', { name: 'Talk to BTS Bank' })).toBeVisible({ timeout: 30_000 });

    // --- Realtime chat over Reverb: staff subscribes FIRST, customer sends, staff sees it live. ---
    const staffPage = await context.newPage();
    await loginStaff(staffPage);
    await staffPage.goto(`${STAFF}/reports`);
    await staffPage.getByRole('link', { name: `Open report for ${state.app3.nDemande}` }).click();
    await staffPage.waitForURL('**/reports/*', { timeout: 30_000 });
    await expect(staffPage.getByText('Live')).toBeVisible({ timeout: 30_000 });

    const staffMessageCount = () => staffPage.locator('[role="log"] > div').count();

    await customerPage.goto(`${CLIENT}/applications/${state.app3.id}/report`);
    await expect(customerPage.getByText('Live')).toBeVisible({ timeout: 30_000 });
    const before = await staffMessageCount();

    await customerPage.getByLabel('Write a message').fill('Bonjour, merci pour l’approbation de mon dossier.');
    await customerPage.getByRole('button', { name: 'Send message' }).click();
    await expect(customerPage.getByText('Bonjour, merci pour l’approbation de mon dossier.')).toBeVisible({
      timeout: 30_000,
    });

    // The staff page receives the message over the WebSocket — no reload.
    await expect
      .poll(async () => staffMessageCount(), { timeout: 30_000 })
      .toBeGreaterThan(before);
    await expect(staffPage.getByText('Bonjour, merci pour l’approbation de mon dossier.')).toBeVisible({
      timeout: 10_000,
    });

    // --- Admin audit surface: team activity on the overview. ---
    const adminPage = await context.newPage();
    await loginAdmin(adminPage);
    await expect(adminPage.getByText('Team activity')).toBeVisible();

    // --- Unauthorized Reverb subscription is rejected at the API level (customer token on the staff channel). ---
    const customerToken = await customerPage.evaluate(() => localStorage.getItem('bts_access_token'));
    const apiResponse = await customerPage.request.post(`${API}/broadcasting/auth`, {
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'application/json',
        Authorization: `Bearer ${customerToken}`,
      },
      form: { socket_id: '123456.789', channel_name: 'private-staff' },
    });
    expect(apiResponse.status()).toBe(403);
  });
});