import { Component, OnInit, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { firstValueFrom } from 'rxjs';

import { ButtonCloseDirective, ButtonDirective, ButtonModule, ModalComponent, ModalHeaderComponent, ModalBodyComponent, ModalFooterComponent, ModalTitleDirective } from '@coreui/angular';
import { RouterLink } from '@angular/router';

import {
  CreatePatientRequest,
  Patient,
  PatientsService,
  PatientsListQuery,
  UpdatePatientRequest
} from '../../../core/services/patients.service';
import { Id } from '../../../core/services/api.models';
import * as QRCode from 'qrcode';

@Component({
  selector: 'app-crm-patients-page',
  templateUrl: './patients-page.component.html',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    CommonModule,
    ButtonCloseDirective,
    ButtonDirective,
    ButtonModule,
    ModalComponent,
    ModalHeaderComponent,
    ModalBodyComponent,
    ModalFooterComponent,
    ModalTitleDirective,
    RouterLink
  ]
  ,
  styleUrls: ['./patients-page.component.scss']
})
export class PatientsPageComponent implements OnInit {
  readonly #patients = inject(PatientsService);
  readonly #fb = inject(FormBuilder);

  readonly qrSize = 280;
  qrCodeText: string | null = null;
  qrDataUrl: string | null = null;

  isLoading = false;
  error: string | null = null;
  submitError: string | null = null;

  actingId: Id | null = null;
  actionError: string | null = null;
  actionInfo: string | null = null;
  qrResult: { patient_id: Id; qr_code: string; qr_url: string | null } | null = null;
  loyaltyPointsById: Partial<Record<string, string>> = {};
  rowStatusById: Partial<Record<string, string>> = {};
  total = 0;
  patients: Patient[] = [];

  isSaving = false;
  editingId: number | null = null;
  showCreateForm = false;

