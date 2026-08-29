import { api } from '@/api/client'

export type InstallmentLine = {
  id: number
  sequence: number
  amount: string
  paidAmount: string
  balanceDue: string
  dueAt: string
  status: string
  isOverdue: boolean
  paidAt?: string | null
}

export type InstallmentPlanResource = {
  id: number
  saleId: number | null
  customerId: number
  storeId: number
  principal: string
  feeTotal: string
  outstandingBalance: string
  mode: string
  status: string
  customer?: {
    id: number
    name: string
    accountNumber: string
    phone: string | null
  } | null
  installments: InstallmentLine[]
  createdAt?: string | null
}

export type InstallmentPaymentResource = {
  id: number
  planId: number
  installmentId: number
  method: string
  provider: string
  amount: string
  reference?: string | null
  collectedAt?: string | null
  statement?: {
    planId: number
    saleId: number | null
    customerName?: string | null
    paymentAmount: string
    installmentSequence?: number | null
    planOutstanding: string
    planStatus: string
    collectedAt?: string | null
  } | null
}

export async function listInstallmentPlans(params: {
  q?: string
  customerId?: number
  overdueOnly?: boolean
}): Promise<{ data: InstallmentPlanResource[] }> {
  const search = new URLSearchParams()
  search.set('status', 'open')
  if (params.q) search.set('q', params.q)
  if (params.customerId != null) search.set('customerId', String(params.customerId))
  if (params.overdueOnly) search.set('overdueOnly', '1')
  return api.get(`/installment-plans?${search.toString()}`)
}

export async function fetchInstallmentPlan(planId: number): Promise<{ data: InstallmentPlanResource }> {
  return api.get(`/installment-plans/${planId}`)
}

export async function collectInstallmentPayment(
  planId: number,
  body: {
    installmentId: number
    sessionId: number
    payment: { method: 'cash' | 'card'; amount: string; provider: 'manual' }
  },
  idempotencyKey: string,
): Promise<{ data: InstallmentPaymentResource }> {
  return api.post(`/installment-plans/${planId}/payments`, body, { 'Idempotency-Key': idempotencyKey })
}

export function nextCollectibleInstallment(plan: InstallmentPlanResource): InstallmentLine | null {
  const open = plan.installments.filter(
    (line) => line.status !== 'paid' && line.status !== 'waived' && Number(line.balanceDue) > 0,
  )
  if (open.length === 0) return null
  const overdue = open.filter((line) => line.isOverdue || line.status === 'overdue')
  const pool = overdue.length > 0 ? overdue : open
  return [...pool].sort((a, b) => a.sequence - b.sequence)[0] ?? null
}
