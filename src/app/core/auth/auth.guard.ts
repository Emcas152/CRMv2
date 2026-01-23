import { CanActivateChildFn, CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { map, catchError, timeout } from 'rxjs/operators';
import { of } from 'rxjs';

import { TokenStorageService } from './token-storage.service';
import { AuthService } from './auth.service';

function hasToken(): boolean {
  const tokenStorage = inject(TokenStorageService);
  return !!tokenStorage.getToken();
}

export const authGuardFn: CanActivateFn = () => {
  const router = inject(Router);
  if (hasToken()) return true;
  return router.parseUrl('/login');
};

export const authChildGuardFn: CanActivateChildFn = (route) => {
  const router = inject(Router);
  const auth = inject(AuthService);
  const tokenStorage = inject(TokenStorageService);

  if (!hasToken()) {
    return router.parseUrl('/login');
  }

  // Si el usuario está accediendo a la ruta raíz del CRM, verificar el rol
  const fullPath = route.pathFromRoot.map(r => r.url.map(s => s.path).join('/')).join('/').replace(/\/+/g, '/');

  // Only redirect patients from the root CRM page to appointments
  // For other routes like /crm/welcome, let the route's own guards handle it
  if (fullPath === '/crm' || fullPath === '/crm/' || fullPath === '' || fullPath === 'crm') {
    const tokenRole = tokenStorage.getTokenRole();
    if (tokenRole && tokenRole.toLowerCase() === 'patient') {
      return router.parseUrl('/crm/welcome');
    }

    // Only call auth.me() if we couldn't get the role from the token
    if (!tokenRole) {
      return auth.me().pipe(
        timeout(5000), // 5 second timeout
        map(res => {
          const role = res?.user?.role;
          if (typeof role === 'string' && role.toLowerCase() === 'patient') {
            return router.parseUrl('/crm/welcome');
          }
          return true;
        }),
        catchError(() => of(true)) // On error, allow access to CRM home
      );
    }
  }

  return true;
};
