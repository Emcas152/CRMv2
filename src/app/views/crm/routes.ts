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
    canMatch: [requireRoles(['superadmin', 'admin', 'doctor', 'staff'])],
    data: { title: 'Pacientes' }
  },
  {
    path: 'products',
    loadComponent: () => import('./products/products-page.component').then(m => m.ProductsPageComponent),
    canMatch: [requireRoles(['superadmin', 'admin', 'staff'])],
    data: { title: 'Productos' }
  },
  {
    path: 'documents',
    loadComponent: () => import('./documents/documents-page.component').then(m => m.DocumentsPageComponent),
    canMatch: [requireRoles(['superadmin', 'admin', 'doctor', 'staff', 'patient'])],
    data: { title: 'Documentos' }
  },
  {
    path: 'appointments',
    loadComponent: () => import('./appointments/appointments-page.component').then(m => m.AppointmentsPageComponent),
    canMatch: [requireRoles(['superadmin', 'admin', 'doctor', 'staff', 'patient'])],
    data: { title: 'Citas' }
  },
  {
    path: 'welcome',
    loadComponent: () => import('./patient-welcome/patient-welcome.component').then(m => m.default),
    canMatch: [requireRoles(['patient'])],
    data: { title: 'Bienvenido' }
  },
  {
    path: 'sales',
    loadComponent: () => import('./sales/sales-page.component').then(m => m.SalesPageComponent),
    canMatch: [requireRoles(['superadmin', 'admin', 'doctor', 'staff'])],
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
    path: 'updates',
    loadComponent: () => import('./updates/updates-page.component').then(m => m.UpdatesPageComponent),
    canMatch: [requireRoles(['superadmin', 'admin'])],
    data: { title: 'Actualizaciones' }
  },
  {
    path: 'conversations',
    loadComponent: () => import('./conversations/conversations-page.component').then(m => m.ConversationsPageComponent),
    canMatch: [requireRoles(['superadmin', 'doctor', 'patient'])],
    data: { title: 'Conversaciones' }
  },
  {
    path: 'tasks',
    loadComponent: () => import('./tasks/tasks-page.component').then(m => m.TasksPageComponent),
    canMatch: [requireRoles(['superadmin', 'admin'])],
    data: { title: 'Tareas' }
  },
  {
    path: 'comments',
    loadComponent: () => import('./comments/comments-page.component').then(m => m.CommentsPageComponent),
    canMatch: [requireRoles(['superadmin', 'admin'])],
    data: { title: 'Comentarios' }
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
