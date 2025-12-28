import { inject } from '@angular/core';
import { CanMatchFn, Router, UrlTree } from '@angular/router';
import { catchError, map, of } from 'rxjs';

import { AuthService } from './auth.service';

export function requireRoles(roles: string[]): CanMatchFn {
  return () => {
    const auth = inject(AuthService);
    const router = inject(Router);

    const deniedTree = (): UrlTree =>
      router.createUrlTree(['/crm'], { queryParams: { denied: roles.join(',') } });

    return auth.me().pipe(
      map((res) => {
        const role = res?.user?.role;
        if (typeof role === 'string' && roles.includes(role)) return true;
        return deniedTree();
      }),
      catchError(() => of(deniedTree()))
    );
  };
}
