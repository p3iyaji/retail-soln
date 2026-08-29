<script setup lang="ts">
import { computed } from 'vue'
import { useSyncStore, type SyncStatus } from '@/stores/sync'

const sync = useSyncStore()

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
</script>

<template>
  <span
    class="sync-badge inline-flex items-center gap-1.5 rounded border border-[var(--ember)] px-2.5 py-1 text-xs font-semibold tracking-wide text-white"
    :class="toneClass"
    role="status"
    :aria-live="live"
    :aria-atomic="true"
    :aria-label="accessibleLabel"
    :title="sync.lastError ? `Sync status: ${sync.label} — ${sync.lastError}` : `Sync status: ${sync.label}`"
  >
    <span
      class="sync-badge__dot inline-block h-1.5 w-1.5 rounded-full bg-white"
      aria-hidden="true"
    />
    {{ sync.label }}
  </span>
</template>

<style scoped>
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
