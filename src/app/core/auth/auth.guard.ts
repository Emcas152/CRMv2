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

  const fullPath = route.pathFromRoot
    .map(r => r.url.map(s => s.path).join('/'))
    .join('/')
    .replace(/\/+/g, '/');

  // Solo redirigir pacientes desde la raíz del CRM
  if (fullPath === '/crm' || fullPath === '/crm/' || fullPath === '' || fullPath === 'crm') {
    const tokenRole = tokenStorage.getTokenRole();
    const normalizedRole = tokenRole?.toLowerCase();

    // Detectar paciente aunque sea PACIENTE o patient
    if (normalizedRole && ['patient', 'paciente'].includes(normalizedRole)) {
      return router.parseUrl('/crm/welcome');
    }

    // Si el rol no está en el token, consultar /me
    if (!tokenRole) {
      return auth.me().pipe(
        timeout(5000),
        map(res => {
          const role = res?.user?.role?.toLowerCase();
          if (role && ['patient', 'paciente'].includes(role)) {
            return router.parseUrl('/crm/welcome');
          }
          return true;
        }),
        catchError(() => of(true))
      );
    }
  }

  return true;
};
