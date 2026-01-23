import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { TokenStorageService } from './token-storage.service';

export const authInterceptorFn: HttpInterceptorFn = (req, next) => {
  const tokenStorage = inject(TokenStorageService);
  const router = inject(Router);
  const token = tokenStorage.getToken();

  let authReq = req;
  if (token && !req.headers.has('Authorization')) {
    authReq = req.clone({
      setHeaders: {
        Authorization: `Bearer ${token}`
      }
    });
  }

  return next(authReq).pipe(
    catchError((error: HttpErrorResponse) => {
      // Handle 401 Unauthorized - token expired or invalid
      if (error.status === 401) {
        // Only redirect if not already on login/register pages and not a login request
        const currentUrl = router.url;
        const isAuthPage = currentUrl.startsWith('/login') || currentUrl.startsWith('/register');
        const isLoginRequest = req.url.includes('/auth/login') || req.url.includes('/auth/register');

        if (!isAuthPage && !isLoginRequest) {
          // Clear the invalid token
          tokenStorage.clearToken();

          // Redirect to login page
          router.navigate(['/login'], {
            queryParams: { sessionExpired: 'true' }
          });
        }
      }

      return throwError(() => error);
    })
  );
};
