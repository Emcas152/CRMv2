import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export type CommentEntityType = 'task' | 'patient';

export interface CommentItem {
  id: Id;
  entity_type: CommentEntityType | string;
  entity_id: Id;
  author_user_id: Id;
  body: string;
  created_at?: string;
  updated_at?: string;
  author_name?: string | null;
}

export interface CreateCommentRequest {
  entity_type: CommentEntityType | string;
  entity_id: Id;
  body: string;
}

export interface UpdateCommentRequest {
  body: string;
}

@Injectable({ providedIn: 'root' })
export class CommentsService {
  readonly #api = inject(ApiClientService);

  list(query: { entity_type: CommentEntityType | string; entity_id: Id }): Observable<ListResponse<CommentItem>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<CommentItem>> | ListResponse<CommentItem>>('/comments', { method: 'GET', params: query })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<CommentItem> {
    return this.#api
      .request<ApiEnvelope<CommentItem> | CommentItem>(`/comments/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: CreateCommentRequest): Observable<CommentItem> {
    return this.#api
      .request<ApiEnvelope<CommentItem> | CommentItem>('/comments', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: UpdateCommentRequest): Observable<CommentItem> {
    return this.#api
      .request<ApiEnvelope<CommentItem> | CommentItem>(`/comments/${id}`, { method: 'PUT', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/comments/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }
}
