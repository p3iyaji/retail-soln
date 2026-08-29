import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export type SyncStatus = 'ONLINE' | 'OFFLINE' | 'SYNCING' | 'SYNC_ERROR'

const LABELS: Record<SyncStatus, string> = {
  ONLINE: 'ONLINE',
  OFFLINE: 'OFFLINE',
  SYNCING: 'SYNCING',
  SYNC_ERROR: 'SYNC ERROR',
}

function navigatorOnline(): boolean {
  return typeof navigator === 'undefined' ? true : navigator.onLine !== false
}

export const useSyncStore = defineStore('sync', () => {
  const status = ref<SyncStatus>(navigatorOnline() ? 'ONLINE' : 'OFFLINE')
  const lastError = ref<string | null>(null)

  const label = computed(() => LABELS[status.value])
  const isError = computed(() => status.value === 'SYNC_ERROR')
  const isSyncing = computed(() => status.value === 'SYNCING')

  /** Set ONLINE only when not holding SYNC_ERROR (must be cleared explicitly). */
  function setOnline(): void {
    if (status.value === 'SYNC_ERROR') return
    lastError.value = null
    status.value = 'ONLINE'
  }

  function setOffline(): void {
    if (status.value === 'SYNC_ERROR') return
    status.value = 'OFFLINE'
  }

  function setSyncing(): void {
    if (status.value === 'SYNC_ERROR') return
    lastError.value = null
    status.value = 'SYNCING'
  }

  function setSyncError(message: string): void {
    lastError.value = message
    status.value = 'SYNC_ERROR'
  }

  /** Clear error and restore ONLINE/OFFLINE from connectivity (used by later stories / retry). */
  function clearError(online = navigatorOnline()): void {
    lastError.value = null
    status.value = online ? 'ONLINE' : 'OFFLINE'
  }

  /**
   * Apply browser connectivity without overwriting SYNCING or SYNC_ERROR
   * (those states must stay visible until cleared/retry — UX spine).
   */
  function applyConnectivity(online: boolean): void {
    if (status.value === 'SYNCING' || status.value === 'SYNC_ERROR') {
      return
    }
    status.value = online ? 'ONLINE' : 'OFFLINE'
  }

  return {
    status,
    lastError,
    label,
    isError,
    isSyncing,
    setOnline,
    setOffline,
    setSyncing,
    setSyncError,
    clearError,
    applyConnectivity,
  }
})
