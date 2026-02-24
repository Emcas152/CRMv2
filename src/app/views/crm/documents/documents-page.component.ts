import { Component, ElementRef, OnInit, ViewChild, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { firstValueFrom } from 'rxjs';

import { GlobalWorkerOptions, getDocument } from 'pdfjs-dist';
import { PDFDocument } from 'pdf-lib';

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

import { DocumentsService, DocumentItem } from '../../../core/services/documents.service';
import { AuthService } from '../../../core/auth/auth.service';
import { Patient, PatientsService } from '../../../core/services/patients.service';
import { Id } from '../../../core/services/api.models';

@Component({
  selector: 'app-crm-documents-page',
  templateUrl: './documents-page.component.html',
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
    FormDirective,
    FormLabelDirective,
    FormControlDirective,
    ButtonDirective,
    AlertComponent
  ]
})
export class DocumentsPageComponent implements OnInit {
  readonly #fb = inject(FormBuilder);
  readonly #docs = inject(DocumentsService);
  readonly #auth = inject(AuthService);
  readonly #patients = inject(PatientsService);

  isLoading = false;
  isUploading = false;
  error: string | null = null;
  actionInfo: string | null = null;
  total = 0;
  items: DocumentItem[] = [];
  selectedFile: File | null = null;
  signFile: File | null = null;

  showUpload = false;
  showSign = false;
  signTarget: DocumentItem | null = null;

  @ViewChild('signCanvas') signCanvasRef?: ElementRef<HTMLCanvasElement>;
  @ViewChild('signaturePadCanvas') signaturePadCanvasRef?: ElementRef<HTMLCanvasElement>;

  signLoading = false;
  signApplying = false;
  signDocMime: string | null = null;
  signDocBytes: Uint8Array | null = null;
  signatureSelectedFile: File | null = null;
  signatureImg: HTMLImageElement | null = null;
  signatureScale = 1;

  signaturePadHasInk = false;
  #sigPadDrawing = false;
  #sigPadLastX = 0;
  #sigPadLastY = 0;
  #sigPadPointerId: number | null = null;

  #baseCanvas: HTMLCanvasElement | null = null;
  #isDraggingSig = false;
  #dragOffsetX = 0;
  #dragOffsetY = 0;
  #sigX = 0;
  #sigY = 0;
  #sigW = 220;
  #sigH = 90;
  #sigBaseW = 220;
  #sigBaseH = 90;

  actingId: Id | null = null;
  rowStatusById: Partial<Record<string, string>> = {};
  replaceFileById: Partial<Record<string, File>> = {};
  titleById: Partial<Record<string, string>> = {};

  // Auth/user context
  me: any | null = null;
  isPatient = false;
  myPatientId = 0;

  // Staff-only patient selection
  selectedPatient: Patient | null = null;
  patientResults: Patient[] = [];
  patientSearchLoading = false;

