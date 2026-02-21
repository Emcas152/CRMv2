import { ChangeDetectorRef, Component, ElementRef, OnDestroy, OnInit, ViewChild, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormArray, FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { DecimalPipe } from '@angular/common';
import { firstValueFrom } from 'rxjs';

import { AlertComponent, ButtonDirective, CardBodyComponent, CardComponent, CardHeaderComponent, ColComponent, FormSelectDirective, RowComponent, TableDirective } from '@coreui/angular';

import { BrowserQRCodeReader, IScannerControls } from '@zxing/browser';

import { CreateSaleRequest, Sale, SalesService, UpdateSaleRequest } from '../../../core/services/sales.service';
import { Id, PaymentMethod } from '../../../core/services/api.models';
import { ProductsService, Product } from '../../../core/services/products.service';
import { QrService } from '../../../core/services/qr.service';
import { PatientsService, Patient } from '../../../core/services/patients.service';

@Component({
  selector: 'app-crm-sales-page',
  templateUrl: './sales-page.component.html',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    CommonModule,
    RowComponent,
    ColComponent,
    CardComponent,
    CardHeaderComponent,
    CardBodyComponent,
    ButtonDirective,
    DecimalPipe
  ]
  ,
  styleUrls: ['./sales-page.component.scss']
})
export class SalesPageComponent implements OnInit, OnDestroy {
  readonly #sales = inject(SalesService);
  readonly #fb = inject(FormBuilder);
  readonly #qr = inject(QrService);
  readonly #products = inject(ProductsService);
  readonly #patients = inject(PatientsService);
  readonly #cdr = inject(ChangeDetectorRef);

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
  products: Product[] = [];
  productSearch = '';

  

  // Simple cart model for TPV
  cart: Array<{ product: Product; quantity: number }> = [];
  taxPercent = 0.0825;

  isSaving = false;
  editingId: Id | null = null;
  readonly paymentMethods: PaymentMethod[] = ['cash', 'card', 'transfer', 'other'];

  // QR / loyalty
  isCameraActive = false;
  cameraError: string | null = null;
  scannedQrCode: string | null = null;
  currentPatient: Patient | null = null;

