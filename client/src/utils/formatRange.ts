import type { NumericRange } from '@/types'

export type RangeFormat = 'number' | 'hour'

/** `9` → `"09:00"` for an hour category, `"9"` for anything else. */
export function formatRangeValue(value: number, format: RangeFormat = 'number'): string {
  return format === 'hour' ? `${String(value).padStart(2, '0')}:00` : String(value)
}

/** One span as a label, e.g. `"09:00–13:00"` or `"25–40"`. */
export function formatRange(range: NumericRange, format: RangeFormat = 'number'): string {
  return `${formatRangeValue(range[0], format)}–${formatRangeValue(range[1], format)}`
}

/**
 * Cosmetics only: hours read as times, everything else as plain numbers.
 *
 * This is the one place a category key is named in the client, and
 * deliberately so — it changes how a number is *printed*, nothing else. A
 * range category nobody has taught this about still works end to end; its
 * spans just render as bare numbers.
 */
export function rangeFormatFor(categoryKey: string): RangeFormat {
  return categoryKey === 'hour_of_day' ? 'hour' : 'number'
}
