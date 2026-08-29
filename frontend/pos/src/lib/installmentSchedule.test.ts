import { describe, expect, it } from 'vitest'
import {
  buildEqualInstallments,
  installmentsMatchBalance,
  sumInstallments,
} from '@/lib/installmentSchedule'

describe('installmentSchedule', () => {
  it('splits balance into equal installments with remainder on last line', () => {
    const rows = buildEqualInstallments('10.7500', 2, new Date('2026-08-28'))
    expect(rows).toHaveLength(2)
    expect(sumInstallments(rows)).toBe('10.7500')
    expect(installmentsMatchBalance(rows, '10.7500')).toBe(true)
  })
})
