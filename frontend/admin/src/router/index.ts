import { createRouter, createWebHistory } from 'vue-router'
import { hasToken } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/',
      component: () => import('@/layouts/AppShell.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
        },
        {
          path: 'organization',
          name: 'organization',
          component: () => import('@/views/stubs/OrganizationView.vue'),
        },
        {
          path: 'stores',
          name: 'stores',
          component: () => import('@/views/stubs/StoresView.vue'),
        },
        {
          path: 'users',
          name: 'users',
          component: () => import('@/views/stubs/UsersView.vue'),
        },
        {
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/stubs/SettingsView.vue'),
        },
        {
          path: 'products',
          name: 'products',
          component: () => import('@/views/ProductsView.vue'),
        },
        {
          path: 'suppliers',
          name: 'suppliers',
          component: () => import('@/views/SuppliersView.vue'),
        },
        {
          path: 'purchase-orders',
          name: 'purchase-orders',
          component: () => import('@/views/PurchaseOrdersView.vue'),
        },
        {
          path: 'payments',
          name: 'payments',
          component: () => import('@/views/PaymentsView.vue'),
        },
        {
          path: 'inventory',
          name: 'inventory',
          component: () => import('@/views/InventoryView.vue'),
        },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  const authed = hasToken()

  if (to.meta.requiresAuth && !authed) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guest && authed) {
    return { name: 'dashboard' }
  }

  if (to.meta.requiresAuth && authed) {
    const auth = useAuthStore()
    if (!auth.user) {
      try {
        await auth.fetchMe()
      } catch {
        await auth.logout()
        return { name: 'login' }
      }
    }
  }

  return true
})

export default router
