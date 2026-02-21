import { CanActivateChildFn, CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { map, catchError, timeout } from 'rxjs/operators';
import { of } from 'rxjs';

import { TokenStorageService } from './token-storage.service';
import { AuthService } from './auth.service';

function hasToken(): boolean {
  const tokenStorage = inject(TokenStorageService);
  const token = tokenStorage.getToken();
  if (!token) return false;
  if (tokenStorage.isTokenExpired()) {
    // Clear expired token so subsequent checks are clean
    tokenStorage.clearToken();
    return false;
  }
  return true;
}

export const authGuardFn: CanActivateFn = () => {
  const router = inject(Router);
  if (hasToken()) return true;
  return router.parseUrl('/login');
};

export const authChildGuardFn: CanActivateChildFn = (_route, state) => {
  const router = inject(Router);
  const auth = inject(AuthService);
  const tokenStorage = inject(TokenStorageService);

  if (!hasToken()) {
    return router.parseUrl('/login?sessionExpired=true');
  }

  // Use state.url to get the full destination URL, not just the current segment
  const targetUrl = state.url.split('?')[0].replace(/\/+/g, '/');

  // Solo redirigir pacientes desde la raíz del CRM (no desde subrutas como /welcome)
  if (targetUrl === '/' || targetUrl === '') {
    const tokenRole = tokenStorage.getTokenRole();
    const normalizedRole = tokenRole?.toLowerCase();

    // Detectar paciente aunque sea PACIENTE o patient
    if (normalizedRole && ['patient', 'paciente'].includes(normalizedRole)) {
      return router.parseUrl('/welcome');
    }

    // Si el rol no está en el token, consultar /me
    if (!tokenRole) {
      return auth.me().pipe(
        timeout(5000),
        map(res => {
          const role = res?.user?.role?.toLowerCase();
          if (role && ['patient', 'paciente'].includes(role)) {
            return router.parseUrl('/welcome');
          }
          return true;
        }),
        catchError(() => of(true))
      );
    }
  }

  return true;
};
