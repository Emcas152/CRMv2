import { Injectable, computed, signal } from '@angular/core';

@Injectable({
  providedIn: 'root'
})
export class LoadingService {
  readonly #inFlightCount = signal(0);

  readonly isLoading = computed(() => this.#inFlightCount() > 0);

  start(): void {
    this.#inFlightCount.update((n) => n + 1);
  }

  stop(): void {
    this.#inFlightCount.update((n) => Math.max(0, n - 1));
  }
}
