const STORAGE_KEY = 'bts_access_token';

/**
 * Bearer token, sent via the Authorization header on every API call — not a cookie. Simple,
 * works across origins in dev without CSRF-cookie setup, and matches what every backend feature
 * test in this build already exercises (Authorization: Bearer {token}). Hardening this to an
 * httpOnly cookie (removing the XSS-exposure tradeoff that any localStorage token carries) is a
 * reasonable follow-up, not blocking this pass.
 */
export function getToken(): string | null {
  if (typeof window === 'undefined') return null;
  return window.localStorage.getItem(STORAGE_KEY);
}

export function setToken(token: string): void {
  window.localStorage.setItem(STORAGE_KEY, token);
}

export function clearToken(): void {
  window.localStorage.removeItem(STORAGE_KEY);
}
