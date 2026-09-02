<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

type MenuLink = {
  label: string
  routeName: string
  requiresAuth?: boolean
}

type MenuSection = {
  id: string
  label: string
  links: MenuLink[]
}

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const open = ref(false)
const menuRef = ref<HTMLElement | null>(null)

const expandedSections = ref<Record<string, boolean>>({
  account: true,
  orders: false,
})

const sections: MenuSection[] = [
  {
    id: 'account',
    label: 'My account',
    links: [
      { label: 'Profile', routeName: 'account', requiresAuth: true },
      { label: 'Addresses', routeName: 'addresses', requiresAuth: true },
    ],
  },
  {
    id: 'orders',
    label: 'Orders & returns',
    links: [
      { label: 'Orders', routeName: 'orders', requiresAuth: true },
      { label: 'Returns', routeName: 'returns', requiresAuth: true },
    ],
  },
]

const isLoggedIn = computed(() => auth.tokenPresent)
const displayName = computed(() => auth.displayName)

function toggleMenu(event: MouseEvent) {
  event.stopPropagation()
  open.value = !open.value
}

function closeMenu() {
  open.value = false
}

function toggleSection(id: string) {
  expandedSections.value[id] = !expandedSections.value[id]
}

function linkTo(link: MenuLink) {
  if (link.requiresAuth && !auth.tokenPresent) {
    const path = router.resolve({ name: link.routeName }).fullPath
    return { name: 'login' as const, query: { redirect: path } }
  }
  return { name: link.routeName as 'account' | 'addresses' | 'orders' | 'returns' }
}

function onDocumentClick(event: MouseEvent) {
  if (!menuRef.value?.contains(event.target as Node)) {
    closeMenu()
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    closeMenu()
  }
}

async function onLogout() {
  closeMenu()
  await auth.logout()
  await router.replace({ name: 'shop' })
}

watch(open, (isOpen) => {
  if (isOpen && auth.tokenPresent && !auth.account) {
    void auth.fetchMe().catch(() => {
      /* optional header label */
    })
  }
})

watch(() => route.fullPath, closeMenu)

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
  if (auth.tokenPresent && !auth.account) {
    void auth.fetchMe().catch(() => {
      /* optional header label */
    })
  }
})

onUnmounted(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="menuRef" class="relative">
    <button
      type="button"
      class="flex h-10 w-10 items-center justify-center rounded-full border border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] transition hover:border-[var(--brand)] hover:text-[var(--brand)]"
      :class="{ 'border-[var(--brand)] text-[var(--brand)]': open }"
      :aria-expanded="open"
      aria-haspopup="true"
      aria-label="Account menu"
      @click="toggleMenu"
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        class="h-5 w-5"
        aria-hidden="true"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a8.25 8.25 0 0 1 15 0"
        />
      </svg>
    </button>

    <div
      v-if="open"
      class="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] shadow-lg"
      role="menu"
    >
      <template v-if="isLoggedIn">
        <div class="border-b border-[var(--line)] px-4 py-3">
          <p class="truncate text-sm font-semibold text-[var(--ink)]">{{ displayName }}</p>
          <p v-if="auth.account?.email" class="truncate text-xs text-[var(--muted)]">
            {{ auth.account.email }}
          </p>
        </div>

        <div class="py-1">
          <div v-for="section in sections" :key="section.id" class="border-b border-[var(--line)] last:border-b-0">
            <button
              type="button"
              class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm font-medium text-[var(--ink)] hover:bg-[var(--brand-soft)]"
              :aria-expanded="expandedSections[section.id]"
              @click="toggleSection(section.id)"
            >
              <span>{{ section.label }}</span>
              <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 20 20"
                fill="currentColor"
                class="h-4 w-4 text-[var(--muted)] transition-transform"
                :class="{ 'rotate-180': expandedSections[section.id] }"
                aria-hidden="true"
              >
                <path
                  fill-rule="evenodd"
                  d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.25a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z"
                  clip-rule="evenodd"
                />
              </svg>
            </button>
            <div v-show="expandedSections[section.id]" class="pb-1">
              <RouterLink
                v-for="link in section.links"
                :key="link.routeName"
                :to="linkTo(link)"
                class="block px-4 py-2 pl-6 text-sm text-[var(--muted)] no-underline hover:bg-[var(--brand-soft)] hover:text-[var(--ink)]"
                role="menuitem"
                @click="closeMenu"
              >
                {{ link.label }}
              </RouterLink>
            </div>
          </div>
        </div>

        <div class="border-t border-[var(--line)] py-1">
          <button
            type="button"
            class="block w-full px-4 py-2.5 text-left text-sm text-[var(--muted)] hover:bg-[var(--brand-soft)] hover:text-[var(--ink)]"
            role="menuitem"
            @click="onLogout"
          >
            Sign out
          </button>
        </div>
      </template>

      <template v-else>
        <div class="py-1">
          <RouterLink
            :to="{ name: 'login' }"
            class="block px-4 py-2.5 text-sm text-[var(--ink)] no-underline hover:bg-[var(--brand-soft)]"
            role="menuitem"
            @click="closeMenu"
          >
            Sign in
          </RouterLink>
          <RouterLink
            :to="{ name: 'register' }"
            class="block px-4 py-2.5 text-sm text-[var(--muted)] no-underline hover:bg-[var(--brand-soft)] hover:text-[var(--ink)]"
            role="menuitem"
            @click="closeMenu"
          >
            Create account
          </RouterLink>
        </div>
      </template>
    </div>
  </div>
</template>
