<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }
type StaffUser = { id: number; name: string; email: string }

type Assignment = {
  id: number
  userId: number
  userName: string | null
  hourlyRate: string
  isActive: boolean
}

type ClockLocation = {
  id: number
  name: string
  address: string | null
  storeId: number | null
  latitude: string | null
  longitude: string | null
  radiusMeters: number | null
  isActive: boolean
  canManage: boolean
  requiresGeofence: boolean
  myAssignment: { id: number; hourlyRate: string; isActive: boolean } | null
  assignments: Assignment[]
}

type Shift = {
  id: number
  userId: number
  userName: string | null
  locationId: number
  locationName: string | null
  hourlyRate: string
  clockInAt: string | null
  clockOutAt: string | null
  durationMinutes: number | null
  grossPay: string | null
  voidedAt: string | null
}

type Summary = {
  durationMinutes: number
  hours: string
  grossPay: string
}

const auth = useAuthStore()
const canManage = computed(() => auth.user?.permissions?.includes('attendance.manage') === true)
const canClock = computed(() => auth.user?.permissions?.includes('attendance.clock') === true)
const orgWide = computed(
  () => auth.user?.role === 'org_admin' || auth.user?.role === 'super_admin',
)

const loading = ref(true)
const error = ref('')
const clocking = ref(false)
const savingLocation = ref(false)
const assigning = ref(false)
const togglingLocationId = ref<number | null>(null)
const voidingId = ref<number | null>(null)

const stores = ref<Store[]>([])
const users = ref<StaffUser[]>([])
const locations = ref<ClockLocation[]>([])
const myShifts = ref<Shift[]>([])
const openShifts = ref<Shift[]>([])
const timesheet = ref<Shift[]>([])
const timesheetTotal = ref(0)
const summary = ref<Summary | null>(null)

const clockLocationId = ref<number | ''>('')

const locationForm = reactive({
  name: '',
  address: '',
  storeId: '' as number | '',
  latitude: '',
  longitude: '',
  radiusMeters: '',
})

const assignForm = reactive({
  locationId: '' as number | '',
  userId: '' as number | '',
  hourlyRate: '',
  isActive: true,
})

const filters = reactive({
  locationId: '' as number | '',
  userId: '' as number | '',
  from: '',
  to: '',
})

const clockable = computed(() =>
  locations.value.filter((location) => location.isActive && location.myAssignment),
)
const manageableLocations = computed(() => locations.value.filter((location) => location.canManage))
const openShift = computed(
  () => myShifts.value.find((shift) => !shift.clockOutAt && !shift.voidedAt) ?? null,
)

function errorMessage(caught: unknown, fallback: string): string {
  if (caught instanceof ApiError) {
    const first = caught.body.errors ? Object.values(caught.body.errors).flat()[0] : undefined
    return first ?? caught.message
  }
  if (caught instanceof Error && caught.message) {
    return caught.message
  }
  return fallback
}

function formatWhen(value: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString()
}

function storeLabel(storeId: number | null): string {
  if (storeId === null) return 'Organization-wide'
  return stores.value.find((store) => store.id === storeId)?.name ?? `Store ${storeId}`
}

function shiftStatus(shift: Shift): string {
  if (shift.voidedAt) return 'Voided'
  if (!shift.clockOutAt) return 'In'
  return 'Out'
}

function query(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  }
  const built = search.toString()
  return built ? `?${built}` : ''
}

function optionalNumber(value: string): number | null {
  const trimmed = value.trim()
  if (!trimmed) return null
  const parsed = Number(trimmed)
  return Number.isFinite(parsed) ? parsed : Number.NaN
}

function readCoordinates(): Promise<{ latitude: number; longitude: number }> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject(new Error('This browser cannot read your location.'))
      return
    }
    navigator.geolocation.getCurrentPosition(
      (position) => {
        resolve({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
        })
      },
      () => reject(new Error('Location is required to clock in at this place.')),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    )
  })
}

