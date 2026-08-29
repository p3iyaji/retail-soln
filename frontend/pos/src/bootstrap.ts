import type { App } from 'vue'
import { ensureDeviceUuid, posDb } from './db/posDb'
import { startConnectivity } from './composables/useConnectivity'
import { useSyncStore } from './stores/sync'

export type BootstrapHandles = {
  stopConnectivity: () => void
}

/**
 * Open IndexedDB, ensure deviceUuid, start connectivity, optionally register SW.
 * Mounts the app last so SYNC_ERROR from IDB is visible immediately.
 */
export async function bootstrapPosApp(
  app: App,
  options: { registerServiceWorker?: boolean } = {},
): Promise<BootstrapHandles> {
  const sync = useSyncStore()
  try {
    await posDb.open()
    await ensureDeviceUuid()
  } catch (err) {
    const message = err instanceof Error ? err.message : 'IndexedDB open failed'
    sync.setSyncError(message)
  }

  const stopConnectivity = startConnectivity()

  if (options.registerServiceWorker ?? import.meta.env.PROD) {
    try {
      const { registerSW } = await import('virtual:pwa-register')
      registerSW({
        immediate: true,
        onRegisterError(error) {
          sync.setSyncError(error instanceof Error ? error.message : 'Service worker registration failed')
        },
      })
    } catch (err) {
      sync.setSyncError(err instanceof Error ? err.message : 'Service worker registration failed')
    }
  }

  app.mount('#app')
  return { stopConnectivity }
}
