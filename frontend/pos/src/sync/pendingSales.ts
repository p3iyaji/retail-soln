import type { CompleteSaleInput } from '@/api/posSales'
import { ensureDeviceUuid, posDb, type PendingSaleEnvelope, type PosDatabase } from '@/db/posDb'

const LOCAL_SEQUENCE_KEY = 'localSequence'

function parseSequence(value: string | undefined): number {
  if (value == null || value === '') return 0
  const n = Number.parseInt(value, 10)
  return Number.isFinite(n) && n >= 0 ? n : 0
}

/** Monotonic local sequence per device, persisted in sync_meta. */
export async function nextLocalSequence(db: PosDatabase = posDb): Promise<number> {
  return db.transaction('rw', db.sync_meta, async () => {
    const existing = await db.sync_meta.get(LOCAL_SEQUENCE_KEY)
    const next = parseSequence(existing?.value) + 1
    await db.sync_meta.put({ key: LOCAL_SEQUENCE_KEY, value: String(next) })
    return next
  })
}

export type EnqueueSaleInput = {
  storeId: number
  userId: number
  terminalId: number | null
  /** Sale body; `uuid` becomes the envelope PK and idempotency key for 5.4. */
  payload: CompleteSaleInput
}

/**
 * Persist one offline sale envelope (`payloadType: sale`, `syncStatus: pending`).
 * Does not call the API or `/sync/push` (Story 5.4).
 */
export async function enqueueSale(
  input: EnqueueSaleInput,
  db: PosDatabase = posDb,
): Promise<PendingSaleEnvelope> {
  const deviceUuid = await ensureDeviceUuid(db)
  const uuid = input.payload.uuid

  if (!uuid || typeof uuid !== 'string') {
    throw new Error('Sale payload requires a client uuid')
  }

  return db.transaction('rw', db.sync_meta, db.pending_sales, async () => {
    const existing = await db.pending_sales.get(uuid)
    if (existing) {
      throw new Error('A sale with this uuid is already queued')
    }

    const seqRow = await db.sync_meta.get(LOCAL_SEQUENCE_KEY)
    const metaSeq = parseSequence(seqRow?.value)
    const lastRow = await db.pending_sales.orderBy('localSequence').last()
    const tableMax = lastRow?.localSequence ?? 0
    const localSequence = Math.max(metaSeq, tableMax) + 1
    await db.sync_meta.put({ key: LOCAL_SEQUENCE_KEY, value: String(localSequence) })

    const envelope: PendingSaleEnvelope = {
      uuid,
      deviceUuid,
      storeId: input.storeId,
      terminalId: input.terminalId,
      userId: input.userId,
      localSequence,
      createdAt: new Date().toISOString(),
      payloadType: 'sale',
      payload: { ...input.payload },
      syncStatus: 'pending',
    }

    await db.pending_sales.add(envelope)
    return envelope
  })
}

/** Ordered rows with syncStatus pending (test / later sync UI helpers). */
export async function listPendingSales(db: PosDatabase = posDb): Promise<PendingSaleEnvelope[]> {
  return db.pending_sales.where('syncStatus').equals('pending').sortBy('localSequence')
}
