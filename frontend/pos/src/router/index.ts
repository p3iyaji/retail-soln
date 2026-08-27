import { createRouter, createWebHistory } from 'vue-router'
import { hasToken } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { usePosStore } from '@/stores/pos'

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
      path: '/select-store',
      name: 'select-store',
      component: () => import('@/views/StorePickerView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/',
      name: 'floor',
      component: () => import('@/views/PosFloorView.vue'),
      meta: { requiresAuth: true, requiresStore: true },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

router.beforeEach(async (to) => {
  const authed = hasToken()

  if (to.meta.requiresAuth && !authed) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guest && authed) {
    const pos = usePosStore()
    return pos.hasSelectedStore ? { name: 'floor' } : { name: 'select-store' }
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

    const pos = usePosStore()
    pos.syncSelectedStoreWithAccess()

    if (to.meta.requiresStore && !pos.hasSelectedStore) {
      return { name: 'select-store' }
    }

    if (to.name === 'select-store' && pos.hasSelectedStore) {
      return { name: 'floor' }
    }
  }

  return true
})

export default router
