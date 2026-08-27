<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Organization = {
  id: number
  name: string
  legalName: string | null
  defaultCurrency: string
  timezone: string
  createdAt: string | null
  updatedAt: string | null
}

const CURRENCIES = ['USD', 'NGN', 'GBP', 'EUR', 'CAD', 'KES', 'GHS']
const TIMEZONES = [
  'UTC',
  'Africa/Lagos',
  'Africa/Nairobi',
  'Africa/Accra',
  'America/New_York',
  'America/Chicago',
  'America/Los_Angeles',
  'Europe/London',
  'Europe/Paris',
]

const auth = useAuthStore()
const canView = computed(() => auth.user?.permissions?.includes('org.view') === true)
const canUpdate = computed(() => auth.user?.permissions?.includes('org.update') === true)

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const message = ref('')
const organizationId = ref<number | null>(null)

const form = reactive({
  name: '',
  legalName: '',
  defaultCurrency: 'USD',
  timezone: 'UTC',
})

function applyOrganization(org: Organization) {
  organizationId.value = org.id
  form.name = org.name
  form.legalName = org.legalName ?? ''
  form.defaultCurrency = org.defaultCurrency
  form.timezone = org.timezone
}

async function refresh() {
  if (!canView.value) {
    loading.value = false
    error.value = 'You do not have permission to view the organization.'
    return
  }

  loading.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await api.get<{ data: Organization }>('/organizations/current')
    applyOrganization(res.data)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load organization'
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!canUpdate.value) return

  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await api.post<{ data: Organization }>('/organizations/current', {
      name: form.name.trim(),
      legalName: form.legalName.trim() || null,
      defaultCurrency: form.defaultCurrency.trim().toUpperCase(),
      timezone: form.timezone.trim(),
    })
    applyOrganization(res.data)
    message.value = 'Organization saved'
  } catch (e) {
    if (e instanceof ApiError && e.body.errors) {
      const first = Object.values(e.body.errors).flat()[0]
      error.value = first ?? e.message
    } else {
      error.value = e instanceof ApiError ? e.message : 'Failed to save organization'
    }
  } finally {
    saving.value = false
  }
}

onMounted(refresh)
</script>

<template>
  <section class="max-w-3xl space-y-6">
    <div>
      <h2 class="text-xl font-semibold tracking-tight">Organization</h2>
      <p class="mt-1 text-sm text-[var(--muted)]">
        Profile for the current organization — name, legal entity, currency, and timezone.
      </p>
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

    <form
      v-if="!loading && canView && organizationId"
      class="space-y-4 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
      @submit.prevent="save"
    >
      <div class="grid gap-3 md:grid-cols-2">
        <label class="block text-sm md:col-span-2">
          <span class="text-[var(--muted)]">Name</span>
          <input
            v-model="form.name"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 disabled:bg-stone-50"
            required
            maxlength="255"
            :disabled="!canUpdate"
            autocomplete="organization"
          />
        </label>
        <label class="block text-sm md:col-span-2">
          <span class="text-[var(--muted)]">Legal name</span>
          <input
            v-model="form.legalName"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 disabled:bg-stone-50"
            maxlength="255"
            :disabled="!canUpdate"
            autocomplete="organization"
          />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Default currency</span>
          <input
            v-model="form.defaultCurrency"
            list="org-currencies"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 uppercase disabled:bg-stone-50"
            required
            maxlength="3"
            :disabled="!canUpdate"
          />
          <datalist id="org-currencies">
            <option v-for="code in CURRENCIES" :key="code" :value="code" />
          </datalist>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Timezone</span>
          <input
            v-model="form.timezone"
            list="org-timezones"
            class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2 disabled:bg-stone-50"
            required
            :disabled="!canUpdate"
          />
          <datalist id="org-timezones">
            <option v-for="tz in TIMEZONES" :key="tz" :value="tz" />
          </datalist>
        </label>
      </div>

      <div class="flex items-center justify-between gap-3 pt-1">
        <p class="text-xs text-[var(--muted)]">Org #{{ organizationId }}</p>
        <button
          v-if="canUpdate"
          type="submit"
          class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-60"
          :disabled="saving"
        >
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
        <p v-else class="text-xs text-[var(--muted)]">View only — org.update required to edit.</p>
      </div>
    </form>
  </section>
</template>
