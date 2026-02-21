import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export interface AttendanceSheet {
  id: Id;
  patient_id: Id;
  product_id?: Id;
  appointment_id?: Id;
  service_name: string;
  total_sessions: number;
  created_at?: string;
  updated_at?: string;
}

export interface AttendanceSheetsListQuery {
  patient_id?: Id;
}

@Injectable({ providedIn: 'root' })
export class AttendanceSheetsService {
  readonly #api = inject(ApiClientService);

  list(query: AttendanceSheetsListQuery = {}): Observable<ListResponse<AttendanceSheet>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<AttendanceSheet>> | ListResponse<AttendanceSheet>>('/attendance-sheets', {
        method: 'GET',
        params: query
      })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<AttendanceSheet> {
    return this.#api
      .request<ApiEnvelope<AttendanceSheet> | AttendanceSheet>(`/attendance-sheets/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  pdf(id: Id, download = false): Observable<Blob> {
    return this.#api.requestBlob(`/attendance-sheets/${id}/pdf`, {
      method: 'GET',
      params: download ? { download: 1 } : undefined
    });
  }
}
