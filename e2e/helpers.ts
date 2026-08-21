import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, type Page } from '@playwright/test';

export const CLIENT = 'http://127.0.0.1:3000';
export const STAFF = 'http://127.0.0.1:3001';
export const ADMIN = 'http://127.0.0.1:3002';
export const API = 'http://127.0.0.1:8000';
export const API_URL = API;

const BACKEND_LOG = join(__dirname, '..', 'backend', 'storage', 'logs', 'laravel.log');

/** Fresh unique identity for every run so repeat runs never collide. */
export function uniqueCreds(): { email: string; phone: string; password: string } {
  const stamp = Date.now().toString().slice(-10);
  const phone = `+216${stamp.slice(0, 8)}`;
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

const STATE_FILE = join(__dirname, '.e2e-state.json');

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
 * The API runs with SMS_PROVIDER=log in the e2e environment: OTP codes are written to
 * storage/logs/laravel.log by LogSmsDriver instead of being emailed (email/SMS transport is
 * the only external service this suite does not call). We read the most recent code issued
 * for the phone number — everything else (DB, queue, API, browser) is the real thing.
 */
export async function readOtpForPhone(phone: string): Promise<string> {
  const log = readFileSync(BACKEND_LOG, 'utf8');
  const re = new RegExp(
    `\\[sms:log-driver\\] outgoing SMS \\{[^}]*?"to":"${escapeRegExp(phone)}","message":"Your BTS Bank verification code is (\\d{6})`,
    'g'
  );
  const matches = [...log.matchAll(re)];
  if (matches.length === 0) {
    throw new Error(`No OTP found in log for ${phone}`);
  }
  return matches[matches.length - 1][1];
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
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
  await page.getByRole('button', { name: 'Verify and continue' }).click();
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
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.waitForURL('**/dashboard', { timeout: 30_000 });
}

export async function createApplication(page: Page): Promise<number> {
  await page.goto(`${CLIENT}/applications`);
  await page.getByRole('button', { name: 'New application' }).click();
  await page.waitForURL(/\/applications\/\d+\/client/, { timeout: 30_000 });
  const id = Number(page.url().match(/\/applications\/(\d+)\/client/)?.[1]);
  return id;
}

/** Fills one shadcn Select by clicking its trigger and picking the option with the given value. */
async function pickSelect(page: Page, triggerId: string, value: string) {
  await page.locator(`#${triggerId}`).click();
  await page.getByRole('option', { name: value, exact: false }).first().click();
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
  await pickSelect(page, 'etat_civil', 'Mari');
  await page.locator('#nombre_enfants').fill('2');
  await pickSelect(page, 'type_pid', 'CIN');
  await page.locator('#numero_pid').fill(`0${String(applicationId).padStart(7, '0')}${String(applicationId).padStart(4, '0')}`);
  await page.locator('#date_delivrance_pid').fill('2010-01-15');
  await page.locator('#lieu_delivrance_pid').fill('Tunis');
  await page.locator('#profession').fill('Ingénieur');
  await page.locator('#date_entree_relation').fill('2012-03-01');
  await page.getByRole('button', { name: 'Enregistrer et continuer' }).click();
  await page.waitForURL(`**/applications/${applicationId}/credit`, { timeout: 30_000 });

  // Step 2 — credit request
  await page.locator('#origine').fill('Agence Tunis');
  await page.locator('#nom_ou_rs').fill('Doe SARL');
  await page.locator('#prenom_ou_dc').fill('John');
  await page.locator('#date_depot').fill('2026-08-01');
  await page.locator('#date_reception').fill('2026-08-02');
  await page.locator('#type_demande').fill('Crédit immobilier');
  await page.locator('#code_devise').fill('TND');
  await page.locator('#montant_global_sollicite').fill('150000');
  await page.locator('#nombre_credits_sollicites').fill('1');
  await page.locator('#unite_depot').fill('Unité centrale');
  await page.getByRole('button', { name: 'Enregistrer et continuer' }).click();
  await page.waitForURL(`**/applications/${applicationId}/project`, { timeout: 30_000 });

  // Step 3 — project (a CIN document is uploaded here — the upload card lives on steps 1-3,
  // not on the validation page).
  await page.locator('#type_projet').fill('Construction');
  await page.locator('#activite').fill('Immobilier');
  await page.locator('#nom_ou_rs').fill('Doe SARL');
  await page.locator('#prenom_ou_dc').fill('John');
  await page.locator('#objet').fill('Construction d’un immeuble résidentiel');
  await page.locator('#description').fill('Immeuble R+3 avec 8 appartements à Tunis.');
  await page.locator('#adresse').fill('12 Avenue Habib Bourguiba');
  await page.locator('#ville').fill('Tunis');
  await page.locator('#code_postal').fill('1000');
  await page.locator('#delegation').fill('Bab Bhar');
  await page.locator('#localisation').fill('Centre-ville');
  await page.locator('#cout').fill('900000');
  await page.locator('#investissement_personnel').fill('200000');
  await page.locator('#financement').fill('700000');
  await page.locator('#revenus').fill('60000');
  await page.locator('#depenses').fill('24000');
  await page.locator('input[type="file"]').setInputFiles(join(__dirname, 'fixtures', 'cin.pdf'));
  await expect(page.getByText('cin.pdf')).toBeVisible({ timeout: 30_000 });
  await page.getByRole('button', { name: 'Enregistrer et continuer' }).click();
  await page.waitForURL(`**/applications/${applicationId}/validation`, { timeout: 30_000 });

  // Validation 1 — the AI authenticity check runs against the real Gemini API when configured;
  // it degrades to "not verified" without blocking the application.
  await page.getByRole('button', { name: 'Run validation' }).click();
  await expect(page.getByRole('button', { name: 'Confirm and finalize' })).toBeVisible({ timeout: 120_000 });

  // Finalize (locks everything) and submit.
  await page.getByRole('button', { name: 'Confirm and finalize' }).click();
  await page.getByRole('button', { name: 'Yes, finalize' }).click();
  await expect(page.getByRole('button', { name: 'Submit application' })).toBeVisible({ timeout: 30_000 });
  await page.getByRole('button', { name: 'Submit application' }).click();
  await expect(page.getByText('This application has been submitted to BTS Bank.')).toBeVisible({ timeout: 30_000 });
}

/** Reads the generated n_demande from the "N° Demande" summary row on the validation page. */
export async function readNDemande(page: Page): Promise<string> {
  const label = page.getByText('N° Demande');
  const row = label.locator('..');
  const value = await row.locator('p.font-medium').textContent();
  if (!value) throw new Error('n_demande not found on validation page');
  return value.trim();
}

export async function logoutCustomer(page: Page) {
  await page.goto(`${CLIENT}/dashboard`);
  await page.getByRole('button', { name: /Log out|Se déconnecter/i }).click().catch(() => {});
  await page.waitForURL('**/login', { timeout: 20_000 }).catch(() => {});
}

export const staffCreds = { email: 'e2e.staff@bts.test', password: 'E2E-Staff-Pass-2026!' };
export const adminCreds = { email: 'e2e.admin@bts.test', password: 'E2E-Admin-Pass-2026!' };

export async function loginStaff(page: Page, base = STAFF) {
  await page.goto(`${base}/login`);
  await page.locator('#email').fill(staffCreds.email);
  await page.locator('#password').fill(staffCreds.password);
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