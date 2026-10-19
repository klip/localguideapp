/** Small display helpers shared by the review components and the deck cards. */
import { t, tn } from '@/i18n'

/** The most characters a review comment may have — mirrors `Reviews::MAX_COMMENT_LENGTH`. */
export const REVIEW_COMMENT_MAX = 256

/** "★ 4.6 · 12 reviews" — the card/hero line (the star is added here, not in the translation). Callers only render it when `count > 0`. */
export function formatRating(average: number, count: number): string {
  return `★ ${tn('common.rating.summary', count, { average: average.toFixed(1) })}`
}

/**
 * Length the way the server counts it (`mb_strlen`): code points, so an
 * emoji is one character, where `String.length` (and the `maxlength`
 * attribute) would count two.
 */
export function characterCount(text: string): number {
  return Array.from(text).length
}

/** First letter of a name for the avatar fallback, upper-cased; "?" for an empty one. */
export function initialOf(name: string): string {
  return (Array.from(name.trim())[0] ?? '?').toUpperCase()
}

/** `unit` names the keys: `common.time_ago.<unit>_single` for "a minute ago", plurals for the rest. */
const UNITS: [limit: number, seconds: number, unit: string][] = [
  [60 * 60, 60, 'minute'],
  [60 * 60 * 24, 60 * 60, 'hour'],
  [60 * 60 * 24 * 7, 60 * 60 * 24, 'day'],
  [60 * 60 * 24 * 30, 60 * 60 * 24 * 7, 'week'],
  [60 * 60 * 24 * 365, 60 * 60 * 24 * 30, 'month'],
  [Infinity, 60 * 60 * 24 * 365, 'year'],
]

/**
 * Google-Maps-style relative date: "just now", "3 hours ago", "a week ago",
 * "2 months ago". `iso` is what the API sends (`2026-09-24T10:00:00Z`); a
 * timestamp slightly in the future (clock skew) reads as "just now".
 */
export function timeAgo(iso: string, now: Date = new Date()): string {
  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return ''

  const seconds = Math.floor((now.getTime() - then) / 1000)
  if (seconds < 60) return t('common.time_ago.just_now')

  for (const [limit, size, unit] of UNITS) {
    if (seconds < limit) {
      const count = Math.floor(seconds / size)
      return count <= 1
        ? t(`common.time_ago.${unit}_single`)
        : tn(`common.time_ago.${unit}s`, count)
    }
  }

  return ''
}
