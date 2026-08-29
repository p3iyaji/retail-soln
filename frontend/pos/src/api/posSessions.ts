import { api } from '@/api/client'

export type CashierSessionResource = {
  id: number
  storeId: number
  terminalId: number
  userId: number
  status: 'open' | 'closed'
  openingFloat: string
  expectedCash?: string | null
  closingCash?: string | null
  variance?: string | null
  openedAt?: string | null
  closedAt?: string | null
  notes?: string | null
  terminal?: { id: number; name: string; uuid: string } | null
  cashier?: { id: number; name: string } | null
  summary?: {
    cashIn: string
    cashOut: string
    saleCash: string
    refundCash: string
  } | null
}

export async function openCashierSession(body: {
  terminalName: string
  openingFloat: string
}): Promise<{ data: CashierSessionResource }> {
  return api.post('/pos/sessions/open', body)
}

export async function fetchCurrentSession(
  terminalName: string,
): Promise<{ data: CashierSessionResource | null }> {
  return api.get(`/pos/sessions/current?terminalName=${encodeURIComponent(terminalName)}`)
}

export async function closeCashierSession(
  sessionId: number,
  body: { closingCash: string; notes?: string | null },
): Promise<{ data: CashierSessionResource }> {
  return api.post(`/pos/sessions/${sessionId}/close`, body)
}

export async function recordCashMovement(
  sessionId: number,
  body: { type: 'cash_in' | 'cash_out'; amount: string; notes?: string | null },
): Promise<{ data: { id: number; type: string; amount: string } }> {
  return api.post(`/pos/sessions/${sessionId}/cash-movements`, body)
}
