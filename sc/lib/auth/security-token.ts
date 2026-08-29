const STORAGE_KEY = 'bts_security_access_token';
const ROLE_STORAGE_KEY = 'bts_security_role';
const USER_STORAGE_KEY = 'bts_security_user';

export interface SecurityUser {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  role: 'security' | 'admin' | 'super_admin';
}

function migrateLegacySession(): void {
  if (typeof window === 'undefined') return;

  for (const key of [STORAGE_KEY, ROLE_STORAGE_KEY, USER_STORAGE_KEY]) {
    const legacy = window.localStorage.getItem(key);
    if (legacy !== null && window.sessionStorage.getItem(key) === null) {
      window.sessionStorage.setItem(key, legacy);
    }
    window.localStorage.removeItem(key);
  }
}

export function getSecurityToken(): string | null {
  if (typeof window === 'undefined') return null;
  migrateLegacySession();
  return window.sessionStorage.getItem(STORAGE_KEY);
}

export function setSecurityToken(token: string): void {
  window.sessionStorage.setItem(STORAGE_KEY, token);
  window.localStorage.removeItem(STORAGE_KEY);
}

export function clearSecurityToken(): void {
  window.sessionStorage.removeItem(STORAGE_KEY);
  window.sessionStorage.removeItem(ROLE_STORAGE_KEY);
  window.sessionStorage.removeItem(USER_STORAGE_KEY);
  window.localStorage.removeItem(STORAGE_KEY);
  window.localStorage.removeItem(ROLE_STORAGE_KEY);
  window.localStorage.removeItem(USER_STORAGE_KEY);
}

export function getSecurityRole(): 'security' | 'admin' | 'super_admin' | null {
  if (typeof window === 'undefined') return null;
  migrateLegacySession();
  const role = window.sessionStorage.getItem(ROLE_STORAGE_KEY);
  return role === 'security' || role === 'admin' || role === 'super_admin' ? role : null;
}

export function setSecurityRole(role: 'security' | 'admin' | 'super_admin'): void {
  window.sessionStorage.setItem(ROLE_STORAGE_KEY, role);
}

export function getSecurityUser(): SecurityUser | null {
  if (typeof window === 'undefined') return null;
  migrateLegacySession();
  const raw = window.sessionStorage.getItem(USER_STORAGE_KEY);
  if (!raw) return null;
  try {
    const value = JSON.parse(raw) as Partial<SecurityUser>;
    if (
      typeof value.id !== 'number' ||
      typeof value.email !== 'string' ||
      (value.role !== 'security' && value.role !== 'admin' && value.role !== 'super_admin')
    ) {
      return null;
    }
    return value as SecurityUser;
  } catch {
    return null;
  }
}

export function setSecurityUser(user: SecurityUser): void {
  window.sessionStorage.setItem(USER_STORAGE_KEY, JSON.stringify(user));
}
