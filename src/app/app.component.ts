import { Component, DestroyRef, inject, OnInit } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Title } from '@angular/platform-browser';
import {
  ActivatedRoute,
  NavigationCancel,
  NavigationEnd,
  NavigationError,
  NavigationStart,
  Router,
  RouterOutlet
} from '@angular/router';
import { delay, filter, map, tap } from 'rxjs/operators';

import { ColorModeService, SpinnerComponent } from '@coreui/angular';
import { IconSetService } from '@coreui/icons-angular';
import { iconSubset } from './icons/icon-subset';
import { LoadingService } from './core/loading/loading.service';

@Component({
    selector: 'app-root',
    template: `
      <router-outlet />

      @if (isNavigating || isRequesting()) {
        <div
          class="app-loading-overlay position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-body bg-opacity-75"
          role="status"
          aria-live="polite"
          aria-label="Cargando"
        >
          <c-spinner color="primary" aria-hidden="true" />
          <span class="visually-hidden">Cargando…</span>
        </div>
      }
    `,
    imports: [RouterOutlet, SpinnerComponent]
})
export class AppComponent implements OnInit {
  title = 'CoreUI Angular Admin Template';

  isNavigating = false;
  readonly isRequesting = inject(LoadingService).isLoading;

  readonly #destroyRef: DestroyRef = inject(DestroyRef);
  readonly #activatedRoute: ActivatedRoute = inject(ActivatedRoute);
  readonly #router = inject(Router);
  readonly #titleService = inject(Title);

  readonly #colorModeService = inject(ColorModeService);
  readonly #iconSetService = inject(IconSetService);

  constructor() {
    this.#titleService.setTitle(this.title);
    // iconSet singleton
    this.#iconSetService.icons = { ...iconSubset };
    this.#colorModeService.localStorageItemName.set('coreui-free-angular-admin-template-theme-default');
    this.#colorModeService.eventName.set('ColorSchemeChange');
  }

  ngOnInit(): void {
    this.#router.events.pipe(takeUntilDestroyed(this.#destroyRef)).subscribe((evt) => {
      if (evt instanceof NavigationStart) {
        this.isNavigating = true;
        return;
      }
      if (evt instanceof NavigationEnd || evt instanceof NavigationCancel || evt instanceof NavigationError) {
        this.isNavigating = false;
      }
    });

    this.#activatedRoute.queryParams
      .pipe(
        delay(1),
        map(params => <string>params['theme']?.match(/^[A-Za-z0-9\s]+/)?.[0]),
        filter(theme => ['dark', 'light', 'auto'].includes(theme)),
        tap(theme => {
          this.#colorModeService.colorMode.set(theme);
        }),
        takeUntilDestroyed(this.#destroyRef)
      )
      .subscribe();
  }
}
