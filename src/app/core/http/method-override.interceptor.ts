import { HttpInterceptorFn } from '@angular/common/http';

import { environment } from '../../../environments/environment';

export const methodOverrideInterceptorFn: HttpInterceptorFn = (req, next) => {
  if (!environment.httpMethodOverride) {
    return next(req);
  }

  const method = req.method.toUpperCase();
  if (method !== 'PUT' && method !== 'DELETE' && method !== 'PATCH') {
    return next(req);
  }

  return next(
    req.clone({
      method: 'POST',
      setHeaders: {
        'X-HTTP-Method-Override': method
      }
    })
  );
};
