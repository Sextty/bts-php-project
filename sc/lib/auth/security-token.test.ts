import { beforeEach, describe, expect, it } from 'vitest';
import { clearSecurityToken, getSecurityRole, getSecurityToken, getSecurityUser } from './security-token';

class MemoryStorage {
  private values = new Map<string, string>();
  getItem(key: string) { return this.values.get(key) ?? null; }
  setItem(key: string, value: string) { this.values.set(key, value); }
  removeItem(key: string) { this.values.delete(key); }
  clear() { this.values.clear(); }
}

const localStorage = new MemoryStorage();
const sessionStorage = new MemoryStorage();

describe('security session migration', () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    Object.assign(globalThis, { window: { localStorage, sessionStorage } });
  });

  it('moves the legacy token, role and user together then clears local storage', () => {
    localStorage.setItem('bts_security_access_token', 'legacy-token');
    localStorage.setItem('bts_security_role', 'security');
    localStorage.setItem('bts_security_user', JSON.stringify({ id: 7, first_name: 'Test', last_name: 'Security', email: 'security@example.test', role: 'security' }));

    expect(getSecurityToken()).toBe('legacy-token');
    expect(getSecurityRole()).toBe('security');
    expect(getSecurityUser()?.id).toBe(7);
    expect(localStorage.getItem('bts_security_access_token')).toBeNull();
    expect(localStorage.getItem('bts_security_role')).toBeNull();
    expect(localStorage.getItem('bts_security_user')).toBeNull();
  });

  it('does not overwrite a current session and logout clears both stores', () => {
    sessionStorage.setItem('bts_security_access_token', 'current-token');
    localStorage.setItem('bts_security_access_token', 'stale-token');
    expect(getSecurityToken()).toBe('current-token');
    clearSecurityToken();
    expect(sessionStorage.getItem('bts_security_access_token')).toBeNull();
    expect(localStorage.getItem('bts_security_access_token')).toBeNull();
  });
});
