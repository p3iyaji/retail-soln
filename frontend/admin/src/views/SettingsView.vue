<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppearanceSettings from '@/components/AppearanceSettings.vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Setting = {
  id: number
  organizationId: number
  storeId: number | null
  key: string
  value: unknown
  updatedAt: string | null
}

type StoreOption = { id: number; name: string }

const SUGGESTED_KEYS = [
  'pos.receipt_footer',
  'pos.receipt_header',
  'feature.flag',
  'inventory.allow_negative',
  'sales.require_customer',
]

const auth = useAuthStore()
const canManage = computed(() => auth.user?.permissions?.includes('settings.manage') === true)

const settings = ref<Setting[]>([])
const stores = ref<StoreOption[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const scope = ref<'all' | 'org' | 'store'>('all')
const filterStoreId = ref<number | ''>('')
const showForm = ref(false)
const editingKey = ref<string | null>(null)

const form = reactive({
  key: '',
  storeId: '' as number | '',
  valueText: '{\n  \n}',
})

const formTitle = computed(() => (editingKey.value ? `Edit “${editingKey.value}”` : 'New setting'))

function formatError(e: unknown, fallback: string): string {
  if (e instanceof ApiError && e.body.errors) {
    return Object.values(e.body.errors).flat()[0] ?? e.message
  }
  return e instanceof ApiError ? e.message : fallback
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined) return '—'
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

function storeName(storeId: number | null): string {
  if (storeId === null) return 'Organization'
  return stores.value.find((s) => s.id === storeId)?.name ?? `Store #${storeId}`
}

function resetForm() {
  form.key = ''
  form.storeId = ''
  form.valueText = '{\n  \n}'
  editingKey.value = null
}

function openCreate() {
  resetForm()
  if (scope.value === 'store' && filterStoreId.value !== '') {
    form.storeId = filterStoreId.value
  }
  showForm.value = true
  message.value = ''
  error.value = ''
}

function openEdit(setting: Setting) {
  editingKey.value = setting.key
  form.key = setting.key
  form.storeId = setting.storeId ?? ''
  form.valueText = JSON.stringify(setting.value ?? {}, null, 2)
  showForm.value = true
  message.value = ''
  error.value = ''
}

function closeForm() {
  showForm.value = false
  resetForm()
}

async function loadStores() {
  const res = await api.get<{ data: StoreOption[] }>('/stores?per_page=100')
  stores.value = res.data.map((s) => ({ id: s.id, name: s.name }))
}

async function loadSettings() {
  const qs = new URLSearchParams()
  if (scope.value === 'org') {
    qs.set('orgOnly', '1')
  } else if (scope.value === 'store' && filterStoreId.value !== '') {
    qs.set('storeId', String(filterStoreId.value))
  }
  const path = qs.toString() ? `/settings?${qs}` : '/settings'
  const res = await api.get<{ data: Setting[] }>(path)
  settings.value = res.data
}

async function refresh() {
  if (!canManage.value) {
    loading.value = false
    error.value = ''
    return
  }

  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadStores(), loadSettings()])
  } catch (e) {
    error.value = formatError(e, 'Failed to load settings')
  } finally {
    loading.value = false
  }
}

async function onSubmit() {
  if (!canManage.value) return

  let value: unknown
  try {
    value = JSON.parse(form.valueText)
  } catch {
    error.value = 'Value must be valid JSON'
    return
  }

  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const body: { key: string; value: unknown; storeId?: number | null } = {
      key: form.key.trim(),
      value,
    }
    if (form.storeId === '') {
      body.storeId = null
    } else {
      body.storeId = Number(form.storeId)
    }

    const res = await api.patch<{ data: Setting }>('/settings', body)
    const idx = settings.value.findIndex(
      (s) => s.key === res.data.key && s.storeId === res.data.storeId,
    )
    if (idx >= 0) {
      settings.value[idx] = res.data
    } else {
      settings.value = [...settings.value, res.data].sort((a, b) => a.key.localeCompare(b.key))
    }
    message.value = `Saved “${res.data.key}”`
    showForm.value = false
    resetForm()
    await loadSettings()
  } catch (e) {
    error.value = formatError(e, 'Save failed')
  } finally {
    saving.value = false
  }
}

watch([scope, filterStoreId], () => {
  if (!canManage.value || loading.value) return
  if (scope.value === 'store' && filterStoreId.value === '') {
    settings.value = []
    return
  }
  void loadSettings().catch((e) => {
    error.value = formatError(e, 'Failed to load settings')
  })
})

onMounted(refresh)
</script>

