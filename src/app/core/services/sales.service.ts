import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse, PaymentMethod } from './api.models';

export interface SaleItem {
  id?: Id;
  product_id: Id;
  price: number;
  quantity: number;
}

export interface Sale {
  id: Id;
  patient_id: Id;
  subtotal?: number;
  total?: number;
  status?: string;
  payment_method: PaymentMethod;
  discount?: number | null;
  notes?: string | null;
  loyalty_points_awarded?: number;
  items: SaleItem[];
  created_at?: string;
}

export interface SalesListQuery {
  date_from?: string;
  date_to?: string;
  patient_id?: Id;
  status?: string;
  payment_method?: PaymentMethod;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export interface CreateSaleRequest {
  patient_id: Id;
  payment_method: PaymentMethod;
  discount?: number;
  notes?: string;
  loyalty_points?: number;
  items: SaleItem[];
}

export interface UpdateSaleRequest {
  status?: string;
  payment_method?: PaymentMethod;
  notes?: string;
}

@Injectable({ providedIn: 'root' })
export class SalesService {
  readonly #api = inject(ApiClientService);

  list(query: SalesListQuery = {}): Observable<ListResponse<Sale>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<Sale>> | ListResponse<Sale>>('/sales', { method: 'GET', params: query })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<Sale> {
    return this.#api
      .request<ApiEnvelope<Sale> | Sale>(`/sales/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: CreateSaleRequest): Observable<Sale> {
    return this.#api
      .request<ApiEnvelope<Sale> | Sale>('/sales', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: UpdateSaleRequest): Observable<Sale> {
    return this.#api
      .request<ApiEnvelope<Sale> | Sale>(`/sales/${id}`, { method: 'PUT', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/sales/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }
}