async function loadLocations() {
  const res = await api.get<{ data: ClockLocation[] }>('/attendance/locations')
  locations.value = res.data
  if (clockLocationId.value === '' && clockable.value[0]) {
    clockLocationId.value = clockable.value[0].id
  }
  if (
    assignForm.locationId === '' ||
    !manageableLocations.value.some((location) => location.id === assignForm.locationId)
  ) {
    assignForm.locationId = manageableLocations.value[0]?.id ?? ''
  }
}

async function loadMyShifts() {
  if (!canClock.value) {
    myShifts.value = []
    return
  }
  const res = await api.get<{ data: Shift[] }>('/attendance/shifts?mine=1&per_page=100')
  myShifts.value = res.data
}

async function loadManagerLists() {
  if (!canManage.value) {
    openShifts.value = []
    timesheet.value = []
    timesheetTotal.value = 0
    summary.value = null
    return
  }

  const filterQuery = {
    locationId: filters.locationId === '' ? undefined : filters.locationId,
    userId: filters.userId === '' ? undefined : filters.userId,
    from: filters.from || undefined,
    to: filters.to || undefined,
  }

  const [openRes, sheetRes, summaryRes] = await Promise.all([
    api.get<{ data: Shift[] }>('/attendance/shifts?open=1&per_page=100'),
    api.get<{ data: Shift[]; meta: { total: number } }>(
      `/attendance/shifts${query({ ...filterQuery, per_page: 100 })}`,
    ),
    api.get<{ data: Summary }>(`/attendance/summary${query(filterQuery)}`),
  ])
  openShifts.value = openRes.data
  timesheet.value = sheetRes.data
  timesheetTotal.value = sheetRes.meta.total
  summary.value = summaryRes.data
}

async function loadLookups() {
  if (!canManage.value) return
  const [storeRes, userRes] = await Promise.all([
    api.get<{ data: Store[] }>('/stores?per_page=100'),
    api.get<{ data: StaffUser[] }>('/users?per_page=100'),
  ])
  stores.value = storeRes.data
  users.value = userRes.data
  if (!orgWide.value && locationForm.storeId === '') {
    const preferred = auth.user?.storeAccess.find((store) => store.isDefault)?.id ?? stores.value[0]?.id
    if (preferred) locationForm.storeId = preferred
  }
  if (assignForm.userId === '' && users.value[0]) {
    assignForm.userId = users.value[0].id
  }
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadLookups(), loadLocations(), loadMyShifts(), loadManagerLists()])
  } catch (caught) {
    error.value = errorMessage(caught, 'Failed to load time clock')
  } finally {
    loading.value = false
  }
}

async function clockIn() {
  if (clocking.value) return
  const location = clockable.value.find((row) => row.id === clockLocationId.value)
  if (!location) {
    error.value = 'That location is no longer available to clock in.'
    return
  }
  clocking.value = true
  error.value = ''
  try {
    const body: { locationId: number; latitude?: number; longitude?: number } = {
      locationId: location.id,
    }
    if (location.requiresGeofence) {
      const coords = await readCoordinates()
      body.latitude = coords.latitude
      body.longitude = coords.longitude
    }
    await api.post('/attendance/clock-in', body)
    await Promise.all([loadMyShifts(), loadManagerLists()])
  } catch (caught) {
    error.value = errorMessage(caught, 'Clock in failed')
  } finally {
    clocking.value = false
  }
}

async function clockOut() {
  if (clocking.value) return
  clocking.value = true
  error.value = ''
  try {
    await api.post('/attendance/clock-out')
    await Promise.all([loadMyShifts(), loadManagerLists()])
  } catch (caught) {
    error.value = errorMessage(caught, 'Clock out failed')
  } finally {
    clocking.value = false
  }
}

