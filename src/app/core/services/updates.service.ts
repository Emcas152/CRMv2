import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export type UpdateAudienceType = 'all' | 'role' | 'user' | 'patient';
export type UpdateAudienceRole = 'superadmin' | 'admin' | 'doctor' | 'staff' | 'patient';

export interface UpdateItem {
  id: Id;
  created_by: Id;
  title: string;
  body: string;
  audience_type: UpdateAudienceType;
  audience_role?: UpdateAudienceRole | null;
  audience_user_id?: Id | null;
  patient_id?: Id | null;
  created_at?: string;
  updated_at?: string;
  created_by_name?: string | null;
}

export interface CreateUpdateRequest {
  title: string;
  body: string;
  audience_type?: UpdateAudienceType;
  audience_role?: UpdateAudienceRole;
  audience_user_id?: Id;
  patient_id?: Id;
}

export interface UpdateUpdateRequest {
  title?: string;
  body?: string;
  audience_type?: UpdateAudienceType;
  audience_role?: UpdateAudienceRole | null;
  audience_user_id?: Id | null;
  patient_id?: Id | null;
}

@Injectable({ providedIn: 'root' })
export class UpdatesService {
  readonly #api = inject(ApiClientService);

  list(query: { created_by?: Id; patient_id?: Id } = {}): Observable<ListResponse<UpdateItem>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<UpdateItem>> | ListResponse<UpdateItem>>('/updates', { method: 'GET', params: query })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<UpdateItem> {
    return this.#api
      .request<ApiEnvelope<UpdateItem> | UpdateItem>(`/updates/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: CreateUpdateRequest): Observable<UpdateItem> {
    return this.#api
      .request<ApiEnvelope<UpdateItem> | UpdateItem>('/updates', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: UpdateUpdateRequest): Observable<UpdateItem> {
    return this.#api
      .request<ApiEnvelope<UpdateItem> | UpdateItem>(`/updates/${id}`, { method: 'PUT', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/updates/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }
}
