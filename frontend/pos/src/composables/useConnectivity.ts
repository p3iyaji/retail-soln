import { useSyncStore } from '@/stores/sync'

/** Start connectivity listeners outside a component (app bootstrap). Returns stop(). */
export function startConnectivity(): () => void {
  const sync = useSyncStore()

  function onOnline(): void {
    sync.applyConnectivity(true)
  }

  function onOffline(): void {
    sync.applyConnectivity(false)
  }

  sync.applyConnectivity(navigator.onLine)
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)

  return () => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
  }
}
