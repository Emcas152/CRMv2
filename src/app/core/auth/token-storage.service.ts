import { Injectable } from '@angular/core';

const TOKEN_KEY = 'auth_token';

@Injectable({ providedIn: 'root' })
export class TokenStorageService {
  getToken(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  }

  setToken(token: string): void {
    localStorage.setItem(TOKEN_KEY, token);
    // Verify token was saved and can be parsed
    const savedToken = this.getToken();
    const payload = this.getTokenPayload();
    if (!savedToken || !payload) {
      console.error('TokenStorage: Failed to save or parse token');
    }
  }

  clearToken(): void {
    localStorage.removeItem(TOKEN_KEY);
  }

  getTokenRole(): string | null {
    const payload = this.getTokenPayload();
    const role = payload?.['role'];
    if (role === undefined || role === null) {
      // Log only if token exists but role is missing
      if (this.getToken()) {
        console.warn('TokenStorage: Token exists but role not found in payload');
      }
      return null;
    }
    return typeof role === 'string' && role.trim().length ? role : null;
  }

  getTokenPayload(): Record<string, unknown> | null {
    const token = this.getToken();
    if (!token) return null;

    const parts = token.split('.');
    if (parts.length !== 3) {
      console.warn('TokenStorage: Invalid token format (expected 3 parts)');
      return null;
    }

    try {
      const payloadB64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
      const pad = payloadB64.length % 4 ? '='.repeat(4 - (payloadB64.length % 4)) : '';
      const json = atob(`${payloadB64}${pad}`);
      const parsed = JSON.parse(json);
      return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : null;
    } catch (err) {
      console.error('TokenStorage: Failed to parse token payload', err);
      return null;
    }
  }

  isTokenExpired(): boolean {
    const payload = this.getTokenPayload();
    if (!payload) return true;
    const exp = payload['exp'];
    if (typeof exp !== 'number') return true;
    return exp * 1000 < Date.now();
  }
}
