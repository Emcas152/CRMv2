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

import { CreateUserRequest, UpdateUserRequest, User, UsersListQuery, UsersService } from '../../../core/services/users.service';

@Component({
  selector: 'app-crm-users-page',
  templateUrl: './users-page.component.html',
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
export class UsersPageComponent implements OnInit {
  readonly #users = inject(UsersService);
  readonly #fb = inject(FormBuilder);

  isLoading = false;
  error: string | null = null;
  submitError: string | null = null;
  total = 0;
  users: User[] = [];

  isSaving = false;
  editingId: number | null = null;

  readonly filterForm = this.#fb.nonNullable.group({
    search: [''],
    role: [''],
    page: [1, [Validators.required, Validators.min(1)]],
    per_page: [20, [Validators.required, Validators.min(1), Validators.max(200)]]
  });

  readonly form = this.#fb.nonNullable.group({
    name: ['', [Validators.required]],
    email: ['', [Validators.required, Validators.email]],
    role: ['', [Validators.required]],
    phone: [''],
    password: ['']
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
      const query: UsersListQuery = {
        page: Number(raw.page) || 1,
        per_page: Number(raw.per_page) || 20
      };
      if (raw.search.trim().length) query.search = raw.search.trim();
      if (raw.role.trim().length) query.role = raw.role.trim();

      const res = await firstValueFrom(this.#users.list(query));
      this.total = res.total;
      this.users = res.data;
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
    this.form.reset({ name: '', email: '', role: '', phone: '', password: '' });
  }

  startEdit(u: User): void {
    this.editingId = u.id;
    this.submitError = null;
    this.form.reset({
      name: u.name ?? '',
      email: u.email ?? '',
      role: u.role ?? '',
      phone: u.phone ?? '',
      password: ''
    });
  }

  cancelEdit(): void {
    this.startCreate();
  }

  async save(): Promise<void> {
    this.submitError = null;
    this.form.markAllAsTouched();
    if (this.form.invalid || this.isSaving) return;

    const raw = this.form.getRawValue();
    if (this.editingId === null && !raw.password.trim()) {
      this.submitError = 'La contraseña es requerida para crear un usuario.';
      return;
    }

    this.isSaving = true;
    try {
      if (this.editingId === null) {
        const payload: CreateUserRequest = {
          name: raw.name.trim(),
          email: raw.email.trim(),
          role: raw.role.trim(),
          password: raw.password.trim(),
          phone: raw.phone.trim() || undefined
        };
        await firstValueFrom(this.#users.create(payload));
        this.startCreate();
      } else {
        const payload: UpdateUserRequest = {
          name: raw.name.trim(),
          email: raw.email.trim(),
          role: raw.role.trim(),
          phone: raw.phone.trim() ? raw.phone.trim() : null
        };
        if (raw.password.trim()) payload.password = raw.password.trim();
        await firstValueFrom(this.#users.update(this.editingId, payload));
      }
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async delete(u: User): Promise<void> {
    this.error = null;
    if (!window.confirm(`Eliminar usuario #${u.id}?`)) return;
    try {
      await firstValueFrom(this.#users.delete(u.id));
      await this.refresh();
      if (this.editingId === u.id) this.startCreate();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar los usuarios.';
  }
}
