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
  RowComponent,
  TableDirective
} from '@coreui/angular';

import {
  CommentEntityType,
  CommentItem,
  CommentsService,
  CreateCommentRequest
} from '../../../core/services/comments.service';
import { Id } from '../../../core/services/api.models';

@Component({
  selector: 'app-crm-comments-page',
  templateUrl: './comments-page.component.html',
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
export class CommentsPageComponent implements OnInit {
  readonly #comments = inject(CommentsService);
  readonly #fb = inject(FormBuilder);

  isLoading = false;
  error: string | null = null;
  submitError: string | null = null;
  total = 0;
  comments: CommentItem[] = [];

  editingId: Id | null = null;
  isSaving = false;

  currentEntityType: CommentEntityType | string = 'task';
  currentEntityId: Id = 0;

  readonly entityTypes: CommentEntityType[] = ['task', 'patient'];

  readonly filterForm = this.#fb.nonNullable.group({
    entity_type: ['task' as CommentEntityType | string],
    entity_id: [0, [Validators.required, Validators.min(1)]]
  });

  readonly form = this.#fb.nonNullable.group({
    body: ['', [Validators.required]]
  });

  ngOnInit(): void {
    // defer load until entity is selected
  }

  async refresh(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;

    try {
      const raw = this.filterForm.getRawValue();
      if (!raw.entity_type || Number(raw.entity_id) <= 0) {
        this.error = 'Selecciona entidad valida.';
        return;
      }

      this.currentEntityType = raw.entity_type;
      this.currentEntityId = Number(raw.entity_id) as Id;

      const res = await firstValueFrom(this.#comments.list({
        entity_type: this.currentEntityType,
        entity_id: this.currentEntityId
      }));
      this.total = res.total;
      this.comments = res.data;
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  startCreate(): void {
    this.editingId = null;
    this.submitError = null;
    this.form.reset({ body: '' });
  }

  startEdit(c: CommentItem): void {
    this.editingId = c.id;
    this.submitError = null;
    this.form.reset({ body: c.body ?? '' });
  }

  cancelEdit(): void {
    this.startCreate();
  }

  async save(): Promise<void> {
    this.submitError = null;
    this.form.markAllAsTouched();
    if (this.form.invalid || this.isSaving) return;
    if (!this.currentEntityType || this.currentEntityId <= 0) {
      this.submitError = 'Primero selecciona una entidad.';
      return;
    }

    this.isSaving = true;
    const raw = this.form.getRawValue();

    try {
      if (this.editingId === null) {
        const payload: CreateCommentRequest = {
          entity_type: this.currentEntityType,
          entity_id: this.currentEntityId,
          body: raw.body.trim()
        };
        await firstValueFrom(this.#comments.create(payload));
        this.startCreate();
      } else {
        await firstValueFrom(this.#comments.update(this.editingId, { body: raw.body.trim() }));
      }
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async delete(c: CommentItem): Promise<void> {
    this.error = null;
    if (!window.confirm(`Eliminar comentario #${c.id}?`)) return;
    try {
      await firstValueFrom(this.#comments.delete(c.id));
      await this.refresh();
      if (this.editingId === c.id) this.startCreate();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar los comentarios.';
  }
}
