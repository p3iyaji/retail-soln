/** Scale-4 decimal helpers (string-safe; avoids float money drift). */

const SCALE = 4
const FACTOR = 10n ** BigInt(SCALE)

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
