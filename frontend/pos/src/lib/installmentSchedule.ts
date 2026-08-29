import { decimalAdd, decimalCmp, decimalDiv, formatCurrency, moneyToApi, roundCurrency } from '@/lib/decimal'

export type InstallmentDraft = {
  sequence: number
  amount: string
  dueAt: string
}

export function addMonthsIso(date: Date, months: number): string {
  const next = new Date(date)
  next.setMonth(next.getMonth() + months)
  return next.toISOString().slice(0, 10)
}

/** Split balance into equal 2dp installments; remainder goes to the last line. */
export function buildEqualInstallments(balance: string, count: number, startDate = new Date()): InstallmentDraft[] {
  if (count < 1) return []
  const total = roundCurrency(balance)
  const base = roundCurrency(decimalDiv(total, String(count)))
  const rows: InstallmentDraft[] = []
  let allocated = roundCurrency('0')

  for (let i = 0; i < count; i += 1) {
    const isLast = i === count - 1
    const amount = isLast ? roundCurrency(decimalAdd(total, `-${allocated}`)) : base
    allocated = decimalAdd(allocated, amount)
    rows.push({
      sequence: i + 1,
      amount: formatCurrency(amount),
      dueAt: addMonthsIso(startDate, i + 1),
    })
  }

  return rows
}

export function sumInstallments(rows: InstallmentDraft[]): string {
  return rows.reduce((sum, row) => decimalAdd(sum, parseCurrency(row.amount)), roundCurrency('0'))
}

export function parseCurrency(value: string): string {
  const trimmed = value.trim()
  if (trimmed === '') return roundCurrency('0')
  return roundCurrency(trimmed)
}

export function installmentsMatchBalance(rows: InstallmentDraft[], balance: string): boolean {
  return decimalCmp(sumInstallments(rows), roundCurrency(balance)) === 0
}

export function toInstallmentPlanPayload(rows: InstallmentDraft[]): {
  mode: 'pay_over_time'
  installments: Array<{ sequence: number; amount: string; dueAt: string }>
} {
  return {
    mode: 'pay_over_time',
    installments: rows.map((row) => ({
      sequence: row.sequence,
      amount: moneyToApi(parseCurrency(row.amount)),
      dueAt: row.dueAt,
    })),
  }
}

export function availableCredit(creditLimit: string, balance: string): string {
  const available = decimalAdd(creditLimit, `-${balance}`)
  return decimalCmp(available, roundCurrency('0')) < 0 ? roundCurrency('0') : roundCurrency(available)
}