  // Loyalty configuration (simple defaults)
  readonly pointValueQ = 1; // 1 punto = Q1 descuento

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
    void Promise.all([this.refresh(), this.loadProducts()]);
  }

  async loadProducts(): Promise<void> {
    try {
      const res = await firstValueFrom(this.#products.list({ per_page: 200 }));
      this.products = Array.isArray(res.data) ? res.data : [];
      this.#cdr.detectChanges();
    } catch (err: any) {
      // ignore for now or set an error
    }
  }

  ngOnDestroy(): void {
    this.stopCamera();
  }

  async #waitForVideoElement(timeoutMs = 1500): Promise<HTMLVideoElement | null> {
    const started = Date.now();
    while (Date.now() - started < timeoutMs) {
      const el = this.saleVideoEl?.nativeElement;
      if (el) return el;
      // give Angular time to render the *ngIf video element
      await new Promise(resolve => setTimeout(resolve, 25));
    }
    return null;
  }

  async startCamera(): Promise<void> {
    if (this.isCameraActive) return;
    this.cameraError = null;

    try {
      this.isCameraActive = true;
      this.#cdr.detectChanges();

      const video = await this.#waitForVideoElement();
      if (!video) {
        this.isCameraActive = false;
        this.cameraError = 'No se encontró el elemento de video (render).';
        return;
      }

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
      // Common cases: NotAllowedError (permission), NotFoundError (no camera), NotReadableError (in use)
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
        this.form.controls.loyalty_points.setValue(0 as any);
        this.currentPatient = patient as Patient;
        try {
          const p = await firstValueFrom(this.#patients.get(pid as Id));
          this.currentPatient = p as Patient;
        } catch {
          // keep best-effort data returned by QR scan
        }
      } else {
        this.currentPatient = null;
      }
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    }
  }

  async ensureQrCodeForCurrentPatient(): Promise<string | null> {
    const existing = (this.scannedQrCode ?? '').trim();
    if (existing) return existing;

    const pid = Number(this.form.controls.patient_id.value) || 0;
    if (pid <= 0) return null;

    try {
      const qr = await firstValueFrom(this.#patients.getQr(pid as Id));
      const code = String(qr?.qr_code ?? '').trim();
      this.scannedQrCode = code || null;
      return this.scannedQrCode;
    } catch {
      return null;
    }
  }

  onRedeemPointsChange(value: number): void {
    const v = Math.max(0, Math.floor(Number(value) || 0));
    this.form.controls.loyalty_points.setValue(v as any);
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

  

  addToCart(p: Product): void {
    const idx = this.cart.findIndex(c => c.product.id === p.id);
    if (idx >= 0) {
      this.cart[idx].quantity += 1;
    } else {
      this.cart.push({ product: p, quantity: 1 });
    }
  }

  onProductSearch(value: string): void {
    this.productSearch = value ?? '';
  }

  get filteredProducts(): Product[] {
    const term = this.productSearch.trim().toLowerCase();
    if (!term) return this.products;
    return this.products.filter(p => {
      const name = String(p.name ?? '').toLowerCase();
      const type = String(p.type ?? '').toLowerCase();
      return name.includes(term) || type.includes(term);
    });
  }

  removeCartItem(index: number): void {
    this.cart.splice(index, 1);
  }

  changeQty(index: number, qty: number): void {
    if (qty <= 0) { this.removeCartItem(index); return; }
    this.cart[index].quantity = qty;
  }

  get cartSubtotal(): number {
    return this.cart.reduce((s, it) => s + (Number(it.product.price || 0) * it.quantity), 0);
  }

  get cartTax(): number {
    return Math.round(this.cartSubtotal * this.taxPercent * 100) / 100;
  }

  get cartTotal(): number {
    return Math.round((this.cartSubtotal + this.cartTax) * 100) / 100;
  }

  get availablePoints(): number {
    return Number(this.currentPatient?.loyalty_points ?? 0) || 0;
  }

  get redeemPoints(): number {
    return Math.max(0, Number(this.form.controls.loyalty_points.value) || 0);
  }

  get redeemPointsClamped(): number {
    return Math.min(this.redeemPoints, this.availablePoints, this.maxRedeemablePoints);
  }

  get maxRedeemablePoints(): number {
    if (this.pointValueQ <= 0) return 0;
    const payableBeforePoints = Math.max(0, this.cartTotal - this.manualDiscountQ);
    return Math.max(0, Math.floor(payableBeforePoints / this.pointValueQ));
  }

  get pointsDiscountQ(): number {
    const disc = this.redeemPointsClamped * this.pointValueQ;
    return Math.max(0, Math.round(disc * 100) / 100);
  }

  get manualDiscountQ(): number {
    return Math.max(0, Number(this.form.controls.discount.value) || 0);
  }

  get totalDiscountQ(): number {
    return Math.round((this.manualDiscountQ + this.pointsDiscountQ) * 100) / 100;
  }

  get cartTotalAfterDiscount(): number {
    return Math.max(0, Math.round((this.cartTotal - this.totalDiscountQ) * 100) / 100);
  }

  get cartItemsCount(): number {
    return this.cart.reduce((s, it) => s + (Number(it.quantity) || 0), 0);
  }

  async completePurchase(): Promise<void> {
    if (!this.cart.length) return;
    this.isSaving = true;
    try {
      const pid = Number(this.form.controls.patient_id.value) as Id;
      if (!pid || pid <= 0) {
        this.submitError = 'Selecciona un cliente (QR) para continuar.';
        return;
      }

      // Redeem points first
      const qrCode = await this.ensureQrCodeForCurrentPatient();
      const toRedeem = this.redeemPointsClamped;
      if (toRedeem > 0) {
        if (!qrCode) {
          this.submitError = 'No se pudo obtener el QR del cliente para canjear puntos.';
          return;
        }
        const updated: any = await firstValueFrom(this.#qr.scan({ qr_code: qrCode, action: 'redeem', points: toRedeem }));
        this.currentPatient = { ...(this.currentPatient as any), ...(updated as any) } as any;
      }

      const items = this.cart.map(c => ({ product_id: c.product.id as Id, price: c.product.price, quantity: c.quantity }));
      const payload: CreateSaleRequest = {
        patient_id: pid,
        payment_method: this.form.controls.payment_method.value,
        discount: this.totalDiscountQ,
        notes: this.form.controls.notes.value || undefined,
        loyalty_points: toRedeem,
        items
      };
      const created = await firstValueFrom(this.#sales.create(payload));
      this.cart = [];

      // Award points based on final amount paid
      const toAward = Math.max(0, Math.floor(this.cartTotalAfterDiscount));
      if (toAward > 0) {
        const qrCode2 = await this.ensureQrCodeForCurrentPatient();
        if (qrCode2) {
          const updated2: any = await firstValueFrom(this.#qr.scan({ qr_code: qrCode2, action: 'add', points: toAward }));
          this.currentPatient = { ...(this.currentPatient as any), ...(updated2 as any) } as any;
        }
      }

      const redeemedMsg = toRedeem > 0 ? ` Canjeados: ${toRedeem}.` : '';
      const awardedMsg = toAward > 0 ? ` Puntos acumulados: ${toAward}.` : '';
      const balanceMsg = this.currentPatient ? ` Saldo: ${this.availablePoints}.` : '';
      this.actionInfo = `Compra completada.${redeemedMsg}${awardedMsg}${balanceMsg}`;
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
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
    this.currentPatient = null;
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
          items
        };
        const created = await firstValueFrom(this.#sales.create(payload));
        const awarded = Number((created as any)?.loyalty_points_awarded) || 0;
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

  trackByProduct(_idx: number, p: Product): number | string {
    return p.id;
  }
}
