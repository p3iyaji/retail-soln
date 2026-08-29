import { createRouter, createWebHistory } from 'vue-router'
import { hasToken } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: '/account',
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
    return { name: 'account' }
  }

  return true
})

export default router
