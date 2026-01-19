import { CanActivateChildFn, CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { map, catchError } from 'rxjs/operators';
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

  if (!hasToken()) {
    return router.parseUrl('/login');
  }

  // Si el usuario está accediendo a la ruta raíz del CRM, verificar el rol
  const fullPath = route.pathFromRoot.map(r => r.url.map(s => s.path).join('/')).join('/').replace(/\/+/g, '/');

  if (fullPath === '/crm' || fullPath === '/crm/' || fullPath === '') {
    return auth.me().pipe(
      map(res => {
        if (res?.user?.role === 'patient') {
          return router.parseUrl('/crm/appointments');
        }
        return true;
      }),
      catchError(() => of(true))
    );
  }

  return true;
};
