import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import {
  AlertComponent,
  ButtonDirective,
  CardBodyComponent,
  CardComponent,
  CardHeaderComponent,
  ColComponent,
  RowComponent,
  TableDirective
} from '@coreui/angular';
import { ChartjsComponent } from '@coreui/angular-chartjs';
import { getStyle } from '@coreui/utils';
import { ChartData, ChartOptions } from 'chart.js';

import { AuthService, AuthUser } from '../../../core/auth/auth.service';
import { Sale, SalesService } from '../../../core/services/sales.service';

@Component({
  selector: 'app-crm-home',
  templateUrl: './crm-home.component.html',
  standalone: true,
  imports: [
    RowComponent,
    ColComponent,
    CardComponent,
    CardHeaderComponent,
    CardBodyComponent,
    TableDirective,
    AlertComponent,
    ButtonDirective,
    RouterLink,
    ChartjsComponent
  ]
})
export class CrmHomeComponent implements OnInit {
  readonly #sales = inject(SalesService);
  readonly #auth = inject(AuthService);
  readonly #route = inject(ActivatedRoute);

  isLoading = false;
  error: string | null = null;
  deniedRoles: string | null = null;

  me: AuthUser | null = null;

  monthSalesTotal = 0;
  monthSalesCount = 0;
  monthSalesAmount = 0;
  todaySalesCount = 0;
  pendingSalesCount = 0;

  recentSales: Sale[] = [];

  salesByDayChartData: ChartData<'line'> = { labels: [], datasets: [] };
  salesByDayChartOptions: ChartOptions<'line'> = this.#buildLineChartOptions();

  salesByPaymentChartData: ChartData<'doughnut'> = { labels: [], datasets: [] };
  salesByPaymentChartOptions: ChartOptions<'doughnut'> = this.#buildDoughnutChartOptions();

  ngOnInit(): void {
    this.deniedRoles = this.#route.snapshot.queryParamMap.get('denied');
    void this.load();
  }

