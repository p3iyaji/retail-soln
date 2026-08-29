<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }

type Device = {
  id: number
  storeId: number
  uuid: string
  name: string | null
  enabled: boolean
  lastSeenAt?: string | null
  createdAt?: string | null
}

const auth = useAuthStore()
const canManage = computed(() => auth.user?.permissions?.includes('devices.manage') === true)

const stores = ref<Store[]>([])
const storeId = ref<number | ''>('')
const rows = ref<Device[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const togglingId = ref<number | null>(null)
const renamingId = ref<number | null>(null)

const form = reactive({
  uuid: '',
  name: '',
})

const renameDraft = reactive<Record<number, string>>({})
let loadGeneration = 0

async function loadStores() {
  const res = await api.get<{ data: Store[] }>('/stores?per_page=50')
  stores.value = res.data
  if (!storeId.value) {
    const preferred = auth.user?.storeAccess.find((s) => s.isDefault)?.id ?? stores.value[0]?.id
    storeId.value = preferred ?? ''
  }
}

async function loadDevices() {
  const gen = ++loadGeneration
  const forStore = storeId.value

  if (!canManage.value || !forStore) {
    if (gen === loadGeneration) {
      rows.value = []
    }
    return
  }

  const res = await api.get<{ data: Device[] }>('/devices?per_page=50', {
    'X-Store-Id': String(forStore),
  })

  if (gen !== loadGeneration || storeId.value !== forStore) {
    return
  }

  rows.value = res.data
  for (const row of rows.value) {
    renameDraft[row.id] = row.name ?? ''
  }
}

async function refresh() {
  if (!canManage.value) {
    loading.value = false
    error.value = 'You do not have permission to manage devices.'
    return
  }

  loading.value = true
  error.value = ''
  try {
    await loadStores()
    await loadDevices()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load devices'
  } finally {
    loading.value = false
  }
}

async function registerDevice() {
  if (!storeId.value || saving.value) return
  saving.value = true
  message.value = ''
  error.value = ''
  try {
    await api.post(
      '/devices',
      { uuid: form.uuid.trim(), name: form.name.trim() },
      { 'X-Store-Id': String(storeId.value) },
    )
    message.value = 'Device registered'
    form.uuid = ''
    form.name = ''
    await loadDevices()
  } catch (e) {
    if (e instanceof ApiError && e.body.errors) {
      error.value = Object.values(e.body.errors).flat()[0] ?? e.message
    } else {
      error.value = e instanceof ApiError ? e.message : 'Register failed'
    }
  } finally {
    saving.value = false
  }
}

async function rename(row: Device) {
  if (!storeId.value || renamingId.value != null) return
  const name = (renameDraft[row.id] ?? '').trim()
  if (!name || name === (row.name ?? '')) return

  renamingId.value = row.id
  message.value = ''
  error.value = ''
  try {
    await api.patch(
      `/devices/${row.id}`,
      { name },
      { 'X-Store-Id': String(storeId.value) },
    )
    message.value = `Renamed device #${row.id}`
    await loadDevices()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Rename failed'
  } finally {
    renamingId.value = null
  }
}

async function setEnabled(row: Device, enabled: boolean) {
  if (!storeId.value || togglingId.value != null) return
  togglingId.value = row.id
  message.value = ''
  error.value = ''
  try {
    await api.patch(
      `/devices/${row.id}`,
      { enabled },
      { 'X-Store-Id': String(storeId.value) },
    )
    message.value = enabled ? `Enabled device #${row.id}` : `Disabled device #${row.id}`
    await loadDevices()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Update failed'
  } finally {
    togglingId.value = null
  }
}

watch(storeId, () => {
  if (canManage.value) {
    void loadDevices().catch((e) => {
      error.value = e instanceof ApiError ? e.message : 'Failed to load devices'
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
        <h2 class="text-base font-semibold">POS devices</h2>
        <p class="text-sm text-[var(--muted)]">
          Register a terminal UUID from the POS sync panel, then enable or disable sync.
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-sm">
          <span class="text-[var(--muted)]">Store</span>
          <select
            v-model="storeId"
            class="mt-1 block min-w-[12rem] rounded-md border border-[var(--line)] px-3 py-2"
            :disabled="!canManage || loading"
          >
            <option disabled value="">Select store</option>
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-2 text-sm hover:bg-stone-50 disabled:opacity-60"
          :disabled="loading || !canManage"
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

    <form
      v-if="canManage"
      class="grid gap-3 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4 sm:grid-cols-[1fr_1fr_auto]"
      @submit.prevent="registerDevice"
    >
      <label class="text-sm">
        <span class="text-[var(--muted)]">Device UUID</span>
        <input
          v-model="form.uuid"
          type="text"
          required
          autocomplete="off"
          placeholder="Paste from POS sync panel"
          class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2 font-mono text-xs"
        />
      </label>
      <label class="text-sm">
        <span class="text-[var(--muted)]">Name</span>
        <input
          v-model="form.name"
          type="text"
          required
          maxlength="255"
          placeholder="Front counter"
          class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
        />
      </label>
      <div class="flex items-end">
        <button
          type="submit"
          class="rounded-md border border-[var(--brand)] bg-teal-50 px-4 py-2 text-sm font-medium text-[var(--brand-ink)] hover:bg-teal-100 disabled:opacity-60"
          :disabled="saving || !storeId"
        >
          {{ saving ? 'Registering…' : 'Register' }}
        </button>
      </div>
    </form>

    <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>

    <div
      v-else-if="canManage"
      class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]"
    >
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-xs tracking-wide text-[var(--muted)] uppercase">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">UUID</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th class="px-3 py-2 font-medium">Last seen</th>
            <th class="px-3 py-2 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0">
            <td colspan="5" class="px-3 py-4 text-[var(--muted)]">No devices registered for this store.</td>
          </tr>
          <tr
            v-for="row in rows"
            :key="row.id"
            class="border-b border-[var(--line)] last:border-b-0"
          >
            <td class="px-3 py-2">
              <div class="flex flex-wrap items-center gap-2">
                <input
                  v-model="renameDraft[row.id]"
                  type="text"
                  class="min-w-[8rem] flex-1 rounded-md border border-[var(--line)] px-2 py-1.5 text-sm"
                  :aria-label="`Rename device ${row.id}`"
                />
                <button
                  type="button"
                  class="rounded-md border border-[var(--line)] px-2 py-1.5 text-xs hover:bg-stone-50 disabled:opacity-60"
                  :disabled="renamingId === row.id"
                  @click="rename(row)"
                >
                  {{ renamingId === row.id ? 'Saving…' : 'Rename' }}
                </button>
              </div>
            </td>
            <td class="px-3 py-2">
              <code class="break-all text-xs">{{ row.uuid }}</code>
            </td>
            <td class="px-3 py-2">
              <span :class="row.enabled ? 'text-teal-800' : 'text-[var(--ember)]'">
                {{ row.enabled ? 'Enabled' : 'Disabled' }}
              </span>
            </td>
            <td class="px-3 py-2 text-[var(--muted)]">{{ row.lastSeenAt ?? '—' }}</td>
            <td class="px-3 py-2">
              <button
                v-if="row.enabled"
                type="button"
                class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-800 hover:bg-red-100 disabled:opacity-60"
                :disabled="togglingId === row.id"
                @click="setEnabled(row, false)"
              >
                {{ togglingId === row.id ? 'Updating…' : 'Disable' }}
              </button>
              <button
                v-else
                type="button"
                class="rounded-md border border-[var(--brand)] bg-teal-50 px-3 py-1.5 text-sm font-medium text-[var(--brand-ink)] hover:bg-teal-100 disabled:opacity-60"
                :disabled="togglingId === row.id"
                @click="setEnabled(row, true)"
              >
                {{ togglingId === row.id ? 'Updating…' : 'Enable' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
