/** Scale-4 decimal helpers (string-safe; avoids float money drift). */

const SCALE = 4
const FACTOR = 10n ** BigInt(SCALE)

/** Business currency uses 2 decimal places (half-up). */
export const CURRENCY_SCALE = 2
const CURRENCY_FACTOR = 10n ** BigInt(CURRENCY_SCALE)
const CURRENCY_ROUND_HALF = CURRENCY_FACTOR / 2n

export function isDecimalString(value: string): boolean {
  return /^-?\d+(\.\d{1,4})?$/.test(value)
}

export function toScaled(value: string): bigint {
  const negative = value.startsWith('-')
  const raw = negative ? value.slice(1) : value
  const [whole, frac = ''] = raw.split('.')
  const scaled = BigInt(`${whole}${frac.padEnd(SCALE, '0').slice(0, SCALE)}`)
  return negative ? -scaled : scaled
}

export function fromScaled(total: bigint): string {
  const negative = total < 0n
  const abs = negative ? -total : total
  const raw = abs.toString().padStart(SCALE + 1, '0')
  const whole = raw.slice(0, -SCALE) || '0'
  const frac = raw.slice(-SCALE)
  return `${negative ? '-' : ''}${whole}.${frac}`
}

/** Round half-up to 2dp; returns a scale-4 string for API compatibility. */
export function roundCurrency(value: string): string {
  const scaled = toScaled(value || '0')
  const adjusted = scaled >= 0n ? scaled + CURRENCY_ROUND_HALF : scaled - CURRENCY_ROUND_HALF
  const rounded = (adjusted / CURRENCY_FACTOR) * CURRENCY_FACTOR
  return fromScaled(rounded)
}

export function formatCurrency(value: string): string {
  const rounded = roundCurrency(value)
  const n = Number(rounded)
  return Number.isFinite(n) ? n.toFixed(CURRENCY_SCALE) : rounded
}

/** Normalize user tender input to 2dp currency (scale-4 string). */
export function parseCurrencyInput(value: string): string {
  const trimmed = value.trim()
  if (trimmed === '' || trimmed === '-') {
    return roundCurrency('0')
  }
  if (!/^-?\d+(\.\d{0,4})?$/.test(trimmed)) {
    return roundCurrency('0')
  }
  return roundCurrency(trimmed)
}

/** Scale-4 API money string from a currency amount. */
export function moneyToApi(value: string): string {
  return roundCurrency(value)
}

export function decimalCmp(a: string, b: string): number {
  const left = toScaled(a)
  const right = toScaled(b)
  if (left < right) return -1
  if (left > right) return 1
  return 0
}

export function decimalAdd(a: string, b: string): string {
  return fromScaled(toScaled(a) + toScaled(b))
}

export function decimalSub(a: string, b: string): string {
  return fromScaled(toScaled(a) - toScaled(b))
}

/** Multiply two decimals; result rounded half-up to 4dp. */
export function decimalMul(a: string, b: string): string {
  const product = toScaled(a) * toScaled(b)
  const half = FACTOR / 2n
  const adjusted = product >= 0n ? product + half : product - half
  return fromScaled(adjusted / FACTOR)
}

/** Divide a by b; result rounded half-up to 4dp. Throws if b is zero. */
export function decimalDiv(a: string, b: string): string {
  const divisor = toScaled(b)
  if (divisor === 0n) {
    throw new Error('Division by zero')
  }
  const numerator = toScaled(a) * FACTOR
  const half = divisor >= 0n ? divisor / 2n : -(divisor / 2n)
  const adjusted = numerator >= 0n === divisor >= 0n ? numerator + half : numerator - half
  return fromScaled(adjusted / divisor)
}

export function money(value: string): string {
  if (!isDecimalString(value)) {
    throw new Error(`Invalid decimal: ${value}`)
  }
  return fromScaled(toScaled(value))
}
