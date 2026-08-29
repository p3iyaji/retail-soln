import { api } from '@/api/client'

export type CustomerResource = {
  id: number
  name: string
  phone: string | null
  email: string | null
  accountNumber: string
  isWalkIn: boolean
  creditLimit: string
  balance: string
  isActive: boolean
}

export async function searchCustomers(q: string): Promise<{ data: CustomerResource[] }> {
  const params = new URLSearchParams({ q, activeOnly: '1', per_page: '20' })
  return api.get(`/customers?${params.toString()}`)
}

export async function fetchWalkInCustomer(): Promise<{ data: CustomerResource }> {
  return api.get('/customers/walk-in')
}
