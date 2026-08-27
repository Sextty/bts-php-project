const STORAGE_KEY = 'bts_access_token';

/**
 * Bearer token scoped to the current tab. Legacy localStorage values are moved once and removed,
 * so a banking session no longer survives closing the browser.
 */
export function getToken(): string | null {
  if (typeof window === 'undefined') return null;
  const current = window.sessionStorage.getItem(STORAGE_KEY);
  if (current) return current;

  const legacy = window.localStorage.getItem(STORAGE_KEY);
  if (legacy) {
    window.sessionStorage.setItem(STORAGE_KEY, legacy);
    window.localStorage.removeItem(STORAGE_KEY);
  }
  return legacy;
}

export function setToken(token: string): void {
  window.sessionStorage.setItem(STORAGE_KEY, token);
  window.localStorage.removeItem(STORAGE_KEY);
}

export function clearToken(): void {
  window.sessionStorage.removeItem(STORAGE_KEY);
  window.localStorage.removeItem(STORAGE_KEY);
}
