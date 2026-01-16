import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { CardComponent, CardBodyComponent, CardHeaderComponent, ButtonDirective, ColComponent, RowComponent } from '@coreui/angular';
import { AuthService, AuthUser } from '../../../core/auth/auth.service';

@Component({
  selector: 'app-patient-welcome',
  standalone: true,
  imports: [CommonModule, RouterLink, RowComponent, ColComponent, CardComponent, CardHeaderComponent, CardBodyComponent, ButtonDirective],
  templateUrl: './patient-welcome.component.html'
})
export default class PatientWelcomeComponent {
  readonly #auth = inject(AuthService);
  me: AuthUser | null = null;

  constructor() {
    void this.loadMe();
  }

  async loadMe(): Promise<void> {
    try {
      const res = await this.#auth.me().toPromise?.();
      this.me = (res as any)?.user ?? null;
    } catch {
      this.me = null;
    }
  }
}
