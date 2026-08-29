import Dexie, { type EntityTable, type Table } from 'dexie'

export type SyncMetaRow = {
  key: string
  value: string
}

/** Placeholder row shape for stores filled by later Epic 5 stories. */
export type IdRow = {
  id?: number
}

export type CachedProductVariant = {
  id: number
  name: string
  sku: string
  barcode: string | null
  isActive: boolean
  isDefault?: boolean
  /** Variant list/base price (DECIMAL string). */
  price: string
}

export type CachedProduct = {
  id: number
  name: string
  sku: string
  barcode: string | null
  categoryId: number | null
  isActive: boolean
  variants: CachedProductVariant[]
}

export type CachedPrice = {
  variantId: number
  storeId: number
  unitPrice: string
  listPrice: string
}

export type CachedTax = {
  storeId: number
  name: string
  rate: string
  inclusive: boolean
}

export type CachedInventorySnapshot = {
  variantId: number
  storeId: number
  available: string
}

export type CachedPosConfig = {
  storeId: number
  storeName: string
  currency: string
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
  products!: EntityTable<CachedProduct, 'id'>
  prices!: Table<CachedPrice, [number, number]>
  taxes!: EntityTable<CachedTax, 'storeId'>
  customers!: EntityTable<IdRow, 'id'>
  inventory_snapshot!: Table<CachedInventorySnapshot, [number, number]>
  pos_config!: EntityTable<CachedPosConfig, 'storeId'>
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
    // Drop placeholder tables before redefining primary keys (Dexie cannot alter PK in place).
    this.version(2).stores({
      products: null,
      prices: null,
      taxes: null,
      inventory_snapshot: null,
      pos_config: null,
    })
    // Typed catalog cache (Story 5.2).
    this.version(3).stores({
      products: 'id, sku, barcode, categoryId',
      prices: '[variantId+storeId], storeId, variantId',
      taxes: 'storeId',
      customers: '++id',
      inventory_snapshot: '[variantId+storeId], storeId, variantId',
      pos_config: 'storeId',
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
