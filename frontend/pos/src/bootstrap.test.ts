import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import Dexie from 'dexie'
import { bootstrapPosApp } from './bootstrap'
import { useSyncStore } from './stores/sync'
import * as posDbModule from './db/posDb'

describe('bootstrapPosApp', () => {
  beforeEach(async () => {
    await Dexie.delete('retail_soln_pos')
    document.body.innerHTML = '<div id="app"></div>'
    Object.defineProperty(navigator, 'onLine', {
      configurable: true,
      get: () => true,
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('sets SYNC_ERROR when IndexedDB open fails and still mounts', async () => {
    vi.spyOn(posDbModule.posDb, 'open').mockRejectedValue(new Error('IndexedDB open failed'))

    const pinia = createPinia()
    setActivePinia(pinia)
    const app = createApp({ template: '<div>ok</div>' })
    app.use(pinia)
    const sync = useSyncStore()

    await bootstrapPosApp(app, { registerServiceWorker: false })

    expect(sync.status).toBe('SYNC_ERROR')
    expect(sync.lastError).toBe('IndexedDB open failed')
    expect(document.body.textContent).toContain('ok')

    window.dispatchEvent(new Event('offline'))
    expect(sync.status).toBe('SYNC_ERROR')
  })
})
