import { t } from '@/i18n'
/**
 * Booking date-times arrive as UTC `YYYY-MM-DD HH:MM:SS` strings (see
 * `Bookings.php`); the UI shows them in the viewer's own zone.
 */

/** Parses a server UTC date-time; `null` for a missing or unparseable one. */
export function utcDate(value: string | null | undefined): Date | null {
  if (!value) return null
  const date = new Date(`${value.replace(' ', 'T')}Z`)
  return Number.isNaN(date.getTime()) ? null : date
}

/** "Tue 6 Oct, 14:30" in the viewer's locale; `null` without a date. */
export function formatWhen(date: Date | null): string | null {
  return date
    ? date.toLocaleString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
      })
    : null
}

/** When a trip of `hours` starting at `start` ends; `null` unless both are known. */
export function tripEnd(start: Date | null, hours: number | null): Date | null {
  return start && hours ? new Date(start.getTime() + hours * 3600_000) : null
}

/** In the viewer's currency — see `utils/money.ts` (kept here for the many existing imports). */
export { formatMoney } from './money'

/** "3 h", "1.5 h"; `null` when not set. */
export function formatHours(hours: number | null): string | null {
  return hours ? t('common.booking_time.hours', { hours }) : null
}
