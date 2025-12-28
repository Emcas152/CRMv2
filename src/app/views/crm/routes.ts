import { Routes } from '@angular/router';

import { requireRoles } from '../../core/auth/role.guard';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./crm-home/crm-home.component').then(m => m.CrmHomeComponent),
    data: { title: 'CRM' }
  },
  {
    path: 'patients',
    loadComponent: () => import('./patients/patients-page.component').then(m => m.PatientsPageComponent),
    data: { title: 'Pacientes' }
  },
  {
    path: 'products',
    loadComponent: () => import('./products/products-page.component').then(m => m.ProductsPageComponent),
    data: { title: 'Productos' }
  },
  {
    path: 'documents',
    loadComponent: () => import('./documents/documents-page.component').then(m => m.DocumentsPageComponent),
    data: { title: 'Documentos' }
  },
  {
    path: 'appointments',
    loadComponent: () => import('./appointments/appointments-page.component').then(m => m.AppointmentsPageComponent),
    data: { title: 'Citas' }
  },
  {
    path: 'sales',
    loadComponent: () => import('./sales/sales-page.component').then(m => m.SalesPageComponent),
    data: { title: 'Ventas' }
  },
  {
    path: 'users',
    loadComponent: () => import('./users/users-page.component').then(m => m.UsersPageComponent),
    canMatch: [requireRoles(['superadmin', 'admin'])],
    data: { title: 'Usuarios', roles: ['superadmin', 'admin'] }
  },
  {
    path: 'email-templates',
    loadComponent: () => import('./email-templates/email-templates-page.component').then(m => m.EmailTemplatesPageComponent),
    canMatch: [requireRoles(['superadmin', 'admin'])],
    data: { title: 'Plantillas de correo', roles: ['superadmin', 'admin'] }
  },
  {
    path: 'profile',
    loadComponent: () => import('./profile/profile-page.component').then(m => m.ProfilePageComponent),
    data: { title: 'Perfil' }
  },
  {
    path: 'qr',
    loadComponent: () => import('./qr/qr-page.component').then(m => m.QrPageComponent),
    data: { title: 'QR' }
  }
];
