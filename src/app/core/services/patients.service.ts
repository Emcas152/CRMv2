import { Injectable, inject } from '@angular/core';
import { map } from 'rxjs/operators';
import { Observable } from 'rxjs';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export interface Patient {
  id: Id;
  name: string;
  email: string;
  phone?: string | null;
  birthday?: string | null;
  address?: string | null;
  nit?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface CreatePatientRequest {
  name: string;
  email: string;
  phone?: string;
  birthday?: string;
  address?: string;
  nit?: string;
}

export interface UpdatePatientRequest {
  name?: string;
  email?: string;
  phone?: string | null;
  birthday?: string | null;
  address?: string | null;
  nit?: string | null;
}

export interface PatientsListQuery {
  search?: string;
  date_from?: string;
  date_to?: string;
  birthday_month?: string | number;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

@Injectable({ providedIn: 'root' })
export class PatientsService {
  readonly #api = inject(ApiClientService);

  list(query: PatientsListQuery = {}): Observable<ListResponse<Patient>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<Patient>> | ListResponse<Patient>>('/patients', {
        method: 'GET',
        params: query
      })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<Patient> {
    return this.#api
      .request<ApiEnvelope<Patient> | Patient>(`/patients/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: CreatePatientRequest): Observable<Patient> {
    return this.#api
      .request<ApiEnvelope<Patient> | Patient>('/patients', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: UpdatePatientRequest): Observable<Patient> {
    return this.#api
      .request<ApiEnvelope<Patient> | Patient>(`/patients/${id}`, { method: 'PUT', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/patients/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }

  getQr(id: Id): Observable<{ qr_code: string; qr_url: string | null }> {
    return this.#api
      .request<ApiEnvelope<{ qr_code: string; qr_url: string | null }> | { qr_code: string; qr_url: string | null }>(
        `/patients/${id}/qr`,
        { method: 'GET' }
      )
      .pipe(map(unwrapApiEnvelope));
  }

  loyaltyAdd(id: Id, points: number): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/patients/${id}/loyalty-add`, {
        method: 'POST',
        body: { points }
      })
      .pipe(map(unwrapApiEnvelope));
  }

  loyaltyRedeem(id: Id, points: number): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/patients/${id}/loyalty-redeem`, {
        method: 'POST',
        body: { points }
      })
      .pipe(map(unwrapApiEnvelope));
  }

  uploadPhotoMultipart(id: Id, photo: File, type: 'before' | 'after' = 'before'): Observable<unknown> {
    const form = new FormData();
    form.append('photo', photo);
    form.append('type', type);

    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/patients/${id}/upload-photo`, {
        method: 'POST',
        body: form
      })
      .pipe(map(unwrapApiEnvelope));
  }

  uploadPhotoBase64(id: Id, photo_base64: string, type: 'before' | 'after' = 'before'): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/patients/${id}/upload-photo`, {
        method: 'POST',
        body: { photo_base64, type }
      })
      .pipe(map(unwrapApiEnvelope));
  }
}
