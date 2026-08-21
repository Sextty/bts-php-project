import { expect, test } from '@playwright/test';
import { loadState, loginStaff, STAFF } from './helpers';

/**
 * Staff portal: login, view the submitted queue, open an application, review its documents
 * (filename + AI verdict), approve it. A second application is rejected with a reason.
 */
test.describe('staff review', () => {
  test('logs in, reviews documents and approves application 1', async ({ page }) => {
    const state = loadState();

    await loginStaff(page);
    await expect(page.getByRole('heading', { name: 'Applications awaiting review' })).toBeVisible();

    // Open application 1 by its generated n_demande.
    await page.getByRole('link', { name: `Open application ${state.app1.nDemande}` }).click();
    await page.waitForURL('**/dashboard/*', { timeout: 30_000 });

    // Document review: the CIN file and its AI verification verdict are shown.
    const docRow = page.locator('li', { hasText: 'cin.pdf' }).first();
    await expect(docRow).toBeVisible();
    await expect(page.getByText('Documents').first()).toBeVisible();

    // Approve.
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.getByText(/STAFF_APPROVED|Decision recorded/i).first()).toBeVisible({ timeout: 30_000 });
  });

  test('rejects application 2 with a reason', async ({ page }) => {
    const state = loadState();

    await loginStaff(page);
    await page.getByRole('link', { name: `Open application ${state.app2.nDemande}` }).click();
    await page.waitForURL('**/dashboard/*', { timeout: 30_000 });

    await page.getByRole('button', { name: 'Reject', exact: true }).click();
    await page.locator('#reason').fill('Pièces justificatives non conformes (scénario E2E).');
    await page.getByRole('button', { name: 'Confirm rejection' }).click();

    // The decision and the recorded reason are displayed.
    await expect(page.getByText(/Reason: Pièces justificatives non conformes/)).toBeVisible({ timeout: 30_000 });

    // The rejected application leaves the submitted queue.
    await page.goto(`${STAFF}/dashboard`);
    await expect(page.getByRole('link', { name: `Open application ${state.app2.nDemande}` })).toHaveCount(0);
  });

  test('approves application 3 (destined for the appointment lock + report chat)', async ({ page }) => {
    const state = loadState();

    await loginStaff(page);
    await page.getByRole('link', { name: `Open application ${state.app3.nDemande}` }).click();
    await page.waitForURL('**/dashboard/*', { timeout: 30_000 });

    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.getByText(/STAFF_APPROVED|Decision recorded/i).first()).toBeVisible({ timeout: 30_000 });
  });
});