  async load(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;

    try {
      // Current user (for role display)
      const meRes = await firstValueFrom(this.#auth.me());
      this.me = meRes?.user ?? null;

      // Sales dashboard (current month)
      const now = new Date();
      const dateFrom = new Date(now.getFullYear(), now.getMonth(), 1);

      const fmt = (d: Date): string => {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
      };

      const res = await firstValueFrom(
        this.#sales.list({
          date_from: fmt(dateFrom),
          date_to: fmt(now),
          page: 1,
          per_page: 200,
          sort_by: 'created_at',
          sort_dir: 'desc'
        })
      );

      const data = Array.isArray(res.data) ? res.data : [];
      this.monthSalesTotal = Number(res.total) || data.length;
      this.monthSalesCount = data.length;

      const todayStr = fmt(now);

      const saleTotal = (s: Sale): number => {
        const items = Array.isArray(s.items) ? s.items : [];
        const subtotal = items.reduce((acc, it) => acc + Number(it.price || 0) * Number(it.quantity || 0), 0);
        const discount = Number(s.discount || 0);
        return Math.max(0, subtotal - discount);
      };

      this.monthSalesAmount = data.reduce((acc, s) => acc + saleTotal(s), 0);
      this.todaySalesCount = data.filter((s) => {
        const created = typeof s.created_at === 'string' ? s.created_at : '';
        return created.startsWith(todayStr);
      }).length;
      this.pendingSalesCount = data.filter((s) => String(s.status || '').toLowerCase() === 'pending').length;

      this.recentSales = data.slice(0, 10);

      this.#buildCharts(data, dateFrom, now);
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  formatMoney(value: number): string {
    const n = Number(value) || 0;
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo cargar el dashboard.';
  }

  #buildCharts(sales: Sale[], dateFrom: Date, dateTo: Date): void {
    const fmt = (d: Date): string => {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    };

    // Day labels for current month range
    const labels: string[] = [];
    const dayKeys: string[] = [];
    const cursor = new Date(dateFrom);
    while (cursor <= dateTo) {
      const key = fmt(cursor);
      dayKeys.push(key);
      labels.push(key.slice(5)); // MM-DD
      cursor.setDate(cursor.getDate() + 1);
    }

    const saleTotal = (s: Sale): number => {
      const items = Array.isArray(s.items) ? s.items : [];
      const subtotal = items.reduce((acc, it) => acc + Number(it.price || 0) * Number(it.quantity || 0), 0);
      const discount = Number(s.discount || 0);
      return Math.max(0, subtotal - discount);
    };

    const amountByDay = new Map<string, number>();
    const countByDay = new Map<string, number>();
    for (const key of dayKeys) {
      amountByDay.set(key, 0);
      countByDay.set(key, 0);
    }

    for (const s of sales) {
      const created = typeof s.created_at === 'string' ? s.created_at : '';
      const dayKey = created.slice(0, 10);
      if (!amountByDay.has(dayKey)) continue;
      amountByDay.set(dayKey, (amountByDay.get(dayKey) ?? 0) + saleTotal(s));
      countByDay.set(dayKey, (countByDay.get(dayKey) ?? 0) + 1);
    }

    const brandPrimary = getStyle('--cui-primary') ?? '#0d6efd';
    const brandPrimaryRgb = getStyle('--cui-primary-rgb') ?? '13,110,253';
    const brandInfo = getStyle('--cui-info') ?? '#20a8d8';

    this.salesByDayChartData = {
      labels,
      datasets: [
        {
          type: 'line',
          label: 'Monto por día',
          data: dayKeys.map((k) => amountByDay.get(k) ?? 0),
          borderColor: brandPrimary,
          backgroundColor: `rgba(${brandPrimaryRgb}, .15)`,
          pointRadius: 0,
          fill: true,
          tension: 0.35
        },
        {
          type: 'line',
          label: 'Ventas por día',
          data: dayKeys.map((k) => countByDay.get(k) ?? 0),
          borderColor: brandInfo,
          backgroundColor: 'transparent',
          pointRadius: 0,
          fill: false,
          tension: 0.35,
          yAxisID: 'y1'
        }
      ]
    };

    const paymentLabels: string[] = [];
    const paymentCounts: number[] = [];
    const byPayment = new Map<string, number>();
    for (const s of sales) {
      const method = String(s.payment_method || 'N/A').trim() || 'N/A';
      byPayment.set(method, (byPayment.get(method) ?? 0) + 1);
    }
    for (const [k, v] of Array.from(byPayment.entries()).sort((a, b) => b[1] - a[1]).slice(0, 6)) {
      paymentLabels.push(k);
      paymentCounts.push(v);
    }

    const palette = [
      getStyle('--cui-primary') ?? '#0d6efd',
      getStyle('--cui-success') ?? '#198754',
      getStyle('--cui-info') ?? '#0dcaf0',
      getStyle('--cui-warning') ?? '#ffc107',
      getStyle('--cui-danger') ?? '#dc3545',
      getStyle('--cui-secondary') ?? '#6c757d'
    ];

    this.salesByPaymentChartData = {
      labels: paymentLabels,
      datasets: [
        {
          data: paymentCounts,
          backgroundColor: palette.slice(0, paymentCounts.length),
          borderWidth: 0
        }
      ]
    };
  }

  #buildLineChartOptions(): ChartOptions<'line'> {
    const colorBorderTranslucent = getStyle('--cui-border-color-translucent');
    const colorBody = getStyle('--cui-body-color');
    const formatMoney = (value: number): string => {
      const n = Number(value) || 0;
      return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    return {
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true
        },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const y = (ctx.parsed as any)?.y;
              const label = ctx.dataset?.label ? `${ctx.dataset.label}: ` : '';
              if (ctx.dataset?.yAxisID === 'y1') {
                const n = Number(y) || 0;
                return `${label}${n}`;
              }
              return `${label}${formatMoney(Number(y) || 0)}`;
            }
          }
        }
      },
      scales: {
        x: {
          grid: {
            color: colorBorderTranslucent,
            drawOnChartArea: false
          },
          ticks: {
            color: colorBody,
            maxRotation: 0,
            maxTicksLimit: 10
          }
        },
        y: {
          grid: {
            color: colorBorderTranslucent
          },
          ticks: {
            color: colorBody,
            callback: (value) => formatMoney(Number(value) || 0)
          },
          beginAtZero: true
        },
        y1: {
          position: 'right',
          grid: {
            drawOnChartArea: false
          },
          ticks: {
            color: colorBody,
            precision: 0,
            stepSize: 1
          },
          beginAtZero: true
        }
      }
    };
  }

  #buildDoughnutChartOptions(): ChartOptions<'doughnut'> {
    return {
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: 'bottom'
        }
      }
    };
  }
}
