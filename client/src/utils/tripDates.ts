import { currentLanguage } from '@/i18n'

/**
 * Trip stops carry plain calendar dates (`YYYY-MM-DD`, no time or zone), so
 * they're read and printed in UTC — a stop on 3 May is 3 May wherever the
 * viewer is.
 */

function calendarDate(value: string): Date {
  return new Date(`${value}T00:00:00Z`)
}

/** "3–5 May", "30 Apr – 2 May", "28 Dec 2026 – 2 Jan 2027" in the UI language (year only when not this year). */
export function formatStay(arriveOn: string, departOn: string, now: Date = new Date()): string {
  const from = calendarDate(arriveOn)
  const to = calendarDate(departOn)
  const thisYear = now.getUTCFullYear()
  const showYear = from.getUTCFullYear() !== thisYear || to.getUTCFullYear() !== thisYear
  const format = new Intl.DateTimeFormat(currentLanguage.value, {
    day: 'numeric',
    month: 'short',
    ...(showYear ? { year: 'numeric' } : {}),
    timeZone: 'UTC',
  })
  if (arriveOn === departOn) return format.format(from)
  // `formatRange` is ES2021 — newer than the TypeScript target's lib, but in every supported browser.
  const ranged = format as Intl.DateTimeFormat & {
    formatRange?: (start: Date, end: Date) => string
  }
  return ranged.formatRange
    ? ranged.formatRange(from, to)
    : `${format.format(from)} – ${format.format(to)}`
}

/** Today as `YYYY-MM-DD` in the viewer's own zone — the earliest date a stop can start. */
export function todayIso(now: Date = new Date()): string {
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 10)
}
