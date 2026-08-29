import Dexie, { type EntityTable } from 'dexie'

export type SyncMetaRow = {
  key: string
  value: string
}

/** Placeholder row shape for stores filled by later Epic 5 stories. */
export type IdRow = {
  id?: number
}

const DEVICE_UUID_KEY = 'deviceUuid'

function createDeviceUuid(): string {
  const c = globalThis.crypto
  if (c && typeof c.randomUUID === 'function') {
    return c.randomUUID()
  }
  // Fallback for older runtimes — still unique enough for local device id.
  const bytes = new Uint8Array(16)
  if (c?.getRandomValues) {
    c.getRandomValues(bytes)
  } else {
    for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256)
  }
  bytes[6] = (bytes[6]! & 0x0f) | 0x40
  bytes[8] = (bytes[8]! & 0x3f) | 0x80
  const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export class PosDatabase extends Dexie {
  products!: EntityTable<IdRow, 'id'>
  prices!: EntityTable<IdRow, 'id'>
  taxes!: EntityTable<IdRow, 'id'>
  customers!: EntityTable<IdRow, 'id'>
  inventory_snapshot!: EntityTable<IdRow, 'id'>
  pos_config!: EntityTable<IdRow, 'id'>
  pending_sales!: EntityTable<IdRow, 'id'>
  sync_meta!: EntityTable<SyncMetaRow, 'key'>

  constructor() {
    super('retail_soln_pos')
    this.version(1).stores({
      products: '++id',
      prices: '++id',
      taxes: '++id',
      customers: '++id',
      inventory_snapshot: '++id',
      pos_config: '++id',
      pending_sales: '++id',
      sync_meta: 'key',
    })
  }
}

export const posDb = new PosDatabase()

/** Persist a stable device UUID in sync_meta on first open; reuse thereafter. */
export async function ensureDeviceUuid(db: PosDatabase = posDb): Promise<string> {
  return db.transaction('rw', db.sync_meta, async () => {
    const existing = await db.sync_meta.get(DEVICE_UUID_KEY)
    if (existing && typeof existing.value === 'string' && existing.value.length > 0) {
      return existing.value
    }

    const deviceUuid = createDeviceUuid()
    await db.sync_meta.put({ key: DEVICE_UUID_KEY, value: deviceUuid })
    return deviceUuid
  })
}
