import { JsonPipe } from '@angular/common';
import { ChangeDetectorRef, Component, ElementRef, OnDestroy, OnInit, ViewChild, inject } from '@angular/core';
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

import { BrowserQRCodeReader, IScannerControls } from '@zxing/browser';

import { QrService, QrScanAction } from '../../../core/services/qr.service';
import { AppointmentsService, Appointment } from '../../../core/services/appointments.service';

@Component({
  selector: 'app-crm-qr-page',
  templateUrl: './qr-page.component.html',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RowComponent,
    ColComponent,
    CardComponent,
    CardHeaderComponent,
    CardBodyComponent,
    TableDirective,
    FormDirective,
    FormLabelDirective,
    FormControlDirective,
    FormSelectDirective,
    ButtonDirective,
    AlertComponent,
    JsonPipe
  ]
})
export class QrPageComponent implements OnInit {
  readonly #fb = inject(FormBuilder);
  readonly #qr = inject(QrService);
  readonly #appointments = inject(AppointmentsService);
  readonly #cdr = inject(ChangeDetectorRef);

  @ViewChild('videoEl') videoEl?: ElementRef<HTMLVideoElement>;
  readonly #reader = new BrowserQRCodeReader();
  #scannerControls: IScannerControls | null = null;

  isSubmitting = false;
  error: string | null = null;
  result: any = null;

  isCameraActive = false;
  cameraError: string | null = null;

  patient: any = null;
  appointments: Appointment[] = [];
  isLoadingAppointments = false;

  readonly actions: QrScanAction[] = ['none', 'add', 'redeem'];

  readonly form = this.#fb.nonNullable.group({
    qr_code: ['', [Validators.required]],
    action: ['none' as QrScanAction],
    points: [0]
  });

  ngOnInit(): void {
    // no-op
  }

  ngOnDestroy(): void {
    this.stopCamera();
  }

  async startCamera(): Promise<void> {
    if (this.isCameraActive) return;
    this.cameraError = null;
    this.error = null;

    try {
      this.isCameraActive = true;

      // The <video> is behind an @if in the template; wait for it to render.
      this.#cdr.detectChanges();
      await new Promise<void>(resolve => setTimeout(resolve, 0));

      const video = this.videoEl?.nativeElement;
      if (!video) {
        this.isCameraActive = false;
        this.cameraError = 'No se encontró el elemento de video.';
        return;
      }

      this.#scannerControls = await this.#reader.decodeFromVideoDevice(undefined, video, (result, err) => {
        if (!result) return;

        const text = result.getText();
        if (!text) return;

        // Stop at first successful decode
        this.stopCamera();
        this.form.controls.qr_code.setValue(text);

        // Auto-process for common flows
        const action = this.form.controls.action.value;
        const points = Number(this.form.controls.points.value) || 0;
        if (action === 'none') {
          void this.onScan();
        } else if (points > 0) {
          void this.onScan();
        }
      });
    } catch (e: any) {
      this.isCameraActive = false;
      this.#scannerControls = null;
      const msg = e?.message;
      this.cameraError = typeof msg === 'string' && msg.trim().length ? msg : 'No se pudo iniciar la cámara.';
    }
  }

  stopCamera(): void {
    try {
      this.#scannerControls?.stop();
    } catch {
      // ignore
    }
    this.#scannerControls = null;
    this.isCameraActive = false;
  }

  async onScan(): Promise<void> {
    this.error = null;
    this.result = null;
    this.patient = null;
    this.appointments = [];
    this.form.markAllAsTouched();
    if (this.form.invalid || this.isSubmitting) return;

    this.isSubmitting = true;
    try {
      const raw = this.form.getRawValue();
      const payload: any = {
        qr_code: raw.qr_code,
        action: raw.action
      };
      if (raw.action !== 'none') payload.points = Number(raw.points) || 0;
      this.result = await firstValueFrom(this.#qr.scan(payload));
      this.patient = this.result;

      // When just scanning (none), also show appointments for that patient.
      if (raw.action === 'none' && this.patient?.id) {
        await this.loadAppointmentsForPatient(Number(this.patient.id));
      }
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isSubmitting = false;
    }
  }

  async loadAppointmentsForPatient(patientId: number): Promise<void> {
    if (!patientId || this.isLoadingAppointments) return;
    this.isLoadingAppointments = true;
    try {
      const today = new Date();
      const yyyy = today.getFullYear();
      const mm = String(today.getMonth() + 1).padStart(2, '0');
      const dd = String(today.getDate()).padStart(2, '0');
      const dateFrom = `${yyyy}-${mm}-${dd}`;

      const res = await firstValueFrom(this.#appointments.list({ patient_id: patientId, date_from: dateFrom, per_page: 50 } as any));
      this.appointments = Array.isArray(res?.data) ? res.data : [];
    } catch {
      this.appointments = [];
    } finally {
      this.isLoadingAppointments = false;
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo procesar el QR.';
  }
}
