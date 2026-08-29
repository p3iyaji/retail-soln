import { createRouter, createWebHistory } from 'vue-router'
import { hasToken } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: '/shop',
    },
    {
      path: '/shop',
      component: () => import('@/components/ShopLayout.vue'),
      children: [
        {
          path: '',
          name: 'shop',
          component: () => import('@/views/CatalogueView.vue'),
        },
        {
          path: 'products/:id',
          name: 'shop-product',
          component: () => import('@/views/ProductDetailView.vue'),
        },
        {
          path: 'cart',
          name: 'cart',
          component: () => import('@/views/CartView.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'checkout',
          name: 'checkout',
          component: () => import('@/views/CheckoutView.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'confirmation',
          name: 'confirmation',
          component: () => import('@/views/ConfirmationView.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'orders',
          name: 'orders',
          component: () => import('@/views/OrdersListView.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'orders/:id',
          name: 'order-detail',
          component: () => import('@/views/OrderDetailView.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'orders/:id/return',
          name: 'order-return',
          component: () => import('@/views/ReturnRequestView.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'returns',
          name: 'returns',
          component: () => import('@/views/ReturnsListView.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'returns/:id',
          name: 'return-detail',
          component: () => import('@/views/ReturnDetailView.vue'),
          meta: { requiresAuth: true },
        },
      ],
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { guest: true },
    },
    {
      path: '/verify',
      name: 'verify',
      component: () => import('@/views/VerifyView.vue'),
      meta: { guest: true },
    },
    {
      path: '/account',
      name: 'account',
      component: () => import('@/views/AccountView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/addresses',
      name: 'addresses',
      component: () => import('@/views/AddressesView.vue'),
      meta: { requiresAuth: true },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth) {
    if (!hasToken()) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
    if (!auth.account) {
      try {
        await auth.fetchMe()
      } catch {
        await auth.logout()
        return { name: 'login', query: { redirect: to.fullPath } }
      }
    }
    if (!auth.account?.emailVerified) {
      return { name: 'verify' }
    }
  }

  if (to.meta.guest && hasToken() && auth.account?.emailVerified) {
    return { name: 'shop' }
  }

  return true
})

export default router
