import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';

@Injectable({ providedIn: 'root' })
export class DashboardService {
  readonly #api = inject(ApiClientService);

  stats(): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>('/dashboard/stats', { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }

  debugStats(): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>('/dashboard/debug-stats', { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }
}
