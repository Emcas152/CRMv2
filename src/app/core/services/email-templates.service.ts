import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export interface EmailTemplate {
  id: Id;
  name?: string;
  subject?: string;
  body?: string;
}

@Injectable({ providedIn: 'root' })
export class EmailTemplatesService {
  readonly #api = inject(ApiClientService);

  list(): Observable<ListResponse<EmailTemplate>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<EmailTemplate>> | ListResponse<EmailTemplate>>('/email-templates', { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<EmailTemplate> {
    return this.#api
      .request<ApiEnvelope<EmailTemplate> | EmailTemplate>(`/email-templates/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: Partial<EmailTemplate>): Observable<EmailTemplate> {
    return this.#api
      .request<ApiEnvelope<EmailTemplate> | EmailTemplate>('/email-templates', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: Partial<EmailTemplate>): Observable<EmailTemplate> {
    return this.#api
      .request<ApiEnvelope<EmailTemplate> | EmailTemplate>(`/email-templates/${id}`, { method: 'PUT', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/email-templates/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }
}
