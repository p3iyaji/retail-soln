<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type AuditLog = {
  id: number
  storeId: number | null
  userId: number | null
  action: string
  auditableType: string
  auditableId: number
  createdAt: string | null
}

type ListMeta = { currentPage: number; lastPage: number; total: number }

const auth = useAuthStore()
const canView = computed(() => auth.user?.permissions?.includes('audit.view') === true)

const logs = ref<AuditLog[]>([])
const meta = ref<ListMeta>({ currentPage: 1, lastPage: 1, total: 0 })
const page = ref(1)
const loading = ref(true)
const error = ref('')

async function loadLogs(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get<{ data: AuditLog[]; meta: ListMeta }>(`/audit-logs?page=${page.value}&per_page=25`)
    logs.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load audit logs'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (canView.value) void loadLogs()
  else loading.value = false
})
</script>

<template>
  <div class="space-y-4">
    <div v-if="!canView" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      You do not have permission to view audit logs.
    </div>
    <template v-else>
      <div>
        <h2 class="text-xl font-semibold">Audit logs</h2>
        <p class="text-sm text-[var(--muted)]">Immutable trail of create, update, and delete actions.</p>
      </div>
      <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ error }}</p>
      <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>
      <div v-else class="overflow-x-auto rounded-lg border border-[var(--line)]">
        <table class="min-w-full text-sm">
          <thead class="bg-stone-50 text-left text-[var(--muted)]">
            <tr>
              <th class="px-3 py-2">When</th>
              <th class="px-3 py-2">Action</th>
              <th class="px-3 py-2">Type</th>
              <th class="px-3 py-2">Record</th>
              <th class="px-3 py-2">Store</th>
              <th class="px-3 py-2">User</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in logs" :key="log.id" class="border-t border-[var(--line)]">
              <td class="px-3 py-2 text-xs text-[var(--muted)]">{{ log.createdAt ?? '—' }}</td>
              <td class="px-3 py-2">{{ log.action }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ log.auditableType }}</td>
              <td class="px-3 py-2 font-mono text-xs">#{{ log.auditableId }}</td>
              <td class="px-3 py-2">{{ log.storeId ?? '—' }}</td>
              <td class="px-3 py-2">{{ log.userId ?? '—' }}</td>
            </tr>
            <tr v-if="logs.length === 0">
              <td colspan="6" class="px-3 py-6 text-center text-[var(--muted)]">No audit entries yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="meta.lastPage > 1" class="flex items-center gap-2 text-sm">
        <button type="button" class="rounded border px-2 py-1 disabled:opacity-50" :disabled="page <= 1" @click="page--; loadLogs()">Prev</button>
        <span>Page {{ meta.currentPage }} / {{ meta.lastPage }}</span>
        <button type="button" class="rounded border px-2 py-1 disabled:opacity-50" :disabled="page >= meta.lastPage" @click="page++; loadLogs()">Next</button>
      </div>
    </template>
  </div>
</template>
