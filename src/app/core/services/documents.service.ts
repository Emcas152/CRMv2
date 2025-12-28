import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export interface DocumentItem {
  id: Id;
  patient_id: Id;
  title?: string | null;
  filename?: string | null;
  mime?: string | null;
  mime_type?: string | null;
  created_at?: string;

  download_url?: string;
  file_url?: string;
  view_url?: string;
}

export interface DocumentsListQuery {
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

@Injectable({ providedIn: 'root' })
export class DocumentsService {
  readonly #api = inject(ApiClientService);

  list(patientId: Id, query: DocumentsListQuery = {}): Observable<ListResponse<DocumentItem>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<DocumentItem>> | ListResponse<DocumentItem>>('/documents', {
        method: 'GET',
        params: { patient_id: patientId, ...query }
      })
      .pipe(map(unwrapApiEnvelope));
  }

  upload(file: File, patientId: Id, title?: string): Observable<DocumentItem> {
    const form = new FormData();
    form.append('file', file);
    form.append('patient_id', String(patientId));
    if (title) form.append('title', title);

    return this.#api
      .request<ApiEnvelope<DocumentItem> | DocumentItem>('/documents', {
        method: 'POST',
        body: form
      })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: { title?: string | null; patient_id?: Id | null }): Observable<DocumentItem> {
    return this.#api
      .request<ApiEnvelope<DocumentItem> | DocumentItem>(`/documents/${id}`, {
        method: 'PUT',
        body: payload
      })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/documents/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }

  downloadInfo(id: Id): Observable<{ url: string; filename: string }> {
    return this.#api
      .request<ApiEnvelope<{ url: string; filename: string }> | { url: string; filename: string }>(
        `/documents/${id}/download`,
        { method: 'GET' }
      )
      .pipe(map(unwrapApiEnvelope));
  }

  file(id: Id): Observable<Blob> {
    return this.#api.requestBlob(`/documents/${id}/file`, { method: 'GET' });
  }

  viewHtml(id: Id): Observable<string> {
    return this.#api.requestText(`/documents/${id}/view`, { method: 'GET' });
  }

  sign(id: Id, payload: { signature?: File; method?: string; meta?: string } = {}): Observable<unknown> {
    const form = new FormData();
    if (payload.signature) form.append('signature', payload.signature);
    if (payload.method) form.append('method', payload.method);
    if (payload.meta) form.append('meta', payload.meta);

    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/documents/${id}/sign`, {
        method: 'POST',
        body: form
      })
      .pipe(map(unwrapApiEnvelope));
  }
}
