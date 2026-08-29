import { api } from '@/api/client'

export type ReturnableSaleItem = {
  id: number
  variantId: number
  qty: string
  returnedQty: string
  returnableQty: string
  unitPrice: string
  discount: string
  taxAmount: string
  lineTotal: string
  variant?: { name: string; sku: string } | null
}

export type ReturnableSale = {
  id: number
  uuid: string
  total: string
  taxTotal: string
  subtotal: string
  status: string
  invoice?: { invoiceNumber: string | null } | null
  items: ReturnableSaleItem[]
  payments: Array<{ method: string; amount: string }>
  completedAt?: string | null
}

export type SaleReturnResource = {
  id: number
  uuid: string
  saleId: number
  total: string
  reason: string
  items: Array<{ id: number; saleItemId: number; qty: string; lineTotal: string }>
  refunds: Array<{ method: string; amount: string }>
}

export async function fetchReturnableSale(saleId: number): Promise<{ data: ReturnableSale }> {
  return api.get(`/pos/sales/${saleId}/returnable`)
}

export type ProcessReturnInput = {
  uuid: string
  saleId: number
  sessionId?: number | null
  reason: string
  items: Array<{ saleItemId: number; qty: string }>
  refunds: Array<{ method: 'cash' | 'card' | 'store_credit'; amount: string; provider: 'manual' }>
}

export async function processReturn(
  body: ProcessReturnInput,
  idempotencyKey: string,
): Promise<{ data: SaleReturnResource }> {
  return api.post('/pos/returns', body, { 'Idempotency-Key': idempotencyKey })
}
