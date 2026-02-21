import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import {
  ButtonDirective
} from '@coreui/angular';
import { ChartjsComponent } from '@coreui/angular-chartjs';
import { getStyle } from '@coreui/utils';
import { ChartData, ChartOptions } from 'chart.js';

import { AuthService, AuthUser } from '../../../core/auth/auth.service';
import { Router } from '@angular/router';
import { Sale, SalesService } from '../../../core/services/sales.service';
import { AppointmentsService, Appointment } from '../../../core/services/appointments.service';

@Component({
  selector: 'app-crm-home',
  templateUrl: './crm-home.component.html',
  styleUrls: ['./crm-home.component.scss'],
  standalone: true,
  imports: [
    ButtonDirective,
    RouterLink,
    ChartjsComponent,
    CommonModule,
  ]
})
export class CrmHomeComponent implements OnInit {
  readonly #sales = inject(SalesService);
  readonly #auth = inject(AuthService);
  readonly #router = inject(Router);
  readonly #route = inject(ActivatedRoute);
  readonly #appointments = inject(AppointmentsService);

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

  // Filters & goals
  period: 'today' | 'week' | 'month' | 'custom' = 'month';
  dateFrom: string | null = null;
  dateTo: string | null = null;

  goals: { daily: number; weekly: number; monthly: number } = { daily: 0, weekly: 0, monthly: 0 };
  showGoalsEditor = false;

  // Weekly calendar
  weekDays: string[] = [];
  weekAppointments: Record<string, Appointment[]> = {};

  // ─── Dashboard KPI values ───
  dailyRevenue = 0;
  dailyRevenueChange = 0;
  patientsInLobby = 0;
  patientsInTreatment = 0;
  completionPct = 0;
  monthGoalPct = 0;
  dailyGoalPct = 0;
  staffingPct = 90;

  // Schedule
  todayAppointments: Appointment[] = [];
  confirmedCount = 0;
  pendingApptsCount = 0;

  readonly scheduleHours = [
    '9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM',
    '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM',
    '5:00 PM', '6:00 PM', '7:00 PM'
  ];

  // Alerts
  criticalAlerts: Array<{ text: string; type: 'red' | 'yellow' | 'blue' }> = [];

  // ─── Charts ───
  salesByDayChartData: ChartData<'line'> = { labels: [], datasets: [] };
  salesByDayChartOptions: ChartOptions<'line'> = this.#buildLineChartOptions();
  salesByPaymentChartData: ChartData<'doughnut'> = { labels: [], datasets: [] };
  salesByPaymentChartOptions: ChartOptions<'doughnut'> = this.#buildDoughnutChartOptions();

  // Bar charts for dashboard
  financialBarData: ChartData<'bar'> = { labels: [], datasets: [] };
  financialBarOptions: ChartOptions<'bar'> = this.#buildBarChartOptions();
  categoryBarData: ChartData<'bar'> = { labels: [], datasets: [] };
  categoryBarOptions: ChartOptions<'bar'> = this.#buildBarChartOptions();

