export type NavItem = {
  name: string
  label: string
  to: string
  permission?: string | string[]
}

export type NavGroup = {
  id: string
  label: string
  items: NavItem[]
}

export const navGroups: NavGroup[] = [
  {
    id: 'insights',
    label: 'Insights',
    items: [
      { name: 'reports', label: 'Reports', to: '/reports', permission: 'reports.view' },
      {
        name: 'notifications',
        label: 'Notifications',
        to: '/notifications',
        permission: 'notifications.view',
      },
    ],
  },
  {
    id: 'catalog',
    label: 'Catalog & stock',
    items: [
      { name: 'products', label: 'Products', to: '/products' },
      {
        name: 'catalog-taxonomy',
        label: 'Categories & brands',
        to: '/catalog',
        permission: ['catalog.view', 'products.view'],
      },
      { name: 'pricing', label: 'Pricing', to: '/pricing' },
      { name: 'inventory', label: 'Inventory', to: '/inventory' },
      {
        name: 'stock-report',
        label: 'Stock report',
        to: '/reports?tab=inventory',
        permission: 'reports.view',
      },
      { name: 'oversell-exceptions', label: 'Oversells', to: '/oversell-exceptions' },
    ],
  },
  {
    id: 'procurement',
    label: 'Procurement',
    items: [
      { name: 'suppliers', label: 'Suppliers', to: '/suppliers' },
      { name: 'purchase-orders', label: 'Purchase orders', to: '/purchase-orders' },
      { name: 'payments', label: 'Payments', to: '/payments' },
    ],
  },
  {
    id: 'sales',
    label: 'Sales & CRM',
    items: [
      { name: 'customers', label: 'Customers', to: '/customers' },
      { name: 'proforma-invoices', label: 'Proforma invoices', to: '/proforma-invoices' },
    ],
  },
  {
    id: 'operations',
    label: 'Operations',
    items: [
      {
        name: 'fulfilment',
        label: 'Fulfilment',
        to: '/fulfilment',
        permission: ['fulfilment.pick', 'fulfilment.ship', 'fulfilment.pickup'],
      },
      {
        name: 'return-requests',
        label: 'Return requests',
        to: '/return-requests',
        permission: ['fulfilment.pick', 'fulfilment.ship', 'fulfilment.pickup'],
      },
      {
        name: 'carriers',
        label: 'Carriers',
        to: '/carriers',
        permission: 'carriers.view',
      },
      { name: 'devices', label: 'Devices', to: '/devices' },
    ],
  },
  {
    id: 'workforce',
    label: 'Workforce',
    items: [
      {
        name: 'attendance',
        label: 'Time clock',
        to: '/attendance',
        permission: ['attendance.manage', 'attendance.clock'],
      },
    ],
  },
  {
    id: 'organization',
    label: 'Organization',
    items: [
      { name: 'organization', label: 'Organization', to: '/organization' },
      { name: 'stores', label: 'Stores', to: '/stores' },
      { name: 'users', label: 'Users', to: '/users' },
      {
        name: 'roles',
        label: 'Roles & permissions',
        to: '/roles',
        permission: 'roles.view',
      },
    ],
  },
  {
    id: 'system',
    label: 'System',
    items: [
      { name: 'audit-logs', label: 'Audit logs', to: '/audit-logs' },
      { name: 'settings', label: 'Settings', to: '/settings' },
    ],
  },
]

export const allNavItems = navGroups.flatMap((group) => group.items)

/** Always visible — personal preferences, no permission required. */
export const personalNavItems: NavItem[] = [
  { name: 'appearance', label: 'Appearance', to: '/appearance' },
]
