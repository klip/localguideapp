/** Shared domain types for the BeLikeLocal matching flow. */

/**
 * A human constant, not a category: it's a fact about a person rather than
 * something they pick from a list, so it stays a `user_profiles` column and
 * the one dropdown left in the filter bar. `null` means unset — "no
 * preference" when filtering, "prefer not to say" on a profile.
 */
export type Gender = 'male' | 'female'

/**
 * One numeric span, inclusive at both ends — hours of the day (0–23) or
 * years of age. A category holds a list of them, so "mornings and evenings"
 * is two spans rather than one awkward one.
 */
export type NumericRange = [start: number, end: number]

/**
 * Everything a profile carries beyond the human constants, keyed by category
 * `key` (`languages`, `interests`, `hour_of_day`, or whatever a user has
 * since invented — see `Profiles::getCategories()`). Deliberately an open
 * map rather than named fields: nothing in the client knows which categories
 * exist, so adding one needs no code change here.
 */
export type AttributeMap = Record<string, string[]>
export type RangeMap = Record<string, NumericRange[]>

/**
 * What a person has earned on the platform, shown as badges on cards and
 * profiles (`StandingBadges.vue`) and behind their place in the deck
 * (`Ranking.php`).
 */
export interface Standing {
  verified: boolean
  topRated: boolean
  tripsCompleted: number
  /** Effective level 0–7 (`Levels.php`). */
  level: number
  /** A guide who has only guided for free — shown as "Volunteer" instead of a level. */
  volunteer?: boolean
  /** Badges the team awarded by hand, shown after the earned ones. */
  awarded?: { label: string; icon: string }[]
}

/** A local guide, shown to visitors on the visitor discovery deck. */
export interface Guide {
  id: string
  name: string
  age: number
  /** One-line summary under the name, e.g. "History · Food · Scenic walks". */
  headline: string
  /**
   * Mean review grade, 0 with no reviews yet — from `reviews`, never
   * self-reported. Render it only when `reviewCount > 0`.
   */
  ratingAverage: number
  reviewCount: number
  /**
   * The guide's own price text as written ("£95 / 6 hours") — show it through
   * `guidePriceLabel(priceAmount, priceLabel)`, which puts the fee in the
   * viewer's currency.
   */
  priceLabel: string
  /** The fee in pounds; null/0 for a free guide (or the marketing mock). */
  priceAmount?: number | null
  priceNote: string
  bio: string
  /** Longer prose listing what the fee covers. */
  includes: string
  gender: Gender | null
  attributes: AttributeMap
  ranges: RangeMap
  standing: Standing
  /** CSS background value standing in for the photo until imagery exists. */
  photo: string
  /** Km from the deck's search point to where they guide (0 = under 1 km); `null` without one. */
  distanceKm: number | null
}

/** A visiting party, shown to guides on the guide discovery deck. */
export interface Visitor {
  id: string
  name: string
  age: number
  /** Party and arrival context, e.g. "2 adults · weekend in Lisbon". */
  party: string
  bio: string
  /** Requested tour length in hours. */
  durationHours: number
  attributes: AttributeMap
  ranges: RangeMap
  standing: Standing
  photo: string
  /** Where they're looking for guides ("Lisbon, Portugal"), when they've said lately. */
  areaLabel: string | null
  /** Km from the guide's search point to that area or stop (0 = under 1 km); `null` without one. */
  distanceKm: number | null
  /** A planned stop still ahead (the one nearest the search point, else the soonest), or `null`. */
  nextStop: { label: string; arriveOn: string; departOn: string } | null
}

/** A chosen place: its point and how to name it (from the place search). */
export interface PlacePoint {
  /** "Lisbon, Portugal" */
  label: string
  countryCode: string | null
  latitude: number
  longitude: number
}

/**
 * Discovery filter state, shared by the filter bar and both decks.
 *
 * `attributes` and `ranges` are keyed by category, so the bar is built from
 * whatever `api.attributeCategories()` returns rather than from a fixed list
 * of fields. `age` is the one key in `ranges` with no category row behind it
 * — it's matched against an age derived from `date_of_birth` (see
 * `AGE_CATEGORY` in `stores/categories.ts`), which is why `plugins/api.ts`
 * peels it off into its own `ageRanges` request field.
 */
export interface DiscoveryFilters {
  gender: Gender | null
  attributes: AttributeMap
  ranges: RangeMap
  /** Where to search near: closer people rank first and cards show the distance. Not a filter by itself. */
  near: PlacePoint | null
  /** With `near`: only people whose area comes within this many km. `null` = unlimited. */
  maxKm: number | null
}

/** How a card was dispatched from a discovery deck. */
export type Decision = 'pass' | 'interested'
