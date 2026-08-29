import { describe, expect, it, vi } from 'vitest'
import type { CompleteSaleInput, SaleResource } from '@/api/posSales'
import type { PendingSaleEnvelope } from '@/db/posDb'
import {
  OFFLINE_HELD_SALE_MESSAGE,
  OFFLINE_INSTALLMENT_MESSAGE,
  runSaleCheckout,
} from '@/sync/saleCheckout'

const baseBody = {
  customerId: null as number | null,
  sessionId: 1,
  terminalId: 5 as number | null,
  items: [{ variantId: 10, qty: '1.0000', unitPrice: '5.0000', discount: '0.0000' }],
  payments: [{ method: 'cash' as const, amount: '5.0000', provider: 'manual' as const }],
}

describe('runSaleCheckout', () => {
  it('blocks offline installment plan create without writing', async () => {
    const enqueueSaleFn = vi.fn()
    const completeSaleFn = vi.fn()

    await expect(
      runSaleCheckout({
        offline: true,
        useInstallmentPlan: true,
        body: baseBody,
        enqueue: { storeId: 1, userId: 2, terminalId: null },
        enqueueSaleFn,
        completeSaleFn,
      }),
    ).rejects.toThrow(OFFLINE_INSTALLMENT_MESSAGE)

    expect(enqueueSaleFn).not.toHaveBeenCalled()
    expect(completeSaleFn).not.toHaveBeenCalled()
  })

  it('blocks offline checkout of an active held sale without writing', async () => {
    const enqueueSaleFn = vi.fn()
    const completeSaleFn = vi.fn()

    await expect(
      runSaleCheckout({
        offline: true,
        useInstallmentPlan: false,
        activeHeldSaleId: 42,
        body: baseBody,
        enqueue: { storeId: 1, userId: 2, terminalId: null },
        enqueueSaleFn,
        completeSaleFn,
      }),
    ).rejects.toThrow(OFFLINE_HELD_SALE_MESSAGE)

    expect(enqueueSaleFn).not.toHaveBeenCalled()
    expect(completeSaleFn).not.toHaveBeenCalled()
  })

  it('enqueues offline cash/card sale via Dexie helper', async () => {
    const envelope = {
      uuid: 'fixed-uuid',
      syncStatus: 'pending',
      payloadType: 'sale',
    } as PendingSaleEnvelope
    const enqueueSaleFn = vi.fn().mockResolvedValue(envelope)
    const completeSaleFn = vi.fn()

    const result = await runSaleCheckout({
      offline: true,
      useInstallmentPlan: false,
      body: baseBody,
      enqueue: { storeId: 1, userId: 2, terminalId: 5 },
      enqueueSaleFn,
      completeSaleFn,
      newUuid: () => 'fixed-uuid',
    })

    expect(result).toEqual({ kind: 'queued', envelope })
    expect(completeSaleFn).not.toHaveBeenCalled()
    expect(enqueueSaleFn).toHaveBeenCalledTimes(1)
    const arg = enqueueSaleFn.mock.calls[0]![0] as {
      storeId: number
      payload: CompleteSaleInput
    }
    expect(arg.storeId).toBe(1)
    expect(arg.payload.uuid).toBe('fixed-uuid')
    expect(arg.payload.payments[0]?.method).toBe('cash')
  })

  it('online path calls completeSale with uuid === Idempotency-Key (no Dexie)', async () => {
    const sale = { id: 99, uuid: 'online-uuid', total: '5.0000' } as SaleResource
    const completeSaleFn = vi.fn().mockResolvedValue({ data: sale })
    const enqueueSaleFn = vi.fn()

    const result = await runSaleCheckout({
      offline: false,
      useInstallmentPlan: false,
      body: baseBody,
      completeSaleFn,
      enqueueSaleFn,
      newUuid: () => 'online-uuid',
    })

    expect(result).toEqual({ kind: 'completed', sale })
    expect(enqueueSaleFn).not.toHaveBeenCalled()
    expect(completeSaleFn).toHaveBeenCalledTimes(1)
    const [body, idempotencyKey] = completeSaleFn.mock.calls[0]!
    expect((body as CompleteSaleInput).uuid).toBe('online-uuid')
    expect(idempotencyKey).toBe('online-uuid')
    expect(idempotencyKey).toBe((body as CompleteSaleInput).uuid)
  })
})
