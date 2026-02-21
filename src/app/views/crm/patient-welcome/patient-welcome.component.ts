import { Component, inject } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { AuthService, AuthUser } from '../../../core/auth/auth.service';
import { PatientsService } from '../../../core/services/patients.service';
import { AppointmentsService, Appointment } from '../../../core/services/appointments.service';
import { DocumentsService, DocumentItem } from '../../../core/services/documents.service';
import * as QRCode from 'qrcode';

@Component({
  selector: 'app-patient-welcome',
  standalone: true,
  imports: [CommonModule, RouterLink],
  templateUrl: './patient-welcome.component.html',
  styleUrls: ['./patient-welcome.component.scss']
})
export default class PatientWelcomeComponent {
  readonly #auth = inject(AuthService);
  readonly #patients = inject(PatientsService);
  readonly #appointments = inject(AppointmentsService);
  readonly #docs = inject(DocumentsService);
  readonly #router = inject(Router);
  me: AuthUser | null = null;
  patient: any = null;
  loyaltyPoints = 0;
  qrDataUrl: string | null = null;
  nextAppointment: Appointment | null = null;
  unsignedDocuments: DocumentItem[] = [];
  beforePhotoUrl: string | null = null;
  afterPhotoUrl: string | null = null;
  isLoading = false;

  constructor() {
    void this.loadMe();
  }

  async loadMe(): Promise<void> {
    try {
      const res = await this.#auth.me().toPromise?.();
      this.me = (res as any)?.user ?? null;
      const pid = Number((res as any)?.patient?.id ?? 0) || 0;
      if (pid > 0) {
        this.isLoading = true;
        try {
          const p = await this.#patients.get(pid).toPromise?.();
          this.patient = p;
          this.loyaltyPoints = Number(p?.loyalty_points ?? 0) || 0;
          // QR
          try {
            const qr = await this.#patients.getQr(pid).toPromise?.();
            const qrText = qr?.qr_code ?? null;
            if (qrText) this.qrDataUrl = await QRCode.toDataURL(qrText, { margin: 2, width: 120, errorCorrectionLevel: 'M' });
          } catch {
            // ignore qr errors
          }

          // Next appointment
          try {
            const today = new Date();
            const dateFrom = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
            const apptRes = await this.#appointments.list({ patient_id: pid, date_from: dateFrom, per_page: 1, sort_by: 'appointment_date', sort_dir: 'asc' }).toPromise?.();
            this.nextAppointment = (apptRes?.data && apptRes.data.length) ? apptRes.data[0] : null;
          } catch {}

          // Unsigned documents (e.g., consent forms)
          try {
            const docsRes = await this.#docs.list(pid, { page: 1, per_page: 20 }).toPromise?.();
            this.unsignedDocuments = (docsRes?.data ?? []).filter((d: any) => !d.signed);
          } catch {}
        } finally {
          this.isLoading = false;
        }
      }
    } catch {
      this.me = null;
    }
  }

  bookAppointment(): void {
    this.#router.navigateByUrl('/crm/appointments');
  }

  goMessages(): void {
    this.#router.navigateByUrl('/crm/conversations');
  }

  async uploadPhoto(type: 'before' | 'after', ev: Event): Promise<void> {
    const input = ev.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file || !this.patient) return;
    try {
      this.isLoading = true;
      await this.#patients.uploadPhotoMultipart(this.patient.id, file, type).toPromise?.();
      if (type === 'before') this.beforePhotoUrl = URL.createObjectURL(file);
      else this.afterPhotoUrl = URL.createObjectURL(file);
    } catch (e) {
      // ignore
    } finally {
      this.isLoading = false;
    }
  }

  openSign(documentId: number): void {
    // Navigate to documents page and include query param to indicate which document to sign
    void this.#router.navigate(['/crm/documents'], { queryParams: { sign: documentId } });
  }
}
