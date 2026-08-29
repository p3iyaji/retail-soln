<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { api, getStoredStoreId } from '@/api/client'
import { drainThenPull } from '@/composables/useConnectivity'
import { ensureDeviceUuid } from '@/db/posDb'
import { useSyncStore, type SyncStatus } from '@/stores/sync'
import { countPendingAndError } from '@/sync/pendingSales'

const sync = useSyncStore()

const panelOpen = ref(false)
const pendingCount = ref(0)
const errorCount = ref(0)
const retrying = ref(false)
const retryHint = ref<string | null>(null)
const serverPendingOversell = ref<number | null>(null)
const serverDeviceEnabled = ref<boolean | null>(null)
const panelEl = ref<HTMLElement | null>(null)

const toneClass = computed(() => {
  const map: Record<SyncStatus, string> = {
    ONLINE: 'sync-badge--online',
    OFFLINE: 'sync-badge--offline',
    SYNCING: 'sync-badge--syncing',
    SYNC_ERROR: 'sync-badge--error',
  }
  return map[sync.status]
})

const live = computed(() => (sync.status === 'SYNC_ERROR' ? 'assertive' : 'polite'))
const accessibleLabel = computed(() =>
  sync.lastError ? `${sync.label}: ${sync.lastError}` : sync.label,
)

async function refreshCounts(): Promise<void> {
  try {
    const counts = await countPendingAndError()
    pendingCount.value = counts.pending
    errorCount.value = counts.error
  } catch {
    pendingCount.value = 0
    errorCount.value = 0
  }
}

async function refreshServerStatus(): Promise<void> {
  serverPendingOversell.value = null
  serverDeviceEnabled.value = null
  if (!navigator.onLine || getStoredStoreId() == null) return
  try {
    const deviceUuid = await ensureDeviceUuid()
    const res = await api.get<{
      data: {
        pendingOversellCount: number
        deviceEnabled?: boolean
      }
    }>(`/sync/status?deviceUuid=${encodeURIComponent(deviceUuid)}`)
    serverPendingOversell.value = res.data.pendingOversellCount
    serverDeviceEnabled.value =
      typeof res.data.deviceEnabled === 'boolean' ? res.data.deviceEnabled : null
  } catch {
    // Local Dexie counts remain authoritative for the panel.
  }
}

async function togglePanel(): Promise<void> {
  panelOpen.value = !panelOpen.value
  if (panelOpen.value) {
    retryHint.value = null
    await Promise.all([refreshCounts(), refreshServerStatus()])
  }
}

async function onRetry(): Promise<void> {
  if (retrying.value) return
  if (getStoredStoreId() == null) {
    retryHint.value = 'Select a store before retrying sync'
    return
  }
  retryHint.value = null
  retrying.value = true
  try {
    sync.clearError(navigator.onLine)
    await drainThenPull()
    await refreshCounts()
    await refreshServerStatus()
    if (sync.status !== 'SYNC_ERROR') {
      panelOpen.value = false
    }
  } finally {
    retrying.value = false
  }
}

function onDocPointerDown(event: PointerEvent): void {
  if (!panelOpen.value || !panelEl.value) return
  const target = event.target as Node | null
  if (target && !panelEl.value.contains(target)) {
    panelOpen.value = false
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && panelOpen.value) {
    panelOpen.value = false
  }
}

watch(
  () => sync.status,
  async () => {
    if (panelOpen.value) await refreshCounts()
  },
)

onMounted(() => {
  document.addEventListener('pointerdown', onDocPointerDown)
  document.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  document.removeEventListener('pointerdown', onDocPointerDown)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="panelEl" class="relative inline-flex">
    <!-- Live region separate from the interactive control (WCAG / UX spine). -->
    <span
      class="sr-only"
      role="status"
      :aria-live="live"
      :aria-atomic="true"
    >
      {{ accessibleLabel }}
    </span>

    <button
      type="button"
      class="sync-badge inline-flex min-h-11 items-center gap-1.5 rounded border border-[var(--ember)] px-2.5 py-1 text-xs font-semibold tracking-wide text-white"
      :class="toneClass"
      :aria-expanded="panelOpen"
      aria-haspopup="dialog"
      :aria-controls="panelOpen ? 'sync-status-panel' : undefined"
      :aria-label="accessibleLabel"
      :title="sync.lastError ? `Sync status: ${sync.label} — ${sync.lastError}` : `Sync status: ${sync.label}`"
      @click="togglePanel"
    >
      <span
        class="sync-badge__dot inline-block h-1.5 w-1.5 rounded-full bg-white"
        aria-hidden="true"
      />
      {{ sync.label }}
    </button>

    <div
      v-if="panelOpen"
      id="sync-status-panel"
      role="dialog"
      aria-label="Sync status details"
      class="absolute top-full right-0 z-40 mt-2 w-72 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-3 text-left text-[var(--ink)] shadow-md"
    >
      <p class="text-xs font-semibold tracking-wide text-[var(--muted)] uppercase">Sync status</p>
      <p class="mt-1 text-sm font-medium">{{ sync.label }}</p>

      <p v-if="sync.lastError" class="mt-2 text-sm text-[var(--sync-error)]" role="status">
        {{ sync.lastError }}
      </p>
      <p v-else class="mt-2 text-sm text-[var(--muted)]">No sync error.</p>

      <p v-if="retryHint" class="mt-2 text-sm text-[var(--sync-error)]" role="status">
        {{ retryHint }}
      </p>

      <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
        <div>
          <dt class="text-[var(--muted)]">Pending</dt>
          <dd class="font-semibold">{{ pendingCount }}</dd>
        </div>
        <div>
          <dt class="text-[var(--muted)]">Errors</dt>
          <dd class="font-semibold">{{ errorCount }}</dd>
        </div>
        <div v-if="serverPendingOversell != null">
          <dt class="text-[var(--muted)]">Store oversells</dt>
          <dd class="font-semibold">{{ serverPendingOversell }}</dd>
        </div>
        <div v-if="serverDeviceEnabled != null">
          <dt class="text-[var(--muted)]">Device</dt>
          <dd class="font-semibold">{{ serverDeviceEnabled ? 'Enabled' : 'Disabled' }}</dd>
        </div>
      </dl>

      <button
        type="button"
        class="mt-3 min-h-11 w-full rounded-[var(--radius)] border border-[var(--primary)] bg-[var(--primary)] px-3 text-sm font-medium text-white disabled:opacity-60"
        :disabled="retrying"
        @click="onRetry"
      >
        {{ retrying ? 'Retrying…' : 'Retry' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border-width: 0;
}

.sync-badge--online {
  background: var(--online);
}

.sync-badge--offline {
  background: var(--offline);
}

.sync-badge--syncing {
  background: var(--syncing);
  animation: sync-pulse 1.2s ease-in-out infinite;
}

.sync-badge--error {
  background: var(--sync-error);
}

@keyframes sync-pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.72;
  }
}
</style>
