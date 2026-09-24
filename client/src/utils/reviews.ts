/** Small display helpers shared by the review components and the deck cards. */

/** The most characters a review comment may have — mirrors `Reviews::MAX_COMMENT_LENGTH`. */
export const REVIEW_COMMENT_MAX = 256

/** "★ 4.6 · 12 reviews" — the card/hero line. Callers only render it when `count > 0`. */
export function formatRating(average: number, count: number): string {
  return `★ ${average.toFixed(1)} · ${count} ${count === 1 ? 'review' : 'reviews'}`
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

const UNITS: [limit: number, seconds: number, one: string, many: string][] = [
  [60 * 60, 60, 'a minute ago', 'minutes ago'],
  [60 * 60 * 24, 60 * 60, 'an hour ago', 'hours ago'],
  [60 * 60 * 24 * 7, 60 * 60 * 24, 'a day ago', 'days ago'],
  [60 * 60 * 24 * 30, 60 * 60 * 24 * 7, 'a week ago', 'weeks ago'],
  [60 * 60 * 24 * 365, 60 * 60 * 24 * 30, 'a month ago', 'months ago'],
  [Infinity, 60 * 60 * 24 * 365, 'a year ago', 'years ago'],
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
  if (seconds < 60) return 'just now'

  for (const [limit, unit, one, many] of UNITS) {
    if (seconds < limit) {
      const count = Math.floor(seconds / unit)
      return count <= 1 ? one : `${count} ${many}`
    }
  }

  return ''
}
