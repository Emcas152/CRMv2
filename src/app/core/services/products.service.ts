import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export type ProductType = 'product' | 'service';

export interface Product {
  id: Id;
  name: string;
  price: number;
  type: ProductType;
  sku?: string | null;
  description?: string | null;
  stock?: number | null;
  active?: boolean;
  image_url?: string | null;
}

export interface ProductsListQuery {
  type?: ProductType;
  active?: boolean;
  search?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export interface CreateProductRequest {
  name: string;
  price: number;
  type: ProductType;
  sku?: string;
  description?: string;
  stock?: number;
  active?: boolean;
}

export interface UpdateProductRequest extends Partial<CreateProductRequest> {}

@Injectable({ providedIn: 'root' })
export class ProductsService {
  readonly #api = inject(ApiClientService);

  list(query: ProductsListQuery = {}): Observable<ListResponse<Product>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<Product>> | ListResponse<Product>>('/products', {
        method: 'GET',
        params: query
      })
      .pipe(map(unwrapApiEnvelope));
  }

  get(id: Id): Observable<Product> {
    return this.#api
      .request<ApiEnvelope<Product> | Product>(`/products/${id}`, { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  create(payload: CreateProductRequest): Observable<Product> {
    return this.#api
      .request<ApiEnvelope<Product> | Product>('/products', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  update(id: Id, payload: UpdateProductRequest): Observable<Product> {
    return this.#api
      .request<ApiEnvelope<Product> | Product>(`/products/${id}`, { method: 'PUT', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }

  delete(id: Id): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/products/${id}`, { method: 'DELETE' })
      .pipe(map(unwrapApiEnvelope));
  }

  uploadImage(id: Id, image: File): Observable<{ image_url: string }> {
    const form = new FormData();
    form.append('image', image);

    return this.#api
      .request<ApiEnvelope<{ image_url: string }> | { image_url: string }>(`/products/${id}/upload-image`, {
        method: 'POST',
        body: form
      })
      .pipe(map(unwrapApiEnvelope));
  }

  adjustStock(id: Id, quantity: number, type: 'add' | 'subtract' | 'set'): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>(`/products/${id}/adjust-stock`, {
        method: 'POST',
        body: { quantity, type }
      })
      .pipe(map(unwrapApiEnvelope));
  }
}
