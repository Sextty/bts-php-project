import { expect, test } from '@playwright/test';
import { completeApplication, createApplication, loadState, loginCustomer, readNDemande, saveState } from './helpers';

/**
 * The full customer application journey: create → 4 steps → CIN document upload → AI
 * verification (real Gemini when configured, graceful degradation otherwise) → validation 1 →
 * finalize → submit. Runs three times: application 1 goes to staff approval + appointment
 * acceptance, application 2 to a staff rejection, application 3 to staff approval + the
 * appointment lock path (3 rejections opens the report chat) — all covered in later specs.
 */
test.describe('client application journey', () => {
  test('creates three applications through the complete workflow and tracks them', async ({ page }) => {
    const state = loadState();

    await loginCustomer(page, state.customer);

    // --- Application 1: full happy path ---
    const app1Id = await createApplication(page);
    await completeApplication(page, app1Id);
    const n1 = await readNDemande(page);

    // AI verification result is surfaced for the uploaded document (verified / invalid /
    // awaiting) — the badge is always present next to the document.
    const docRow = page.locator('li', { hasText: 'cin.pdf' }).first();
    await expect(docRow).toBeVisible();
    const badgeText = await docRow.locator('span, p').allTextContents();
    expect(badgeText.some((t) => /verif|Awaiting/i.test(t))).toBeTruthy();

    // --- Application 2: same journey (destined for the staff rejection flow) ---
    const app2Id = await createApplication(page);
    await completeApplication(page, app2Id);
    const n2 = await readNDemande(page);

    // --- Application 3: same journey (destined for the appointment lock + report chat) ---
    const app3Id = await createApplication(page);
    await completeApplication(page, app3Id);
    const n3 = await readNDemande(page);

    // Track: the applications list shows both applications with their generated n_demande.
    await page.goto(`${'http://127.0.0.1:3000'}/applications`);
    await expect(page.getByText(n1)).toBeVisible();
    await expect(page.getByText(n2)).toBeVisible();
    await expect(page.getByText(n3)).toBeVisible();

    saveState({
      customer: state.customer,
      app1: { id: app1Id, nDemande: n1 },
      app2: { id: app2Id, nDemande: n2 },
      app3: { id: app3Id, nDemande: n3 },
    });
  });
});