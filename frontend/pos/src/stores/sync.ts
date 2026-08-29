import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export type SyncStatus = 'ONLINE' | 'OFFLINE' | 'SYNCING' | 'SYNC_ERROR'

export type OversellNotice = {
  uuid: string
  exceptionId: number
  saleId?: number
}

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
  /** Non-blocking notices after push returns exceptionId (sale ref). */
  const oversellNotices = ref<OversellNotice[]>([])

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

  function addOversellNotices(notices: OversellNotice[]): void {
    if (notices.length === 0) return
    const seen = new Set(oversellNotices.value.map((n) => `${n.exceptionId}:${n.uuid}`))
    for (const notice of notices) {
      const key = `${notice.exceptionId}:${notice.uuid}`
      if (!seen.has(key)) {
        oversellNotices.value.push(notice)
        seen.add(key)
      }
    }
    // Cap to last 5 so the floor chrome does not accumulate indefinitely.
    if (oversellNotices.value.length > 5) {
      oversellNotices.value = oversellNotices.value.slice(-5)
    }
  }

  function dismissOversellNotice(exceptionId: number): void {
    oversellNotices.value = oversellNotices.value.filter((n) => n.exceptionId !== exceptionId)
  }

  function clearOversellNotices(): void {
    oversellNotices.value = []
  }

  return {
    status,
    lastError,
    oversellNotices,
    label,
    isError,
    isSyncing,
    setOnline,
    setOffline,
    setSyncing,
    setSyncError,
    clearError,
    applyConnectivity,
    addOversellNotices,
    dismissOversellNotice,
    clearOversellNotices,
  }
})
