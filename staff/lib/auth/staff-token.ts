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
  window.localStorage.removeItem(ROLE_STORAGE_KEY);
}

const ROLE_STORAGE_KEY = 'bts_staff_role';

/**
 * Not returned by any GET endpoint on its own, so the login page stashes it here — pages that
 * don't already know the role (e.g. a shared "Reports" screen reachable by both staff and admin)
 * read it back for display only, never for authorization (the backend enforces that).
 */
export function getStaffRole(): 'staff' | 'admin' | null {
  if (typeof window === 'undefined') return null;
  return window.localStorage.getItem(ROLE_STORAGE_KEY) as 'staff' | 'admin' | null;
}

export function setStaffRole(role: 'staff' | 'admin'): void {
  window.localStorage.setItem(ROLE_STORAGE_KEY, role);
}
