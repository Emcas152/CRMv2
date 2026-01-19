import { Injectable, inject } from '@angular/core';
import { map, tap, shareReplay, catchError } from 'rxjs/operators';
import { Observable, of } from 'rxjs';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { TokenStorageService } from './token-storage.service';

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: string;
  email_verified: boolean;
}

export interface LoginResponse {
  token: string;
  user: AuthUser;
}

export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  phone?: string;
  birthday?: string;
  address?: string;
}

export interface MeResponse {
  user: AuthUser;
  patient?: unknown;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  readonly #api = inject(ApiClientService);
  readonly #tokenStorage = inject(TokenStorageService);

  // cached observable for /auth/me
  private _me$?: Observable<MeResponse> | null = null;

  login(email: string, password: string): Observable<LoginResponse> {
    const deviceName = (typeof navigator !== 'undefined' && (navigator as any).userAgent)
      ? (navigator as any).userAgent
      : 'web';

    const body = {
      email,
      // include `login` for compatibility with clients/backends that use that key
      login: email,
      password,
      deviceName
    };

    // store token and invalidate cached /me
    return this.#api.request<LoginResponse>('/auth/login', {
      method: 'POST',
      body
    }).pipe(
      tap((res) => {
        this.#tokenStorage.setToken(res.token);
        this._me$ = null;
      })
    );
  }

  register(payload: RegisterRequest): Observable<unknown> {
    return this.#api.request<ApiEnvelope<unknown> | unknown>('/auth/register', {
      method: 'POST',
      body: payload
    }).pipe(map(unwrapApiEnvelope));
  }

  me(): Observable<MeResponse> {
    if (this._me$) return this._me$;

    this._me$ = this.#api.request<ApiEnvelope<MeResponse> | MeResponse>('/auth/me', {
      method: 'GET'
    }).pipe(
      map(unwrapApiEnvelope),
      shareReplay({ bufferSize: 1, refCount: false }),
      catchError((err) => {
        this._me$ = null;
        throw err;
      })
    );

    return this._me$;
  }

  logout(): Observable<unknown> {
    return this.#api.request<ApiEnvelope<unknown> | unknown>('/auth/logout', {
      method: 'POST'
    }).pipe(
      tap(() => {
        this.#tokenStorage.clearToken();
        this._me$ = null;
      }),
      map(unwrapApiEnvelope)
    );
  }

  getToken(): string | null {
    return this.#tokenStorage.getToken();
  }

  clearToken(): void {
    this.#tokenStorage.clearToken();
    this._me$ = null;
  }
}
