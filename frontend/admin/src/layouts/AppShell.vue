<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { allNavItems, navGroups, personalNavItems, type NavGroup, type NavItem } from '@/config/navigation'
import { userHasPermission } from '@/lib/rbac'
import { useAppearanceStore } from '@/stores/appearance'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const appearance = useAppearanceStore()
const route = useRoute()
const router = useRouter()

function canSeeItem(item: NavItem): boolean {
  if (!item.permission) return true
  return userHasPermission(auth.user, item.permission)
}

const visibleNavGroups = computed(() =>
  navGroups
    .map((group) => ({
      ...group,
      items: group.items.filter(canSeeItem),
    }))
    .filter((group) => group.items.length > 0),
)

const title = computed(() => {
  if (route.name === 'dashboard') return 'Dashboard'
  const personal = personalNavItems.find((item) => item.name === route.name)
  if (personal) return personal.label
  const match = allNavItems.find((item) => item.name === route.name)
  return match?.label ?? 'Admin'
})

const loggingOut = ref(false)
const mobileNavOpen = ref(false)

function isActive(name: string): boolean {
  return route.name === name
}

function groupHasActive(group: NavGroup): boolean {
  return group.items.some((item) => isActive(item.name))
}

function isGroupOpen(groupId: string): boolean {
  return appearance.isNavGroupOpen(groupId)
}

function navLinkClass(active: boolean): string {
  return active
    ? 'bg-[var(--sidebar-active-bg)] font-medium text-[var(--sidebar-active-text)]'
    : 'text-[var(--sidebar-text)] hover:bg-[var(--sidebar-hover-bg)] hover:text-[var(--sidebar-heading)]'
}

function groupButtonClass(group: NavGroup): string {
  const active = groupHasActive(group)
  return active
    ? 'text-[var(--sidebar-active-text)]'
    : 'text-[var(--sidebar-text)] hover:bg-[var(--sidebar-hover-bg)] hover:text-[var(--sidebar-heading)]'
}

function toggleGroup(groupId: string): void {
  appearance.toggleNavGroup(groupId)
}

function syncOpenGroupsForRoute(): void {
  visibleNavGroups.value.forEach((group) => {
    if (groupHasActive(group)) {
      appearance.ensureNavGroupOpen(group.id)
    }
  })
}

watch(
  () => route.name,
  () => {
    syncOpenGroupsForRoute()
  },
  { immediate: true },
)

watch(visibleNavGroups, () => {
  syncOpenGroupsForRoute()
})

onMounted(() => {
  appearance.ensureNavGroupOpen('operations')
})

async function onLogout() {
  loggingOut.value = true
  try {
    await auth.logout()
    useAppearanceStore().load()
    await router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
  }
}

function closeMobileNav() {
  mobileNavOpen.value = false
}
</script>

