import type { NumericRange } from '@/types'

export type RangeFormat = 'number' | 'hour' | 'km'

/** `9` → `"09:00"` for an hour category, `"9 km"` for a distance, `"9"` for anything else. */
export function formatRangeValue(value: number, format: RangeFormat = 'number'): string {
  if (format === 'hour') return `${String(value).padStart(2, '0')}:00`
  return format === 'km' ? `${value} km` : String(value)
}

/** One span as a label, e.g. `"09:00–13:00"`, `"0–5 km"` or `"25–40"`. */
export function formatRange(range: NumericRange, format: RangeFormat = 'number'): string {
  if (format === 'km') return `${range[0]}–${range[1]} km`
  return `${formatRangeValue(range[0], format)}–${formatRangeValue(range[1], format)}`
}

/**
 * Cosmetics only: hours read as times, everything else as plain numbers.
 *
 * This is the one place category keys are named in the client, and
 * deliberately so — it changes how a number is *printed*, nothing else. A
 * range category nobody has taught this about still works end to end; its
 * spans just render as bare numbers.
 */
export function rangeFormatFor(categoryKey: string): RangeFormat {
  if (categoryKey === 'hour_of_day') return 'hour'
  return categoryKey === 'distance' ? 'km' : 'number'
}
