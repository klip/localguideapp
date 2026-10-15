import type { ProfileAccount } from '@/plugins/api'
import type { Standing } from '@/types'

/** A deck card's or profile's badges from the server row (see `Ranking.php`). */
export function standingOf(account: Pick<ProfileAccount, 'verified' | 'top_rated' | 'trips_completed'>): Standing {
  return {
    verified: !!account.verified,
    topRated: !!account.top_rated,
    tripsCompleted: account.trips_completed ?? 0,
  }
}

/** Nothing earned yet — for placeholder cards. */
export const NO_STANDING: Standing = { verified: false, topRated: false, tripsCompleted: 0 }
