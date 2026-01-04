import { Component, ElementRef, OnDestroy, OnInit, ViewChild, inject } from '@angular/core';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
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

import { CreateSaleRequest, Sale, SalesService, UpdateSaleRequest } from '../../../core/services/sales.service';
import { Id, PaymentMethod } from '../../../core/services/api.models';
import { QrService } from '../../../core/services/qr.service';
import { PatientsService } from '../../../core/services/patients.service';

@Component({
  selector: 'app-crm-sales-page',
  templateUrl: './sales-page.component.html',
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
export class SalesPageComponent implements OnInit, OnDestroy {
  readonly #sales = inject(SalesService);
  readonly #fb = inject(FormBuilder);
  readonly #qr = inject(QrService);
  readonly #patients = inject(PatientsService);

  @ViewChild('saleVideoEl') saleVideoEl?: ElementRef<HTMLVideoElement>;
  readonly #reader = new BrowserQRCodeReader();
  #scannerControls: IScannerControls | null = null;

  isLoading = false;
  error: string | null = null;
  submitError: string | null = null;
  actionInfo: string | null = null;
  actingId: Id | null = null;
  rowStatusById: Partial<Record<string, string>> = {};
  total = 0;
  sales: Sale[] = [];

  isSaving = false;
  editingId: Id | null = null;
  readonly paymentMethods: PaymentMethod[] = ['cash', 'card', 'transfer', 'other'];

  // QR / loyalty
  isCameraActive = false;
  cameraError: string | null = null;
  scannedQrCode: string | null = null;

  readonly filterForm = this.#fb.nonNullable.group({
    patient_id: [0],
    status: [''],
    payment_method: ['' as '' | PaymentMethod],
    date_from: [''],
    date_to: [''],
    page: [1, [Validators.required, Validators.min(1)]],
    per_page: [20, [Validators.required, Validators.min(1), Validators.max(200)]]
  });

  readonly form = this.#fb.nonNullable.group({
    patient_id: [0, [Validators.required, Validators.min(1)]],
    payment_method: ['cash' as PaymentMethod, [Validators.required]],
    discount: [0],
    notes: [''],
    status: [''],
    loyalty_points: [0],
    items: this.#fb.array([this.#createItemGroup()]) as any
  });

  get itemsArray(): FormArray<FormGroup> {
    return this.form.controls.items as any;
  }

  #createItemGroup(seed: { product_id?: Id; price?: number; quantity?: number } = {}): FormGroup {
    return this.#fb.nonNullable.group({
      product_id: [Number(seed.product_id ?? 0), [Validators.required, Validators.min(1)]],
      price: [Number(seed.price ?? 0), [Validators.required, Validators.min(0)]],
      quantity: [Number(seed.quantity ?? 1), [Validators.required, Validators.min(1)]]
    });
  }

  addItem(): void {
    this.itemsArray.push(this.#createItemGroup());
  }

  removeItem(index: number): void {
    if (this.itemsArray.length <= 1) return;
    this.itemsArray.removeAt(index);
  }

  ngOnInit(): void {
    void this.refresh();
  }

  ngOnDestroy(): void {
    this.stopCamera();
  }

  async startCamera(): Promise<void> {
    if (this.isCameraActive) return;
    this.cameraError = null;
    const video = this.saleVideoEl?.nativeElement;
    if (!video) {
      this.cameraError = 'No se encontró el elemento de video.';
      return;
    }

    try {
      this.isCameraActive = true;
      this.#scannerControls = await this.#reader.decodeFromVideoDevice(undefined, video, (result) => {
        if (!result) return;
        const text = result.getText();
        if (!text) return;

        this.stopCamera();
        this.scannedQrCode = text;
        void this.resolvePatientFromQr(text);
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

  async resolvePatientFromQr(qrCode: string): Promise<void> {
    this.submitError = null;
    try {
      const patient: any = await firstValueFrom(this.#qr.scan({ qr_code: qrCode, action: 'none' }));
      const pid = Number(patient?.id ?? 0) || 0;
      if (pid > 0) {
        this.form.controls.patient_id.setValue(pid as any);
      }
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    }
  }

  async refresh(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;
    this.actionInfo = null;
    try {
      const raw = this.filterForm.getRawValue();
      const query: any = {
        page: Number(raw.page) || 1,
        per_page: Number(raw.per_page) || 20
      };
      if (Number(raw.patient_id) > 0) query.patient_id = Number(raw.patient_id) as Id;
      if (raw.status.trim().length) query.status = raw.status.trim();
      if (raw.payment_method) query.payment_method = raw.payment_method;
      if (raw.date_from.trim().length) query.date_from = raw.date_from.trim();
      if (raw.date_to.trim().length) query.date_to = raw.date_to.trim();

      const res = await firstValueFrom(this.#sales.list(query));
      this.total = res.total;
      this.sales = res.data;
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
    this.cameraError = null;
    this.scannedQrCode = null;
    this.form.reset({
      patient_id: 0,
      payment_method: 'cash',
      discount: 0,
      notes: '',
      status: '',
      loyalty_points: 0
    });

    this.itemsArray.clear();
    this.itemsArray.push(this.#createItemGroup());
  }

  startEdit(s: Sale): void {
    this.editingId = s.id;
    this.submitError = null;
    this.form.reset({
      patient_id: s.patient_id ?? 0,
      payment_method: s.payment_method ?? 'cash',
      discount: s.discount ?? 0,
      notes: s.notes ?? '',
      status: s.status ?? ''
    });

    this.itemsArray.clear();
    // Keep items visible for reference, but we do not send them on update.
    const items = Array.isArray(s.items) && s.items.length ? s.items : [{ product_id: 0 as any, price: 0, quantity: 1 }];
    for (const it of items) this.itemsArray.push(this.#createItemGroup(it));
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

    try {
      if (this.editingId === null) {
        const items = this.itemsArray.controls.map(c => {
          const v = (c as any).getRawValue();
          return {
            product_id: Number(v.product_id) as Id,
            price: Number(v.price),
            quantity: Number(v.quantity)
          };
        });

        const payload: CreateSaleRequest = {
          patient_id: Number(raw.patient_id) as Id,
          payment_method: raw.payment_method,
          discount: Number(raw.discount) || 0,
          notes: raw.notes.trim() || undefined,
          loyalty_points: Number((raw as any).loyalty_points) || 0,
          items
        };
        const created = await firstValueFrom(this.#sales.create(payload));
        const awarded = Number((created as any)?.loyalty_points_awarded) || Number((raw as any).loyalty_points) || 0;
        if (awarded > 0) this.actionInfo = `Puntos acumulados: ${awarded}`;

        this.startCreate();
      } else {
        const payload: UpdateSaleRequest = {
          payment_method: raw.payment_method,
          notes: raw.notes.trim() || undefined,
          status: raw.status.trim() || undefined
        };
        await firstValueFrom(this.#sales.update(this.editingId, payload));
      }
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async delete(s: Sale): Promise<void> {
    this.error = null;
    this.actionInfo = null;
    if (this.actingId !== null) return;
    if (!window.confirm(`Eliminar venta #${s.id}?`)) return;

    this.actingId = s.id;
    this.rowStatusById[String(s.id)] = 'Deleting…';
    try {
      await firstValueFrom(this.#sales.delete(s.id));
      await this.refresh();
      if (this.editingId === s.id) this.startCreate();
      this.actionInfo = 'Venta eliminada.';
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(s.id)];
      }, 2500);
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar las ventas.';
  }
}
