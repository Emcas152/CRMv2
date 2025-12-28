import { HttpInterceptorFn } from '@angular/common/http';
import { finalize } from 'rxjs/operators';

import { inject } from '@angular/core';
import { LoadingService } from './loading.service';

export const loadingInterceptorFn: HttpInterceptorFn = (req, next) => {
  const loading = inject(LoadingService);

  loading.start();
  return next(req).pipe(finalize(() => loading.stop()));
};