<template>
  <div class="min-h-screen bg-[var(--surface)] lg:grid lg:grid-cols-[17rem_1fr]">
    <div
      class="flex items-center justify-between border-b border-[var(--sidebar-border)] bg-[var(--sidebar-bg)] px-4 py-3 lg:hidden"
    >
      <div>
        <img src="/logo.png" alt="Bag & Cartons Ltd" class="h-8 w-auto object-contain" />
        <p class="text-sm font-medium text-[var(--sidebar-heading)]">Admin</p>
      </div>
      <button
        type="button"
        class="rounded-lg border border-[var(--sidebar-border)] px-3 py-1.5 text-sm text-[var(--sidebar-text)] hover:bg-[var(--sidebar-hover-bg)]"
        :aria-expanded="mobileNavOpen"
        aria-controls="admin-sidebar"
        @click="mobileNavOpen = !mobileNavOpen"
      >
        {{ mobileNavOpen ? 'Close' : 'Menu' }}
      </button>
    </div>

    <aside
      id="admin-sidebar"
      class="border-b border-[var(--sidebar-border)] bg-[var(--sidebar-bg)] lg:sticky lg:top-0 lg:flex lg:max-h-screen lg:flex-col lg:border-b-0 lg:border-r"
      :class="mobileNavOpen ? 'block' : 'hidden lg:flex'"
    >
      <div class="hidden px-5 pt-6 pb-4 lg:block">
        <div class="flex items-center gap-3">
          <img src="/logo.png" alt="Bag & Cartons Ltd" class="h-10 w-auto shrink-0 object-contain" />
          <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-[var(--sidebar-heading)]">Admin console</p>
            <p class="text-xs text-[var(--sidebar-muted)]">Bag & Cartons Ltd</p>
          </div>
        </div>
      </div>

      <nav class="flex-1 space-y-2 overflow-y-auto px-3 py-4 lg:px-3 lg:pb-6" aria-label="Main navigation">
        <div>
          <RouterLink
            to="/"
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition"
            :class="navLinkClass(isActive('dashboard'))"
            :aria-current="isActive('dashboard') ? 'page' : undefined"
            @click="closeMobileNav"
          >
            <span
              class="inline-block h-1.5 w-1.5 rounded-full"
              :class="isActive('dashboard') ? 'bg-[var(--sidebar-accent)]' : 'bg-[var(--sidebar-muted)]'"
            />
            Dashboard
          </RouterLink>
          <RouterLink
            v-for="item in personalNavItems"
            :key="item.name"
            :to="item.to"
            class="mt-0.5 flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition"
            :class="navLinkClass(isActive(item.name))"
            :aria-current="isActive(item.name) ? 'page' : undefined"
            @click="closeMobileNav"
          >
            <span
              class="inline-block h-1.5 w-1.5 rounded-full"
              :class="isActive(item.name) ? 'bg-[var(--sidebar-accent)]' : 'bg-[var(--sidebar-muted)]'"
            />
            {{ item.label }}
          </RouterLink>
        </div>

        <div v-for="group in visibleNavGroups" :key="group.id" class="space-y-1">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition"
            :class="groupButtonClass(group)"
            :aria-expanded="isGroupOpen(group.id)"
            :aria-controls="`nav-group-${group.id}`"
            @click="toggleGroup(group.id)"
          >
            <span class="truncate">{{ group.label }}</span>
            <svg
              class="h-4 w-4 shrink-0 transition-transform"
              :class="isGroupOpen(group.id) ? 'rotate-180' : ''"
              viewBox="0 0 20 20"
              fill="currentColor"
              aria-hidden="true"
            >
              <path
                fill-rule="evenodd"
                d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z"
                clip-rule="evenodd"
              />
            </svg>
          </button>

          <div
            v-show="isGroupOpen(group.id)"
            :id="`nav-group-${group.id}`"
            class="space-y-0.5 border-l border-[var(--sidebar-border)] pl-2"
            role="group"
            :aria-label="group.label"
          >
            <RouterLink
              v-for="item in group.items"
              :key="item.name"
              :to="item.to"
              class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition"
              :class="navLinkClass(isActive(item.name))"
              :aria-current="isActive(item.name) ? 'page' : undefined"
              @click="closeMobileNav"
            >
              <span
                class="inline-block h-1.5 w-1.5 rounded-full"
                :class="isActive(item.name) ? 'bg-[var(--sidebar-accent)]' : 'bg-[var(--sidebar-muted)]/60'"
              />
              {{ item.label }}
            </RouterLink>
          </div>
        </div>
      </nav>

      <div class="hidden border-t border-[var(--sidebar-border)] px-4 py-4 lg:block">
        <p class="truncate text-sm font-medium text-[var(--sidebar-heading)]">{{ auth.displayName }}</p>
        <p class="truncate text-xs text-[var(--sidebar-muted)]">{{ auth.user?.role ?? 'Signed in' }}</p>
      </div>
    </aside>

    <div class="flex min-h-screen min-w-0 flex-col">
      <header
        class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-[var(--line)] bg-[var(--panel)]/95 px-5 py-3 backdrop-blur supports-[backdrop-filter]:bg-[var(--panel)]/80"
      >
        <div class="min-w-0">
          <h1 class="truncate text-lg font-semibold tracking-tight text-[var(--ink)]">{{ title }}</h1>
          <p class="truncate text-xs text-[var(--muted)] lg:hidden">{{ auth.displayName }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-3">
          <RouterLink
            to="/appearance"
            class="hidden rounded-lg border border-[var(--line)] bg-[var(--panel)] px-3 py-1.5 text-sm font-medium text-[var(--ink)] shadow-[var(--shadow-sm)] transition hover:border-[var(--brand)]/30 hover:bg-[var(--brand-soft)] sm:inline"
            :class="isActive('appearance') ? 'border-[var(--brand)]/40 bg-[var(--brand-soft)] text-[var(--brand-ink)]' : ''"
          >
            Appearance
          </RouterLink>
          <span class="hidden text-sm text-[var(--muted)] sm:inline">{{ auth.displayName }}</span>
          <button
            type="button"
            class="rounded-lg border border-[var(--line)] bg-[var(--panel)] px-3 py-1.5 text-sm font-medium text-[var(--ink)] shadow-[var(--shadow-sm)] transition hover:border-[var(--brand)]/30 hover:bg-[var(--brand-soft)] disabled:opacity-60"
            :disabled="loggingOut"
            @click="onLogout"
          >
            Log out
          </button>
        </div>
      </header>

      <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
