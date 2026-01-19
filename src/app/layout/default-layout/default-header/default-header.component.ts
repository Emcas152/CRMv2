import { NgTemplateOutlet } from '@angular/common';
import { Component, computed, inject, input, OnInit } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';

import {
  AvatarComponent,
  BadgeComponent,
  BreadcrumbRouterComponent,
  ColorModeService,
  ContainerComponent,
  DropdownComponent,
  DropdownDividerDirective,
  DropdownHeaderDirective,
  DropdownItemDirective,
  DropdownMenuDirective,
  DropdownToggleDirective,
  HeaderComponent,
  HeaderNavComponent,
  HeaderTogglerDirective,
  NavItemComponent,
  NavLinkDirective,
  SidebarToggleDirective
} from '@coreui/angular';

import { IconDirective } from '@coreui/icons-angular';

import { Router } from '@angular/router';
import { AuthService } from '../../../core/auth/auth.service';
import { firstValueFrom } from 'rxjs';
import { ConversationsService } from '../../../core/services/conversations.service';
import { UpdatesService } from '../../../core/services/updates.service';

@Component({
  selector: 'app-default-header',
  templateUrl: './default-header.component.html',
  imports: [ContainerComponent, HeaderTogglerDirective, SidebarToggleDirective, IconDirective, HeaderNavComponent, NavItemComponent, NavLinkDirective, RouterLink, RouterLinkActive, NgTemplateOutlet, BreadcrumbRouterComponent, DropdownComponent, DropdownToggleDirective, AvatarComponent, DropdownMenuDirective, DropdownHeaderDirective, DropdownItemDirective, BadgeComponent, DropdownDividerDirective]
})
export class DefaultHeaderComponent extends HeaderComponent {

  readonly #colorModeService = inject(ColorModeService);
  readonly colorMode = this.#colorModeService.colorMode;

  readonly colorModes = [
    { name: 'light', text: 'Claro', icon: 'cilSun' },
    { name: 'dark', text: 'Oscuro', icon: 'cilMoon' },
    { name: 'auto', text: 'Automático', icon: 'cilContrast' }
  ];

  readonly icons = computed(() => {
    const currentMode = this.colorMode();
    return this.colorModes.find(mode => mode.name === currentMode)?.icon ?? 'cilSun';
  });

  constructor() {
    super();
  }

  readonly #auth = inject(AuthService);
  readonly #router = inject(Router);
  readonly #conversations = inject(ConversationsService);
  readonly #updates = inject(UpdatesService);

  updatesCount = 0;
  unreadMessagesCount = 0;
  isBadgeLoading = false;
  canSeeUpdates = false;
  canSeeConversations = false;
  canSeeTasks = false;
  canSeeComments = false;
  canSeePatients = false;
  canSeeAppointments = false;
  canSeeSales = false;
  currentUser: any = null;

  async onLogout(): Promise<void> {
    try {
      await firstValueFrom(this.#auth.logout());
    } finally {
      await this.#router.navigateByUrl('/login');
    }
  }

  async ngOnInit(): Promise<void> {
    await this.loadPermissions();
    if (this.canSeeUpdates || this.canSeeConversations) {
      await this.refreshBadges();
    } else {
      this.updatesCount = 0;
      this.unreadMessagesCount = 0;
    }
  }

  async loadPermissions(): Promise<void> {
    try {
      const me = await firstValueFrom(this.#auth.me());
      this.currentUser = me?.user ?? null;
      const role = me?.user?.role;
      const isSuperadmin = role === 'superadmin';
      const isAdmin = role === 'admin' || isSuperadmin;
      const isDoctor = role === 'doctor';
      const isStaff = role === 'staff';
      const isPatient = role === 'patient';

      this.canSeePatients = isAdmin || isDoctor || isStaff;
      this.canSeeAppointments = isAdmin || isDoctor || isStaff || isPatient;
      this.canSeeSales = isAdmin || isDoctor || isStaff;

      if (isPatient) {
        this.canSeePatients = false;
        this.canSeeSales = false;
      }
      this.canSeeUpdates = isAdmin;
      this.canSeeConversations = isSuperadmin || isDoctor || isPatient;
      this.canSeeTasks = isAdmin;
      this.canSeeComments = isAdmin;
    } catch {
      this.canSeeUpdates = false;
      this.canSeeConversations = false;
      this.canSeeTasks = false;
      this.canSeeComments = false;
    }
  }

  async refreshBadges(): Promise<void> {
    if (this.isBadgeLoading) return;
    this.isBadgeLoading = true;

    try {
      // Updates: total visible to current user
      const updatesRes = await firstValueFrom(this.#updates.list());
      this.updatesCount = Number(updatesRes?.total ?? 0) || 0;
    } catch {
      this.updatesCount = 0;
    }

    try {
      // Conversations: sum unread_count across conversations
      const convRes = await firstValueFrom(this.#conversations.list());
      const items = Array.isArray(convRes?.data) ? convRes.data : [];
      this.unreadMessagesCount = items.reduce((acc, it) => {
        const n = typeof it.unread_count === 'string' ? parseInt(it.unread_count, 10) : Number(it.unread_count ?? 0);
        return acc + (Number.isFinite(n) ? n : 0);
      }, 0);
    } catch {
      this.unreadMessagesCount = 0;
    } finally {
      this.isBadgeLoading = false;
    }
  }

  sidebarId = input('sidebar1');

  // The template originally included mock arrays for dropdowns.
  // In this CRM build we use real API counts in the user dropdown instead.

  public newStatus = [
    { id: 0, title: 'CPU Usage', value: 25, color: 'info', details: '348 Processes. 1/4 Cores.' },
    { id: 1, title: 'Memory Usage', value: 70, color: 'warning', details: '11444GB/16384MB' },
    { id: 2, title: 'SSD 1 Usage', value: 90, color: 'danger', details: '243GB/256GB' }
  ];

  public newTasks = [
    { id: 0, title: 'Upgrade NPM', value: 0, color: 'info' },
    { id: 1, title: 'ReactJS Version', value: 25, color: 'danger' },
    { id: 2, title: 'VueJS Version', value: 50, color: 'warning' },
    { id: 3, title: 'Add new layouts', value: 75, color: 'info' },
    { id: 4, title: 'Angular Version', value: 100, color: 'success' }
  ];

}
