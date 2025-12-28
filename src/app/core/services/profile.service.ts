import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';

@Injectable({ providedIn: 'root' })
export class ProfileService {
  readonly #api = inject(ApiClientService);

  get(): Observable<{ user: unknown; patient?: unknown; staff_member?: unknown }> {
    return this.#api
      .request<ApiEnvelope<{ user: unknown; patient?: unknown; staff_member?: unknown }> | { user: unknown; patient?: unknown; staff_member?: unknown }>(
        '/profile',
        { method: 'GET' }
      )
      .pipe(map(unwrapApiEnvelope));
  }

  update(payload: { name?: string; email?: string }): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>('/profile', {
        method: 'PUT',
        body: payload
      })
      .pipe(map(unwrapApiEnvelope));
  }

  changePassword(payload: { current_password: string; new_password: string; confirm_password: string }): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>('/profile/change-password', {
        method: 'POST',
        body: payload
      })
      .pipe(map(unwrapApiEnvelope));
  }

  uploadPhoto(photo: File): Observable<unknown> {
    const form = new FormData();
    form.append('photo', photo);

    return this.#api
      .request<ApiEnvelope<unknown> | unknown>('/profile/upload-photo', {
        method: 'POST',
        body: form
      })
      .pipe(map(unwrapApiEnvelope));
  }
}
