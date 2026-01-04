import { JsonPipe } from '@angular/common';
import { Component, OnInit, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { firstValueFrom } from 'rxjs';

import {
  AlertComponent,
  ButtonDirective,
  CardBodyComponent,
  CardComponent,
  CardHeaderComponent,
  ColComponent,
  FormControlDirective,
  FormDirective,
  FormLabelDirective,
  RowComponent
} from '@coreui/angular';

import { ProfileService } from '../../../core/services/profile.service';
import { PatientsService } from '../../../core/services/patients.service';
import * as QRCode from 'qrcode';

@Component({
  selector: 'app-crm-profile-page',
  templateUrl: './profile-page.component.html',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RowComponent,
    ColComponent,
    CardComponent,
    CardHeaderComponent,
    CardBodyComponent,
    ButtonDirective,
    AlertComponent,
    FormDirective,
    FormLabelDirective,
    FormControlDirective,
    JsonPipe
  ]
})
export class ProfilePageComponent implements OnInit {
  readonly #profile = inject(ProfileService);
  readonly #patients = inject(PatientsService);
  readonly #fb = inject(FormBuilder);

  isLoading = false;
  isSaving = false;
  error: string | null = null;
  info: string | null = null;
  profile: any = null;

  qrCodeText: string | null = null;
  qrDataUrl: string | null = null;
  isLoadingQr = false;

  selectedPhoto: File | null = null;

  readonly updateForm = this.#fb.nonNullable.group({
    name: ['', [Validators.required]],
    email: ['', [Validators.required, Validators.email]]
  });

  readonly passwordForm = this.#fb.nonNullable.group({
    current_password: ['', [Validators.required]],
    new_password: ['', [Validators.required, Validators.minLength(6)]],
    confirm_password: ['', [Validators.required, Validators.minLength(6)]]
  });

  ngOnInit(): void {
    void this.refresh();
  }

  async refresh(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;
    this.info = null;

    try {
      this.profile = await firstValueFrom(this.#profile.get());
      const user = this.profile?.user as any;
      const name = typeof user?.name === 'string' ? user.name : '';
      const email = typeof user?.email === 'string' ? user.email : '';
      this.updateForm.reset({ name, email });

      // If this user is linked to a patient record, load QR
      const patientId = Number(this.profile?.patient?.id ?? 0) || 0;
      if (patientId > 0) {
        await this.loadQr(patientId);
      } else {
        this.qrCodeText = null;
        this.qrDataUrl = null;
      }
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  async loadQr(patientId?: number): Promise<void> {
    const pid = Number(patientId ?? this.profile?.patient?.id ?? 0) || 0;
    if (!pid || this.isLoadingQr) return;
    this.isLoadingQr = true;
    this.error = null;

    try {
      const qr = await firstValueFrom(this.#patients.getQr(pid));
      this.qrCodeText = qr?.qr_code ?? null;
      if (this.qrCodeText) {
        this.qrDataUrl = await QRCode.toDataURL(this.qrCodeText, { margin: 1, width: 280 });
      } else {
        this.qrDataUrl = null;
      }
    } catch (err: any) {
      // Do not hard-fail the whole profile page if QR fails
      const msg = err?.error?.message ?? err?.message;
      this.error = typeof msg === 'string' && msg.trim().length ? msg : 'No se pudo cargar el QR.';
      this.qrCodeText = null;
      this.qrDataUrl = null;
    } finally {
      this.isLoadingQr = false;
    }
  }

  onPhotoChange(evt: Event): void {
    const input = evt.target as HTMLInputElement;
    this.selectedPhoto = input.files?.[0] ?? null;
  }

  async saveProfile(): Promise<void> {
    this.error = null;
    this.info = null;
    this.updateForm.markAllAsTouched();
    if (this.updateForm.invalid || this.isSaving) return;

    this.isSaving = true;
    try {
      const raw = this.updateForm.getRawValue();
      await firstValueFrom(
        this.#profile.update({
          name: raw.name.trim(),
          email: raw.email.trim()
        })
      );
      this.info = 'Perfil actualizado.';
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async changePassword(): Promise<void> {
    this.error = null;
    this.info = null;
    this.passwordForm.markAllAsTouched();
    if (this.passwordForm.invalid || this.isSaving) return;

    const raw = this.passwordForm.getRawValue();
    if (raw.new_password !== raw.confirm_password) {
      this.error = 'La confirmación no coincide.';
      return;
    }

    this.isSaving = true;
    try {
      await firstValueFrom(
        this.#profile.changePassword({
          current_password: raw.current_password,
          new_password: raw.new_password,
          confirm_password: raw.confirm_password
        })
      );
      this.info = 'Contraseña actualizada.';
      this.passwordForm.reset({ current_password: '', new_password: '', confirm_password: '' });
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async uploadPhoto(): Promise<void> {
    this.error = null;
    this.info = null;
    if (!this.selectedPhoto) {
      this.error = 'Selecciona una foto.';
      return;
    }
    if (this.isSaving) return;

    this.isSaving = true;
    try {
      await firstValueFrom(this.#profile.uploadPhoto(this.selectedPhoto));
      this.info = 'Foto subida.';
      this.selectedPhoto = null;
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo cargar el perfil.';
  }
}
