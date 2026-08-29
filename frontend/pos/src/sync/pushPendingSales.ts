import { api, ApiError, formatDeviceSyncError } from '@/api/client'
import { ensureDeviceUuid, posDb, type PosDatabase } from '@/db/posDb'
import { useSyncStore } from '@/stores/sync'
import {
  listPushableSales,
  markSaleError,
  markSalePushing,
  markSaleSynced,
} from '@/sync/pendingSales'

const DEFAULT_BATCH_MAX = 50

export type SyncPushItem = {
  uuid: string
  status: 'synced' | 'error'
  saleId?: number
  exceptionId?: number
  errors?: string[]
}

export type SyncPushResponse = {
  data: {
    batchId: number
    items: SyncPushItem[]
  }
}

export type PushPendingOptions = {
  /** Override max records per POST (tests). */
  batchMax?: number
}

type DrainSession = {
  hadError: boolean
  lastError: string | null
}

let drainInFlight: Promise<void> | null = null

/**
 * Push pending offline sales to `POST /sync/push` and update Dexie + sync badge.
 * Safe to call on reconnect; concurrent calls coalesce.
 */
export async function pushPendingSales(
  db: PosDatabase = posDb,
  options: PushPendingOptions = {},
): Promise<void> {
  if (drainInFlight) {
    return drainInFlight
  }

  drainInFlight = runDrain(db, options, new Set(), { hadError: false, lastError: null }).finally(
    () => {
      drainInFlight = null
    },
  )

  return drainInFlight
}

async function runDrain(
  db: PosDatabase,
  options: PushPendingOptions,
  skipUuids: Set<string>,
  session: DrainSession,
): Promise<void> {
  const sync = useSyncStore()
  const batchMax = options.batchMax ?? DEFAULT_BATCH_MAX

  let pushable
  try {
    pushable = (await listPushableSales(db)).filter((row) => !skipUuids.has(row.uuid))
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Failed to read pending sales'
    sync.setSyncError(message)
    return
  }

  if (pushable.length === 0) {
    if (session.hadError) {
      sync.setSyncError(session.lastError ?? 'Sync failed')
    } else if (sync.status === 'SYNCING') {
      sync.setOnline()
    }
    return
  }

  // Clear prior SYNC_ERROR only on the first batch of this drain session.
  if (skipUuids.size === 0 && sync.status === 'SYNC_ERROR') {
    sync.clearError(true)
  }

  sync.setSyncing()

  let deviceUuid: string
  const batch = pushable.slice(0, batchMax)

  try {
    deviceUuid = await ensureDeviceUuid(db)
    for (const row of batch) {
      await markSalePushing(row.uuid, db)
    }
  } catch (err) {
    const message =
      err instanceof Error ? err.message : 'Failed to prepare sync push'
    sync.setSyncError(message)
    return
  }

  const body = {
    deviceUuid,
    records: batch.map((row) => ({
      uuid: row.uuid,
      localSequence: row.localSequence,
      payloadType: row.payloadType,
      createdAt: row.createdAt,
      payload: row.payload,
    })),
  }

  try {
    const response = await api.post<SyncPushResponse>('/sync/push', body)
    const byUuid = new Map(response.data.items.map((item) => [item.uuid, item]))
    let anyError = false
    const errorMessages: string[] = []

    const oversellNotices: Array<{ uuid: string; exceptionId: number; saleId?: number }> = []

    for (const row of batch) {
      skipUuids.add(row.uuid)
      const item = byUuid.get(row.uuid)
      if (item == null) {
        anyError = true
        errorMessages.push(`Missing sync result for ${row.uuid}`)
        await markSaleError(row.uuid, { errors: ['Missing sync result'] }, db)
        continue
      }

      if (item.status === 'synced') {
        await markSaleSynced(row.uuid, item, db)
        if (item.exceptionId != null) {
          oversellNotices.push({
            uuid: row.uuid,
            exceptionId: item.exceptionId,
            saleId: item.saleId,
          })
        }
      } else {
        anyError = true
        const msg = item.errors?.join('; ') ?? 'Sync item failed'
        errorMessages.push(msg)
        await markSaleError(row.uuid, item, db)
      }
    }

    if (oversellNotices.length > 0) {
      sync.addOversellNotices(oversellNotices)
    }

    if (anyError) {
      session.hadError = true
      session.lastError = errorMessages[0] ?? 'Sync failed'
      sync.setSyncError(session.lastError)
    }

    const remaining = (await listPushableSales(db)).filter((row) => !skipUuids.has(row.uuid))
    if (remaining.length > 0) {
      await runDrain(db, options, skipUuids, session)
      return
    }

    if (session.hadError) {
      sync.setSyncError(session.lastError ?? 'Sync failed')
    } else {
      sync.setOnline()
    }
  } catch (err) {
    const message =
      err instanceof ApiError
        ? formatDeviceSyncError(err)
        : err instanceof Error
          ? err.message
          : 'Sync push failed'

    const deviceGate =
      err instanceof ApiError &&
      err.status === 403 &&
      (err.code === 'device_disabled' ||
        err.code === 'device_unregistered' ||
        // Legacy/unknown 403: still treat as non-retryable device gate.
        err.code == null)

    // Transport / network failure: leave as pushing so reconnect retries.
    // Device gate (403): mark error; badge still SYNC ERROR either way.
    if (deviceGate) {
      for (const row of batch) {
        await markSaleError(row.uuid, { errors: [message] }, db)
      }
    }

    sync.setSyncError(message)
  }
}