  readonly filterForm = this.#fb.nonNullable.group({
    search: [''],
    date_from: [''],
    date_to: [''],
    birthday_month: [''],
    page: [1, [Validators.required, Validators.min(1)]],
    per_page: [20, [Validators.required, Validators.min(1), Validators.max(200)]]
  });

  readonly form = this.#fb.nonNullable.group({
    name: ['', [Validators.required]],
    email: ['', [Validators.required, Validators.email]],
    phone: [''],
    mobile_phone: [''],
    home_phone: [''],
    birthday: [''],
    age: [''],
    address: [''],
    nit: [''],
    marital_status: [''],
    spouse_name: [''],
    place_of_birth: [''],
    nationality: [''],
    dpi: [''],
    blood_type: [''],
    profession: [''],
    workplace: [''],
    referred_by: [''],
    reason_for_consultation: [''],
    invoice_name: ['']
  });

  get maxPage(): number {
    const perPage = Number(this.filterForm.controls.per_page.value) || 20;
    return Math.max(1, Math.ceil((Number(this.total) || 0) / perPage));
  }

  ngOnInit(): void {
    void this.refresh();
  }

  async refresh(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;

    try {
      const raw = this.filterForm.getRawValue();
      const query: PatientsListQuery = {
        page: Number(raw.page) || 1,
        per_page: Number(raw.per_page) || 20
      };
      if (raw.search.trim().length) query.search = raw.search.trim();
      if (raw.date_from.trim().length) query.date_from = raw.date_from.trim();
      if (raw.date_to.trim().length) query.date_to = raw.date_to.trim();
      if (raw.birthday_month.trim().length) query.birthday_month = raw.birthday_month.trim();

      const res = await firstValueFrom(this.#patients.list(query));
      this.total = res.total;
      this.patients = res.data;
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  async prevPage(): Promise<void> {
    const page = Number(this.filterForm.controls.page.value) || 1;
    if (page <= 1) return;
    this.filterForm.controls.page.setValue(page - 1);
    await this.refresh();
  }

  async nextPage(): Promise<void> {
    const page = Number(this.filterForm.controls.page.value) || 1;
    const perPage = Number(this.filterForm.controls.per_page.value) || 20;
    const maxPage = Math.max(1, Math.ceil((Number(this.total) || 0) / perPage));
    if (page >= maxPage) return;
    this.filterForm.controls.page.setValue(page + 1);
    await this.refresh();
  }

  toggleCreateForm(): void {
    this.showCreateForm = !this.showCreateForm;
    if (this.showCreateForm) this.startCreate();
  }

  closeModal(): void {
    this.showCreateForm = false;
    this.cancelEdit();
  }

  onModalVisibleChange(visible: boolean): void {
    this.showCreateForm = visible;
    if (!visible) {
      this.cancelEdit();
    }
  }

  startEditAndShow(p: Patient): void {
    this.startEdit(p);
    this.showCreateForm = true;
  }

  startCreate(): void {
    this.editingId = null;
    this.submitError = null;
    this.form.reset({
      name: '', email: '', phone: '', mobile_phone: '', home_phone: '', birthday: '', age: '', address: '', nit: '', marital_status: '', spouse_name: '', place_of_birth: '', nationality: '', dpi: '', blood_type: '', profession: '', workplace: '', referred_by: '', reason_for_consultation: '', invoice_name: ''
    });
  }

  startEdit(p: Patient): void {
    this.editingId = p.id;
    this.submitError = null;
    this.form.reset({
      name: p.name ?? '',
      email: p.email ?? '',
      phone: (p as any).phone ?? '',
      mobile_phone: (p as any).mobile_phone ?? '',
      home_phone: (p as any).home_phone ?? '',
      birthday: (p as any).birthday ?? '',
      age: (p as any).age ?? '',
      address: (p as any).address ?? '',
      nit: (p as any).nit ?? '',
      marital_status: (p as any).marital_status ?? '',
      spouse_name: (p as any).spouse_name ?? '',
      place_of_birth: (p as any).place_of_birth ?? '',
      nationality: (p as any).nationality ?? '',
      dpi: (p as any).dpi ?? '',
      blood_type: (p as any).blood_type ?? '',
      profession: (p as any).profession ?? '',
      workplace: (p as any).workplace ?? '',
      referred_by: (p as any).referred_by ?? '',
      reason_for_consultation: (p as any).reason_for_consultation ?? '',
      invoice_name: (p as any).invoice_name ?? ''
    });
  }

  cancelEdit(): void {
    this.startCreate();
  }

  async save(): Promise<void> {
    this.submitError = null;
    this.form.markAllAsTouched();
    if (this.form.invalid || this.isSaving) return;

    this.isSaving = true;
    const raw = this.form.getRawValue();

    const payloadBase = {
      name: raw.name.trim(),
      email: raw.email.trim(),
      phone: raw.phone.trim() || undefined,
      mobile_phone: raw.mobile_phone?.trim() || undefined,
      home_phone: raw.home_phone?.trim() || undefined,
      birthday: raw.birthday?.trim() || undefined,
      age: raw.age?.toString?.().trim() || undefined,
      address: raw.address?.trim() || undefined,
      nit: raw.nit?.trim() || undefined,
      marital_status: raw.marital_status?.trim() || undefined,
      spouse_name: raw.spouse_name?.trim() || undefined,
      place_of_birth: raw.place_of_birth?.trim() || undefined,
      nationality: raw.nationality?.trim() || undefined,
      dpi: raw.dpi?.trim() || undefined,
      blood_type: raw.blood_type?.trim() || undefined,
      profession: raw.profession?.trim() || undefined,
      workplace: raw.workplace?.trim() || undefined,
      referred_by: raw.referred_by?.trim() || undefined,
      reason_for_consultation: raw.reason_for_consultation?.trim() || undefined,
      invoice_name: raw.invoice_name?.trim() || undefined
    };

    try {
      if (this.editingId === null) {
        await firstValueFrom(this.#patients.create(payloadBase as CreatePatientRequest));
        this.showCreateForm = false;
        this.startCreate();
      } else {
        const update: UpdatePatientRequest = {
          ...payloadBase,
          phone: raw.phone?.trim() ? raw.phone.trim() : null,
          mobile_phone: raw.mobile_phone?.trim() ? raw.mobile_phone.trim() : null,
          home_phone: raw.home_phone?.trim() ? raw.home_phone.trim() : null,
          birthday: raw.birthday?.trim() ? raw.birthday.trim() : null,
          age: raw.age ? raw.age : null,
          address: raw.address?.trim() ? raw.address.trim() : null,
          nit: raw.nit?.trim() ? raw.nit.trim() : null,
          marital_status: raw.marital_status?.trim() ? raw.marital_status.trim() : null,
          spouse_name: raw.spouse_name?.trim() ? raw.spouse_name.trim() : null,
          place_of_birth: raw.place_of_birth?.trim() ? raw.place_of_birth.trim() : null,
          nationality: raw.nationality?.trim() ? raw.nationality.trim() : null,
          dpi: raw.dpi?.trim() ? raw.dpi.trim() : null,
          blood_type: raw.blood_type?.trim() ? raw.blood_type.trim() : null,
          profession: raw.profession?.trim() ? raw.profession.trim() : null,
          workplace: raw.workplace?.trim() ? raw.workplace.trim() : null,
          referred_by: raw.referred_by?.trim() ? raw.referred_by.trim() : null,
          reason_for_consultation: raw.reason_for_consultation?.trim() ? raw.reason_for_consultation.trim() : null,
          invoice_name: raw.invoice_name?.trim() ? raw.invoice_name.trim() : null
        };
        await firstValueFrom(this.#patients.update(this.editingId, update));
      }
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async delete(p: Patient): Promise<void> {
    this.error = null;
    if (!window.confirm(`Eliminar paciente #${p.id}?`)) return;
    try {
      await firstValueFrom(this.#patients.delete(p.id));
      await this.refresh();
      if (this.editingId === p.id) this.startCreate();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  setLoyaltyPoints(p: Patient, value: string): void {
    this.loyaltyPointsById[String(p.id)] = value;
  }

  async showQr(p: Patient): Promise<void> {
    this.actionError = null;
    this.actionInfo = null;
    this.qrCodeText = null;
    this.qrDataUrl = null;
    if (this.actingId !== null) return;
    this.actingId = p.id;
    this.rowStatusById[String(p.id)] = 'Loading QR…';
    try {
      const res = await firstValueFrom(this.#patients.getQr(p.id));
      this.qrResult = { patient_id: p.id, ...res };
      this.qrCodeText = res?.qr_code ?? null;
      if (this.qrCodeText) {
        this.qrDataUrl = await QRCode.toDataURL(this.qrCodeText, { margin: 1, width: this.qrSize });
      }
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(p.id)];
      }, 2500);
    }
  }

  copyQrCode(): void {
    if (!this.qrCodeText) return;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        void navigator.clipboard.writeText(this.qrCodeText).then(
          () => (this.actionInfo = 'Código QR copiado.'),
          () => (this.actionError = 'No se pudo copiar el código.')
        );
      } else {
        const ta = document.createElement('textarea');
        ta.value = this.qrCodeText;
        document.body.appendChild(ta);
        ta.select();
        try {
          document.execCommand('copy');
          this.actionInfo = 'Código QR copiado.';
        } catch {
          this.actionError = 'No se pudo copiar el código.';
        }
        document.body.removeChild(ta);
      }
    } catch {
      this.actionError = 'No se pudo copiar el código.';
    }
  }

  downloadQr(): void {
    if (!this.qrDataUrl) return;
    const a = document.createElement('a');
    a.href = this.qrDataUrl;
    a.download = `paciente-${this.qrResult?.patient_id ?? 'qr'}.png`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  async loyaltyAdd(p: Patient): Promise<void> {
    this.actionError = null;
    this.actionInfo = null;
    if (this.actingId !== null) return;
    const points = Number(this.loyaltyPointsById[String(p.id)]);
    if (!Number.isFinite(points) || points <= 0) {
      this.actionError = 'Ingrese puntos válidos (> 0).';
      return;
    }
    this.actingId = p.id;
    this.rowStatusById[String(p.id)] = 'Adding points…';
    try {
      await firstValueFrom(this.#patients.loyaltyAdd(p.id, points));
      this.actionInfo = `Puntos agregados a paciente #${p.id}.`;
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(p.id)];
      }, 2500);
    }
  }

  async loyaltyRedeem(p: Patient): Promise<void> {
    this.actionError = null;
    this.actionInfo = null;
    if (this.actingId !== null) return;
    const points = Number(this.loyaltyPointsById[String(p.id)]);
    if (!Number.isFinite(points) || points <= 0) {
      this.actionError = 'Ingrese puntos válidos (> 0).';
      return;
    }
    this.actingId = p.id;
    this.rowStatusById[String(p.id)] = 'Redeeming points…';
    try {
      await firstValueFrom(this.#patients.loyaltyRedeem(p.id, points));
      this.actionInfo = `Puntos redimidos para paciente #${p.id}.`;
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(p.id)];
      }, 2500);
    }
  }

  async uploadPhoto(p: Patient, ev: Event, type: 'before' | 'after'): Promise<void> {
    this.actionError = null;
    this.actionInfo = null;
    const input = ev.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    if (this.actingId !== null) return;
    this.actingId = p.id;
    this.rowStatusById[String(p.id)] = `Uploading (${type})…`;
    try {
      await firstValueFrom(this.#patients.uploadPhotoMultipart(p.id, file, type));
      this.actionInfo = `Foto (${type}) subida para paciente #${p.id}.`;
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(p.id)];
      }, 2500);
    }
  }

  #formatError(err: any): string {
    // Prefer structured API messages when available (validation errors, etc.)
    try {
      const apiMessage = err?.error?.message;
      if (typeof apiMessage === 'string' && apiMessage.trim().length) return apiMessage;

      // Some backends return an `errors` object with field errors
      const errors = err?.error?.errors;
      if (errors && typeof errors === 'object') {
        const parts: string[] = [];
        for (const [k, v] of Object.entries(errors)) {
          if (Array.isArray(v)) parts.push(`${k}: ${v.join(', ')}`);
          else parts.push(`${k}: ${String(v)}`);
        }
        if (parts.length) return parts.join(' — ');
      }

      const message = err?.message;
      if (typeof message === 'string' && message.trim().length) return message;
    } catch {
      // fallthrough
    }
    return 'No se pudieron completar la operación.';
  }

  trackByPatient(_index: number, p: Patient): number | string {
    return p.id;
  }
}
