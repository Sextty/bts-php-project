import { expect, test } from '@playwright/test';
import { ADMIN, API, CLIENT, loadState, loginAdmin, loginCustomer, loginStaff, STAFF } from './helpers';

/**
 * Admin portal: login, dashboard KPIs, final approval of the staff-approved applications,
 * appointment auto-proposal + customer acceptance (app1), four committed appointment changes
 * and discussion escalation (app3), live report chat over Reverb (a customer message appears in
 * the staff portal without a reload), the admin audit surface, and the broadcasting auth guard.
 */
test.describe('admin approval & realtime', () => {
  test('final-approves, customer accepts the appointment, chat is delivered live', async ({ page, context }) => {
    const state = loadState();

    // --- Admin: login + dashboard ---
    await loginAdmin(page);
    await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
    await expect(page.getByText('Total Dossiers')).toBeVisible();
    await expect(page.getByText('En attente de votre décision finale')).toBeVisible();

    // Grant final approval to app1 (staff-approved).
    await page.getByText(state.app1.nDemande).click();
    await page.waitForURL('**/applications/*', { timeout: 30_000 });
    await page.getByRole('button', { name: 'Approuver le dossier' }).click();
    await expect(page.getByText('RDV proposé', { exact: true }).first()).toBeVisible({ timeout: 30_000 });

    // Grant final approval to app3 too — it will take the lock + report path.
    await page.goto(`${ADMIN}/`);
    await page.getByText(state.app3.nDemande).click();
    await page.waitForURL('**/applications/*', { timeout: 30_000 });
    await page.getByRole('button', { name: 'Approuver le dossier' }).click();
    await expect(page.getByText('RDV proposé', { exact: true }).first()).toBeVisible({ timeout: 30_000 });

    const customerPage = await context.newPage();
    await loginCustomer(customerPage, state.customer);

    // --- app2: terminal rejection has no appointment or appointment controls. ---
    await customerPage.goto(`${CLIENT}/applications/${state.app2.id}`);
    await expect(customerPage.getByText('Dossier non retenu')).toBeVisible();
    await expect(customerPage.getByRole('link', { name: 'Gérer mon rendez-vous' })).toHaveCount(0);

    // --- app1: the approval auto-proposed an appointment — the customer accepts it. ---
    await customerPage.goto(`${CLIENT}/applications`);
    const app1Card = customerPage.getByText(state.app1.nDemande).locator('xpath=ancestor::a[1]');
    await app1Card.click();
    await customerPage.getByRole('link', { name: 'Gérer mon rendez-vous' }).click();
    await customerPage.waitForURL('**/applications/*/appointment', { timeout: 30_000 });
    await customerPage.getByRole('button', { name: 'Confirmer ce rendez-vous (Fixe & Définitif)' }).click();
    await expect(customerPage.getByText('Votre entretien en agence est validé et verrouillé !')).toBeVisible({ timeout: 30_000 });

    // --- app3: derive the live domain limit from AppointmentResource, then exhaust it. ---
    await customerPage.goto(`${CLIENT}/applications/${state.app3.id}/appointment`);
    const customerToken = await customerPage.evaluate(() => sessionStorage.getItem('bts_access_token'));
    expect(customerToken).toBeTruthy();
    const notifications = await customerPage.request.get(`${API}/api/notifications`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${customerToken}` },
    });
    expect(notifications.ok()).toBeTruthy();
    const notificationItems = (await notifications.json()).data as Array<{
      type: string;
      data?: { application_id?: number };
    }>;
    expect(notificationItems.some((item) => item.type === 'admin.approved' && item.data?.application_id === state.app3.id)).toBeTruthy();

    await expect(customerPage.getByRole('link', { name: 'Contacter mon agence' })).toHaveCount(0);
    const closedReport = await customerPage.request.get(`${API}/api/applications/${state.app3.id}/report/messages`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${customerToken}` },
    });
    expect(closedReport.status()).toBe(404);
    expect((await closedReport.json()).error.code).toBe('REPORT_NOT_OPEN');

    const appointmentContract = await customerPage.request.get(`${API}/api/applications/${state.app3.id}/appointment`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${customerToken}` },
    });
    expect(appointmentContract.ok()).toBeTruthy();
    const initialContract = (await appointmentContract.json()).data.appointment as {
      id: number;
      max_reschedules: number;
      remaining_reschedules: number;
    };
    expect(initialContract.max_reschedules).toBe(4);
    expect(initialContract.remaining_reschedules).toBe(4);
    for (let change = 1; change <= initialContract.max_reschedules; change++) {
      await customerPage.getByRole('button', { name: /Modifier le rendez-vous/ }).click();
      await customerPage.getByRole('button', { name: 'Valider le changement de créneau' }).click();
      const remaining = initialContract.max_reschedules - change;
      await expect(customerPage.getByText(`${remaining} changement${remaining === 1 ? '' : 's'} restant${remaining === 1 ? '' : 's'}`).first()).toBeVisible();
      if (remaining > 0) {
        await expect(customerPage.getByRole('link', { name: 'Contacter mon agence' })).toHaveCount(0);
      }
    }
    await expect(customerPage.getByRole('button', { name: '0 changement restant' })).toBeDisabled();
    await expect(customerPage.getByRole('link', { name: 'Contacter mon agence' })).toBeVisible();

    const exhaustedContract = await customerPage.request.get(`${API}/api/applications/${state.app3.id}/appointment`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${customerToken}` },
    });
    const exhaustedAppointment = (await exhaustedContract.json()).data.appointment as { id: number; reschedule_count: number };
    expect(exhaustedAppointment.reschedule_count).toBe(4);
    const fifthChange = await customerPage.request.post(`${API}/api/applications/${state.app3.id}/appointment/reject`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${customerToken}` },
    });
    expect(fifthChange.status()).toBe(409);
    expect((await fifthChange.json()).error.code).toBe('APPOINTMENT_RESCHEDULE_LIMIT');
    const afterBlockedChange = await customerPage.request.get(`${API}/api/applications/${state.app3.id}/appointment`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${customerToken}` },
    });
    expect((await afterBlockedChange.json()).data.appointment.id).toBe(exhaustedAppointment.id);

    // --- Empty eligible discussion: first customer message is allowed, a consecutive one is not. ---
    await customerPage.goto(`${CLIENT}/applications/${state.app3.id}/report`);
    await expect(customerPage.getByText('En direct')).toBeVisible({ timeout: 30_000 });
    await expect(customerPage.getByText("Aucun message pour l'instant")).toBeVisible();
    await expect(customerPage.getByPlaceholder('Écrivez votre message ou joignez une pièce justificative…')).toBeEnabled();

    await customerPage.getByPlaceholder('Écrivez votre message ou joignez une pièce justificative…').fill('Bonjour, merci pour l’approbation de mon dossier.');
    await customerPage.getByRole('button', { name: 'Envoyer' }).click();
    await expect(customerPage.getByText('Bonjour, merci pour l’approbation de mon dossier.')).toBeVisible({
      timeout: 30_000,
    });
    await expect(customerPage.getByText(/Votre message a été transmis/)).toBeVisible();
    await expect(customerPage.getByPlaceholder('Écrivez votre message ou joignez une pièce justificative…')).toHaveCount(0);

    // --- Assigned Staff sees the fresh case from the database and replies. ---
    const staffPage = await context.newPage();
    await loginStaff(staffPage);
    await staffPage.goto(`${STAFF}/reports`);
    await staffPage.getByRole('link', { name: `Ouvrir la discussion du dossier ${state.app3.nDemande}` }).click();
    await staffPage.waitForURL('**/reports/*', { timeout: 30_000 });
    await expect(staffPage.getByText('En direct')).toBeVisible({ timeout: 30_000 });

    await expect(staffPage.getByText('Bonjour, merci pour l’approbation de mon dossier.')).toBeVisible({
      timeout: 30_000,
    });
    await staffPage.getByPlaceholder(/Écrivez un message/).fill('Votre conseiller vous répond.');
    await staffPage.getByRole('button', { name: 'Envoyer' }).click();
    await expect(customerPage.getByText('Votre conseiller vous répond.')).toBeVisible({ timeout: 30_000 });
    await expect(customerPage.getByPlaceholder('Écrivez votre message ou joignez une pièce justificative…')).toBeEnabled();

    // Manual staff scheduling uses the same transactional protocol as automatic scheduling.
    await staffPage.getByRole('button', { name: /Fixer \/ Modifier RDV/i }).click();
    const nextWorkingDay = await staffPage.evaluate(() => {
      const value = new Date();
      value.setDate(value.getDate() + 1);
      while (value.getDay() === 0 || value.getDay() === 6) value.setDate(value.getDate() + 1);
      return value.toISOString().slice(0, 10);
    });
    await staffPage.locator('#schedule_date').fill(nextWorkingDay);
    await staffPage.locator('#schedule_time').fill('10:00');
    await staffPage.getByRole('button', { name: 'Valider et Notifier le Client' }).click();
    await expect(staffPage.getByText('Rendez-vous et agence mis à jour avec succès !')).toBeVisible({ timeout: 30_000 });

    // --- Admin audit surface: team activity on the overview. ---
    const adminPage = await context.newPage();
    await loginAdmin(adminPage);
    await expect(adminPage.getByText("Activité de l’Équipe")).toBeVisible();

    // --- Unauthorized Reverb subscription is rejected at the API level (customer token on the staff channel). ---
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
