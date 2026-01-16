import { Component, OnInit, inject } from '@angular/core';
import { RouterLink, RouterOutlet } from '@angular/router';
import { NgScrollbar } from 'ngx-scrollbar';

import {
  ContainerComponent,
  ShadowOnScrollDirective,
  SidebarBrandComponent,
  SidebarComponent,
  SidebarFooterComponent,
  SidebarHeaderComponent,
  SidebarNavComponent,
  SidebarToggleDirective,
  SidebarTogglerDirective
} from '@coreui/angular';

import { DefaultFooterComponent, DefaultHeaderComponent } from './';
import { navItems } from './_nav';
import { AuthService } from '../../core/auth/auth.service';
import { firstValueFrom } from 'rxjs';

function isOverflown(element: HTMLElement) {
  return (
    element.scrollHeight > element.clientHeight ||
    element.scrollWidth > element.clientWidth
  );
}

@Component({
  selector: 'app-dashboard',
  templateUrl: './default-layout.component.html',
  styleUrls: ['./default-layout.component.scss'],
  imports: [
    SidebarComponent,
    SidebarHeaderComponent,
    SidebarBrandComponent,
    SidebarNavComponent,
    SidebarFooterComponent,
    SidebarToggleDirective,
    SidebarTogglerDirective,
    ContainerComponent,
    DefaultFooterComponent,
    DefaultHeaderComponent,
    NgScrollbar,
    RouterOutlet,
    RouterLink,
    ShadowOnScrollDirective
  ]
})
export class DefaultLayoutComponent implements OnInit {
  readonly #auth = inject(AuthService);
  public navItems = [...navItems];

  async ngOnInit(): Promise<void> {
    try {
      const me = await firstValueFrom(this.#auth.me());
      const role = me?.user?.role;
      if (typeof role === 'string' && role.trim().length) {
        this.navItems = navItems.filter((item: any) => {
          if (!item?.roles || !Array.isArray(item.roles)) return true;
          return item.roles.includes(role);
        });
        return;
      }
    } catch {
      // Fall back to unfiltered nav.
    }
    this.navItems = [...navItems];
  }
}
