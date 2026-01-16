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
  CreateTaskRequest,
  TaskItem,
  TaskPriority,
  TaskStatus,
  TasksService,
  UpdateTaskRequest
} from '../../../core/services/tasks.service';
import { Id } from '../../../core/services/api.models';

@Component({
  selector: 'app-crm-tasks-page',
  templateUrl: './tasks-page.component.html',
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
export class TasksPageComponent implements OnInit {
  readonly #tasks = inject(TasksService);
  readonly #fb = inject(FormBuilder);

  isLoading = false;
  error: string | null = null;
  submitError: string | null = null;
  total = 0;
  tasks: TaskItem[] = [];

  isSaving = false;
  editingId: Id | null = null;

  readonly statuses: TaskStatus[] = ['open', 'in_progress', 'done', 'cancelled'];
  readonly priorities: TaskPriority[] = ['low', 'normal', 'high'];

  readonly filterForm = this.#fb.nonNullable.group({
    status: ['' as '' | TaskStatus],
    assigned_to_user_id: [0],
    related_patient_id: [0]
  });

  readonly form = this.#fb.nonNullable.group({
    title: ['', [Validators.required]],
    description: [''],
    status: ['open' as TaskStatus],
    priority: ['normal' as TaskPriority],
    assigned_to_user_id: [0],
    related_patient_id: [0],
    due_date: ['']
  });

  ngOnInit(): void {
    void this.refresh();
  }

  async refresh(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;

    try {
      const raw = this.filterForm.getRawValue();
      const query: { status?: TaskStatus; assigned_to_user_id?: Id; related_patient_id?: Id } = {};
      if (raw.status) query.status = raw.status;
      if (Number(raw.assigned_to_user_id) > 0) query.assigned_to_user_id = Number(raw.assigned_to_user_id) as Id;
      if (Number(raw.related_patient_id) > 0) query.related_patient_id = Number(raw.related_patient_id) as Id;

      const res = await firstValueFrom(this.#tasks.list(query));
      this.total = res.total;
      this.tasks = res.data;
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  startCreate(): void {
    this.editingId = null;
    this.submitError = null;
    this.form.reset({
      title: '',
      description: '',
      status: 'open',
      priority: 'normal',
      assigned_to_user_id: 0,
      related_patient_id: 0,
      due_date: ''
    });
  }

  startEdit(t: TaskItem): void {
    this.editingId = t.id;
    this.submitError = null;
    this.form.reset({
      title: t.title ?? '',
      description: t.description ?? '',
      status: (t.status ?? 'open') as TaskStatus,
      priority: (t.priority ?? 'normal') as TaskPriority,
      assigned_to_user_id: Number(t.assigned_to_user_id ?? 0),
      related_patient_id: Number(t.related_patient_id ?? 0),
      due_date: t.due_date ?? ''
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

    try {
      if (this.editingId === null) {
        const payload: CreateTaskRequest = {
          title: raw.title.trim(),
          description: raw.description.trim() || undefined,
          status: raw.status,
          priority: raw.priority,
          assigned_to_user_id: Number(raw.assigned_to_user_id) > 0 ? Number(raw.assigned_to_user_id) as Id : undefined,
          related_patient_id: Number(raw.related_patient_id) > 0 ? Number(raw.related_patient_id) as Id : undefined,
          due_date: raw.due_date.trim() || undefined
        };
        await firstValueFrom(this.#tasks.create(payload));
        this.startCreate();
      } else {
        const payload: UpdateTaskRequest = {
          title: raw.title.trim(),
          description: raw.description.trim() ? raw.description.trim() : null,
          status: raw.status,
          priority: raw.priority,
          assigned_to_user_id: Number(raw.assigned_to_user_id) > 0 ? Number(raw.assigned_to_user_id) as Id : null,
          related_patient_id: Number(raw.related_patient_id) > 0 ? Number(raw.related_patient_id) as Id : null,
          due_date: raw.due_date.trim() ? raw.due_date.trim() : null
        };
        await firstValueFrom(this.#tasks.update(this.editingId, payload));
      }
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async delete(t: TaskItem): Promise<void> {
    this.error = null;
    if (!window.confirm(`Eliminar tarea #${t.id}?`)) return;
    try {
      await firstValueFrom(this.#tasks.delete(t.id));
      await this.refresh();
      if (this.editingId === t.id) this.startCreate();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar las tareas.';
  }
}
