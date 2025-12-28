import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { AppointmentStatus, Id, ListResponse } from './api.models';

export interface Appointment {
  id: Id;
  patient_id: Id;
  appointment_date: string;
  appointment_time: string;
  service: string;
  staff_member_id?: Id | null;
  notes?: string | null;
  status?: AppointmentStatus;
  patient_name?: string | null;
  patient_email?: string | null;
}

export interface AppointmentsListQuery {
  date?: string;
  date_from?: string;
  date_to?: string;
  patient_id?: Id;
  staff_member_id?: Id;
  status?: AppointmentStatus;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export interface CreateAppointmentRequest {
  patient_id: Id;
  appointment_date: string;
  appointment_time: string;
  service: string;
  staff_member_id?: Id;
  notes?: string;
  status?: AppointmentStatus;
}

export interface UpdateAppointmentRequest extends Partial<CreateAppointmentRequest> {}

@Injectable({ providedIn: 'root' })
export class AppointmentsService {
  readonly #api = inject(ApiClientService);

  list(query: AppointmentsListQuery = {}): Observable<ListResponse<Appointment>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<Appointment>> | ListResponse<Appointment>>('/appointments', { method: 'GET', params: query })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<Appointment> {
    return this.#api
      .request<ApiEnvelope<Appointment> | Appointment>(`/appointments/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: CreateAppointmentRequest): Observable<Appointment> {
    return this.#api
      .request<ApiEnvelope<Appointment> | Appointment>('/appointments', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: UpdateAppointmentRequest): Observable<Appointment> {
    return this.#api
      .request<ApiEnvelope<Appointment> | Appointment>(`/appointments/${id}`, { method: 'PUT', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/appointments/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }

  updateStatus(id: Id, status: AppointmentStatus): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/appointments/${id}/update-status`, {
        method: 'POST',
        body: { status }
      })
      .pipe(map(unwrapApiEnvelope));
  }

  sendEmail(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/appointments/${id}/send-email`, { method: 'POST' })
      .pipe(map(unwrapApiEnvelope));
  }

  generateReminder(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/appointments/${id}/generate-reminder`, { method: 'POST' })
      .pipe(map(unwrapApiEnvelope));
  }

  sendWhatsapp(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/appointments/${id}/send-whatsapp`, { method: 'POST' })
      .pipe(map(unwrapApiEnvelope));
  }
}
