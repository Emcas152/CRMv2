import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { API_BASE_URL } from './api.tokens';

export type ApiHttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
export type ApiResponseType = 'json' | 'text' | 'blob';

export interface ApiRequestOptions {
  method?: ApiHttpMethod;
  params?: object;
  headers?: Record<string, string | undefined>;
  body?: unknown;
  responseType?: ApiResponseType;
  observeResponse?: boolean;
}

@Injectable({ providedIn: 'root' })
export class ApiClientService {
  readonly #http = inject(HttpClient);
  readonly #baseUrl = inject(API_BASE_URL);

  request<T>(path: string, options: ApiRequestOptions = {}): Observable<T> {
    const {
      method = 'GET',
      params,
      headers,
      body,
      responseType = 'json',
      observeResponse = false
    } = options;

    if (responseType === 'blob') {
      return this.requestBlob(path, { ...options, responseType: undefined } as any) as unknown as Observable<T>;
    }
    if (responseType === 'text') {
      return this.requestText(path, { ...options, responseType: undefined } as any) as unknown as Observable<T>;
    }

    const url = this.#joinUrl(this.#baseUrl, path);
    const httpParams = this.#toHttpParams(params);

    let httpHeaders = new HttpHeaders();
    if (headers) {
      for (const [key, value] of Object.entries(headers)) {
        if (typeof value === 'string') {
          httpHeaders = httpHeaders.set(key, value);
        }
      }
    }

    const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;
    if (!isFormData && body !== undefined && body !== null) {
      if (!httpHeaders.has('Content-Type')) {
        httpHeaders = httpHeaders.set('Content-Type', 'application/json');
      }
    }

    if (observeResponse) {
      return this.#http.request(method, url, {
        body: isFormData ? (body as any) : body,
        params: httpParams,
        headers: httpHeaders,
        observe: 'response',
        responseType: 'json'
      }) as unknown as Observable<T>;
    }

    return this.#http.request(method, url, {
      body: isFormData ? (body as any) : body,
      params: httpParams,
      headers: httpHeaders,
      responseType: 'json'
    }) as Observable<T>;
  }

  requestBlob(path: string, options: Omit<ApiRequestOptions, 'responseType'> = {}): Observable<Blob> {
    const url = this.#joinUrl(this.#baseUrl, path);
    const httpParams = this.#toHttpParams(options.params);

    let httpHeaders = new HttpHeaders();
    if (options.headers) {
      for (const [key, value] of Object.entries(options.headers)) {
        if (typeof value === 'string') {
          httpHeaders = httpHeaders.set(key, value);
        }
      }
    }

    return this.#http.request(options.method ?? 'GET', url, {
      body: options.body as any,
      params: httpParams,
      headers: httpHeaders,
      responseType: 'blob'
    });
  }

  requestText(path: string, options: Omit<ApiRequestOptions, 'responseType'> = {}): Observable<string> {
    const url = this.#joinUrl(this.#baseUrl, path);
    const httpParams = this.#toHttpParams(options.params);

    let httpHeaders = new HttpHeaders();
    if (options.headers) {
      for (const [key, value] of Object.entries(options.headers)) {
        if (typeof value === 'string') {
          httpHeaders = httpHeaders.set(key, value);
        }
      }
    }

    return this.#http.request(options.method ?? 'GET', url, {
      body: options.body as any,
      params: httpParams,
      headers: httpHeaders,
      responseType: 'text'
    });
  }

  #toHttpParams(params?: ApiRequestOptions['params']): HttpParams | undefined {
    if (!params) return undefined;
    let httpParams = new HttpParams();
    for (const [key, rawValue] of Object.entries(params as Record<string, unknown>)) {
      if (rawValue === undefined || rawValue === null) continue;
      if (typeof rawValue === 'string' || typeof rawValue === 'number' || typeof rawValue === 'boolean') {
        httpParams = httpParams.set(key, String(rawValue));
        continue;
      }
      httpParams = httpParams.set(key, String(rawValue));
    }
    return httpParams;
  }

  #joinUrl(baseUrl: string, path: string): string {
    const trimmedBase = baseUrl.replace(/\/$/, '');
    const trimmedPath = path.startsWith('/') ? path : `/${path}`;
    return `${trimmedBase}${trimmedPath}`;
  }
}
