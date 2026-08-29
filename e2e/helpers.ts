import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, type Page } from '@playwright/test';

export const CLIENT = process.env.E2E_CLIENT_URL ?? 'http://127.0.0.1:3000';
export const STAFF = process.env.E2E_STAFF_URL ?? 'http://127.0.0.1:3001';
export const ADMIN = process.env.E2E_ADMIN_URL ?? 'http://127.0.0.1:3002';
export const SECURITY = process.env.E2E_SECURITY_URL ?? 'http://127.0.0.1:3003';
export const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000';
export const API_URL = API;

const OTP_FILE = process.env.E2E_OTP_FILE ?? join(__dirname, '.e2e-otp.jsonl');

/** Fresh unique identity for every run so repeat runs never collide. */
export function uniqueCreds(): { email: string; phone: string; password: string } {
  const stamp = Date.now().toString().slice(-10);
  const phone = `+2162${stamp.slice(-7)}`;
  return {
    email: `e2e.customer.${stamp}@bts.test`,
    phone,
    password: `E2E-Customer-${stamp}!`,
  };
}

export interface E2EState {
  customer: { email: string; phone: string; password: string };
  app1: { id: number; nDemande: string };
  app2: { id: number; nDemande: string };
  app3: { id: number; nDemande: string };
}

const STATE_FILE = process.env.E2E_STATE_FILE ?? join(__dirname, '.e2e-state.json');

export function saveState(state: E2EState): void {
  writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
}

export function loadState(): E2EState {
  if (!existsSync(STATE_FILE)) throw new Error('No e2e state — run client-register.spec.ts first.');
  return JSON.parse(readFileSync(STATE_FILE, 'utf8'));
}

export function clearState(): void {
  try {
    rmSync(STATE_FILE, { force: true });
  } catch {
    /* already gone */
  }
}

/**
 * Isolated E2E runs use a dedicated ephemeral transport file. OTP values never enter normal
 * application logs, and the runner deletes the file during teardown.
 */
export async function readOtpForPhone(phone: string): Promise<string> {
  const records = readFileSync(OTP_FILE, 'utf8')
    .split(/\r?\n/)
    .filter(Boolean)
    .map((line) => JSON.parse(line) as { phone: string; code: string });
  const match = records.filter((record) => record.phone === phone).at(-1);
  if (!match) {
    throw new Error(`No OTP found in ephemeral E2E channel for ${phone}`);
  }
  return match.code;
}

