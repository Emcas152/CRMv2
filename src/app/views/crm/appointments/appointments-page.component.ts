import { Component, OnInit, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { firstValueFrom } from 'rxjs';

import {
  AlertComponent,
  ButtonDirective,
  CardBodyComponent,
  CardComponent,
  CardHeaderComponent,
  ColComponent,
  RowComponent
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

interface CalendarDay {
  date: Date;
  dayNumber: number;
  isCurrentMonth: boolean;
  isToday: boolean;
  appointments: Appointment[];
  dateStr: string;
}

@Component({
  selector: 'app-crm-appointments-page',
  templateUrl: './appointments-page.component.html',
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    FormsModule,
    RowComponent,
    ColComponent,
    CardComponent,
    CardHeaderComponent,
    CardBodyComponent,
    ButtonDirective,
    AlertComponent
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

  // Custom Calendar
  currentDate = new Date();
  calendarDays: CalendarDay[] = [];
  weekDays = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
  allAppointments: Appointment[] = [];

  selectedDate: string | null = null;
  selectedDay: CalendarDay | null = null;
  daySlots: Array<{ time: string; available: boolean; appointment?: Appointment }> = [];


  // Simple business hours config for generating free slots (can be adjusted later)
  readonly businessStart = '09:00';
  readonly businessEnd = '17:00';
  readonly slotMinutes = 30;

  // Modal / request draft
  showRequestModal = false;
  requestDraft: { date?: string; time?: string; notes?: string } = {};
  // If true, we could fetch occupied slots for selected date; false if backend denied access
  canViewOccupiedForDate = true;
  // Track recently created appointment for highlighting
  recentlyCreatedAppointmentId: number | null = null;

  // Reminder modal state
  showReminderModal = false;
  reminderDraft = '';
  reminderAppointment: Appointment | null = null;
  isSavingReminder = false;

  ngOnInit(): void {
    void this.loadMe();
    void this.loadLookups();
    void this.refresh();
    this.generateCalendar();
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
      // Reload calendar after getting user info
      void this.loadAllAppointments();
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
      if (this.isPatient) {
        // Patients may not have permissions to list all appointments — skip listing and refresh calendar only.
        this.items = [];
        await this.loadAllAppointments();
      } else {
        const res = await firstValueFrom(this.#appointments.list(query));
        this.total = res.total;
        this.items = res.data;
        // refresh calendar events when listing changes
        void this.loadAllAppointments();
      }
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  async loadAllAppointments(): Promise<void> {
    try {
      const res = await firstValueFrom(this.#appointments.list({ per_page: 500 } as any));
      this.allAppointments = res.data || [];
      this.generateCalendar();
    } catch (e) {
      // ignore calendar load errors silently
    }
  }

  // Calendar navigation
  prevMonth(): void {
    this.currentDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth() - 1, 1);
    this.generateCalendar();
  }

  nextMonth(): void {
    this.currentDate = new Date(this.currentDate.getFullYear(), this.currentDate.getMonth() + 1, 1);
    this.generateCalendar();
  }

  goToToday(): void {
    this.currentDate = new Date();
    this.generateCalendar();
  }

  get currentMonthYear(): string {
    const months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    return `${months[this.currentDate.getMonth()]} ${this.currentDate.getFullYear()}`;
  }

  generateCalendar(): void {
    const year = this.currentDate.getFullYear();
    const month = this.currentDate.getMonth();

    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDay = firstDay.getDay();
    const daysInMonth = lastDay.getDate();

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    this.calendarDays = [];

    // Days from previous month
    const prevMonth = new Date(year, month, 0);
    const prevMonthDays = prevMonth.getDate();
    for (let i = startDay - 1; i >= 0; i--) {
      const dayNum = prevMonthDays - i;
      const date = new Date(year, month - 1, dayNum);
      this.calendarDays.push(this.createCalendarDay(date, false, today));
    }

    // Days of current month
    for (let day = 1; day <= daysInMonth; day++) {
      const date = new Date(year, month, day);
      this.calendarDays.push(this.createCalendarDay(date, true, today));
    }

    // Days from next month to complete grid (6 rows = 42 cells)
    const remaining = 42 - this.calendarDays.length;
    for (let day = 1; day <= remaining; day++) {
      const date = new Date(year, month + 1, day);
      this.calendarDays.push(this.createCalendarDay(date, false, today));
    }
  }

  private createCalendarDay(date: Date, isCurrentMonth: boolean, today: Date): CalendarDay {
    const dateStr = this.formatDateStr(date);
    const appointments = this.getAppointmentsForDate(dateStr);

    return {
      date,
      dayNumber: date.getDate(),
      isCurrentMonth,
      isToday: date.getTime() === today.getTime(),
      appointments,
      dateStr
    };
  }

  private formatDateStr(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  private getAppointmentsForDate(dateStr: string): Appointment[] {
    return this.allAppointments.filter(a => a.appointment_date === dateStr);
  }

  getVisibleAppointments(day: CalendarDay): Appointment[] {
    // Show max 4 appointments per day cell, rest will show as "+N more"
    return day.appointments.slice(0, 4);
  }

  getHiddenCount(day: CalendarDay): number {
    return Math.max(0, day.appointments.length - 4);
  }

  getAppointmentDisplay(a: Appointment): string {
    if (this.isPatient) {
      // Patients see their own appointments with details, others just as "Ocupado"
      if (a.patient_id === this.patientIdForFilter) {
        return a.service || 'Mi cita';
      }
      return 'Ocupado';
    }
    // Staff sees patient name
    const name = a.patient_name || `Paciente #${a.patient_id}`;
    // Truncate long names
    return name.length > 18 ? name.substring(0, 16) + '…' : name;
  }

  isMyAppointment(a: Appointment): boolean {
    return this.isPatient && a.patient_id === this.patientIdForFilter;
  }

  onDayClick(day: CalendarDay): void {
    this.selectedDate = day.dateStr;
    this.selectedDay = day;
    void this.generateDaySlots(day.dateStr);
  }

  onAppointmentClick(a: Appointment, event: Event): void {
    event.stopPropagation();

    if (this.isPatient) {
      if (a.patient_id === this.patientIdForFilter) {
        // Show own appointment details
        alert(`Tu cita:\nFecha: ${a.appointment_date}\nHora: ${a.appointment_time}\nServicio: ${a.service}\nEstado: ${this.statusLabels[a.status as AppointmentStatus] || a.status}`);
      } else {
        alert('Este horario está ocupado.');
      }
      return;
    }

    // Staff: open reminder/edit modal
    this.openReminderModal(a);
  }

  openReminderModal(a: Appointment): void {
    this.reminderAppointment = a;
    // prefer notes if present, otherwise empty
    this.reminderDraft = a.notes ?? '';
    this.showReminderModal = true;
  }

  async generateDaySlots(dateStr: string): Promise<void> {
    this.daySlots = [];
    const [startH, startM] = this.businessStart.split(':').map(x => Number(x));
    const [endH, endM] = this.businessEnd.split(':').map(x => Number(x));
    const startMinutes = startH * 60 + startM;
    const endMinutes = endH * 60 + endM;

    // Build a map of time -> appointment for this date
    const occupiedMap = new Map<string, Appointment>();
    try {
      const res = await firstValueFrom(this.#appointments.list({ date: dateStr, per_page: 500 } as any));
      const list = (res?.data ?? []) as Appointment[];
      for (const a of list) {
        if (a.appointment_date === dateStr && a.appointment_time) {
          occupiedMap.set(a.appointment_time, a);
        }
      }
      this.canViewOccupiedForDate = true;
    } catch (err: any) {
      // If patient cannot view other patients' appointments, backend may return 403/permission error.
      // Fall back to using locally cached allAppointments
      this.canViewOccupiedForDate = false;
      for (const a of this.allAppointments) {
        if (a.appointment_date === dateStr && a.appointment_time) {
          occupiedMap.set(a.appointment_time, a);
        }
      }
    }

    for (let t = startMinutes; t < endMinutes; t += this.slotMinutes) {
      const hh = Math.floor(t / 60).toString().padStart(2, '0');
      const mm = (t % 60).toString().padStart(2, '0');
      const time = `${hh}:${mm}`;
      const appointment = occupiedMap.get(time);
      const available = !appointment;
      this.daySlots.push({ time, available, appointment });
    }
  }
  requestSlot(dateStr: string, time: string): void {
    if (!this.isPatient) return;
    this.requestDraft = { date: dateStr, time, notes: '' };
    this.showRequestModal = true;
  }

  async confirmRequest(): Promise<void> {
    if (!this.isPatient) return;
    const patientId = Number(this.patientIdForFilter) || 0;
    if (!patientId) {
      alert('No se pudo identificar al paciente.');
      return;
    }
    const payload: any = {
      patient_id: patientId,
      appointment_date: this.requestDraft.date,
      appointment_time: this.requestDraft.time,
      service: this.requestDraft.notes ? `Solicitud: ${this.requestDraft.notes}` : 'Solicitud (pendiente)'
    };

    // If we couldn't verify occupied slots, warn user that the slot could be taken and server will validate.
    if (!this.canViewOccupiedForDate) {
      const ok = window.confirm('No podemos verificar completamente si este horario está libre. Deseas enviar la solicitud de todas formas?');
      if (!ok) return;
    }

    try {
      this.isSaving = true;
      const created = await firstValueFrom(this.#appointments.create(payload));
      this.recentlyCreatedAppointmentId = Number(created?.id) || null;
      this.showRequestModal = false;
      alert('Solicitud enviada. El personal confirmará la cita.');
      await this.refresh();
      // Highlight the created event for a short time
      setTimeout(() => {
        this.recentlyCreatedAppointmentId = null;
        void this.loadAllAppointments();
      }, 8000);
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  cancelRequest(): void {
    this.showRequestModal = false;
    this.requestDraft = {};
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
      const res: any = await firstValueFrom(this.#appointments.generateReminder(a.id));
      const reminderText = (res && res.reminder) ? res.reminder : '';
      this.reminderAppointment = a;
      this.reminderDraft = reminderText;
      this.showReminderModal = true;
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

  async saveReminderAndSendEmail(): Promise<void> {
    if (!this.reminderAppointment) return;
    if (this.isSavingReminder) return;
    this.isSavingReminder = true;
    const id = this.reminderAppointment.id;
    try {
      // save as notes then send email so Mailer includes custom text
      await firstValueFrom(this.#appointments.update(id, { notes: this.reminderDraft } as any));
      await firstValueFrom(this.#appointments.sendEmail(id));
      this.showReminderModal = false;
      this.reminderAppointment = null;
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.isSavingReminder = false;
    }
  }

  async saveReminderAndSendWhatsapp(): Promise<void> {
    if (!this.reminderAppointment) return;
    if (this.isSavingReminder) return;
    this.isSavingReminder = true;
    const id = this.reminderAppointment.id;
    try {
      await firstValueFrom(this.#appointments.update(id, { notes: this.reminderDraft } as any));
      await firstValueFrom(this.#appointments.sendWhatsapp(id));
      this.showReminderModal = false;
      this.reminderAppointment = null;
    } catch (err: any) {
      this.actionError = this.#formatError(err);
    } finally {
      this.isSavingReminder = false;
    }
  }

  copyReminderToClipboard(): void {
    const text = this.reminderDraft || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).catch(() => {});
    } else {
      // fallback
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch {}
      document.body.removeChild(ta);
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