async function createLocation() {
  if (savingLocation.value) return
  const latitude = optionalNumber(locationForm.latitude)
  const longitude = optionalNumber(locationForm.longitude)
  const radius = optionalNumber(locationForm.radiusMeters)
  if (Number.isNaN(latitude) || Number.isNaN(longitude) || Number.isNaN(radius)) {
    error.value = 'Latitude, longitude, and radius must be numbers.'
    return
  }

  savingLocation.value = true
  error.value = ''
  try {
    const payload: {
      name: string
      address?: string
      storeId?: number
      latitude?: number
      longitude?: number
      radiusMeters?: number
    } = { name: locationForm.name.trim() }
    if (locationForm.address.trim()) payload.address = locationForm.address.trim()
    if (locationForm.storeId !== '') payload.storeId = locationForm.storeId
    if (latitude !== null) payload.latitude = latitude
    if (longitude !== null) payload.longitude = longitude
    if (radius !== null) payload.radiusMeters = Math.round(radius)

    await api.post('/attendance/locations', payload)
    locationForm.name = ''
    locationForm.address = ''
    locationForm.latitude = ''
    locationForm.longitude = ''
    locationForm.radiusMeters = ''
    await loadLocations()
  } catch (caught) {
    error.value = errorMessage(caught, 'Could not create location')
  } finally {
    savingLocation.value = false
  }
}

async function setLocationActive(location: ClockLocation, isActive: boolean) {
  if (togglingLocationId.value != null) return
  togglingLocationId.value = location.id
  error.value = ''
  try {
    await api.patch(`/attendance/locations/${location.id}`, { isActive })
    await Promise.all([loadLocations(), loadMyShifts()])
  } catch (caught) {
    error.value = errorMessage(caught, 'Could not update location')
  } finally {
    togglingLocationId.value = null
  }
}

async function saveAssignment() {
  if (assignForm.locationId === '' || assignForm.userId === '' || assigning.value) return
  assigning.value = true
  error.value = ''
  try {
    await api.post(`/attendance/locations/${assignForm.locationId}/assignments`, {
      userId: assignForm.userId,
      hourlyRate: assignForm.hourlyRate.trim(),
      isActive: assignForm.isActive,
    })
    assignForm.hourlyRate = ''
    await loadLocations()
  } catch (caught) {
    error.value = errorMessage(caught, 'Could not save assignment')
  } finally {
    assigning.value = false
  }
}

async function applyFilters() {
  error.value = ''
  try {
    await loadManagerLists()
  } catch (caught) {
    error.value = errorMessage(caught, 'Could not load timesheet')
  }
}

async function voidShift(shift: Shift) {
  if (voidingId.value != null || shift.voidedAt) return
  voidingId.value = shift.id
  error.value = ''
  try {
    await api.post(`/attendance/shifts/${shift.id}/void`)
    await Promise.all([loadMyShifts(), loadManagerLists()])
  } catch (caught) {
    error.value = errorMessage(caught, 'Could not void shift')
  } finally {
    voidingId.value = null
  }
}

onMounted(() => {
  void refresh()
})
</script>

