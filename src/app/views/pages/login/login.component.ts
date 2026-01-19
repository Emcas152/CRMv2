import { Component, inject, OnInit } from '@angular/core';
import { ReactiveFormsModule, FormBuilder, Validators } from '@angular/forms';
import { Router, RouterLink, ActivatedRoute } from '@angular/router';
import { IconDirective } from '@coreui/icons-angular';
import {
  AlertComponent,
  ButtonDirective,
  CardBodyComponent,
  CardComponent,
  CardGroupComponent,
  ColComponent,
  ContainerComponent,
  FormControlDirective,
  FormDirective,
  InputGroupComponent,
  InputGroupTextDirective,
  RowComponent
} from '@coreui/angular';

import { AuthService } from '../../../core/auth/auth.service';
import { firstValueFrom } from 'rxjs';

@Component({
  selector: 'app-login',
  templateUrl: './login.component.html',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    ContainerComponent,
    RowComponent,
    ColComponent,
    CardGroupComponent,
    CardComponent,
    CardBodyComponent,
    FormDirective,
    InputGroupComponent,
    InputGroupTextDirective,
    IconDirective,
    FormControlDirective,
    ButtonDirective,
    AlertComponent
  ]
})
export class LoginComponent implements OnInit {
  readonly #fb = inject(FormBuilder);
  readonly #auth = inject(AuthService);
  readonly #router = inject(Router);
  readonly #route = inject(ActivatedRoute);

  isSubmitting = false;
  submitError: string | null = null;
  sessionExpired = false;

  readonly form = this.#fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]]
  });

  ngOnInit(): void {
    // Check if redirected due to session expiration
    this.#route.queryParams.subscribe(params => {
      this.sessionExpired = params['sessionExpired'] === 'true';
    });
  }

  async onSubmit(): Promise<void> {
    this.submitError = null;
    this.form.markAllAsTouched();
    if (this.form.invalid || this.isSubmitting) return;

    this.isSubmitting = true;
    const { email, password } = this.form.getRawValue();

    try {
      const res = await firstValueFrom(this.#auth.login(email, password));
      const role = String(res?.user?.role ?? '').toLowerCase();
      if (role === 'patient') {
        await this.#router.navigateByUrl('/crm/welcome');
      } else {
        await this.#router.navigateByUrl('/crm');
      }
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSubmitting = false;
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo iniciar sesión. Verifica tus credenciales.';
  }
}
