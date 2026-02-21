import { Component, OnInit, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { CommonModule } from '@angular/common';

import {
  ButtonModule,
  ModalComponent,
  ModalHeaderComponent,
  ModalBodyComponent,
  ModalFooterComponent,
  ModalTitleDirective
} from '@coreui/angular';

import { ProfileService } from '../../../core/services/profile.service';
import { PatientsService } from '../../../core/services/patients.service';
import { UsersService } from '../../../core/services/users.service';
import * as QRCode from 'qrcode';
import { AppointmentsService, Appointment } from '../../../core/services/appointments.service';
import { AttendanceSheet, AttendanceSheetsService } from '../../../core/services/attendance-sheets.service';

@Component({
  selector: 'app-crm-profile-page',
  templateUrl: './profile-page.component.html',
  styleUrls: ['./profile-page.component.scss'],
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    ButtonModule,
    ModalComponent,
    ModalHeaderComponent,
    ModalBodyComponent,
    ModalFooterComponent,
    ModalTitleDirective
  ]
})
export class ProfilePageComponent implements OnInit {
  readonly #profile = inject(ProfileService);
  readonly #patients = inject(PatientsService);
  readonly #users = inject(UsersService);
  readonly #appointments = inject(AppointmentsService);
  readonly #attendanceSheets = inject(AttendanceSheetsService);
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
  photoPreviewUrl: string | null = null;

  isSavingLoyalty = false;

  attendanceSheetsLoading = false;
  attendanceSheetsError: string | null = null;
  attendanceSheets: AttendanceSheet[] = [];

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

