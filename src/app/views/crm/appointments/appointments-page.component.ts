import { Component, OnInit, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { CommonModule } from '@angular/common';
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
  Appointment,
  AppointmentsService,
  AppointmentsListQuery,
  CreateAppointmentRequest,
  UpdateAppointmentRequest
} from '../../../core/services/appointments.service';
import { AppointmentStatus, Id } from '../../../core/services/api.models';
import { Patient, PatientsService } from '../../../core/services/patients.service';
import { StaffMember, StaffMembersService } from '../../../core/services/staff-members.service';
import { AuthService, AuthUser } from '../../../core/auth/auth.service';

@Component({
  selector: 'app-crm-appointments-page',
  templateUrl: './appointments-page.component.html',
  standalone: true,
  imports: [
    CommonModule,
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
export class AppointmentsPageComponent implements OnInit {
  readonly #appointments = inject(AppointmentsService);
  readonly #patients = inject(PatientsService);
  readonly #staffMembers = inject(StaffMembersService);
  readonly #auth = inject(AuthService);
  readonly #fb = inject(FormBuilder);

  isLoading = false;
  error: string | null = null;
  submitError: string | null = null;

  actingId: Id | null = null;
  actionError: string | null = null;
  rowStatusById: Partial<Record<string, string>> = {};
  total = 0;
  items: Appointment[] = [];

  patients: Patient[] = [];
  staffMembers: StaffMember[] = [];
  me: AuthUser | null = null;
  isPatient = false;
  patientIdForFilter = 0;

  isSaving = false;
  editingId: Id | null = null;
  readonly statuses: AppointmentStatus[] = ['pending', 'confirmed', 'completed', 'cancelled'];

  readonly statusLabels: Record<AppointmentStatus, string> = {
    pending: 'Pendiente',
    confirmed: 'Confirmada',
    completed: 'Completada',
    cancelled: 'Cancelada'
  };

  readonly filterForm = this.#fb.nonNullable.group({
    patient_id: [0],
    status: ['' as '' | AppointmentStatus],
    date_from: [''],
    date_to: [''],
    page: [1, [Validators.required, Validators.min(1)]],
    per_page: [20, [Validators.required, Validators.min(1), Validators.max(200)]]
  });

  readonly form = this.#fb.nonNullable.group({
    patient_id: [0, [Validators.required, Validators.min(1)]],
    appointment_date: ['', [Validators.required]],
    appointment_time: ['', [Validators.required]],
    service: ['', [Validators.required]],
    status: ['pending' as AppointmentStatus],
    staff_member_id: [0],
    notes: ['']
  });

  ngOnInit(): void {
    void this.loadMe();
    void this.loadLookups();
    void this.refresh();
  }

  async loadMe(): Promise<void> {
    try {
      const res = await this.#auth.me().toPromise?.();
      this.me = (res as any)?.user ?? null;
      this.isPatient = String(this.me?.role ?? '').toLowerCase() === 'patient';
      this.patientIdForFilter = Number((res as any)?.patient?.id ?? 0) || 0;
      if (this.isPatient && this.patientIdForFilter > 0) {
        this.filterForm.controls.patient_id.setValue(this.patientIdForFilter as any);
      }
    } catch {
      this.me = null;
      this.isPatient = false;
    }
  }

  async loadLookups(): Promise<void> {
    try {
      const [patientsRes, staffRes] = await Promise.all([
        firstValueFrom(this.#patients.list({ page: 1, per_page: 200 })),
        firstValueFrom(this.#staffMembers.list())
      ]);

      this.patients = (patientsRes?.data ?? []) as Patient[];
      this.staffMembers = (staffRes?.data ?? []) as StaffMember[];
    } catch {
      // Lookups are optional; keep page functional even if they fail.
    }
  }

  async refresh(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;

    const raw = this.filterForm.getRawValue();
    const query: AppointmentsListQuery = {
      page: Number(raw.page) || 1,
      per_page: Number(raw.per_page) || 20
    };
    if (Number(raw.patient_id) > 0) query.patient_id = Number(raw.patient_id) as Id;
    if (raw.status) query.status = raw.status;
    if (raw.date_from.trim().length) query.date_from = raw.date_from.trim();
    if (raw.date_to.trim().length) query.date_to = raw.date_to.trim();

    try {
      const res = await firstValueFrom(this.#appointments.list(query));
      this.total = res.total;
      this.items = res.data;
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
    this.form.reset({
      patient_id: 0,
      appointment_date: '',
      appointment_time: '',
      service: '',
      status: 'pending',
      staff_member_id: 0,
      notes: ''
    });
  }

  startEdit(a: Appointment): void {
    this.editingId = a.id;
    this.submitError = null;
    this.form.reset({
      patient_id: a.patient_id ?? 0,
      appointment_date: a.appointment_date ?? '',
      appointment_time: a.appointment_time ?? '',
      service: a.service ?? '',
      status: (a.status ?? 'pending') as AppointmentStatus,
      staff_member_id: (a.staff_member_id ?? 0) as any,
      notes: a.notes ?? ''
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
    const staffId = Number(raw.staff_member_id) || 0;

    try {
      if (this.editingId === null) {
        const payload: CreateAppointmentRequest = {
          patient_id: Number(raw.patient_id) as Id,
          appointment_date: raw.appointment_date,
          appointment_time: raw.appointment_time,
          service: raw.service.trim(),
          status: raw.status,
          staff_member_id: staffId > 0 ? (staffId as Id) : undefined,
          notes: raw.notes.trim() || undefined
        };
        await firstValueFrom(this.#appointments.create(payload));
        this.startCreate();
      } else {
        const payload: UpdateAppointmentRequest = {
          patient_id: Number(raw.patient_id) as Id,
          appointment_date: raw.appointment_date,
          appointment_time: raw.appointment_time,
          service: raw.service.trim(),
          status: raw.status,
          staff_member_id: staffId > 0 ? (staffId as Id) : undefined,
          notes: raw.notes.trim() || undefined
        };
        await firstValueFrom(this.#appointments.update(this.editingId, payload));
      }
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async delete(a: Appointment): Promise<void> {
    this.error = null;
    if (!window.confirm(`Eliminar cita #${a.id}?`)) return;
    try {
      await firstValueFrom(this.#appointments.delete(a.id));
      await this.refresh();
      if (this.editingId === a.id) this.startCreate();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  async updateStatus(a: Appointment, status: AppointmentStatus): Promise<void> {
    this.actionError = null;
    if (this.actingId !== null) return;
    this.actingId = a.id;
    this.rowStatusById[String(a.id)] = `Actualizando estado: ${this.statusLabels[status] ?? status}…`;
    try {
      await firstValueFrom(this.#appointments.updateStatus(a.id, status));
      await this.refresh();
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(a.id)];
      }, 2500);
    }
  }

  async sendEmail(a: Appointment): Promise<void> {
    this.actionError = null;
    if (this.actingId !== null) return;
    this.actingId = a.id;
    this.rowStatusById[String(a.id)] = 'Enviando email…';
    try {
      await firstValueFrom(this.#appointments.sendEmail(a.id));
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(a.id)];
      }, 2500);
    }
  }

  async generateReminder(a: Appointment): Promise<void> {
    this.actionError = null;
    if (this.actingId !== null) return;
    this.actingId = a.id;
    this.rowStatusById[String(a.id)] = 'Generando recordatorio…';
    try {
      await firstValueFrom(this.#appointments.generateReminder(a.id));
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(a.id)];
      }, 2500);
    }
  }

  async sendWhatsapp(a: Appointment): Promise<void> {
    this.actionError = null;
    if (this.actingId !== null) return;
    this.actingId = a.id;
    this.rowStatusById[String(a.id)] = 'Sending WhatsApp…';
    try {
      await firstValueFrom(this.#appointments.sendWhatsapp(a.id));
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(a.id)];
      }, 2500);
    }
  }

  trackByAppointment(_index: number, item: Appointment): number {
    return item.id;
  }

  trackByPatient(_index: number, item: Patient): number {
    return item.id;
  }

  getRowStatus(a: Appointment): string | null {
    return (this.rowStatusById[String(a.id)] ?? null) as string | null;
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar las citas.';
  }
}
