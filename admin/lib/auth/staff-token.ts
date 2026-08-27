const STORAGE_KEY = 'bts_staff_access_token';

/**
 * Separate storage key from lib/auth/token.ts on purpose: a staff account and a customer account
 * are different sessions that can coexist in the same browser (e.g. testing both at once) without
 * one login overwriting the other's token.
 */
export function getStaffToken(): string | null {
  if (typeof window === 'undefined') return null;
  const current = window.sessionStorage.getItem(STORAGE_KEY);
  if (current) return current;
  const legacy = window.localStorage.getItem(STORAGE_KEY);
  if (legacy) window.sessionStorage.setItem(STORAGE_KEY, legacy);
  window.localStorage.removeItem(STORAGE_KEY);
  return legacy;
}

export function setStaffToken(token: string): void {
  window.sessionStorage.setItem(STORAGE_KEY, token);
  window.localStorage.removeItem(STORAGE_KEY);
}

export function clearStaffToken(): void {
  window.sessionStorage.removeItem(STORAGE_KEY);
  window.sessionStorage.removeItem(ROLE_STORAGE_KEY);
  window.localStorage.removeItem(STORAGE_KEY);
  window.localStorage.removeItem(ROLE_STORAGE_KEY);
}

const ROLE_STORAGE_KEY = 'bts_staff_role';

/**
 * Not returned by any GET endpoint on its own, so the login page stashes it here — pages that
 * don't already know the role (e.g. a shared "Reports" screen reachable by both staff and admin)
 * read it back for display only, never for authorization (the backend enforces that).
 */
export function getStaffRole(): 'staff' | 'admin' | 'super_admin' | null {
  if (typeof window === 'undefined') return null;
  const current = window.sessionStorage.getItem(ROLE_STORAGE_KEY);
  if (current) return current as 'staff' | 'admin' | 'super_admin';
  const legacy = window.localStorage.getItem(ROLE_STORAGE_KEY);
  if (legacy) window.sessionStorage.setItem(ROLE_STORAGE_KEY, legacy);
  window.localStorage.removeItem(ROLE_STORAGE_KEY);
  return legacy as 'staff' | 'admin' | 'super_admin' | null;
}

export function setStaffRole(role: 'staff' | 'admin' | 'super_admin'): void {
  window.sessionStorage.setItem(ROLE_STORAGE_KEY, role);
  window.localStorage.removeItem(ROLE_STORAGE_KEY);
}
