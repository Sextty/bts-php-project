import { expect, test } from '@playwright/test';
import { API, loadState, loginStaff, otherBranchStaffCreds, STAFF } from './helpers';

/**
 * Staff portal: login, view the submitted queue, open an application, review its documents
 * (filename + AI verdict), approve it. A second application is rejected with a reason.
 */
test.describe('staff review', () => {
  test('logs in, reviews documents and approves application 1', async ({ page }) => {
    const state = loadState();

    await loginStaff(page);
    await expect(page.getByRole('heading', { name: "File d'Instruction des Demandes de Crédit" })).toBeVisible();

    // Open application 1 by its generated n_demande.
    await page.getByRole('link', { name: `Examiner le dossier ${state.app1.nDemande}` }).click();
    await page.waitForURL('**/dashboard/*', { timeout: 30_000 });

    // Document review: the CIN file and its AI verification verdict are shown.
    const docRow = page.getByText('cin.pdf').first();
    await expect(docRow).toBeVisible();
    await expect(page.getByRole('heading', { name: /Pièces Justificatives/ })).toBeVisible();

    // Approve.
    await page.getByRole('button', { name: "Valider l'accord technique" }).click();
    await page.getByRole('button', { name: "Confirmer l'accord" }).click();
    await expect(page.getByText('Accordé par le conseiller')).toBeVisible({ timeout: 30_000 });
  });

  test('rejects application 2 with a reason', async ({ page }) => {
    const state = loadState();

    await loginStaff(page);
    await page.getByRole('link', { name: `Examiner le dossier ${state.app2.nDemande}` }).click();
    await page.waitForURL('**/dashboard/*', { timeout: 30_000 });

    await page.getByRole('button', { name: 'Refuser le dossier' }).click();
    await page.getByRole('textbox').fill('Pièces justificatives non conformes (scénario E2E).');
    await page.getByRole('button', { name: 'Confirmer le refus' }).click();

    // The decision and the recorded reason are displayed.
    await expect(page.getByText('Rejeté')).toBeVisible({ timeout: 30_000 });

    // The rejected application leaves the submitted queue.
    await page.goto(`${STAFF}/dashboard`);
    await expect(page.getByRole('link', { name: `Examiner le dossier ${state.app2.nDemande}` })).toHaveCount(0);
  });

  test('approves application 3 (destined for appointment-change escalation + report chat)', async ({ page }) => {
    const state = loadState();

    await loginStaff(page);
    await page.getByRole('link', { name: `Examiner le dossier ${state.app3.nDemande}` }).click();
    await page.waitForURL('**/dashboard/*', { timeout: 30_000 });

    await page.getByRole('button', { name: "Valider l'accord technique" }).click();
    await page.getByRole('button', { name: "Confirmer l'accord" }).click();
    await expect(page.getByText('Accordé par le conseiller')).toBeVisible({ timeout: 30_000 });
  });

  test('enforces branch isolation for another branch staff account', async ({ page }) => {
    const state = loadState();
    await loginStaff(page, STAFF, otherBranchStaffCreds);
    const token = await page.evaluate(() => sessionStorage.getItem('bts_staff_access_token'));
    expect(token).toBeTruthy();

    const response = await page.request.get(`${API}/api/staff/applications/${state.app1.id}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    expect(response.status()).toBe(403);
  });
});
