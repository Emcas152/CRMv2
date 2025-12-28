import { Injectable, inject } from '@angular/core';
import { map, tap } from 'rxjs/operators';
import { Observable } from 'rxjs';

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

  login(email: string, password: string): Observable<LoginResponse> {
    return this.#api
      .request<LoginResponse>('/auth/login', {
        method: 'POST',
        body: { email, password }
      })
      .pipe(
        tap((res) => this.#tokenStorage.setToken(res.token))
      );
  }

  register(payload: RegisterRequest): Observable<unknown> {
    return this.#api.request<ApiEnvelope<unknown> | unknown>('/auth/register', {
      method: 'POST',
      body: payload
    }).pipe(map(unwrapApiEnvelope));
  }

  me(): Observable<MeResponse> {
    return this.#api.request<ApiEnvelope<MeResponse> | MeResponse>('/auth/me', {
      method: 'GET'
    }).pipe(map(unwrapApiEnvelope));
  }

  logout(): Observable<unknown> {
    return this.#api.request<ApiEnvelope<unknown> | unknown>('/auth/logout', {
      method: 'POST'
    }).pipe(
      tap(() => this.#tokenStorage.clearToken()),
      map(unwrapApiEnvelope)
    );
  }

  getToken(): string | null {
    return this.#tokenStorage.getToken();
  }

  clearToken(): void {
    this.#tokenStorage.clearToken();
  }
}
