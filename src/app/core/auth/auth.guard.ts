import { CanActivateChildFn, CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';

import { TokenStorageService } from './token-storage.service';

function hasToken(): boolean {
  const tokenStorage = inject(TokenStorageService);
  return !!tokenStorage.getToken();
}

export const authGuardFn: CanActivateFn = () => {
  const router = inject(Router);
  if (hasToken()) return true;
  return router.parseUrl('/login');
};

export const authChildGuardFn: CanActivateChildFn = () => {
  const router = inject(Router);
  if (hasToken()) return true;
  return router.parseUrl('/login');
};
