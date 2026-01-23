import { inject } from '@angular/core';
import { CanMatchFn, Router, UrlTree } from '@angular/router';
import { catchError, map, of, timeout } from 'rxjs';

import { AuthService } from './auth.service';
import { TokenStorageService } from './token-storage.service';

export function requireRoles(roles: string[]): CanMatchFn {
  return () => {
    const auth = inject(AuthService);
    const router = inject(Router);
    const tokenStorage = inject(TokenStorageService);

    const deniedTree = (): UrlTree =>
      router.createUrlTree(['/crm'], { queryParams: { denied: roles.join(',') } });

    const loginTree = (): UrlTree =>
      router.createUrlTree(['/login']);

    // Fast-path: use role from JWT to avoid blocking navigation on /auth/me.
    // (Still enforced server-side; this only improves UX/routing.)
    const tokenRole = tokenStorage.getTokenRole();
    if (tokenRole) {
      const normalizedRole = tokenRole.toLowerCase();
      if (normalizedRole === 'superadmin') return true;
      // Check if any of the required roles match (case-insensitive)
      if (roles.some(r => r.toLowerCase() === normalizedRole)) return true;
      return deniedTree();
    }

    // If no token at all, redirect to login
    if (!tokenStorage.getToken()) {
      return loginTree();
    }

    return auth.me().pipe(
      timeout(10000), // 10 second timeout to avoid indefinite hanging
      map((res) => {
        const role = res?.user?.role;
        if (typeof role === 'string') {
          const normalizedRole = role.toLowerCase();
          if (normalizedRole === 'superadmin') return true;
          if (roles.some(r => r.toLowerCase() === normalizedRole)) return true;
        }
        return deniedTree();
      }),
      catchError((err) => {
        // If error is 401 (unauthorized), redirect to login
        if (err?.status === 401) {
          tokenStorage.clearToken();
          return of(loginTree());
        }
        // For other errors (timeout, network), allow access and let the component handle it
        // This prevents blocking patients from accessing their pages due to temporary API issues
        console.warn('Role guard: API error, allowing access', err);
        return of(true);
      })
    );
  };
}
