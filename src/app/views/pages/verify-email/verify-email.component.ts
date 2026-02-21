import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { AuthService } from '../../../core/auth/auth.service';

@Component({
  selector: 'app-verify-email',
  standalone: true,
  imports: [CommonModule, RouterLink],
  templateUrl: './verify-email.component.html'
})
export class VerifyEmailComponent implements OnInit {
  readonly #route = inject(ActivatedRoute);
  readonly #auth = inject(AuthService);

  isLoading = true;
  ok = false;
  message: string | null = null;

  ngOnInit(): void {
    void this.verify();
  }

  async verify(): Promise<void> {
    this.isLoading = true;
    this.ok = false;
    this.message = null;

    const token = String(this.#route.snapshot.queryParamMap.get('token') ?? '').trim();
    if (!token) {
      this.isLoading = false;
      this.message = 'Token no encontrado. Revisa el enlace del correo.';
      return;
    }

    try {
      await firstValueFrom(this.#auth.verifyEmail(token));
      this.ok = true;
      this.message = 'Correo verificado correctamente. Ya puedes iniciar sesión.';
    } catch (err: any) {
      const msg = err?.error?.message ?? err?.message;
      this.message = typeof msg === 'string' && msg.trim().length ? msg : 'No se pudo verificar el correo.';
    } finally {
      this.isLoading = false;
    }
  }
}

