import { Component, OnInit, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { CommonModule } from '@angular/common';

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
    CommonModule,
    ReactiveFormsModule,
    RowComponent,
    ColComponent,
    CardComponent,
    CardHeaderComponent,
    CardBodyComponent,
    ButtonDirective
  ]
})
export class ProfilePageComponent implements OnInit {
  readonly #profile = inject(ProfileService);
  readonly #patients = inject(PatientsService);
  readonly #fb = inject(FormBuilder);

  readonly qrSize = 420;

  isLoading = false;
  isSaving = false;
  error: string | null = null;
  info: string | null = null;
  profile: any = null;
  permissions: string[] = [];
  recent_activity: Array<any> = [];

  // Grow inventory file info
  readonly growInventoryFile = 'Detalle Inv Produtos GROW 2025.xlsx';
  growInventoryUrl = '/assets/' + encodeURIComponent(this.growInventoryFile);

  qrCodeText: string | null = null;
  qrDataUrl: string | null = null;
  isLoadingQr = false;

  selectedPhoto: File | null = null;

  showEdit = false;
  // slide-over panel state for Option C
  slideOpen = false;

  readonly updateForm = this.#fb.nonNullable.group({
    name: ['', [Validators.required]],
    email: ['', [Validators.required, Validators.email]]
  });
  
  // additional small reactive group for phone to keep primary form minimal
  readonly updateFormPhone = this.#fb.nonNullable.group({
    phone: ['']
  });

  readonly passwordForm = this.#fb.nonNullable.group({
    current_password: ['', [Validators.required]],
    new_password: ['', [Validators.required, Validators.minLength(6)]],
    confirm_password: ['', [Validators.required, Validators.minLength(6)]]
  });

  ngOnInit(): void {
    void this.refresh();
  }

  get hasGrowAccess(): boolean {
    const userRole = (this.profile?.user?.role ?? '') as string;
    const isAdmin = userRole === 'admin' || userRole === 'superadmin';
    if (isAdmin) return true;

    const staff = this.profile?.staff_member as any;
    if (!staff) return false;

    // heuristics: check title/department/area for 'grow'
    const fields = [staff.title, staff.department, staff.area, staff.team].filter(Boolean).map((s: any) => String(s));
    const combined = fields.join(' ').toLowerCase();
    return combined.includes('grow');
  }

  async refresh(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;
    this.info = null;

    try {
      this.profile = await firstValueFrom(this.#profile.get());
      // normalize permissions and recent activity if provided by backend
      this.permissions = (this.profile?.user?.permissions ?? this.profile?.permissions ?? []) as string[];
      this.recent_activity = (this.profile?.recent_activity ?? []) as any[];
      const user = this.profile?.user as any;
      const name = typeof user?.name === 'string' ? user.name : '';
      const email = typeof user?.email === 'string' ? user.email : '';
      const phone = typeof this.profile?.staff_member?.phone === 'string'
        ? this.profile.staff_member.phone
        : (typeof user?.phone === 'string' ? user.phone : '');
      this.updateForm.reset({ name, email });
      this.updateFormPhone.reset({ phone });

      await this.refreshQr();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  get hasPatient(): boolean {
    return Number((this.profile as any)?.patient?.id ?? 0) > 0;
  }

  get qrTitle(): string {
    return this.hasPatient ? 'Mi QR ' : 'Mi QR';
  }

  async refreshQr(): Promise<void> {
    if (this.isLoadingQr) return;
    this.isLoadingQr = true;
    this.error = null;

    try {
      const patientId = Number((this.profile as any)?.patient?.id ?? 0) || 0;
      if (patientId > 0) {
        const qr = await firstValueFrom(this.#patients.getQr(patientId));
        this.qrCodeText = qr?.qr_code ?? null;
      } else {
        const userId = Number((this.profile as any)?.user?.id ?? 0) || 0;
        this.qrCodeText = userId > 0 ? `USER:${userId}` : null;
      }

      if (this.qrCodeText) {
        this.qrDataUrl = await QRCode.toDataURL(this.qrCodeText, {
          margin: 2,
          width: this.qrSize,
          errorCorrectionLevel: 'M'
        });
      } else {
        this.qrDataUrl = null;
      }
    } catch (err: any) {
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
      const phoneRaw = this.updateFormPhone.getRawValue();
      const payload: any = {
        name: raw.name.trim(),
        email: raw.email.trim()
      };
      if (phoneRaw?.phone) payload.phone = phoneRaw.phone.trim();
      await firstValueFrom(this.#profile.update(payload));
      this.info = 'Perfil actualizado.';
      await this.refresh();
      // close slide-over if open
      this.slideOpen = false;
      this.showEdit = false;
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  openSlide(): void {
    this.slideOpen = true;
  }

  closeSlide(): void {
    this.slideOpen = false;
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

  cancelEdit(): void {
    const user = this.profile?.user as any;
    const name = typeof user?.name === 'string' ? user.name : '';
    const email = typeof user?.email === 'string' ? user.email : '';
    const phone = typeof this.profile?.staff_member?.phone === 'string'
      ? this.profile.staff_member.phone
      : (typeof user?.phone === 'string' ? user.phone : '');
    this.updateForm.reset({ name, email });
    this.updateFormPhone.reset({ phone });
    this.showEdit = false;
    this.selectedPhoto = null;
    this.error = null;
    this.info = null;
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo cargar el perfil.';
  }
}
