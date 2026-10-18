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
  level_up: '/profile#level',
  bonus_granted: '/profile#level',
}

export function notificationLink(
  note: AppNotification,
  viewerRole: string | null | undefined,
): string | null {
  const about = note.about_user_id
  if (about) {
    if (note.kind === 'match') return `/messages/${about}`
    // Their profile: a guest hears about guides, a guide about guests.
    if (note.kind === 'profile_updated')
      return viewerRole === 'guide' ? `/visitors/${about}` : `/guides/${about}`
  }
  return BY_KIND[note.kind] ?? null
}
