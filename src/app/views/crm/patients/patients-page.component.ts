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
  RowComponent,
  TableDirective
} from '@coreui/angular';

import {
  CreatePatientRequest,
  Patient,
  PatientsService,
  PatientsListQuery,
  UpdatePatientRequest
} from '../../../core/services/patients.service';
import { Id } from '../../../core/services/api.models';

@Component({
  selector: 'app-crm-patients-page',
  templateUrl: './patients-page.component.html',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RowComponent,
    ColComponent,
    CardComponent,
    CardHeaderComponent,
    CardBodyComponent,
    TableDirective,
    ButtonDirective,
    AlertComponent,
    FormDirective,
    FormLabelDirective,
    FormControlDirective
  ]
})
export class PatientsPageComponent implements OnInit {
  readonly #patients = inject(PatientsService);
  readonly #fb = inject(FormBuilder);

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
    birthday: [''],
    address: [''],
    nit: ['']
  });

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

  startCreate(): void {
    this.editingId = null;
    this.submitError = null;
    this.form.reset({ name: '', email: '', phone: '', birthday: '', address: '', nit: '' });
  }

  startEdit(p: Patient): void {
    this.editingId = p.id;
    this.submitError = null;
    this.form.reset({
      name: p.name ?? '',
      email: p.email ?? '',
      phone: p.phone ?? '',
      birthday: p.birthday ?? '',
      address: p.address ?? '',
      nit: p.nit ?? ''
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
      birthday: raw.birthday.trim() || undefined,
      address: raw.address.trim() || undefined,
      nit: raw.nit.trim() || undefined
    };

    try {
      if (this.editingId === null) {
        await firstValueFrom(this.#patients.create(payloadBase as CreatePatientRequest));
        this.startCreate();
      } else {
        const update: UpdatePatientRequest = {
          ...payloadBase,
          phone: raw.phone.trim() ? raw.phone.trim() : null,
          birthday: raw.birthday.trim() ? raw.birthday.trim() : null,
          address: raw.address.trim() ? raw.address.trim() : null,
          nit: raw.nit.trim() ? raw.nit.trim() : null
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
    if (this.actingId !== null) return;
    this.actingId = p.id;
    this.rowStatusById[String(p.id)] = 'Loading QR…';
    try {
      const res = await firstValueFrom(this.#patients.getQr(p.id));
      this.qrResult = { patient_id: p.id, ...res };
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(p.id)];
      }, 2500);
    }
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
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar los pacientes.';
  }
}
