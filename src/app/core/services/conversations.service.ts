import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export interface ConversationListItem {
  id: Id;
  subject?: string | null;
  created_by: Id;
  created_at?: string;
  last_message?: string | null;
  last_message_at?: string | null;
  unread_count?: number | string | null;
}

export interface ConversationParticipant {
  id: Id;
  name?: string | null;
  email?: string | null;
  role?: string | null;
}

export interface ConversationDetail {
  id: Id;
  subject?: string | null;
  created_by: Id;
  created_at?: string;
  participants?: ConversationParticipant[];
}

export interface MessageItem {
  id: Id;
  conversation_id: Id;
  sender_user_id: Id;
  body: string;
  created_at?: string;
  sender_name?: string | null;
  sender_role?: string | null;
}

export interface CreateConversationRequest {
  subject?: string;
  participant_user_ids: Id[];
  first_message: string;
}

@Injectable({ providedIn: 'root' })
export class ConversationsService {
  readonly #api = inject(ApiClientService);

  list(): Observable<ListResponse<ConversationListItem>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<ConversationListItem>> | ListResponse<ConversationListItem>>('/conversations', { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<ConversationDetail> {
    return this.#api
      .request<ApiEnvelope<ConversationDetail> | ConversationDetail>(`/conversations/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: CreateConversationRequest): Observable<ConversationDetail> {
    return this.#api
      .request<ApiEnvelope<ConversationDetail> | ConversationDetail>('/conversations', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  listMessages(conversationId: Id): Observable<ListResponse<MessageItem>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<MessageItem>> | ListResponse<MessageItem>>(`/conversations/${conversationId}/messages`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  sendMessage(conversationId: Id, body: string): Observable<MessageItem> {
    return this.#api
      .request<ApiEnvelope<MessageItem> | MessageItem>(`/conversations/${conversationId}/messages`, { method: 'POST', body: { body } })
      .pipe(map(unwrapApiEnvelope));
  }

  markRead(conversationId: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/conversations/${conversationId}/read`, { method: 'POST' })
      .pipe(map(unwrapApiEnvelope));
  }
}
