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
  FormSelectDirective,
  RowComponent,
  TableDirective
} from '@coreui/angular';

import {
  CreateUpdateRequest,
  UpdateAudienceRole,
  UpdateAudienceType,
  UpdateItem,
  UpdateUpdateRequest,
  UpdatesService
} from '../../../core/services/updates.service';
import { Id } from '../../../core/services/api.models';

@Component({
  selector: 'app-crm-updates-page',
  templateUrl: './updates-page.component.html',
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
    FormControlDirective,
    FormSelectDirective
  ]
})
export class UpdatesPageComponent implements OnInit {
  readonly #updates = inject(UpdatesService);
  readonly #fb = inject(FormBuilder);

  isLoading = false;
  error: string | null = null;
  submitError: string | null = null;
  total = 0;
  updates: UpdateItem[] = [];

  isSaving = false;
  editingId: Id | null = null;

  readonly audienceTypes: UpdateAudienceType[] = ['all', 'role', 'user', 'patient'];
  readonly audienceRoles: UpdateAudienceRole[] = ['superadmin', 'admin', 'doctor', 'staff', 'patient'];

  readonly filterForm = this.#fb.nonNullable.group({
    created_by: [0],
    patient_id: [0]
  });

  readonly form = this.#fb.nonNullable.group({
    title: ['', [Validators.required]],
    body: ['', [Validators.required]],
    audience_type: ['all' as UpdateAudienceType],
    audience_role: ['' as '' | UpdateAudienceRole],
    audience_user_id: [0],
    patient_id: [0]
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
      const query: { created_by?: Id; patient_id?: Id } = {};
      if (Number(raw.created_by) > 0) query.created_by = Number(raw.created_by) as Id;
      if (Number(raw.patient_id) > 0) query.patient_id = Number(raw.patient_id) as Id;

      const res = await firstValueFrom(this.#updates.list(query));
      this.total = res.total;
      this.updates = res.data;
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  startCreate(): void {
    this.editingId = null;
    this.submitError = null;
    this.form.reset({
      title: '',
      body: '',
      audience_type: 'all',
      audience_role: '',
      audience_user_id: 0,
      patient_id: 0
    });
  }

  startEdit(item: UpdateItem): void {
    this.editingId = item.id;
    this.submitError = null;
    this.form.reset({
      title: item.title ?? '',
      body: item.body ?? '',
      audience_type: (item.audience_type ?? 'all') as UpdateAudienceType,
      audience_role: (item.audience_role ?? '') as any,
      audience_user_id: Number(item.audience_user_id ?? 0),
      patient_id: Number(item.patient_id ?? 0)
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
    const basePayload = {
      title: raw.title.trim(),
      body: raw.body.trim(),
      audience_type: raw.audience_type
    };

    try {
      if (this.editingId === null) {
        const payload: CreateUpdateRequest = {
          ...basePayload,
          audience_role: raw.audience_role || undefined,
          audience_user_id: Number(raw.audience_user_id) > 0 ? Number(raw.audience_user_id) as Id : undefined,
          patient_id: Number(raw.patient_id) > 0 ? Number(raw.patient_id) as Id : undefined
        };
        await firstValueFrom(this.#updates.create(payload));
        this.startCreate();
      } else {
        const payload: UpdateUpdateRequest = {
          ...basePayload,
          audience_role: raw.audience_role ? raw.audience_role : null,
          audience_user_id: Number(raw.audience_user_id) > 0 ? Number(raw.audience_user_id) as Id : null,
          patient_id: Number(raw.patient_id) > 0 ? Number(raw.patient_id) as Id : null
        };
        await firstValueFrom(this.#updates.update(this.editingId, payload));
      }
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async delete(item: UpdateItem): Promise<void> {
    this.error = null;
    if (!window.confirm(`Eliminar actualizacion #${item.id}?`)) return;
    try {
      await firstValueFrom(this.#updates.delete(item.id));
      await this.refresh();
      if (this.editingId === item.id) this.startCreate();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  formatAudience(item: UpdateItem): string {
    const type = item.audience_type ?? 'all';
    if (type === 'role') return `role:${item.audience_role ?? ''}`;
    if (type === 'user') return `user:${item.audience_user_id ?? ''}`;
    if (type === 'patient') return `patient:${item.patient_id ?? ''}`;
    return 'all';
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar las actualizaciones.';
  }
}
