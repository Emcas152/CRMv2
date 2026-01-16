import { INavData } from '@coreui/angular';

export interface NavItemWithRoles extends INavData {
  roles?: string[];
}

export const navItems: NavItemWithRoles[] = [
  {
    title: true,
    name: 'CRM'
  },
  {
    name: 'Inicio',
    url: '/crm',
    iconComponent: { name: 'cil-home' }
  },
  {
    name: 'Pacientes',
    url: '/crm/patients',
    iconComponent: { name: 'cil-people' },
    roles: ['superadmin', 'admin', 'doctor', 'staff']
  },
  {
    name: 'Citas',
    url: '/crm/appointments',
    iconComponent: { name: 'cil-calendar' },
    roles: ['superadmin', 'admin', 'doctor', 'staff']
  },
  {
    name: 'Ventas',
    url: '/crm/sales',
    iconComponent: { name: 'cil-cart' },
    roles: ['superadmin', 'admin', 'doctor', 'staff']
  },
  {
    name: 'Documentos',
    url: '/crm/documents',
    iconComponent: { name: 'cil-description' },
    roles: ['superadmin', 'admin', 'doctor', 'staff', 'patient']
  },
  {
    name: 'Productos',
    url: '/crm/products',
    iconComponent: { name: 'cil-tags' },
    roles: ['superadmin', 'admin', 'staff']
  },
  {
    name: 'Usuarios',
    url: '/crm/users',
    iconComponent: { name: 'cil-user' },
    roles: ['superadmin', 'admin']
  },
  {
    name: 'Plantillas email',
    url: '/crm/email-templates',
    iconComponent: { name: 'cil-envelope-letter' },
    roles: ['superadmin', 'admin']
  },
  {
    name: 'Actualizaciones',
    url: '/crm/updates',
    iconComponent: { name: 'cil-notes' },
    roles: ['superadmin', 'admin']
  },
  {
    name: 'Conversaciones',
    url: '/crm/conversations',
    iconComponent: { name: 'cil-speech' },
    roles: ['superadmin', 'doctor', 'patient']
  },
  {
    name: 'Tareas',
    url: '/crm/tasks',
    iconComponent: { name: 'cil-task' },
    roles: ['superadmin', 'admin']
  },
  {
    name: 'Comentarios',
    url: '/crm/comments',
    iconComponent: { name: 'cil-comment-square' },
    roles: ['superadmin', 'admin']
  },
  {
    name: 'Perfil',
    url: '/crm/profile',
    iconComponent: { name: 'cil-settings' }
  },
  {
    name: 'QR',
    url: '/crm/qr',
    iconComponent: { name: 'cil-qr-code' }
  }
];
