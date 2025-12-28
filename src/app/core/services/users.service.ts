import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export interface User {
  id: Id;
  name: string;
  email: string;
  role: string;
  phone?: string | null;
}

export interface UsersListQuery {
  role?: string;
  search?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export interface CreateUserRequest {
  name: string;
  email: string;
  password: string;
  role: string;
  phone?: string;
}

export interface UpdateUserRequest {
  name?: string;
  email?: string;
  password?: string;
  role?: string;
  phone?: string | null;
}

@Injectable({ providedIn: 'root' })
export class UsersService {
  readonly #api = inject(ApiClientService);

  list(query: UsersListQuery = {}): Observable<ListResponse<User>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<User>> | ListResponse<User>>('/users', { method: 'GET', params: query })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<User> {
    return this.#api
      .request<ApiEnvelope<User> | User>(`/users/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: CreateUserRequest): Observable<User> {
    return this.#api
      .request<ApiEnvelope<User> | User>('/users', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: UpdateUserRequest): Observable<User> {
    return this.#api
      .request<ApiEnvelope<User> | User>(`/users/${id}`, { method: 'PUT', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/users/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }
}
