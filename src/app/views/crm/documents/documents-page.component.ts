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
  RowComponent,
  TableDirective
} from '@coreui/angular';

import { DocumentsService, DocumentItem } from '../../../core/services/documents.service';
import { Id } from '../../../core/services/api.models';

@Component({
  selector: 'app-crm-documents-page',
  templateUrl: './documents-page.component.html',
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
    ButtonDirective,
    AlertComponent
  ]
})
export class DocumentsPageComponent implements OnInit {
  readonly #fb = inject(FormBuilder);
  readonly #docs = inject(DocumentsService);

  isLoading = false;
  isUploading = false;
  error: string | null = null;
  actionInfo: string | null = null;
  total = 0;
  items: DocumentItem[] = [];
  selectedFile: File | null = null;
  signFile: File | null = null;

  actingId: Id | null = null;
  rowStatusById: Partial<Record<string, string>> = {};
  replaceFileById: Partial<Record<string, File>> = {};
  titleById: Partial<Record<string, string>> = {};

  readonly form = this.#fb.nonNullable.group({
    patientId: [0, [Validators.required, Validators.min(1)]],
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
    // Intencional: requiere patientId
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
    if (this.form.controls.patientId.invalid || this.isLoading) return;

    this.isLoading = true;
    try {
      const patientId = Number(this.form.controls.patientId.value) as Id;
      const page = Number(this.form.controls.page.value) || 1;
      const per_page = Number(this.form.controls.per_page.value) || 20;
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
    if (this.form.invalid || this.isUploading) return;
    if (!this.selectedFile) {
      this.error = 'Selecciona un archivo.';
      return;
    }

    this.isUploading = true;
    try {
      const patientId = Number(this.form.controls.patientId.value) as Id;
      const title = this.form.controls.title.value?.trim() || undefined;
      await firstValueFrom(this.#docs.upload(this.selectedFile, patientId, title));
      this.selectedFile = null;
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
      await this.refresh();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isUploading = false;
    }
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

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo completar la operación.';
  }

  #isAbsoluteUrl(url: string): boolean {
    return /^https?:\/\//i.test(url);
  }
}