export async function registerCustomer(page: Page, creds: { email: string; phone: string; password: string }) {
  await page.goto(`${CLIENT}/register`);
  await page.locator('#first_name').fill('E2E');
  await page.locator('#last_name').fill('Customer');
  await page.locator('#email').fill(creds.email);
  await page.locator('#phone').fill(creds.phone);
  await page.locator('#password').fill(creds.password);
  await page.locator('#password_confirmation').fill(creds.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/register/verify-otp', { timeout: 30_000 });

  const code = await readOtpForPhone(creds.phone);
  await page.locator('#otp_code').fill(code);
  await page.getByRole('button', { name: 'Vérifier et continuer' }).click();
  await page.waitForURL('**/dashboard', { timeout: 30_000 });
}

export async function loginCustomer(page: Page, creds: { email: string; phone: string; password: string }) {
  await page.goto(`${CLIENT}/login`);
  await page.locator('#identifier').fill(creds.email);
  await page.locator('#password').fill(creds.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/login/verify-otp', { timeout: 30_000 });

  const code = await readOtpForPhone(creds.phone);
  await page.locator('#otp_code').fill(code);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.waitForURL('**/dashboard', { timeout: 30_000 });
  await expect(page.getByText(/Bienvenue sur votre portail BTS Bank/)).toBeVisible({ timeout: 30_000 });
  await expect.poll(() => page.evaluate(() => sessionStorage.getItem('bts_access_token'))).not.toBeNull();
}

export async function createApplication(page: Page): Promise<number> {
  await page.goto(`${CLIENT}/applications`);
  await expect(page.getByRole('heading', { name: 'Mes demandes de crédit' })).toBeVisible({ timeout: 30_000 });
  await expect.poll(() => page.evaluate(() => sessionStorage.getItem('bts_access_token'))).not.toBeNull();
  await page.getByRole('button', { name: 'Nouvelle demande' }).click();
  await page.waitForURL(/\/applications\/\d+\/client/, { timeout: 30_000 });
  const id = Number(page.url().match(/\/applications\/(\d+)\/client/)?.[1]);
  return id;
}

/** Selects one native form option by value. */
async function pickSelect(page: Page, triggerId: string, value: string) {
  await page.locator(`#${triggerId}`).selectOption(value);
}

/**
 * Drives the four-step wizard for a fresh application, uploads a CIN document, runs
 * validation 1 (real AI check — degrades gracefully when Gemini is unreachable), finalizes,
 * and submits. Ends on the validation page with status SUBMITTED.
 */
export async function completeApplication(page: Page, applicationId: number) {
  // Step 1 — client
  await page.goto(`${CLIENT}/applications/${applicationId}/client`);
  await pickSelect(page, 'civilite', 'M');
  await page.locator('#nom').fill('Doe');
  await page.locator('#prenom').fill('John');
  await page.locator('#date_naissance').fill('1985-04-12');
  await page.locator('#lieu_naissance').fill('Tunis');
  await page.locator('#pays_naissance').fill('Tunisie');
  await page.locator('#nationalite').fill('Tunisienne');
  await page.locator('#pays_residence').fill('Tunisie');
  await pickSelect(page, 'etat_civil', 'marié');
  await page.locator('#nombre_enfants').fill('2');
  await pickSelect(page, 'type_pid', 'CIN');
  await page.locator('#numero_pid').fill(String(10_000_000 + applicationId).slice(-8));
  await page.locator('#date_delivrance_pid').fill('2010-01-15');
  await page.locator('#lieu_delivrance_pid').fill('Tunis');
  await page.locator('#profession').fill('Ingénieur');
  await page.locator('#date_entree_relation').fill('2012-03-01');
  await page.getByRole('button', { name: 'Étape suivante : Demande de Crédit' }).click();
  await page.waitForURL(`**/applications/${applicationId}/credit`, { timeout: 30_000 });

  // Step 2 — credit request
  await page.locator('#origine').fill('Agence Tunis');
  await page.locator('#nom_ou_rs').fill('Doe SARL');
  await page.locator('#prenom_ou_dc').fill('John');
  await page.locator('#date_depot').fill('2026-08-01');
  await page.locator('#date_reception').fill('2026-08-02');
  await pickSelect(page, 'type_demande', 'crédit de création');
  await page.locator('#montant_global_sollicite').fill('150000');
  await page.locator('#nombre_credits_sollicites').fill('1');
  await page.locator('#unite_depot').fill('Unité centrale');
  await page.getByRole('button', { name: 'Étape suivante : Descriptif du projet' }).click();
  await page.waitForURL(`**/applications/${applicationId}/project`, { timeout: 30_000 });

  // Step 3 — project. The customer UI intentionally no longer embeds the generic attachment
  // zone here, so this isolated test uploads its synthetic CIN through the same authenticated
  // backend endpoint after the project step has been committed.
  await pickSelect(page, 'type_projet', 'Création');
  await page.locator('#activite').fill('Immobilier');
  await page.locator('#nom_ou_rs').fill('Doe SARL');
  await page.locator('#prenom_ou_dc').fill('John');
  await page.locator('#objet').fill('Construction d’un immeuble résidentiel');
  await page.locator('#description').fill('Immeuble R+3 avec 8 appartements à Tunis.');
  await page.locator('#adresse').fill('12 Avenue Habib Bourguiba');
  await page.locator('#ville').fill('Tunis');
  await page.locator('#code_postal').fill('1000');
  await page.locator('#delegation').fill('Bab Bhar');
  await page.locator('#cout').fill('900000');
  await page.locator('#investissement_personnel').fill('200000');
  await page.locator('#financement').fill('700000');
  await page.locator('#revenus').fill('60000');
  await page.locator('#depenses').fill('24000');
  await page.getByRole('button', { name: 'Étape suivante : Validation' }).click();
  await page.waitForURL(`**/applications/${applicationId}/validation`, { timeout: 30_000 });

  const token = await page.evaluate(() => sessionStorage.getItem('bts_access_token'));
  if (!token) throw new Error('Customer token missing before synthetic document upload.');
  const fixturePath = join(__dirname, 'fixtures', 'cin.pdf');
  const upload = await page.request.post(`${API}/api/applications/${applicationId}/documents`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    multipart: {
      document_type: 'cin',
      file: { name: 'cin.pdf', mimeType: 'application/pdf', buffer: readFileSync(fixturePath) },
    },
  });
  expect(upload.ok(), await upload.text()).toBeTruthy();
  await page.reload();
  await expect(page.getByText('cin.pdf')).toBeVisible({ timeout: 30_000 });

  // Validation 1 — the AI authenticity check runs against the real Gemini API when configured;
  // it degrades to "not verified" without blocking the application.
  await page.getByRole('button', { name: 'Lancer la vérification de conformité' }).click();
  await expect(page.getByRole('button', { name: 'Confirmer et transmettre le dossier' })).toBeVisible({ timeout: 120_000 });

  // Final confirmation atomically locks and submits the application.
  await page.getByRole('button', { name: 'Confirmer et transmettre le dossier' }).click();
  await page.getByRole('button', { name: 'Oui, transmettre mon dossier' }).click();
  await expect(page.getByText('Dossier soumis avec succès')).toBeVisible({ timeout: 30_000 });
}

/** Reads the generated n_demande from the "N° Demande" summary row on the validation page. */
export async function readNDemande(page: Page): Promise<string> {
  const applicationId = Number(page.url().match(/\/applications\/(\d+)/)?.[1]);
  const token = await page.evaluate(() => sessionStorage.getItem('bts_access_token'));
  if (!applicationId || !token) throw new Error('Application id or customer token missing.');
  const response = await page.request.get(`${API}/api/applications/${applicationId}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  expect(response.ok()).toBeTruthy();
  const value = (await response.json()).data.application.credit_request?.n_demande as string | undefined;
  if (!value) throw new Error('n_demande missing from application API response.');
  return value;
}

export async function logoutCustomer(page: Page) {
  await page.goto(`${CLIENT}/dashboard`);
  await page.getByRole('button', { name: /Log out|Se déconnecter/i }).click().catch(() => {});
  await page.waitForURL('**/login', { timeout: 20_000 }).catch(() => {});
}

export const staffCreds = { email: 'e2e.staff@bts.test', password: 'E2E-Staff-Pass-2026!' };
export const otherBranchStaffCreds = { email: 'e2e.other-branch@bts.test', password: 'E2E-Other-Branch-Pass-2026!' };
export const adminCreds = { email: 'e2e.admin@bts.test', password: 'E2E-Admin-Pass-2026!' };
export const securityCreds = { email: 'e2e.security@bts.test', password: 'E2E-Security-Pass-2026!' };
export const suspendedSecurityCreds = { email: 'e2e.suspended.security@bts.test', password: 'E2E-Suspended-Pass-2026!' };

export async function loginStaff(page: Page, base = STAFF, creds = staffCreds) {
  await page.goto(`${base}/login`);
  await page.locator('#email').fill(creds.email);
  await page.locator('#password').fill(creds.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL('**/dashboard', { timeout: 30_000 });
}

export async function loginAdmin(page: Page) {
  await page.goto(`${ADMIN}/login`);
  await page.locator('#email').fill(adminCreds.email);
  await page.locator('#password').fill(adminCreds.password);
  await page.locator('button[type="submit"]').click();
  // The admin home lives at "/" — as a plain string "/" is a full-URL glob that can never
  // match, so match the trailing-slash form explicitly.
  await page.waitForURL(/\/$/, { timeout: 30_000 });
}