  // Patient 'Ficha de datos' - clinical / contact fields
  readonly patientForm = this.#fb.nonNullable.group({
    phone: [''],
    birthday: [''],
    address: [''],
    nit: [''],
    weight: [''],
    height: [''],
    blood_type: [''],
    allergies: [''],
    medications: [''],
    chronic_conditions: [''],
    emergency_contact_name: [''],
    emergency_contact_phone: [''],
    occupation: [''],
    skin_type: [''],
    pregnant: [false],
    smoking: [false],
    notes: ['']
  });

  // Expanded Ficha fields
  readonly fichaExtras = this.#fb.nonNullable.group({
    marital_status: [''],
    spouse_name: [''],
    age: [''],
    place_of_birth: [''],
    nationality: [''],
    dpi: [''],
    profession: [''],
    workplace: [''],
    home_phone: [''],
    mobile_phone: [''],
    referred_by: [''],
    reason_for_consultation: [''],
    invoice_name: ['']
  });

  // Additional fields from Ficha de Asistencia
  // Nombre completo is `updateForm.name` / profile.user.name
  // Add specific fields required by the user form
  // (marital_status, spouse_name, age, place_of_birth, nationality, dpi,
  // profession, workplace, home_phone, mobile_phone, referred_by,
  // reason_for_consultation, invoice_name)
  // Kept optional and string-typed for the UI layer.


  // Calendar / slots (mini-appointments view for profile)
  currentDate = new Date();
  calendarDays: Array<{ date: Date; dayNumber: number; isCurrentMonth: boolean; isToday: boolean; appointments: Appointment[]; dateStr: string }> = [];
  weekDays = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
  allAppointments: Appointment[] = [];
  selectedDate: string | null = null;
  selectedDay: any = null;
  daySlots: Array<{ time: string; available: boolean; appointment?: Appointment }> = [];

  // business hours for staff profile calendar
  readonly businessStart = '09:00';
  readonly businessEnd = '17:00';
  readonly slotMinutes = 30;

  readonly loyaltyConfigForm = this.#fb.nonNullable.group({
    points_per_item: [0, [Validators.required, Validators.min(0), Validators.max(1000000)]]
  });

  readonly passwordForm = this.#fb.nonNullable.group({
    current_password: ['', [Validators.required]],
    new_password: ['', [Validators.required, Validators.minLength(6)]],
    confirm_password: ['', [Validators.required, Validators.minLength(6)]]
  });

  // New patient modal state + form
  newPatientVisible = false;
  isSavingNewPatient = false;
  readonly newPatientForm = this.#fb.nonNullable.group({
    name: ['', [Validators.required]],
    email: ['', [Validators.required, Validators.email]],
    marital_status: [''],
    spouse_name: [''],
    birthday: [''],
    age: [''],
    place_of_birth: [''],
    nationality: [''],
    dpi: [''],
    blood_type: [''],
    profession: [''],
    workplace: [''],
    address: [''],
    home_phone: [''],
    mobile_phone: [''],
    referred_by: [''],
    reason_for_consultation: [''],
    invoice_name: [''],
    nit: ['']
  });

  ngOnInit(): void {
    void this.refresh();
  }

  openNewPatientModal(): void {
    this.newPatientVisible = true;
    this.newPatientForm.reset({
      name: '', email: '', marital_status: '', spouse_name: '', birthday: '', age: '', place_of_birth: '', nationality: '', dpi: '', blood_type: '', profession: '', workplace: '', address: '', home_phone: '', mobile_phone: '', referred_by: '', reason_for_consultation: '', invoice_name: '', nit: ''
    });
  }

  closeNewPatientModal(): void {
    this.newPatientVisible = false;
  }

  private generateRandomPassword(length = 10): string {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    let out = '';
    for (let i = 0; i < length; i++) out += chars.charAt(Math.floor(Math.random() * chars.length));
    return out;
  }

  async createNewPatient(): Promise<void> {
    this.newPatientForm.markAllAsTouched();
    if (this.newPatientForm.invalid || this.isSavingNewPatient) return;
    this.isSavingNewPatient = true;
    this.error = null;
    try {
      const raw = this.newPatientForm.getRawValue();
      const password = this.generateRandomPassword(10);

      // Create user with role 'patient'
      const userPayload = {
        name: (raw.name as string).trim(),
        email: (raw.email as string).trim(),
        password,
        role: 'patient',
        phone: (raw.mobile_phone as string).trim() || undefined
      } as any;

      const createdUser = await firstValueFrom(this.#users.create(userPayload));

      // Create patient record (backend may link by email/user)
      const patientPayload: any = {
        name: raw.name?.trim() || '',
        email: raw.email?.trim() || '',
        phone: raw.mobile_phone?.trim() || raw.home_phone?.trim() || undefined,
        birthday: raw.birthday || undefined,
        address: raw.address || undefined,
        nit: raw.nit || undefined,
        marital_status: raw.marital_status || undefined,
        spouse_name: raw.spouse_name || undefined,
        age: raw.age || undefined,
        place_of_birth: raw.place_of_birth || undefined,
        nationality: raw.nationality || undefined,
        dpi: raw.dpi || undefined,
        blood_type: raw.blood_type || undefined,
        profession: raw.profession || undefined,
        workplace: raw.workplace || undefined,
        home_phone: raw.home_phone || undefined,
        mobile_phone: raw.mobile_phone || undefined,
        referred_by: raw.referred_by || undefined,
        reason_for_consultation: raw.reason_for_consultation || undefined,
        invoice_name: raw.invoice_name || undefined
      };

      await firstValueFrom(this.#patients.create(patientPayload));

      this.info = 'Paciente creado. Envíale la contraseña generada al usuario y solicita cambiarla en su primer ingreso.';
      this.closeNewPatientModal();
      await this.refresh();
      // Note: password is returned here only for admin to distribute if desired.
      // We don't display it automatically for security reasons.
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isSavingNewPatient = false;
    }
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
      // If the current user is a patient, ensure recent activity only contains
      // items relevant to that patient (avoid leaking other patients' data).
      const role = String((this.profile as any)?.user?.role ?? '').toLowerCase();
      if (role === 'patient' || role === 'paciente') {
        const pid = Number((this.profile as any)?.patient?.id ?? 0) || 0;
        if (pid > 0) {
          this.recent_activity = this.recent_activity.filter(a => {
            if (!a || typeof a !== 'object') return false;
            if (!('patient_id' in a)) return true;
            return Number(a.patient_id || 0) === pid;
          });
        } else {
          this.recent_activity = [];
        }
      }
      const user = this.profile?.user as any;
      const name = typeof user?.name === 'string' ? user.name : '';
      const email = typeof user?.email === 'string' ? user.email : '';
      const phone = typeof this.profile?.staff_member?.phone === 'string'
        ? this.profile.staff_member.phone
        : (typeof user?.phone === 'string' ? user.phone : '');
      this.updateForm.reset({ name, email });
      this.updateFormPhone.reset({ phone });

      // Prefill patient form if available
      const patient = this.profile?.patient as any;
      if (patient) {
        this.patientForm.reset({
          phone: patient.phone ?? '',
          birthday: patient.birthday ?? '',
          address: patient.address ?? '',
          nit: patient.nit ?? '',
          weight: patient.weight ?? '',
          height: patient.height ?? '',
          blood_type: patient.blood_type ?? '',
          allergies: patient.allergies ?? '',
          medications: patient.medications ?? '',
          chronic_conditions: patient.chronic_conditions ?? '',
          emergency_contact_name: patient.emergency_contact_name ?? '',
          emergency_contact_phone: patient.emergency_contact_phone ?? '',
          occupation: patient.occupation ?? '',
          skin_type: patient.skin_type ?? '',
          pregnant: !!patient.pregnant,
          smoking: !!patient.smoking,
          notes: patient.notes ?? ''
        });
        // Prefill fichaExtras
        this.fichaExtras.reset({
          marital_status: patient.marital_status ?? '',
          spouse_name: patient.spouse_name ?? '',
          age: patient.age ?? '',
          place_of_birth: patient.place_of_birth ?? '',
          nationality: patient.nationality ?? '',
          dpi: patient.dpi ?? '',
          profession: patient.profession ?? '',
          workplace: patient.workplace ?? '',
          home_phone: patient.home_phone ?? '',
          mobile_phone: patient.mobile_phone ?? '',
          referred_by: patient.referred_by ?? '',
          reason_for_consultation: patient.reason_for_consultation ?? '',
          invoice_name: patient.invoice_name ?? ''
        });
      }

      if (this.isAdminOrSuperAdmin) {
        this.loyaltyConfigForm.reset({
          points_per_item: Number((this.profile as any)?.loyalty?.points_per_item ?? 0) || 0
        });
      }

      await this.refreshQr();
      // load appointments for this staff member (if any)
      void this.loadStaffAppointments();

      if (this.hasPatient) {
        void this.loadAttendanceSheets();
      } else {
        this.attendanceSheets = [];
        this.attendanceSheetsError = null;
      }
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  async loadAttendanceSheets(): Promise<void> {
    if (this.attendanceSheetsLoading) return;
    this.attendanceSheetsLoading = true;
    this.attendanceSheetsError = null;
    try {
      const res = await firstValueFrom(this.#attendanceSheets.list());
      this.attendanceSheets = Array.isArray(res?.data) ? res.data : [];
    } catch (err: any) {
      this.attendanceSheets = [];
      this.attendanceSheetsError = this.#formatError(err);
    } finally {
      this.attendanceSheetsLoading = false;
    }
  }

  async openPatientDataSheetPdf(download = false): Promise<void> {
    this.error = null;
    try {
      const patientId = Number((this.profile as any)?.patient?.id ?? 0) || 0;
      if (!patientId) throw new Error('Paciente no disponible');
      const blob = await firstValueFrom(this.#patients.dataSheetPdf(patientId as any, download));
      this.#openBlob(blob, 'ficha_datos.pdf');
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  async openAttendanceSheetPdf(sheetId: any, download = false): Promise<void> {
    this.error = null;
    try {
      const blob = await firstValueFrom(this.#attendanceSheets.pdf(sheetId, download));
      this.#openBlob(blob, 'ficha_asistencia.pdf');
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  #openBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    // Open in a new tab for print/download UX.
    window.open(url, '_blank', 'noopener,noreferrer');
    setTimeout(() => URL.revokeObjectURL(url), 30_000);
  }

  async loadStaffAppointments(): Promise<void> {
    try {
      const staffId = Number((this.profile as any)?.staff_member?.id ?? 0) || 0;
      if (!staffId) return;
      const res = await firstValueFrom(this.#appointments.list({ staff_member_id: staffId, per_page: 500 }));
      this.allAppointments = Array.isArray(res.data) ? res.data : [];
      this.generateCalendar();
    } catch (e) {
      // ignore errors for calendar
    }
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

  private createCalendarDay(date: Date, isCurrentMonth: boolean, today: Date) {
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

    const prevMonth = new Date(year, month, 0);
    const prevMonthDays = prevMonth.getDate();
    for (let i = startDay - 1; i >= 0; i--) {
      const dayNum = prevMonthDays - i;
      const date = new Date(year, month - 1, dayNum);
      this.calendarDays.push(this.createCalendarDay(date, false, today));
    }

    for (let day = 1; day <= daysInMonth; day++) {
      const date = new Date(year, month, day);
      this.calendarDays.push(this.createCalendarDay(date, true, today));
    }

    const remaining = 42 - this.calendarDays.length;
    for (let day = 1; day <= remaining; day++) {
      const date = new Date(year, month + 1, day);
      this.calendarDays.push(this.createCalendarDay(date, false, today));
    }
  }

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

  onDayClick(day: any): void {
    this.selectedDate = day.dateStr;
    this.selectedDay = day;
    void this.generateDaySlots(day.dateStr);
  }

  private getAppointmentsForDateMap(dateStr: string) {
    return this.allAppointments.filter(a => a.appointment_date === dateStr);
  }

  async generateDaySlots(dateStr: string): Promise<void> {
    this.daySlots = [];
    const [startH, startM] = this.businessStart.split(':').map(x => Number(x));
    const [endH, endM] = this.businessEnd.split(':').map(x => Number(x));
    const startMinutes = startH * 60 + startM;
    const endMinutes = endH * 60 + endM;

    const occupiedMap = new Map<string, Appointment>();
    try {
      const list = this.getAppointmentsForDateMap(dateStr);
      for (const a of list) {
        if (a.appointment_time) occupiedMap.set(a.appointment_time, a);
      }
    } catch {
      // ignore
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

  get hasPatient(): boolean {
    return Number((this.profile as any)?.patient?.id ?? 0) > 0;
  }

  get isAdminOrSuperAdmin(): boolean {
    const role = String((this.profile as any)?.user?.role ?? '');
    return role === 'admin' || role === 'superadmin';
  }

  get qrTitle(): string {
    return this.hasPatient ? 'QR para acumular puntos' : 'Mi QR';
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
    if (this.selectedPhoto) {
      this.photoPreviewUrl = URL.createObjectURL(this.selectedPhoto);
    } else {
      this.photoPreviewUrl = null;
    }
  }

  async saveProfile(): Promise<void> {
    this.error = null;
    this.info = null;
    this.updateForm.markAllAsTouched();
    this.patientForm.markAllAsTouched();
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
      // If current user has a linked patient record, update its extended fields
      try {
        const patient = this.profile?.patient as any;
        if (patient && Number(patient.id) > 0) {
          const prow = this.patientForm.getRawValue();
          const fex = this.fichaExtras.getRawValue();
          const patientPayload: any = {
            phone: prow.phone?.trim() || null,
            birthday: prow.birthday?.trim() || null,
            address: prow.address?.trim() || null,
            nit: prow.nit?.trim() || null,
            weight: prow.weight?.trim() || null,
            height: prow.height?.trim() || null,
            blood_type: prow.blood_type?.trim() || null,
            allergies: prow.allergies?.trim() || null,
            medications: prow.medications?.trim() || null,
            chronic_conditions: prow.chronic_conditions?.trim() || null,
            emergency_contact_name: prow.emergency_contact_name?.trim() || null,
            emergency_contact_phone: prow.emergency_contact_phone?.trim() || null,
            occupation: prow.occupation?.trim() || null,
            skin_type: prow.skin_type?.trim() || null,
            pregnant: !!prow.pregnant,
            smoking: !!prow.smoking,
            notes: prow.notes?.trim() || null,
            // ficha extras
            marital_status: fex.marital_status?.trim() || null,
            spouse_name: fex.spouse_name?.trim() || null,
            age: fex.age?.toString()?.trim() || null,
            place_of_birth: fex.place_of_birth?.trim() || null,
            nationality: fex.nationality?.trim() || null,
            dpi: fex.dpi?.trim() || null,
            profession: fex.profession?.trim() || null,
            workplace: fex.workplace?.trim() || null,
            home_phone: fex.home_phone?.trim() || null,
            mobile_phone: fex.mobile_phone?.trim() || null,
            referred_by: fex.referred_by?.trim() || null,
            reason_for_consultation: fex.reason_for_consultation?.trim() || null,
            invoice_name: fex.invoice_name?.trim() || null
          };
          await firstValueFrom(this.#patients.update(Number(patient.id), patientPayload));
        }
      } catch {
        // ignore patient update errors here; surface main profile success
      }
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
      this.photoPreviewUrl = null;
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async saveLoyaltyConfig(): Promise<void> {
    if (!this.isAdminOrSuperAdmin) return;
    if (this.isSavingLoyalty) return;

    this.error = null;
    this.info = null;

    this.loyaltyConfigForm.markAllAsTouched();
    if (this.loyaltyConfigForm.invalid) return;

    this.isSavingLoyalty = true;
    try {
      const raw = this.loyaltyConfigForm.getRawValue();
      await firstValueFrom(
        this.#profile.update({
          loyalty_points_per_item: Number(raw.points_per_item) || 0
        } as any)
      );
      this.info = 'Configuración de puntos actualizada.';
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isSavingLoyalty = false;
    }
  }

  copyQrCode(): void {
    if (!this.qrCodeText) return;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        void navigator.clipboard.writeText(this.qrCodeText).then(() => {
          this.info = 'Código QR copiado.';
        }, () => {
          this.error = 'No se pudo copiar el código.';
        });
      } else {
        const ta = document.createElement('textarea');
        ta.value = this.qrCodeText;
        document.body.appendChild(ta);
        ta.select();
        try {
          document.execCommand('copy');
          this.info = 'Código QR copiado.';
        } catch {
          this.error = 'No se pudo copiar el código.';
        }
        document.body.removeChild(ta);
      }
    } catch (err: any) {
      this.error = 'No se pudo copiar el código.';
    }
  }

  downloadQr(): void {
    if (!this.qrDataUrl) return;
    const a = document.createElement('a');
    a.href = this.qrDataUrl;
    a.download = 'qr.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
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
    this.photoPreviewUrl = null;
    this.error = null;
    this.info = null;
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo cargar el perfil.';
  }
}