<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-base font-semibold">Time clock</h2>
      <p class="text-sm text-[var(--muted)]">
        Clock in at an assigned location. Managers set places, rates, and review pay.
      </p>
    </div>

    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
      {{ error }}
    </p>

    <div v-if="loading" class="text-sm text-[var(--muted)]">Loading…</div>

    <template v-else>
      <section v-if="canClock" class="space-y-3">
        <h3 class="text-sm font-semibold">Your shift</h3>
        <div
          v-if="openShift"
          class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
        >
          <p class="text-sm">
            Clocked in at <span class="font-medium">{{ openShift.locationName }}</span>
            since {{ formatWhen(openShift.clockInAt) }}.
          </p>
          <button
            type="button"
            class="rounded-md border border-[var(--brand)] bg-teal-50 px-4 py-2 text-sm font-medium text-[var(--brand-ink)] hover:bg-teal-100 disabled:opacity-60"
            :disabled="clocking"
            @click="clockOut"
          >
            {{ clocking ? 'Clocking out…' : 'Clock out' }}
          </button>
        </div>
        <form
          v-else
          class="flex flex-wrap items-end gap-3 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4"
          @submit.prevent="clockIn"
        >
          <label class="text-sm">
            <span class="text-[var(--muted)]">Location</span>
            <select
              v-model="clockLocationId"
              class="mt-1 block min-w-[14rem] rounded-md border border-[var(--line)] px-3 py-2"
              required
            >
              <option disabled value="">Select a location</option>
              <option v-for="location in clockable" :key="location.id" :value="location.id">
                {{ location.name }}{{ location.requiresGeofence ? ' (location required)' : '' }}
              </option>
            </select>
          </label>
          <button
            type="submit"
            class="rounded-md border border-[var(--brand)] bg-teal-50 px-4 py-2 text-sm font-medium text-[var(--brand-ink)] hover:bg-teal-100 disabled:opacity-60"
            :disabled="clocking || clockLocationId === ''"
          >
            {{ clocking ? 'Clocking in…' : 'Clock in' }}
          </button>
          <p v-if="clockable.length === 0" class="text-sm text-[var(--muted)]">
            You have no active location assignment.
          </p>
        </form>

        <div class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]">
          <table class="min-w-full text-left text-sm">
            <thead class="border-b border-[var(--line)] bg-stone-50 text-xs tracking-wide text-[var(--muted)] uppercase">
              <tr>
                <th class="px-3 py-2 font-medium">Location</th>
                <th class="px-3 py-2 font-medium">In</th>
                <th class="px-3 py-2 font-medium">Out</th>
                <th class="px-3 py-2 font-medium">Minutes</th>
                <th class="px-3 py-2 font-medium">Gross pay</th>
                <th class="px-3 py-2 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="myShifts.length === 0">
                <td colspan="6" class="px-3 py-4 text-[var(--muted)]">No shifts yet.</td>
              </tr>
              <tr v-for="shift in myShifts" :key="shift.id" class="border-b border-[var(--line)] last:border-b-0">
                <td class="px-3 py-2">{{ shift.locationName }}</td>
                <td class="px-3 py-2">{{ formatWhen(shift.clockInAt) }}</td>
                <td class="px-3 py-2">{{ formatWhen(shift.clockOutAt) }}</td>
                <td class="px-3 py-2">{{ shift.durationMinutes ?? '—' }}</td>
                <td class="px-3 py-2">{{ shift.voidedAt ? '—' : (shift.grossPay ?? '—') }}</td>
                <td class="px-3 py-2">{{ shiftStatus(shift) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="canManage" class="space-y-3">
        <h3 class="text-sm font-semibold">Locations</h3>
        <form
          class="grid gap-3 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4 sm:grid-cols-2 lg:grid-cols-3"
          @submit.prevent="createLocation"
        >
          <label class="text-sm">
            <span class="text-[var(--muted)]">Name</span>
            <input
              v-model="locationForm.name"
              required
              maxlength="255"
              class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
            />
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">Address</span>
            <input
              v-model="locationForm.address"
              maxlength="255"
              class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
            />
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">Store</span>
            <select
              v-model="locationForm.storeId"
              class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
              :required="!orgWide"
            >
              <option v-if="orgWide" value="">Organization-wide</option>
              <option v-for="store in stores" :key="store.id" :value="store.id">{{ store.name }}</option>
            </select>
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">Latitude</span>
            <input
              v-model="locationForm.latitude"
              inputmode="decimal"
              class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
            />
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">Longitude</span>
            <input
              v-model="locationForm.longitude"
              inputmode="decimal"
              class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
            />
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">Radius (meters)</span>
            <input
              v-model="locationForm.radiusMeters"
              inputmode="numeric"
              class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
            />
          </label>
          <div class="flex items-end sm:col-span-2 lg:col-span-3">
            <button
              type="submit"
              class="rounded-md border border-[var(--brand)] bg-teal-50 px-4 py-2 text-sm font-medium text-[var(--brand-ink)] hover:bg-teal-100 disabled:opacity-60"
              :disabled="savingLocation"
            >
              {{ savingLocation ? 'Saving…' : 'Create location' }}
            </button>
          </div>
          <p class="text-xs text-[var(--muted)] sm:col-span-2 lg:col-span-3">
            A geofence applies only when latitude, longitude, and radius are all set.
          </p>
        </form>

        <div class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]">
          <table class="min-w-full text-left text-sm">
            <thead class="border-b border-[var(--line)] bg-stone-50 text-xs tracking-wide text-[var(--muted)] uppercase">
              <tr>
                <th class="px-3 py-2 font-medium">Name</th>
                <th class="px-3 py-2 font-medium">Store</th>
                <th class="px-3 py-2 font-medium">Geofence</th>
                <th class="px-3 py-2 font-medium">Assignments</th>
                <th class="px-3 py-2 font-medium">Status</th>
                <th class="px-3 py-2 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="locations.length === 0">
                <td colspan="6" class="px-3 py-4 text-[var(--muted)]">No locations yet.</td>
              </tr>
              <tr v-for="location in locations" :key="location.id" class="border-b border-[var(--line)] last:border-b-0">
                <td class="px-3 py-2">
                  <div>{{ location.name }}</div>
                  <div v-if="location.address" class="text-xs text-[var(--muted)]">{{ location.address }}</div>
                </td>
                <td class="px-3 py-2">{{ storeLabel(location.storeId) }}</td>
                <td class="px-3 py-2">
                  <span v-if="location.requiresGeofence">{{ location.radiusMeters }} m</span>
                  <span v-else class="text-[var(--muted)]">None</span>
                </td>
                <td class="px-3 py-2">
                  <span v-if="location.assignments.length === 0" class="text-[var(--muted)]">None</span>
                  <span v-else>
                    {{
                      location.assignments
                        .map((row) => `${row.userName ?? row.userId} · ${row.hourlyRate}${row.isActive ? '' : ' (inactive)'}`)
                        .join(', ')
                    }}
                  </span>
                </td>
                <td class="px-3 py-2">{{ location.isActive ? 'Active' : 'Inactive' }}</td>
                <td class="px-3 py-2">
                  <button
                    v-if="location.canManage"
                    type="button"
                    class="rounded-md border border-[var(--line)] px-2 py-1.5 text-xs hover:bg-stone-50 disabled:opacity-60"
                    :disabled="togglingLocationId === location.id"
                    @click="setLocationActive(location, !location.isActive)"
                  >
                    {{ location.isActive ? 'Deactivate' : 'Activate' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <form
          class="grid gap-3 rounded-md border border-[var(--line)] bg-[var(--panel)] p-4 sm:grid-cols-2 lg:grid-cols-4"
          @submit.prevent="saveAssignment"
        >
          <label class="text-sm">
            <span class="text-[var(--muted)]">Location</span>
            <select
              v-model="assignForm.locationId"
              required
              class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
            >
              <option disabled value="">Select</option>
              <option v-for="location in manageableLocations" :key="location.id" :value="location.id">
                {{ location.name }}
              </option>
            </select>
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">User</span>
            <select
              v-model="assignForm.userId"
              required
              class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
            >
              <option disabled value="">Select</option>
              <option v-for="user in users" :key="user.id" :value="user.id">
                {{ user.name }}
              </option>
            </select>
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">Hourly rate</span>
            <input
              v-model="assignForm.hourlyRate"
              required
              inputmode="decimal"
              placeholder="15.5000"
              class="mt-1 block w-full rounded-md border border-[var(--line)] px-3 py-2"
            />
          </label>
          <div class="flex items-end gap-3">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="assignForm.isActive" type="checkbox" />
              Active
            </label>
            <button
              type="submit"
              class="rounded-md border border-[var(--brand)] bg-teal-50 px-4 py-2 text-sm font-medium text-[var(--brand-ink)] hover:bg-teal-100 disabled:opacity-60"
              :disabled="assigning"
            >
              {{ assigning ? 'Saving…' : 'Save assignment' }}
            </button>
          </div>
        </form>
      </section>

      <section v-if="canManage" class="space-y-3">
        <h3 class="text-sm font-semibold">Who is in</h3>
        <div class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]">
          <table class="min-w-full text-left text-sm">
            <thead class="border-b border-[var(--line)] bg-stone-50 text-xs tracking-wide text-[var(--muted)] uppercase">
              <tr>
                <th class="px-3 py-2 font-medium">Name</th>
                <th class="px-3 py-2 font-medium">Location</th>
                <th class="px-3 py-2 font-medium">Since</th>
                <th class="px-3 py-2 font-medium">Rate</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="openShifts.length === 0">
                <td colspan="4" class="px-3 py-4 text-[var(--muted)]">Nobody is clocked in.</td>
              </tr>
              <tr v-for="shift in openShifts" :key="shift.id" class="border-b border-[var(--line)] last:border-b-0">
                <td class="px-3 py-2">{{ shift.userName }}</td>
                <td class="px-3 py-2">{{ shift.locationName }}</td>
                <td class="px-3 py-2">{{ formatWhen(shift.clockInAt) }}</td>
                <td class="px-3 py-2">{{ shift.hourlyRate }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="canManage" class="space-y-3">
        <h3 class="text-sm font-semibold">Timesheet</h3>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
          <label class="text-sm">
            <span class="text-[var(--muted)]">Location</span>
            <select v-model="filters.locationId" class="mt-1 block min-w-[12rem] rounded-md border border-[var(--line)] px-3 py-2">
              <option value="">All</option>
              <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
            </select>
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">User</span>
            <select v-model="filters.userId" class="mt-1 block min-w-[12rem] rounded-md border border-[var(--line)] px-3 py-2">
              <option value="">All</option>
              <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
            </select>
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">From</span>
            <input v-model="filters.from" type="date" class="mt-1 block rounded-md border border-[var(--line)] px-3 py-2" />
          </label>
          <label class="text-sm">
            <span class="text-[var(--muted)]">To</span>
            <input v-model="filters.to" type="date" class="mt-1 block rounded-md border border-[var(--line)] px-3 py-2" />
          </label>
          <button
            type="submit"
            class="rounded-md border border-[var(--line)] px-3 py-2 text-sm hover:bg-stone-50"
          >
            Apply
          </button>
        </form>
        <p v-if="summary" class="text-sm">
          Hours <span class="font-medium">{{ summary.hours }}</span>
          · Gross pay <span class="font-medium">{{ summary.grossPay }}</span>
        </p>
        <p v-if="timesheetTotal > timesheet.length" class="text-sm text-[var(--muted)]">
          This table is the latest page. Hours and gross pay include every matching closed shift.
        </p>
        <div class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)]">
          <table class="min-w-full text-left text-sm">
            <thead class="border-b border-[var(--line)] bg-stone-50 text-xs tracking-wide text-[var(--muted)] uppercase">
              <tr>
                <th class="px-3 py-2 font-medium">Name</th>
                <th class="px-3 py-2 font-medium">Location</th>
                <th class="px-3 py-2 font-medium">In</th>
                <th class="px-3 py-2 font-medium">Out</th>
                <th class="px-3 py-2 font-medium">Minutes</th>
                <th class="px-3 py-2 font-medium">Rate</th>
                <th class="px-3 py-2 font-medium">Gross pay</th>
                <th class="px-3 py-2 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="timesheet.length === 0">
                <td colspan="8" class="px-3 py-4 text-[var(--muted)]">No shifts for this filter.</td>
              </tr>
              <tr v-for="shift in timesheet" :key="shift.id" class="border-b border-[var(--line)] last:border-b-0">
                <td class="px-3 py-2">{{ shift.userName }}</td>
                <td class="px-3 py-2">{{ shift.locationName }}</td>
                <td class="px-3 py-2">{{ formatWhen(shift.clockInAt) }}</td>
                <td class="px-3 py-2">{{ formatWhen(shift.clockOutAt) }}</td>
                <td class="px-3 py-2">{{ shift.durationMinutes ?? '—' }}</td>
                <td class="px-3 py-2">{{ shift.hourlyRate }}</td>
                <td class="px-3 py-2">{{ shift.voidedAt ? '—' : (shift.grossPay ?? '—') }}</td>
                <td class="px-3 py-2">
                  <button
                    v-if="!shift.voidedAt"
                    type="button"
                    class="rounded-md border border-red-200 bg-red-50 px-2 py-1.5 text-xs font-medium text-red-800 hover:bg-red-100 disabled:opacity-60"
                    :disabled="voidingId === shift.id"
                    @click="voidShift(shift)"
                  >
                    {{ voidingId === shift.id ? 'Voiding…' : 'Void' }}
                  </button>
                  <span v-else class="text-xs text-[var(--muted)]">Voided</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
