import { completeSale, type CompleteSaleInput, type SaleResource } from '@/api/posSales'
import { createClientUuid, type PendingSaleEnvelope } from '@/db/posDb'
import { enqueueSale, type EnqueueSaleInput } from '@/sync/pendingSales'

export const OFFLINE_INSTALLMENT_MESSAGE =
  'Installment plans require an online connection — complete them when back online'

export const OFFLINE_HELD_SALE_MESSAGE =
  'Held sales must be completed online — resume when connected'

export type SaleCheckoutBodyInput = Omit<CompleteSaleInput, 'uuid'>

export type RunSaleCheckoutInput = {
  offline: boolean
  useInstallmentPlan: boolean
  /** When set offline, block — server hold cannot be voided without network. */
  activeHeldSaleId?: number | null
  body: SaleCheckoutBodyInput
  /** Required when offline. */
  enqueue?: Omit<EnqueueSaleInput, 'payload'>
  /** Injected for tests; defaults to API / Dexie helpers. */
  completeSaleFn?: typeof completeSale
  enqueueSaleFn?: typeof enqueueSale
  newUuid?: () => string
}

export type SaleCheckoutResult =
  | { kind: 'queued'; envelope: PendingSaleEnvelope }
  | { kind: 'completed'; sale: SaleResource }

/**
 * Offline: enqueue Dexie envelope (block installment create).
 * Online: POST /pos/sales with body.uuid === Idempotency-Key.
 */
export async function runSaleCheckout(input: RunSaleCheckoutInput): Promise<SaleCheckoutResult> {
  if (input.offline && input.useInstallmentPlan) {
    throw new Error(OFFLINE_INSTALLMENT_MESSAGE)
  }
  if (input.offline && input.activeHeldSaleId != null) {
    throw new Error(OFFLINE_HELD_SALE_MESSAGE)
  }

  const newUuid = input.newUuid ?? createClientUuid
  const uuid = newUuid()
  const payload: CompleteSaleInput = { ...input.body, uuid }

  if (input.offline) {
    if (!input.enqueue) {
      throw new Error('Offline checkout requires store and user context')
    }
    const enqueueFn = input.enqueueSaleFn ?? enqueueSale
    const envelope = await enqueueFn({
      ...input.enqueue,
      payload,
    })
    return { kind: 'queued', envelope }
  }

  const post = input.completeSaleFn ?? completeSale
  const res = await post(payload, uuid)
  return { kind: 'completed', sale: res.data }
}

export function isBrowserOffline(): boolean {
  return typeof navigator !== 'undefined' && navigator.onLine === false
}