  readonly form = this.#fb.nonNullable.group({
    patientId: [0, [Validators.required, Validators.min(1)]],
    patientSearch: [''],
    title: [''],
    page: [1, [Validators.required, Validators.min(1)]],
    per_page: [20, [Validators.required, Validators.min(1), Validators.max(200)]]
  });

  readonly signForm = this.#fb.nonNullable.group({
    documentId: [0, [Validators.required, Validators.min(1)]],
    method: [''],
    meta: ['']
  });

  ngOnInit(): void {
    // Configure PDF.js worker (resolved by bundler)
    // Configure PDF.js worker (resolved by bundler)
    GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString();

    // If current user is a patient, pre-fill patientId and simplify the UI
    void (async () => {
      try {
        const res: any = await this.#auth.me().toPromise?.();
        this.me = res?.user ?? null;
        const role = String(this.me?.role ?? '').toLowerCase();
        this.isPatient = role === 'patient' || role === 'paciente';
        this.myPatientId = Number(res?.patient?.id ?? 0) || 0;
        if (this.isPatient && this.myPatientId > 0) {
          this.form.controls.patientId.setValue(this.myPatientId as any);
          // auto-load patient documents
          await this.refresh();
        }
      } catch {
        // ignore
      }
    })();
  }

  get currentPatientId(): number {
    return Number(this.form.controls.patientId.value) || this.myPatientId || 0;
  }

  get hasPatient(): boolean {
    return this.currentPatientId > 0;
  }

  get page(): number {
    return Number(this.form.controls.page.value) || 1;
  }

  get perPage(): number {
    return Number(this.form.controls.per_page.value) || 20;
  }

  get pageCount(): number {
    const per = this.perPage;
    if (!per) return 1;
    return Math.max(1, Math.ceil((this.total || 0) / per));
  }

  toggleUpload(): void {
    if (!this.hasPatient) {
      this.error = 'Selecciona un paciente para continuar.';
      return;
    }
    this.showUpload = !this.showUpload;
  }

  async searchPatients(): Promise<void> {
    if (this.isPatient) return;
    this.error = null;
    this.actionInfo = null;
    const q = (this.form.controls.patientSearch.value ?? '').trim();
    if (q.length < 2) {
      this.patientResults = [];
      return;
    }

    this.patientSearchLoading = true;
    try {
      const res = await firstValueFrom(this.#patients.list({ search: q, page: 1, per_page: 10 }));
      this.patientResults = res.data ?? [];
    } catch (err: any) {
      this.error = this.#formatError(err);
      this.patientResults = [];
    } finally {
      this.patientSearchLoading = false;
    }
  }

  async selectPatient(p: Patient): Promise<void> {
    this.selectedPatient = p;
    this.patientResults = [];
    this.form.controls.patientId.setValue(Number(p.id) as any);
    this.form.controls.page.setValue(1);
    await this.refresh();
  }

  clearSelectedPatient(): void {
    this.selectedPatient = null;
    this.patientResults = [];
    this.form.controls.patientId.setValue(0 as any);
    this.form.controls.page.setValue(1);
    this.total = 0;
    this.items = [];
  }

  openSign(d: DocumentItem): void {
    this.showSign = true;
    this.signTarget = d;
    this.signForm.controls.documentId.setValue(Number(d.id));
    this.signatureSelectedFile = null;
    this.signatureImg = null;
    this.signatureScale = 1;
    this.signaturePadHasInk = false;
    this.signDocBytes = null;
    this.signDocMime = null;
    this.#baseCanvas = null;
    this.signLoading = true;
    this.error = null;

    // Render async (after panel shows and canvas exists)
    void this.#loadAndRenderSignDoc(d);
  }

  cancelSign(): void {
    this.showSign = false;
    this.signTarget = null;
    this.signFile = null;
    this.signForm.reset({ documentId: 0, method: '', meta: '' });
    this.signatureSelectedFile = null;
    this.signatureImg = null;
    this.signaturePadHasInk = false;
    this.signDocBytes = null;
    this.signDocMime = null;
    this.#baseCanvas = null;
    this.signLoading = false;
    this.signApplying = false;
  }

  onSignatureFileChange(evt: Event): void {
    const input = evt.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    input.value = '';
    this.signatureSelectedFile = file;
    void this.#loadSignatureImage(file);
  }

  onSignatureScaleChange(evt: Event): void {
    const input = evt.target as HTMLInputElement;
    const v = Number(input.value);
    if (!isFinite(v) || v <= 0) return;
    this.signatureScale = v;
    this.#applySignatureScale();
    this.#redrawSignCanvas();
  }

  resetSignaturePosition(): void {
    const canvas = this.signCanvasRef?.nativeElement;
    if (!canvas) return;
    this.#sigX = Math.max(12, canvas.width - this.#sigW - 12);
    this.#sigY = Math.max(12, canvas.height - this.#sigH - 12);
    this.#redrawSignCanvas();
  }

  onSignPointerDown(evt: PointerEvent): void {
    const canvas = this.signCanvasRef?.nativeElement;
    if (!canvas || !this.signatureImg) return;
    const p = this.#canvasPoint(canvas, evt);
    if (!this.#hitSig(p.x, p.y)) return;
    this.#isDraggingSig = true;
    this.#dragOffsetX = p.x - this.#sigX;
    this.#dragOffsetY = p.y - this.#sigY;
    canvas.setPointerCapture(evt.pointerId);
  }

  onSignPointerMove(evt: PointerEvent): void {
    if (!this.#isDraggingSig) return;
    const canvas = this.signCanvasRef?.nativeElement;
    if (!canvas) return;
    const p = this.#canvasPoint(canvas, evt);
    this.#sigX = p.x - this.#dragOffsetX;
    this.#sigY = p.y - this.#dragOffsetY;
    this.#clampSig(canvas);
    this.#redrawSignCanvas();
  }

  onSignPointerUp(evt: PointerEvent): void {
    const canvas = this.signCanvasRef?.nativeElement;
    if (!canvas) return;
    this.#isDraggingSig = false;
    try {
      canvas.releasePointerCapture(evt.pointerId);
    } catch {
      // ignore
    }
  }

  async applyCanvasSignature(): Promise<void> {
    this.error = null;
    this.actionInfo = null;
    if (this.signApplying) return;
    if (!this.signTarget) return;
    if (!this.signDocBytes || !this.signDocMime) {
      this.error = 'No se pudo cargar el documento para firmar.';
      return;
    }
    if (!this.signatureImg) {
      this.error = 'Selecciona o dibuja una firma para colocarla.';
      return;
    }
    const canvas = this.signCanvasRef?.nativeElement;
    if (!canvas || !this.#baseCanvas) {
      this.error = 'No se pudo preparar el canvas de firma.';
      return;
    }

    this.signApplying = true;
    try {
      const raw = this.signForm.getRawValue();
      const method = raw.method.trim() || undefined;
      const meta = raw.meta.trim() || undefined;

      const signedFile = await this.#buildSignedFile(this.signTarget, canvas);
      const newDoc = await firstValueFrom(this.#docs.upload(signedFile, this.signTarget.patient_id, this.signTarget.title ?? undefined));

      const positionMeta = `canvas_sig={x:${Math.round(this.#sigX)},y:${Math.round(this.#sigY)},w:${Math.round(this.#sigW)},h:${Math.round(this.#sigH)},cw:${canvas.width},ch:${canvas.height}}`;
      await firstValueFrom(
        this.#docs.sign(newDoc.id, {
          method,
          meta: [meta, positionMeta].filter(Boolean).join(' | ')
        })
      );

      await firstValueFrom(this.#docs.delete(this.signTarget.id));
      this.actionInfo = 'Documento firmado y reemplazado.';
      this.cancelSign();
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.signApplying = false;
    }
  }

  onSignaturePadPointerDown(evt: PointerEvent): void {
    const canvas = this.signaturePadCanvasRef?.nativeElement;
    if (!canvas) return;
    // Only primary button
    if (evt.button !== 0) return;

    this.#ensureSignaturePadSized();
    const ctx = canvas.getContext('2d');
    if (ctx) this.#configureSignaturePadCtx(ctx);

    const p = this.#canvasPoint(canvas, evt);
    this.#sigPadDrawing = true;
    this.#sigPadPointerId = evt.pointerId;
    this.#sigPadLastX = p.x;
    this.#sigPadLastY = p.y;
    try {
      canvas.setPointerCapture(evt.pointerId);
    } catch {
      // ignore
    }
  }

  onSignaturePadPointerMove(evt: PointerEvent): void {
    if (!this.#sigPadDrawing) return;
    if (this.#sigPadPointerId !== null && evt.pointerId !== this.#sigPadPointerId) return;
    const canvas = this.signaturePadCanvasRef?.nativeElement;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const p = this.#canvasPoint(canvas, evt);

    ctx.beginPath();
    ctx.moveTo(this.#sigPadLastX, this.#sigPadLastY);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();

    this.#sigPadLastX = p.x;
    this.#sigPadLastY = p.y;
    this.signaturePadHasInk = true;
  }

  onSignaturePadPointerUp(evt: PointerEvent): void {
    const canvas = this.signaturePadCanvasRef?.nativeElement;
    this.#sigPadDrawing = false;
    this.#sigPadPointerId = null;
    if (!canvas) return;
    try {
      canvas.releasePointerCapture(evt.pointerId);
    } catch {
      // ignore
    }
  }

  clearSignaturePad(): void {
    const canvas = this.signaturePadCanvasRef?.nativeElement;
    if (!canvas) {
      this.signaturePadHasInk = false;
      return;
    }
    this.#ensureSignaturePadSized();
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      this.signaturePadHasInk = false;
      return;
    }
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    this.#configureSignaturePadCtx(ctx);
    this.signaturePadHasInk = false;
  }

  async useSignaturePad(): Promise<void> {
    this.error = null;
    const canvas = this.signaturePadCanvasRef?.nativeElement;
    if (!canvas) return;
    if (!this.signaturePadHasInk) {
      this.error = 'Dibuja una firma antes de usarla.';
      return;
    }
    this.#ensureSignaturePadSized();

    const blob = await new Promise<Blob>((resolve, reject) => {
      canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('No se pudo exportar la firma'))), 'image/png');
    });

    const name = `firma-${this.signTarget?.id ?? 'documento'}.png`;
    const file = new File([blob], name, { type: 'image/png' });
    this.signatureSelectedFile = file;
    await this.#loadSignatureImage(file);
  }

  async prevPage(): Promise<void> {
    if (this.page <= 1) return;
    this.form.controls.page.setValue(this.page - 1);
    await this.refresh();
  }

  async nextPage(): Promise<void> {
    if (this.page >= this.pageCount) return;
    this.form.controls.page.setValue(this.page + 1);
    await this.refresh();
  }

  onFileChange(evt: Event): void {
    const input = evt.target as HTMLInputElement;
    this.selectedFile = input.files?.[0] ?? null;
  }

  onSignFileChange(evt: Event): void {
    const input = evt.target as HTMLInputElement;
    this.signFile = input.files?.[0] ?? null;
  }

  async refresh(): Promise<void> {
    this.error = null;
    this.actionInfo = null;
    this.form.markAllAsTouched();
    // allow patients (pre-filled) to proceed even if control validation differs
    if (this.isLoading) return;

    if (!this.hasPatient) {
      this.error = 'Selecciona un paciente para listar documentos.';
      return;
    }

    this.isLoading = true;
    try {
      const patientId = this.currentPatientId as Id;
      const page = this.page;
      const per_page = this.perPage;
      const res = await firstValueFrom(this.#docs.list(patientId, { page, per_page }));
      this.total = res.total;
      this.items = res.data;

      // Keep per-row title inputs in sync (non-destructive)
      for (const d of this.items) {
        const key = String(d.id);
        if (this.titleById[key] === undefined) this.titleById[key] = d.title ?? '';
      }
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  async upload(): Promise<void> {
    this.error = null;
    this.actionInfo = null;
    this.form.markAllAsTouched();
    if (this.isUploading) return;
    if (!this.selectedFile) {
      this.error = 'Selecciona un archivo.';
      return;
    }

    this.isUploading = true;
    try {
      const patientId = this.currentPatientId;
      if (!patientId) {
        this.error = 'Selecciona un paciente para subir documentos.';
        return;
      }
      const title = this.form.controls.title.value?.trim() || undefined;
      await firstValueFrom(this.#docs.upload(this.selectedFile, patientId as Id, title));
      this.selectedFile = null;
      this.showUpload = false;
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isUploading = false;
    }
  }

  setTitle(d: DocumentItem, value: string): void {
    this.titleById[String(d.id)] = value;
  }

  onReplaceFileChange(d: DocumentItem, evt: Event): void {
    const input = evt.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    this.replaceFileById[String(d.id)] = file;
  }

  async saveTitle(d: DocumentItem): Promise<void> {
    this.error = null;
    this.actionInfo = null;
    if (this.actingId !== null) return;

    const title = (this.titleById[String(d.id)] ?? '').trim();
    this.actingId = d.id;
    this.rowStatusById[String(d.id)] = 'Saving title…';
    try {
      await firstValueFrom(this.#docs.update(d.id, { title: title.length ? title : null }));
      this.rowStatusById[String(d.id)] = 'Title saved.';
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
      this.rowStatusById[String(d.id)] = 'Failed.';
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(d.id)];
      }, 2500);
    }
  }

  async replace(d: DocumentItem): Promise<void> {
    this.error = null;
    this.actionInfo = null;
    if (this.actingId !== null) return;
    const file = this.replaceFileById[String(d.id)];
    if (!file) {
      this.error = 'Selecciona un archivo para reemplazar.';
      return;
    }

    if (!window.confirm(`Reemplazar documento #${d.id}? Se subirá un nuevo documento y se eliminará el anterior.`)) return;

    this.actingId = d.id;
    this.rowStatusById[String(d.id)] = 'Replacing…';
    try {
      const title = (this.titleById[String(d.id)] ?? d.title ?? '').trim() || undefined;
      await firstValueFrom(this.#docs.upload(file, d.patient_id, title));
      await firstValueFrom(this.#docs.delete(d.id));
      delete this.replaceFileById[String(d.id)];
      this.actionInfo = 'Documento reemplazado.';
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.actingId = null;
      setTimeout(() => {
        delete this.rowStatusById[String(d.id)];
      }, 2500);
    }
  }

  async delete(id: Id): Promise<void> {
    this.error = null;
    this.actionInfo = null;
    if (!window.confirm(`Eliminar documento #${id}?`)) return;
    try {
      await firstValueFrom(this.#docs.delete(id));
      this.actionInfo = 'Documento eliminado.';
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  async sign(): Promise<void> {
    this.error = null;
    this.actionInfo = null;
    this.signForm.markAllAsTouched();
    if (this.signForm.invalid || this.isUploading) return;

    this.isUploading = true;
    try {
      const raw = this.signForm.getRawValue();
      const documentId = Number(raw.documentId) as Id;
      const method = raw.method.trim() || undefined;
      const meta = raw.meta.trim() || undefined;

      await firstValueFrom(
        this.#docs.sign(documentId, {
          signature: this.signFile ?? undefined,
          method,
          meta
        })
      );
      this.actionInfo = 'Documento firmado.';
      this.cancelSign();
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isUploading = false;
    }
  }

  async #loadAndRenderSignDoc(d: DocumentItem): Promise<void> {
    try {
      const blob = await firstValueFrom(this.#docs.file(d.id));
      const bytes = new Uint8Array(await blob.arrayBuffer());
      const mime = blob.type || d.mime || d.mime_type || '';
      this.signDocBytes = bytes;
      this.signDocMime = mime;

      await this.#waitForCanvas();
      await this.#waitForSignaturePadCanvas();
      this.#initSignaturePad();
      await this.#renderDocument(bytes, mime);
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.signLoading = false;
    }
  }

  async #waitForCanvas(): Promise<void> {
    for (let i = 0; i < 30; i++) {
      if (this.signCanvasRef?.nativeElement) return;
      await new Promise((r) => requestAnimationFrame(() => r(null)));
    }
    throw new Error('Canvas no disponible');
  }

  async #waitForSignaturePadCanvas(): Promise<void> {
    for (let i = 0; i < 30; i++) {
      if (this.signaturePadCanvasRef?.nativeElement) return;
      await new Promise((r) => requestAnimationFrame(() => r(null)));
    }
    // If it doesn't exist, it's not fatal (template could have been customized)
  }

  #initSignaturePad(): void {
    const canvas = this.signaturePadCanvasRef?.nativeElement;
    if (!canvas) return;
    this.#ensureSignaturePadSized();
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    this.#configureSignaturePadCtx(ctx);
    if (!this.signaturePadHasInk) {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
  }

  #ensureSignaturePadSized(): void {
    const canvas = this.signaturePadCanvasRef?.nativeElement;
    if (!canvas) return;
    // Match internal bitmap to displayed size for crisp lines
    const rect = canvas.getBoundingClientRect();
    const dpr = Math.max(1, Math.min(3, window.devicePixelRatio || 1));
    const nextW = Math.max(1, Math.floor(rect.width * dpr));
    const nextH = Math.max(1, Math.floor(rect.height * dpr));
    if (canvas.width !== nextW || canvas.height !== nextH) {
      canvas.width = nextW;
      canvas.height = nextH;
    }
  }

  #configureSignaturePadCtx(ctx: CanvasRenderingContext2D): void {
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = 2.2;
    const root = getComputedStyle(document.documentElement);
    const color = (root.getPropertyValue('--cui-body-color') || '').trim();
    ctx.strokeStyle = color || getComputedStyle(document.body).color;
  }

  async #renderDocument(bytes: Uint8Array, mime: string): Promise<void> {
    const canvas = this.signCanvasRef?.nativeElement;
    if (!canvas) throw new Error('Canvas no disponible');

    const isPdf = /pdf/i.test(mime) || (mime === '' && this.signTarget?.original_filename?.toLowerCase().endsWith('.pdf'));

    if (isPdf) {
      const pdf = await getDocument({ data: bytes }).promise;
      const page = await pdf.getPage(1);
      const unscaled = page.getViewport({ scale: 1 });
      const maxW = 980;
      const scale = Math.min(2, maxW / unscaled.width);
      const viewport = page.getViewport({ scale });

      canvas.width = Math.floor(viewport.width);
      canvas.height = Math.floor(viewport.height);

      const ctx = canvas.getContext('2d');
      if (!ctx) throw new Error('No hay contexto 2D');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      await page.render({ canvasContext: ctx, viewport, canvas }).promise;

      this.#baseCanvas = document.createElement('canvas');
      this.#baseCanvas.width = canvas.width;
      this.#baseCanvas.height = canvas.height;
      const bctx = this.#baseCanvas.getContext('2d');
      if (!bctx) throw new Error('No hay contexto 2D');
      bctx.drawImage(canvas, 0, 0);
      this.#redrawSignCanvas();
      return;
    }

    // Image fallback
    const blob = new Blob([this.#u8ToArrayBuffer(bytes)], { type: mime || 'application/octet-stream' });
    const url = URL.createObjectURL(blob);
    try {
      const img = await this.#loadImg(url);
      const maxW = 980;
      const scale = Math.min(1, maxW / img.naturalWidth);
      canvas.width = Math.floor(img.naturalWidth * scale);
      canvas.height = Math.floor(img.naturalHeight * scale);
      const ctx = canvas.getContext('2d');
      if (!ctx) throw new Error('No hay contexto 2D');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

      this.#baseCanvas = document.createElement('canvas');
      this.#baseCanvas.width = canvas.width;
      this.#baseCanvas.height = canvas.height;
      const bctx = this.#baseCanvas.getContext('2d');
      if (!bctx) throw new Error('No hay contexto 2D');
      bctx.drawImage(canvas, 0, 0);
      this.#redrawSignCanvas();
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  async #loadSignatureImage(file: File | null): Promise<void> {
    if (!file) {
      this.signatureImg = null;
      this.#redrawSignCanvas();
      return;
    }
    const url = URL.createObjectURL(file);
    try {
      const img = await this.#loadImg(url);
      this.signatureImg = img;
      this.#initSigSize(img);
      this.resetSignaturePosition();
      this.#redrawSignCanvas();
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  #initSigSize(img: HTMLImageElement): void {
    const canvas = this.signCanvasRef?.nativeElement;
    if (!canvas) return;
    const baseW = Math.min(260, Math.floor(canvas.width * 0.28));
    const ratio = img.naturalHeight / Math.max(1, img.naturalWidth);
    this.#sigBaseW = baseW;
    this.#sigBaseH = Math.max(40, Math.floor(baseW * ratio));
    this.#sigW = this.#sigBaseW;
    this.#sigH = this.#sigBaseH;
    this.#applySignatureScale();
  }

  #applySignatureScale(): void {
    const s = Math.max(0.5, Math.min(2.5, this.signatureScale || 1));
    this.signatureScale = s;
    this.#sigW = Math.max(24, Math.round(this.#sigBaseW * s));
    this.#sigH = Math.max(24, Math.round(this.#sigBaseH * s));
  }

  #redrawSignCanvas(): void {
    const canvas = this.signCanvasRef?.nativeElement;
    const base = this.#baseCanvas;
    if (!canvas || !base) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(base, 0, 0);
    if (this.signatureImg) {
      this.#clampSig(canvas);
      ctx.drawImage(this.signatureImg, this.#sigX, this.#sigY, this.#sigW, this.#sigH);
      ctx.strokeStyle = 'rgba(255,255,255,0.7)';
      ctx.lineWidth = 1;
      ctx.strokeRect(this.#sigX, this.#sigY, this.#sigW, this.#sigH);
    }
  }

  #hitSig(x: number, y: number): boolean {
    return x >= this.#sigX && x <= this.#sigX + this.#sigW && y >= this.#sigY && y <= this.#sigY + this.#sigH;
  }

  #clampSig(canvas: HTMLCanvasElement): void {
    this.#sigX = Math.max(0, Math.min(this.#sigX, canvas.width - this.#sigW));
    this.#sigY = Math.max(0, Math.min(this.#sigY, canvas.height - this.#sigH));
  }

  #canvasPoint(canvas: HTMLCanvasElement, evt: PointerEvent): { x: number; y: number } {
    const rect = canvas.getBoundingClientRect();
    const x = ((evt.clientX - rect.left) / rect.width) * canvas.width;
    const y = ((evt.clientY - rect.top) / rect.height) * canvas.height;
    return { x, y };
  }

  async #buildSignedFile(target: DocumentItem, canvas: HTMLCanvasElement): Promise<File> {
    const mime = this.signDocMime || '';
    const isPdf = /pdf/i.test(mime) || (mime === '' && (target.original_filename ?? '').toLowerCase().endsWith('.pdf'));

    const sigPngBytes = await this.#signaturePngBytes();

    if (isPdf) {
      const pdfDoc = await PDFDocument.load(this.signDocBytes as Uint8Array);
      const pages = pdfDoc.getPages();
      if (!pages.length) throw new Error('PDF vacío');
      const page = pages[0];
      const { width: pageW, height: pageH } = page.getSize();
      const png = await pdfDoc.embedPng(sigPngBytes);

      const scaleX = pageW / canvas.width;
      const scaleY = pageH / canvas.height;
      const x = this.#sigX * scaleX;
      const y = pageH - (this.#sigY + this.#sigH) * scaleY;
      const w = this.#sigW * scaleX;
      const h = this.#sigH * scaleY;
      page.drawImage(png, { x, y, width: w, height: h, opacity: 1 });

      const signedBytes = await pdfDoc.save();
      const name = this.#makeSignedName(target, 'pdf');
      return new File([this.#u8ToArrayBuffer(signedBytes)], name, { type: 'application/pdf' });
    }

    // image: composite from the visible canvas (which already has base+signature)
    const blob = await new Promise<Blob>((resolve, reject) => {
      canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('No se pudo exportar imagen'))), 'image/png');
    });
    const name = this.#makeSignedName(target, 'png');
    return new File([blob], name, { type: 'image/png' });
  }

  async #signaturePngBytes(): Promise<Uint8Array> {
    // Prefer the uploaded signature file bytes if present; otherwise, rasterize current signature image
    if (this.signatureSelectedFile) {
      const arr = new Uint8Array(await this.signatureSelectedFile.arrayBuffer());
      // If already PNG, use as-is. Otherwise convert to PNG via canvas.
      if (/png/i.test(this.signatureSelectedFile.type)) return arr;
    }

    if (!this.signatureImg) throw new Error('Firma no cargada');
    const c = document.createElement('canvas');
    c.width = Math.max(1, this.signatureImg.naturalWidth);
    c.height = Math.max(1, this.signatureImg.naturalHeight);
    const ctx = c.getContext('2d');
    if (!ctx) throw new Error('No hay contexto 2D');
    ctx.drawImage(this.signatureImg, 0, 0);
    const blob = await new Promise<Blob>((resolve, reject) => {
      c.toBlob((b) => (b ? resolve(b) : reject(new Error('No se pudo exportar firma'))), 'image/png');
    });
    return new Uint8Array(await blob.arrayBuffer());
  }

  #makeSignedName(target: DocumentItem, ext: string): string {
    const base = (target.original_filename ?? target.filename ?? `documento-${target.id}`).replace(/\.[^.]+$/, '');
    return `${base}-firmado.${ext}`;
  }

  #loadImg(url: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('No se pudo cargar la imagen'));
      img.src = url;
    });
  }

  async openFile(id: Id): Promise<void> {
    this.error = null;
    try {
      // Prefer signed download URL (cloud/S3 style). If the backend returns a relative URL
      // (which would typically require Authorization headers), fall back to blob download.
      try {
        const info = await firstValueFrom(this.#docs.downloadInfo(id));
        if (info?.url && this.#isAbsoluteUrl(info.url)) {
          window.open(info.url, '_blank', 'noopener,noreferrer');
          return;
        }
      } catch {
        // ignore and fall back
      }

      const blob = await firstValueFrom(this.#docs.file(id));
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank', 'noopener,noreferrer');
      setTimeout(() => URL.revokeObjectURL(url), 30_000);
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  async openHtml(id: Id): Promise<void> {
    this.error = null;
    try {
      const html = await firstValueFrom(this.#docs.viewHtml(id));
      const win = window.open('', '_blank', 'noopener,noreferrer');
      if (!win) return;
      win.document.open();
      win.document.write(html);
      win.document.close();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  // Helpers for template
  isImage(d: DocumentItem): boolean {
    const m = d.mime ?? d.mime_type ?? '';
    return /^image\//i.test(m);
  }

  previewUrl(d: DocumentItem): string | null {
    return (d.file_url ?? d.view_url ?? d.download_url ?? d.url) || null;
  }

  formatDate(iso?: string): string {
    if (!iso) return '-';
    const dt = new Date(iso);
    if (isNaN(dt.getTime())) return iso;
    return dt.toLocaleString();
  }

  trackById(_index: number, item: DocumentItem): Id {
    return item.id;
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo completar la operación.';
  }

  #isAbsoluteUrl(url: string): boolean {
    return /^https?:\/\//i.test(url);
  }

  #u8ToArrayBuffer(u8: Uint8Array): ArrayBuffer {
    const copy = new Uint8Array(u8.byteLength);
    copy.set(u8);
    return copy.buffer;
  }
}