  // Sparkline for pulse card
  sparklineData: ChartData<'line'> = { labels: [], datasets: [] };
  sparklineOptions: ChartOptions<'line'> = {
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { enabled: false } },
    scales: {
      x: { display: false },
      y: { display: false }
    },
    elements: { point: { radius: 0 } }
  };

  ngOnInit(): void {
    this.deniedRoles = this.#route.snapshot.queryParamMap.get('denied');
    queueMicrotask(() => void this.load());
  }

  async load(): Promise<void> {
    if (this.isLoading) return;
    this.isLoading = true;
    this.error = null;

    try {
      const meRes = await firstValueFrom(this.#auth.me());
      this.me = meRes?.user ?? null;

      if (this.me) {
        const role = String(this.me.role || '').toLowerCase();
        if (role === 'patient' || role === 'paciente') {
        void this.#router.navigateByUrl('/crm/welcome');
        this.isLoading = false;
        return;
        }
      }

      const now = new Date();
      let dateFrom = new Date(now.getFullYear(), now.getMonth(), 1);
      let dateTo = now;

      if (this.period === 'today') {
        dateFrom = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        dateTo = new Date(dateFrom);
      } else if (this.period === 'week') {
        const day = now.getDay();
        const diffToMonday = ((day + 6) % 7);
        dateFrom = new Date(now);
        dateFrom.setDate(now.getDate() - diffToMonday);
        dateFrom.setHours(0, 0, 0, 0);
        dateTo = new Date(dateFrom);
        dateTo.setDate(dateFrom.getDate() + 6);
      } else if (this.period === 'custom' && this.dateFrom && this.dateTo) {
        dateFrom = new Date(this.dateFrom);
        dateTo = new Date(this.dateTo);
      }

      const fmt = (d: Date): string => {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
      };

      const res = await firstValueFrom(
        this.#sales.list({
          date_from: fmt(dateFrom),
          date_to: fmt(dateTo),
          page: 1,
          per_page: 500,
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

      const todaySales = data.filter((s) => {
        const created = typeof s.created_at === 'string' ? s.created_at : '';
        return created.startsWith(todayStr);
      });
      this.todaySalesCount = todaySales.length;
      this.dailyRevenue = todaySales.reduce((acc, s) => acc + saleTotal(s), 0);

      this.pendingSalesCount = data.filter((s) => String(s.status || '').toLowerCase() === 'pending').length;

      this.recentSales = data.slice(0, 8);

      // Compute KPI percentages
      this._computeKPIs(data, dateFrom, dateTo, saleTotal);

      // Build all charts
      this.#buildCharts(data, dateFrom, dateTo);
      this.#buildBarCharts(data, dateFrom, dateTo);

      // Load appointments
      try {
        const apptsRes = await firstValueFrom(this.#appointments.list({ date_from: fmt(dateFrom), date_to: fmt(dateTo), per_page: 500 }));
        const appts = Array.isArray(apptsRes.data) ? apptsRes.data : [];
        this._buildWeekCalendar(dateFrom, dateTo, appts);

        // Today's appointments for schedule
        this.todayAppointments = appts.filter(a => a.appointment_date === todayStr);
        this.todayAppointments.sort((a, b) => (a.appointment_time || '').localeCompare(b.appointment_time || ''));

        this.confirmedCount = this.todayAppointments.filter(a => a.status === 'confirmed').length;
        this.pendingApptsCount = this.todayAppointments.filter(a => a.status === 'pending').length;

        // Patient counts from appointments
        this.patientsInLobby = this.todayAppointments.filter(a => a.status === 'pending' || a.status === 'confirmed').length;
        this.patientsInTreatment = this.todayAppointments.filter(a => a.status === 'confirmed').length;

        // Completion percentage
        const totalAppts = this.todayAppointments.length;
        const completedAppts = this.todayAppointments.filter(a => a.status === 'completed').length;
        this.completionPct = totalAppts > 0 ? Math.round((completedAppts / totalAppts) * 100) : 0;
      } catch (e) {
        // ignore calendar errors
      }

      // Build alerts
      this._buildAlerts();

      // Load saved goals
      this._loadGoals();
    } catch (err: any) {
      this.error = this.#formatError(err);
    } finally {
      this.isLoading = false;
    }
  }

  // ─── Schedule helpers ───

  getAppointmentsForHour(hour: string): Appointment[] {
    const h24 = this._parseHourTo24(hour);
    return this.todayAppointments.filter(a => {
      const time = a.appointment_time || '';
      const appointmentHour = parseInt(time.split(':')[0], 10);
      return appointmentHour === h24;
    });
  }

  getBlockColor(appt: Appointment): string {
    const service = (appt.service || '').toLowerCase();
    if (service.includes('hydra') || service.includes('facial') || service.includes('limpieza')) return 'green';
    if (service.includes('botox') || service.includes('consult')) return 'blue';
    if (service.includes('filler') || service.includes('relleno')) return 'yellow';
    if (service.includes('laser') || service.includes('depilac')) return 'red';
    return 'purple';
  }

  private _parseHourTo24(hour: string): number {
    const match = hour.match(/^(\d+):/);
    if (!match) return -1;
    let h = parseInt(match[1], 10);
    if (hour.includes('PM') && h !== 12) h += 12;
    if (hour.includes('AM') && h === 12) h = 0;
    return h;
  }

  // ─── KPI computation ───

  private _computeKPIs(
    sales: Sale[],
    dateFrom: Date,
    dateTo: Date,
    saleTotal: (s: Sale) => number
  ): void {
    // Daily revenue change vs average
    const diffDays = Math.max(1, Math.ceil((dateTo.getTime() - dateFrom.getTime()) / (1000 * 60 * 60 * 24)) + 1);
    const avgDaily = this.monthSalesAmount / diffDays;
    this.dailyRevenueChange = avgDaily > 0 ? Math.round(((this.dailyRevenue - avgDaily) / avgDaily) * 100) : 0;

    // Monthly goal percentage
    this.monthGoalPct = this.goals.monthly > 0 ? Math.min(100, Math.round((this.monthSalesAmount / this.goals.monthly) * 100)) : 0;

    // Daily goal percentage
    this.dailyGoalPct = this.goals.daily > 0 ? Math.min(100, Math.round((this.dailyRevenue / this.goals.daily) * 100)) : 0;
  }

  // ─── Alerts ───

  private _buildAlerts(): void {
    this.criticalAlerts = [];
    if (this.pendingSalesCount > 0) {
      this.criticalAlerts.push({ text: `${this.pendingSalesCount} venta(s) pendientes`, type: 'yellow' });
    }
    if (this.pendingApptsCount > 0) {
      this.criticalAlerts.push({ text: `${this.pendingApptsCount} cita(s) sin confirmar`, type: 'blue' });
    }
    if (this.goals.daily > 0 && this.dailyRevenue < this.goals.daily * 0.5) {
      this.criticalAlerts.push({ text: 'Meta diaria por debajo del 50%', type: 'red' });
    }
    if (this.goals.monthly > 0 && this.monthGoalPct < 60) {
      this.criticalAlerts.push({ text: `Meta mensual al ${this.monthGoalPct}%`, type: 'red' });
    }
  }

  // ─── Event handlers ───

  onPeriodChange(event: Event): void {
    const value = (event.target as HTMLSelectElement | null)?.value as any;
    if (value === 'today' || value === 'week' || value === 'month' || value === 'custom') {
      this.period = value;
    } else {
      this.period = 'month';
    }
    void this.load();
  }

  onDateFromChange(event: Event): void {
    this.dateFrom = (event.target as HTMLInputElement | null)?.value || null;
  }

  onDateToChange(event: Event): void {
    this.dateTo = (event.target as HTMLInputElement | null)?.value || null;
  }

  onGoalChange(kind: 'daily' | 'weekly' | 'monthly', event: Event): void {
    const raw = (event.target as HTMLInputElement | null)?.value;
    const n = Number(raw);
    const value = Number.isFinite(n) ? n : 0;
    this.goals = { ...this.goals, [kind]: value };
  }

  formatMoney(value: number): string {
    const n = Number(value) || 0;
    return 'Q' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  #formatError(err: any): string {
    const message = err?.error?.message ?? err?.message;
    if (typeof message === 'string' && message.trim().length) return message;
    return 'No se pudo cargar el dashboard.';
  }

  private _loadGoals(): void {
    try {
      const raw = localStorage.getItem('dashboard_goals');
      if (raw) this.goals = JSON.parse(raw);
      // Recompute after loading goals
      this.monthGoalPct = this.goals.monthly > 0 ? Math.min(100, Math.round((this.monthSalesAmount / this.goals.monthly) * 100)) : 0;
      this.dailyGoalPct = this.goals.daily > 0 ? Math.min(100, Math.round((this.dailyRevenue / this.goals.daily) * 100)) : 0;
      this._buildAlerts();
    } catch (e) {
      // ignore
    }
  }

  saveGoals(): void {
    try {
      localStorage.setItem('dashboard_goals', JSON.stringify(this.goals));
      // Recompute KPIs
      this.monthGoalPct = this.goals.monthly > 0 ? Math.min(100, Math.round((this.monthSalesAmount / this.goals.monthly) * 100)) : 0;
      this.dailyGoalPct = this.goals.daily > 0 ? Math.min(100, Math.round((this.dailyRevenue / this.goals.daily) * 100)) : 0;
      this._buildAlerts();
    } catch (e) {
      // ignore
    }
  }

  private _buildWeekCalendar(from: Date, to: Date, appts: Appointment[]): void {
    const fmt = (d: Date) => {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    };

    this.weekDays = [];
    this.weekAppointments = {};
    const cur = new Date(from);
    while (cur <= to) {
      const key = fmt(cur);
      this.weekDays.push(key);
      this.weekAppointments[key] = [];
      cur.setDate(cur.getDate() + 1);
    }

    for (const a of appts) {
      const day = (a.appointment_date || '').slice(0, 10);
      if (this.weekAppointments[day]) this.weekAppointments[day].push(a);
    }
  }

  // ─── Chart builders ───

  #buildCharts(sales: Sale[], dateFrom: Date, dateTo: Date): void {
    const fmt = (d: Date): string => {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    };

    const labels: string[] = [];
    const dayKeys: string[] = [];
    const cursor = new Date(dateFrom);
    while (cursor <= dateTo) {
      const key = fmt(cursor);
      dayKeys.push(key);
      labels.push(key.slice(5));
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

    // Sparkline for pulse card
    this.sparklineData = {
      labels: labels.slice(-14),
      datasets: [{
        data: dayKeys.slice(-14).map(k => amountByDay.get(k) ?? 0),
        borderColor: '#22c55e',
        backgroundColor: 'rgba(34,197,94,0.1)',
        borderWidth: 1.5,
        fill: true,
        tension: 0.4,
        pointRadius: 0
      }]
    };

    // Payment chart
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

  #buildBarCharts(sales: Sale[], dateFrom: Date, dateTo: Date): void {
    const fmt = (d: Date): string => {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    };

    const saleTotal = (s: Sale): number => {
      const items = Array.isArray(s.items) ? s.items : [];
      const subtotal = items.reduce((acc, it) => acc + Number(it.price || 0) * Number(it.quantity || 0), 0);
      const discount = Number(s.discount || 0);
      return Math.max(0, subtotal - discount);
    };

    // Financial Bar: daily amounts
    const labels: string[] = [];
    const dayKeys: string[] = [];
    const cursor = new Date(dateFrom);
    while (cursor <= dateTo) {
      const key = fmt(cursor);
      dayKeys.push(key);
      labels.push(key.slice(8)); // DD only
      cursor.setDate(cursor.getDate() + 1);
    }

    const amountByDay = new Map<string, number>();
    for (const key of dayKeys) amountByDay.set(key, 0);
    for (const s of sales) {
      const created = typeof s.created_at === 'string' ? s.created_at : '';
      const dayKey = created.slice(0, 10);
      if (amountByDay.has(dayKey)) {
        amountByDay.set(dayKey, (amountByDay.get(dayKey) ?? 0) + saleTotal(s));
      }
    }

    const brandPrimary = getStyle('--cui-primary') ?? '#0d6efd';
    const brandSuccess = getStyle('--cui-success') ?? '#198754';

    this.financialBarData = {
      labels,
      datasets: [{
        label: 'Ventas por día',
        data: dayKeys.map(k => amountByDay.get(k) ?? 0),
        backgroundColor: brandPrimary,
        borderRadius: 4,
        maxBarThickness: 16
      }]
    };

    // Category Bar: by payment method
    const byPayment = new Map<string, number>();
    const byPaymentAmount = new Map<string, number>();
    for (const s of sales) {
      const method = String(s.payment_method || 'N/A').trim() || 'N/A';
      byPayment.set(method, (byPayment.get(method) ?? 0) + 1);
      byPaymentAmount.set(method, (byPaymentAmount.get(method) ?? 0) + saleTotal(s));
    }

    const catLabels: string[] = [];
    const catCounts: number[] = [];
    const catAmounts: number[] = [];
    for (const [k] of Array.from(byPayment.entries()).sort((a, b) => b[1] - a[1]).slice(0, 8)) {
      catLabels.push(k);
      catCounts.push(byPayment.get(k) ?? 0);
      catAmounts.push(byPaymentAmount.get(k) ?? 0);
    }

    this.categoryBarData = {
      labels: catLabels,
      datasets: [
        {
          label: 'Cantidad',
          data: catCounts,
          backgroundColor: brandPrimary,
          borderRadius: 4,
          maxBarThickness: 20
        },
        {
          label: 'Monto',
          data: catAmounts,
          backgroundColor: brandSuccess,
          borderRadius: 4,
          maxBarThickness: 20
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
        legend: { display: true },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const y = (ctx.parsed as any)?.y;
              const label = ctx.dataset?.label ? `${ctx.dataset.label}: ` : '';
              if (ctx.dataset?.yAxisID === 'y1') {
                return `${label}${Number(y) || 0}`;
              }
              return `${label}${formatMoney(Number(y) || 0)}`;
            }
          }
        }
      },
      scales: {
        x: {
          grid: { color: colorBorderTranslucent, drawOnChartArea: false },
          ticks: { color: colorBody, maxRotation: 0, maxTicksLimit: 10 }
        },
        y: {
          grid: { color: colorBorderTranslucent },
          ticks: { color: colorBody, callback: (value) => formatMoney(Number(value) || 0) },
          beginAtZero: true
        },
        y1: {
          position: 'right',
          grid: { drawOnChartArea: false },
          ticks: { color: colorBody, precision: 0, stepSize: 1 },
          beginAtZero: true
        }
      }
    };
  }

  #buildDoughnutChartOptions(): ChartOptions<'doughnut'> {
    return {
      maintainAspectRatio: false,
      plugins: { legend: { display: true, position: 'bottom' } }
    };
  }

  #buildBarChartOptions(): ChartOptions<'bar'> {
    const colorBorderTranslucent = getStyle('--cui-border-color-translucent');
    const colorBody = getStyle('--cui-body-color');

    return {
      maintainAspectRatio: false,
      plugins: {
        legend: { display: true },
        tooltip: { mode: 'index', intersect: false }
      },
      scales: {
        x: {
          grid: { color: colorBorderTranslucent, drawOnChartArea: false },
          ticks: { color: colorBody, maxRotation: 0, maxTicksLimit: 15 }
        },
        y: {
          grid: { color: colorBorderTranslucent },
          ticks: { color: colorBody },
          beginAtZero: true
        }
      }
    };
  }
}
