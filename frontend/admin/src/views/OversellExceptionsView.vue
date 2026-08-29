<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }

type OversellException = {
  id: number
  saleId: number
  saleUuid?: string | null
  storeId: number
  variantId: number
  variantSku?: string | null
  qtyDeficit: string
  status: string
  createdAt?: string | null
}

const auth = useAuthStore()
const canApprove = computed(
  () => auth.user?.permissions?.includes('inventory.approve-oversell') === true,
)

const stores = ref<Store[]>([])
const storeId = ref<number | ''>('')
const rows = ref<OversellException[]>([])
const loading = ref(true)
const error = ref('')
const message = ref('')
const approvingId = ref<number | null>(null)

async function loadStores() {
  const res = await api.get<{ data: Store[] }>('/stores?per_page=50')
  stores.value = res.data
  if (!storeId.value) {
    const preferred = auth.user?.storeAccess.find((s) => s.isDefault)?.id ?? stores.value[0]?.id
    storeId.value = preferred ?? ''
  }
}

async function loadExceptions() {
  if (!canApprove.value || !storeId.value) {
    rows.value = []
    return
  }

  const res = await api.get<{ data: OversellException[] }>(
    '/sync/oversell-exceptions?per_page=50',
    { 'X-Store-Id': String(storeId.value) },
  )
  rows.value = res.data
}

async function refresh() {
  if (!canApprove.value) {
    loading.value = false
    error.value = 'You do not have permission to approve oversell exceptions.'
    return
  }

  loading.value = true
  error.value = ''
  try {
    await loadStores()
    await loadExceptions()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load oversell exceptions'
  } finally {
    loading.value = false
  }
}

async function approve(row: OversellException) {
  if (!storeId.value || approvingId.value != null) return
  approvingId.value = row.id
  message.value = ''
  error.value = ''
  try {
    await api.post(
      `/sync/oversell-exceptions/${row.id}/approve`,
      {},
      { 'X-Store-Id': String(storeId.value) },
    )
    message.value = `Approved exception #${row.id}`
    await loadExceptions()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Approve failed'
  } finally {
    approvingId.value = null
  }
}

watch(storeId, () => {
  if (canApprove.value) {
    void loadExceptions().catch((e) => {
      error.value = e instanceof ApiError ? e.message : 'Failed to load oversell exceptions'
    })
  }
})

onMounted(() => {
  void refresh()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold">Pending oversell exceptions</h2>
        <p class="text-sm text-[var(--muted)]">
          Offline sync oversells awaiting manager approval (AD-9).
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-sm">
          <span class="text-[var(--muted)]">Store</span>
          <select
            v-model="storeId"
            class="mt-1 block min-w-[12rem] rounded-md border border-[var(--line)] px-3 py-2"
            :disabled="!canApprove || loading"
          >
            <option disabled value="">Select store</option>
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-2 text-sm hover:bg-stone-50 disabled:opacity-60"
          :disabled="loading || !canApprove"
          @click="refresh"
        >
          Refresh
        </button>
      </div>
    </div>

    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
      {{ error }}
    </p>
    <p v-if="message" class="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900">
      {{ message }}
    </p>

    <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>

    <div
      v-else-if="canApprove"
      class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]"
    >
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-xs tracking-wide text-[var(--muted)] uppercase">
          <tr>
            <th class="px-3 py-2 font-medium">ID</th>
            <th class="px-3 py-2 font-medium">Sale</th>
            <th class="px-3 py-2 font-medium">Variant</th>
            <th class="px-3 py-2 font-medium">Qty deficit</th>
            <th class="px-3 py-2 font-medium">Created</th>
            <th class="px-3 py-2 font-medium">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0">
            <td colspan="6" class="px-3 py-4 text-[var(--muted)]">No pending oversell exceptions.</td>
          </tr>
          <tr
            v-for="row in rows"
            :key="row.id"
            class="border-b border-[var(--line)] last:border-b-0"
          >
            <td class="px-3 py-2">{{ row.id }}</td>
            <td class="px-3 py-2">
              #{{ row.saleId }}
              <span v-if="row.saleUuid" class="block text-xs text-[var(--muted)]">{{ row.saleUuid }}</span>
            </td>
            <td class="px-3 py-2">{{ row.variantSku ?? `#${row.variantId}` }}</td>
            <td class="px-3 py-2 font-medium text-[var(--ember)]">{{ row.qtyDeficit }}</td>
            <td class="px-3 py-2 text-[var(--muted)]">{{ row.createdAt ?? '—' }}</td>
            <td class="px-3 py-2">
              <button
                type="button"
                class="rounded-md border border-[var(--brand)] bg-teal-50 px-3 py-1.5 text-sm font-medium text-[var(--brand-ink)] hover:bg-teal-100 disabled:opacity-60"
                :disabled="approvingId === row.id"
                @click="approve(row)"
              >
                {{ approvingId === row.id ? 'Approving…' : 'Approve' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
