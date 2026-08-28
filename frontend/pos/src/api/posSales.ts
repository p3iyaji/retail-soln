import { api } from '@/api/client'

export type SalePaymentInput = {
  method: 'cash' | 'card' | 'bank' | 'mobile' | 'store_credit'
  amount: string
  provider: 'manual'
  reference?: string | null
}

export type CompleteSaleInput = {
  uuid: string
  customerId?: number | null
  sessionId: number
  terminalId?: number | null
  items: Array<{
    variantId: number
    qty: string
    unitPrice: string
    discount: string
  }>
  payments: SalePaymentInput[]
}

export type SaleResource = {
  id: number
  uuid: string
  total: string
  taxTotal: string
  subtotal: string
  status: string
  customer?: { id: number; name: string; isWalkIn: boolean } | null
  items: Array<{
    variantId: number
    qty: string
    unitPrice?: string
    discount?: string
    taxAmount?: string
    lineTotal: string
    variant?: { name: string; sku: string } | null
  }>
  payments: Array<{ method: string; amount: string }>
  invoice?: { invoiceNumber: string | null; status: string } | null
}

export type ReceiptResource = {
  saleId: number
  saleUuid: string
  total: string
  taxTotal: string
  subtotal: string
  invoiceNumber?: string | null
  completedAt?: string | null
  customer?: { name: string } | null
  store?: { name: string } | null
  items: SaleResource['items']
  payments: SaleResource['payments']
}

export type HoldSaleInput = {
  uuid: string
  customerId?: number | null
  notes?: string | null
  items: CompleteSaleInput['items']
}

export async function holdSale(body: HoldSaleInput): Promise<{ data: SaleResource }> {
  return api.post('/pos/sales/hold', body)
}

export async function listHeldSales(): Promise<{ data: SaleResource[] }> {
  return api.get('/pos/sales/held')
}

export async function voidSale(saleId: number, reason?: string): Promise<{ data: SaleResource }> {
  return api.post(`/pos/sales/${saleId}/void`, reason ? { reason } : {})
}

export async function completeSale(
  body: CompleteSaleInput,
  idempotencyKey: string,
): Promise<{ data: SaleResource }> {
  return api.post('/pos/sales', body, { 'Idempotency-Key': idempotencyKey })
}

export async function fetchReceipt(saleId: number): Promise<{ data: ReceiptResource }> {
  return api.get(`/pos/receipts/${saleId}`)
}
