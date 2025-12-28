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
  CreateProductRequest,
  Product,
  ProductType,
  ProductsService,
  ProductsListQuery,
  UpdateProductRequest
} from '../../../core/services/products.service';

@Component({
  selector: 'app-crm-products-page',
  templateUrl: './products-page.component.html',
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
export class ProductsPageComponent implements OnInit {
  readonly #products = inject(ProductsService);
  readonly #fb = inject(FormBuilder);

  isLoading = false;
  error: string | null = null;
  submitError: string | null = null;
  total = 0;
  products: Product[] = [];

  isSaving = false;
  editingId: number | null = null;
  readonly types: ProductType[] = ['product', 'service'];

  readonly filterForm = this.#fb.nonNullable.group({
    search: [''],
    type: ['' as '' | ProductType],
    active: ['' as '' | 'true' | 'false'],
    page: [1, [Validators.required, Validators.min(1)]],
    per_page: [20, [Validators.required, Validators.min(1), Validators.max(200)]]
  });

  readonly form = this.#fb.nonNullable.group({
    name: ['', [Validators.required]],
    price: [0, [Validators.required, Validators.min(0)]],
    type: ['product' as ProductType, [Validators.required]],
    sku: [''],
    description: [''],
    stock: [0],
    active: ['true' as 'true' | 'false']
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
      const query: ProductsListQuery = {
        page: Number(raw.page) || 1,
        per_page: Number(raw.per_page) || 20
      };
      if (raw.search.trim().length) query.search = raw.search.trim();
      if (raw.type) query.type = raw.type;
      if (raw.active === 'true') query.active = true;
      if (raw.active === 'false') query.active = false;

      const res = await firstValueFrom(this.#products.list(query));
      this.total = res.total;
      this.products = res.data;
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
    this.form.reset({
      name: '',
      price: 0,
      type: 'product',
      sku: '',
      description: '',
      stock: 0,
      active: 'true'
    });
  }

  startEdit(p: Product): void {
    this.editingId = p.id;
    this.submitError = null;
    this.form.reset({
      name: p.name ?? '',
      price: p.price ?? 0,
      type: p.type ?? 'product',
      sku: p.sku ?? '',
      description: p.description ?? '',
      stock: p.stock ?? 0,
      active: p.active === false ? 'false' : 'true'
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
    const active = raw.active === 'true';

    const payload: CreateProductRequest = {
      name: raw.name.trim(),
      price: Number(raw.price),
      type: raw.type,
      sku: raw.sku.trim() || undefined,
      description: raw.description.trim() || undefined,
      stock: raw.stock === null || raw.stock === undefined ? undefined : Number(raw.stock),
      active
    };

    try {
      if (this.editingId === null) {
        await firstValueFrom(this.#products.create(payload));
        this.startCreate();
      } else {
        await firstValueFrom(this.#products.update(this.editingId, payload as UpdateProductRequest));
      }
      await this.refresh();
    } catch (err: any) {
      this.submitError = this.#formatError(err);
    } finally {
      this.isSaving = false;
    }
  }

  async delete(p: Product): Promise<void> {
    this.error = null;
    if (!window.confirm(`Eliminar producto #${p.id}?`)) return;
    try {
      await firstValueFrom(this.#products.delete(p.id));
      await this.refresh();
      if (this.editingId === p.id) this.startCreate();
    } catch (err: any) {
      this.error = this.#formatError(err);
    }
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudieron cargar los productos.';
  }
}
