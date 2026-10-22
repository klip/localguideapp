import type { AppNotification } from '@/plugins/api'

/**
 * Where a notification's toast leads when clicked or tapped (`App.vue`), by
 * its `kind` (see "In-app notifications" in CLAUDE.md):
 *
 *   a request still waiting on someone          → Matches
 *   anything about a confirmed or finished trip → My trips
 *   a new paid-guide match                      → the chat with them
 *   someone updating their profile              → their profile
 *   a level or a bonus package                  → "Your level" on Profile
 *
 * `null` for a kind it doesn't know — that toast just isn't a link.
 */
const BY_KIND: Record<string, string> = {
  booking_requested: '/matches',
  booking_declined: '/matches',
  booking_withdrawn: '/matches',
  booking_expired: '/matches',
  request_reminder: '/matches',
  request_expiring: '/matches',
  request_location: '/matches',
  match: '/matches',
  profile_updated: '/matches',
  booking_accepted: '/trips',
  booking_free: '/trips',
  booking_cancelled: '/trips',
  cancel_requested: '/trips',
  cancel_request_withdrawn: '/trips',
  cancel_approved: '/trips',
  cancel_refused: '/trips',
  finish_requested: '/trips',
  finish_refused: '/trips',
  trip_finished: '/trips',
  trip_location: '/trips',
  trip_reminder: '/trips',
  trip_not_started: '/trips',
  trip_disputed: '/trips',
  trip_dispute_settled: '/trips',
  trip_started: '/trips',
  level_up: '/profile#level',
  bonus_granted: '/profile#level',
}

export function notificationLink(
  note: AppNotification,
  viewerRole: string | null | undefined,
): string | null {
  // The side it's about (an account can hold both), else the current one.
  const role = note.role ?? viewerRole
  const about = note.about_user_id
  let path: string | null = BY_KIND[note.kind] ?? null
  if (about) {
    if (note.kind === 'match') path = `/messages/${about}`
    // Their profile: a guest hears about guides, a guide about guests.
    if (note.kind === 'profile_updated')
      path = role === 'guide' ? `/visitors/${about}` : `/guides/${about}`
  }
  return path && note.role ? withRole(path, note.role) : path
}

/**
 * `path` opened as `role` — `?as=` (before any `#hash`), which the router
 * turns into a mode switch for an account holding that role
 * (`router/index.ts`).
 */
export function withRole(path: string, role: string): string {
  const [base, hash] = path.split('#') as [string, string | undefined]
  return `${base}${base.includes('?') ? '&' : '?'}as=${role}${hash ? `#${hash}` : ''}`
}
