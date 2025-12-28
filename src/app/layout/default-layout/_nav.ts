import { INavData } from '@coreui/angular';

export const navItems: INavData[] = [
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
    iconComponent: { name: 'cil-people' }
  },
  {
    name: 'Citas',
    url: '/crm/appointments',
    iconComponent: { name: 'cil-calendar' }
  },
  {
    name: 'Ventas',
    url: '/crm/sales',
    iconComponent: { name: 'cil-cart' }
  },
  {
    name: 'Documentos',
    url: '/crm/documents',
    iconComponent: { name: 'cil-description' }
  },
  {
    name: 'Productos',
    url: '/crm/products',
    iconComponent: { name: 'cil-tags' }
  },
  {
    name: 'Usuarios',
    url: '/crm/users',
    iconComponent: { name: 'cil-user' }
  },
  {
    name: 'Plantillas email',
    url: '/crm/email-templates',
    iconComponent: { name: 'cil-envelope-letter' }
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
