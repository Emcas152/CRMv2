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

import { EmailTemplate, EmailTemplatesService } from '../../../core/services/email-templates.service';

@Component({
  selector: 'app-crm-email-templates-page',
  templateUrl: './email-templates-page.component.html',
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
    FormControlDirective
  ]
})
export class EmailTemplatesPageComponent implements OnInit {
  readonly #templates = inject(EmailTemplatesService);
  readonly #fb = inject(FormBuilder);

  isLoading = false;
  error: string | null = null;
  submitError: string | null = null;
  total = 0;
  templates: EmailTemplate[] = [];

  isSaving = false;
  editingId: number | null = null;

  readonly form = this.#fb.nonNullable.group({
    name: ['', [Validators.required]],
    subject: ['', [Validators.required]],
    body: ['', [Validators.required]]
  });

  ngOnInit(): void {
    void this.refresh();
  }

  async refresh(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;
    try {
      const res = await firstValueFrom(this.#templates.list());
      this.total = res.total;
      this.templates = res.data;
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  startCreate(): void {
    this.editingId = null;
    this.submitError = null;
    this.form.reset({ name: '', subject: '', body: '' });
  }

  startEdit(t: EmailTemplate): void {
    this.editingId = t.id;
    this.submitError = null;
    this.form.reset({
      name: t.name ?? '',
      subject: t.subject ?? '',
      body: t.body ?? ''
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
    const payload: Partial<EmailTemplate> = {
      name: raw.name.trim(),
      subject: raw.subject.trim(),
      body: raw.body
    };

    try {
      if (this.editingId === null) {
        await firstValueFrom(this.#templates.create(payload));
        this.startCreate();
      } else {
        await firstValueFrom(this.#templates.update(this.editingId, payload));
      }
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async delete(t: EmailTemplate): Promise<void> {
    this.error = null;
    if (!window.confirm(`Eliminar plantilla #${t.id}?`)) return;
    try {
      await firstValueFrom(this.#templates.delete(t.id));
      await this.refresh();
      if (this.editingId === t.id) this.startCreate();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar las plantillas.';
  }
}
