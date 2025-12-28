import { Component, inject } from '@angular/core';
import { ReactiveFormsModule, FormBuilder, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { IconDirective } from '@coreui/icons-angular';
import {
  AlertComponent,
  ButtonDirective,
  CardBodyComponent,
  CardComponent,
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
  selector: 'app-register',
  templateUrl: './register.component.html',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    ContainerComponent,
    RowComponent,
    ColComponent,
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
export class RegisterComponent {
  readonly #fb = inject(FormBuilder);
  readonly #auth = inject(AuthService);
  readonly #router = inject(Router);

  isSubmitting = false;
  submitError: string | null = null;

  readonly form = this.#fb.nonNullable.group({
    name: ['', [Validators.required]],
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(8)]],
    confirmPassword: ['', [Validators.required]]
  });

  get passwordsMismatch(): boolean {
    const { password, confirmPassword } = this.form.getRawValue();
    return this.form.controls.confirmPassword.touched && password !== confirmPassword;
  }

  async onSubmit(): Promise<void> {
    this.submitError = null;
    this.form.markAllAsTouched();
    if (this.form.invalid || this.passwordsMismatch || this.isSubmitting) return;

    this.isSubmitting = true;
    const { name, email, password } = this.form.getRawValue();

    try {
      await firstValueFrom(this.#auth.register({ name, email, password }));
      await this.#router.navigateByUrl('/login');
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSubmitting = false;
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo registrar. Revisa los datos e intenta de nuevo.';
  }
}
