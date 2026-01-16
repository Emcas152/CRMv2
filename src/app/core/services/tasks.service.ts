import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export type TaskStatus = 'open' | 'in_progress' | 'done' | 'cancelled';
export type TaskPriority = 'low' | 'normal' | 'high';

export interface TaskItem {
  id: Id;
  created_by: Id;
  assigned_to_user_id?: Id | null;
  related_patient_id?: Id | null;
  title: string;
  description?: string | null;
  status: TaskStatus;
  priority?: TaskPriority | null;
  due_date?: string | null;
  created_at?: string;
  updated_at?: string;
  created_by_name?: string | null;
  assigned_to_name?: string | null;
  patient_name?: string | null;
}

export interface CreateTaskRequest {
  title: string;
  description?: string;
  assigned_to_user_id?: Id;
  related_patient_id?: Id;
  status?: TaskStatus;
  priority?: TaskPriority;
  due_date?: string;
}

export interface UpdateTaskRequest {
  title?: string;
  description?: string | null;
  assigned_to_user_id?: Id | null;
  related_patient_id?: Id | null;
  status?: TaskStatus;
  priority?: TaskPriority | null;
  due_date?: string | null;
}

@Injectable({ providedIn: 'root' })
export class TasksService {
  readonly #api = inject(ApiClientService);

  list(query: { status?: TaskStatus; assigned_to_user_id?: Id; related_patient_id?: Id } = {}): Observable<ListResponse<TaskItem>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<TaskItem>> | ListResponse<TaskItem>>('/tasks', { method: 'GET', params: query })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<TaskItem> {
    return this.#api
      .request<ApiEnvelope<TaskItem> | TaskItem>(`/tasks/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: CreateTaskRequest): Observable<TaskItem> {
    return this.#api
      .request<ApiEnvelope<TaskItem> | TaskItem>('/tasks', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: UpdateTaskRequest): Observable<TaskItem> {
    return this.#api
      .request<ApiEnvelope<TaskItem> | TaskItem>(`/tasks/${id}`, { method: 'PUT', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  updateStatus(id: Id, status: TaskStatus): Observable<TaskItem> {
    return this.#api
      .request<ApiEnvelope<TaskItem> | TaskItem>(`/tasks/${id}/update-status`, { method: 'POST', body: { status } })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/tasks/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }
}
