const STORAGE_KEY = 'bts_staff_access_token';

/**
 * Separate storage key from lib/auth/token.ts on purpose: a staff account and a customer account
 * are different sessions that can coexist in the same browser (e.g. testing both at once) without
 * one login overwriting the other's token.
 */
export function getStaffToken(): string | null {
  if (typeof window === 'undefined') return null;
  return window.localStorage.getItem(STORAGE_KEY);
}

export function setStaffToken(token: string): void {
  window.localStorage.setItem(STORAGE_KEY, token);
}

export function clearStaffToken(): void {
  window.localStorage.removeItem(STORAGE_KEY);
}
