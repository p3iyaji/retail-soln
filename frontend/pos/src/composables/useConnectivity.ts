import { useSyncStore } from '@/stores/sync'
import { getStoredStoreId } from '@/api/client'
import { pushPendingSales } from '@/sync/pushPendingSales'
import { pullCache } from '@/sync/pullCache'

/**
 * Push pending sales then pull cache for the selected store.
 * Shared by reconnect and SyncBadge Retry.
 */
export async function drainThenPull(): Promise<void> {
  const sync = useSyncStore()
  await pushPendingSales()
  const pushFailed = sync.status === 'SYNC_ERROR'
  const pushError = sync.lastError
  const storeId = getStoredStoreId()
  if (storeId != null) {
    try {
      await pullCache(storeId, { priorError: pushFailed ? pushError : null })
    } catch {
      // SYNC ERROR already set inside pullCache
    }
  }
}

/** Start connectivity listeners outside a component (app bootstrap). Returns stop(). */
export function startConnectivity(): () => void {
  const sync = useSyncStore()

  function onOnline(): void {
    sync.applyConnectivity(true)
    void drainThenPull()
  }

  function onOffline(): void {
    sync.applyConnectivity(false)
  }

  sync.applyConnectivity(navigator.onLine)
  if (navigator.onLine) {
    void drainThenPull()
  }

  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)

  return () => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
  }
}
