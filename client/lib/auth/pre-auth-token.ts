const SESSION_KEY = 'bts_pre_auth_token';

/**
 * Pre-auth token for the OTP challenge. Deliberately NOT passed through the URL: a
 * token that grants a half-authenticated session must not leak into browser history,
 * referrer headers, or server access logs. sessionStorage dies with the tab, which is
 * exactly the lifetime of a single sign-in attempt. Old links that still carry the
 * token in the query string are honoured as a fallback.
 */
export function setPreAuthToken(token: string): void {
  window.sessionStorage.setItem(SESSION_KEY, token);
}

export function getPreAuthToken(): string {
  return window.sessionStorage.getItem(SESSION_KEY) ?? '';
}

export function clearPreAuthToken(): void {
  window.sessionStorage.removeItem(SESSION_KEY);
}