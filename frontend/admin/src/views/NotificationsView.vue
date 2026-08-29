<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Notification = {
  id: number
  type: string
  subject: string | null
  body: string | null
  payload: Record<string, string | number | boolean | null> | null
  storeId: number | null
  readAt: string | null
  createdAt: string | null
}

type ListMeta = {
  currentPage: number
  lastPage: number
  perPage: number
  total: number
  unreadCount: number
}

const auth = useAuthStore()
const canView = computed(() => auth.user?.permissions?.includes('notifications.view') === true)

const notifications = ref<Notification[]>([])
const meta = ref<ListMeta>({ currentPage: 1, lastPage: 1, perPage: 25, total: 0, unreadCount: 0 })
const page = ref(1)
const filter = ref<'all' | 'unread'>('all')
const loading = ref(true)
const error = ref('')
const marking = ref<number | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get<{ data: Notification[]; meta: ListMeta }>(
      `/notifications?page=${page.value}&per_page=25&status=${filter.value}`,
    )
    notifications.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load notifications'
  } finally {
    loading.value = false
  }
}

async function markRead(notification: Notification): Promise<void> {
  if (notification.readAt) return
  marking.value = notification.id
  error.value = ''
  try {
    const res = await api.patch<{ data: Notification }>(`/notifications/${notification.id}/read`)
    notification.readAt = res.data.readAt
    meta.value.unreadCount = Math.max(0, meta.value.unreadCount - 1)
    if (filter.value === 'unread') await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to mark as read'
  } finally {
    marking.value = null
  }
}

function setFilter(next: 'all' | 'unread'): void {
  if (filter.value === next) return
  filter.value = next
  page.value = 1
  void load()
}

function changePage(delta: number): void {
  page.value += delta
  void load()
}

function payloadEntries(notification: Notification): Array<[string, string]> {
  if (!notification.payload) return []
  return Object.entries(notification.payload)
    .filter(([, value]) => value !== null && value !== '')
    .map(([key, value]) => [key, String(value)])
}

onMounted(() => {
  if (canView.value) void load()
  else loading.value = false
})
</script>

<template>
  <div class="space-y-4">
    <div v-if="!canView" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      You do not have permission to view notifications.
    </div>
    <template v-else>
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="text-xl font-semibold">Notifications</h2>
          <p class="text-sm text-[var(--muted)]">
            Operational alerts addressed to you. {{ meta.unreadCount }} unread.
          </p>
        </div>
        <div class="flex gap-1 rounded-md border border-[var(--line)] p-1 text-sm">
          <button
            type="button"
            class="rounded px-3 py-1"
            :class="filter === 'all' ? 'bg-teal-50 font-medium text-[var(--brand-ink)]' : 'text-[var(--muted)]'"
            @click="setFilter('all')"
          >
            All
          </button>
          <button
            type="button"
            class="rounded px-3 py-1"
            :class="filter === 'unread' ? 'bg-teal-50 font-medium text-[var(--brand-ink)]' : 'text-[var(--muted)]'"
            @click="setFilter('unread')"
          >
            Unread
          </button>
        </div>
      </div>

      <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ error }}</p>

      <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>

      <div v-else-if="notifications.length === 0" class="rounded-lg border border-[var(--line)] px-4 py-10 text-center text-sm text-[var(--muted)]">
        Nothing here yet.
      </div>

      <ul v-else class="space-y-2">
        <li
          v-for="notification in notifications"
          :key="notification.id"
          class="rounded-lg border px-4 py-3"
          :class="notification.readAt ? 'border-[var(--line)]' : 'border-amber-200 bg-amber-50/60'"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium">{{ notification.subject ?? notification.type }}</p>
              <p class="mt-1 text-sm text-[var(--muted)]">{{ notification.body }}</p>
              <dl v-if="payloadEntries(notification).length" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--muted)]">
                <div v-for="[label, value] in payloadEntries(notification)" :key="label" class="flex gap-1">
                  <dt class="font-medium">{{ label }}:</dt>
                  <dd>{{ value }}</dd>
                </div>
              </dl>
              <p class="mt-2 font-mono text-xs text-[var(--muted)]">
                {{ notification.type }} · {{ notification.createdAt ?? '—' }}
              </p>
            </div>
            <button
              v-if="!notification.readAt"
              type="button"
              class="shrink-0 rounded-md border border-[var(--line)] bg-white px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-60"
              :disabled="marking === notification.id"
              @click="markRead(notification)"
            >
              Mark read
            </button>
            <span v-else class="shrink-0 text-xs text-[var(--muted)]">Read</span>
          </div>
        </li>
      </ul>

      <div v-if="meta.lastPage > 1" class="flex items-center gap-2 text-sm">
        <button type="button" class="rounded border px-2 py-1 disabled:opacity-50" :disabled="page <= 1" @click="changePage(-1)">Prev</button>
        <span>Page {{ meta.currentPage }} / {{ meta.lastPage }}</span>
        <button type="button" class="rounded border px-2 py-1 disabled:opacity-50" :disabled="page >= meta.lastPage" @click="changePage(1)">Next</button>
      </div>
    </template>
  </div>
</template>
