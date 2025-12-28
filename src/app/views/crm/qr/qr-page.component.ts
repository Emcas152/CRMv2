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
  FormSelectDirective,
  RowComponent
} from '@coreui/angular';

import { QrService, QrScanAction } from '../../../core/services/qr.service';

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

  isSubmitting = false;
  error: string | null = null;
  result: any = null;

  readonly actions: QrScanAction[] = ['none', 'add', 'redeem'];

  readonly form = this.#fb.nonNullable.group({
    qr_code: ['', [Validators.required]],
    action: ['none' as QrScanAction],
    points: [0]
  });

  ngOnInit(): void {
    // no-op
  }

  async onScan(): Promise<void> {
    this.error = null;
    this.result = null;
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
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isSubmitting = false;
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo procesar el QR.';
  }
}
