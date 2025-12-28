import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

import { ApiClientService } from '../api/api-client.service';
import { ApiEnvelope, unwrapApiEnvelope } from '../api/api.types';
import { Id, ListResponse } from './api.models';

export interface StaffMember {
  id: Id;
  name: string;
}

@Injectable({ providedIn: 'root' })
export class StaffMembersService {
  readonly #api = inject(ApiClientService);

  list(): Observable<ListResponse<StaffMember>> {
    return this.#api
      .request<ApiEnvelope<ListResponse<StaffMember>> | ListResponse<StaffMember>>('/staff-members', { method: 'GET' })
      .pipe(map(unwrapApiEnvelope));
  }
}