<template>
  <section class="space-y-8">
    <AppearanceSettings />

    <div class="space-y-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-xl font-semibold tracking-tight">Organization settings</h2>
          <p class="mt-1 text-sm text-[var(--muted)]">
            Organization and store key/value settings (JSON). Requires settings.manage.
          </p>
        </div>
        <button
          v-if="canManage"
          type="button"
          class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white hover:opacity-90"
          @click="openCreate"
        >
          New setting
        </button>
      </div>

      <div
        v-if="!canManage"
        class="rounded-lg border border-[var(--line)] bg-[var(--panel)] px-4 py-3 text-sm text-[var(--muted)]"
      >
        Organization key/value settings require the settings.manage permission. Use
        <RouterLink to="/appearance" class="text-[var(--brand-ink)] underline-offset-2 hover:underline">
          Appearance
        </RouterLink>
        above or in the sidebar to customise theme, sidebar, and text size.
      </div>

      <template v-else>
        <div class="flex flex-wrap items-end gap-3">
          <label class="block text-sm">
            <span class="text-[var(--muted)]">Scope</span>
            <select v-model="scope" class="mt-1 rounded-md border border-[var(--line)] px-3 py-2">
              <option value="all">All</option>
              <option value="org">Organization only</option>
              <option value="store">By store</option>
            </select>
          </label>
          <label v-if="scope === 'store'" class="block text-sm">
            <span class="text-[var(--muted)]">Store</span>
            <select v-model="filterStoreId" class="mt-1 rounded-md border border-[var(--line)] px-3 py-2">
              <option value="">Select store…</option>
              <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </label>
        </div>

        <p v-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>
        <p
          v-if="error"
          class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
          {{ error }}
        </p>
        <p
          v-if="message"
          class="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900"
        >
          {{ message }}
        </p>

        <div
          v-if="showForm"
          class="space-y-4 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
        >
          <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold">{{ formTitle }}</h3>
            <button type="button" class="text-sm text-[var(--muted)] hover:text-[var(--ink)]" @click="closeForm">
              Cancel
            </button>
          </div>
          <form class="grid gap-3" @submit.prevent="onSubmit">
            <label class="block text-sm">
              <span class="text-[var(--muted)]">Key</span>
              <input
                v-model="form.key"
                list="setting-keys"
                required
                maxlength="255"
                :disabled="Boolean(editingKey)"
                class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 disabled:bg-stone-50"
                placeholder="pos.receipt_footer"
              />
              <datalist id="setting-keys">
                <option v-for="k in SUGGESTED_KEYS" :key="k" :value="k" />
              </datalist>
            </label>
            <label class="block text-sm">
              <span class="text-[var(--muted)]">Scope</span>
              <select
                v-model="form.storeId"
                :disabled="Boolean(editingKey)"
                class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 disabled:bg-stone-50"
              >
                <option value="">Organization</option>
                <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
            </label>
            <label class="block text-sm">
              <span class="text-[var(--muted)]">Value (JSON)</span>
              <textarea
                v-model="form.valueText"
                rows="8"
                required
                class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 font-mono text-xs"
                spellcheck="false"
              />
            </label>
            <div>
              <button
                type="submit"
                class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-60"
                :disabled="saving"
              >
                {{ saving ? 'Saving…' : 'Save' }}
              </button>
            </div>
          </form>
        </div>

        <div v-if="!loading" class="overflow-x-auto rounded-md border border-[var(--line)]">
          <table class="min-w-full text-left text-sm">
            <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
              <tr>
                <th class="px-3 py-2 font-medium">Key</th>
                <th class="px-3 py-2 font-medium">Scope</th>
                <th class="px-3 py-2 font-medium">Value</th>
                <th class="px-3 py-2 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="setting in settings"
                :key="`${setting.storeId ?? 'org'}-${setting.key}`"
                class="border-b border-[var(--line)] last:border-b-0"
              >
                <td class="px-3 py-2 font-medium">{{ setting.key }}</td>
                <td class="px-3 py-2 text-[var(--muted)]">{{ storeName(setting.storeId) }}</td>
                <td class="max-w-md truncate px-3 py-2 font-mono text-xs" :title="formatValue(setting.value)">
                  {{ formatValue(setting.value) }}
                </td>
                <td class="px-3 py-2 text-right">
                  <button
                    type="button"
                    class="text-[var(--brand-ink)] hover:underline"
                    @click="openEdit(setting)"
                  >
                    Edit
                  </button>
                </td>
              </tr>
              <tr v-if="settings.length === 0">
                <td colspan="4" class="px-3 py-6 text-center text-[var(--muted)]">No settings yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </section>
</template>
