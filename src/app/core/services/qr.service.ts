import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';

export type QrScanAction = 'none' | 'add' | 'redeem';

export interface QrScanRequest {
  qr_code: string;
  action?: QrScanAction;
  points?: number;
}

@Injectable({ providedIn: 'root' })
export class QrService {
  readonly #api = inject(ApiClientService);

  scan(payload: QrScanRequest): Observable<unknown> {
    return this.#api
      .request<ApiEnvelope<unknown> | unknown>('/qr/scan', { method: 'POST', body: payload })
      .pipe(map(unwrapApiEnvelope));
  }
}
