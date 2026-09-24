import { inject, type App, type InjectionKey } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import type { AttributeMap, DiscoveryFilters, Gender, RangeMap } from '@/types'

/**
 * Thrown for every failure mode of a request: network failure, a
 * non-JSON/unexpected response, or the API's own `{ error }` payload.
 * `status` is 0 for failures that never got an HTTP response at all
 * (offline, DNS, CORS rejection).
 */
export class ApiError extends Error {
  status: number

  constructor(message: string, status: number) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

export interface RegisterPayload {
  email: string
  password: string
  role: 'guest' | 'guide'
  name?: string
  /** A `data:image/(png|jpeg|webp);base64,...` string from ImageCropper.vue — see RequestProcessor::validateImage(). */
  image?: string
}

export interface LoginPayload {
  email: string
  password: string
}

/** Shape returned by `register`, `login`, and `session`. */
export interface AuthResult {
  id: number
  email: string
  name?: string | null
  role?: string
  /**
   * Identifies this login server-side (see `Session.php`) — persisted
   * alongside the rest of `AuthResult` in `stores/auth.ts` so a page reload
   * doesn't sign the user out, and sent back on every subsequent
   * authenticated request by `call()` below. Expires server-side after 5
   * minutes of inactivity (a sliding window — any valid use renews it, see
   * `RequestProcessor::requireUserId()`), not because the client tracks
   * time itself.
   */
  sessionHash: string
}

/** The bare columns `users` itself has. */
export interface BasicAccount {
  id: number
  name: string | null
  email: string
  /** A `data:image/...;base64,...` string, or falsy (`''`/`null`) if none was set. */
  image: string | null
  created_at: string
}

/**
 * What `Users::searchByRole()` returns: a `BasicAccount` plus everything
 * `Profiles` knows about that user — `user_profiles`' scalar columns
 * (snake_case, matching the SQL row exactly, same as `created_at` above)
 * and, since 010, the whole taxonomy as two open maps keyed by category.
 * There are no `languages`/`activities`/`specialties` fields any more: a
 * category the client has never heard of arrives here just the same.
 * Any scalar field can be `null` — plenty of accounts have a partial profile.
 */
export interface ProfileAccount extends BasicAccount {
  age: number | null
  gender: Gender | null
  headline: string | null
  bio: string | null
  price_label: string | null
  price_note: string | null
  includes: string | null
  party: string | null
  duration_hours: number | null
  /**
   * Mean of this person's reviews to one decimal, `null` with none — from
   * `reviews`, never self-reported (see `Users::REVIEW_SUMMARY_JOIN`).
   */
  rating_average: number | null
  review_count: number
  attributes: AttributeMap
  ranges: RangeMap
}

/** Fields `RequestProcessor::actionUpdateProfile()` accepts (beyond the session-derived caller identity) — every one optional and independent. */
export interface ProfileUpdatePayload {
  /** ISO `YYYY-MM-DD` — what the `<input type="date">` on `ProfilePage.vue` produces natively. */
  dateOfBirth?: string
  gender?: Gender
  headline?: string
  bio?: string
  priceAmount?: number
  priceLabel?: string
  priceNote?: string
  includes?: string
  party?: string
  durationHours?: number
  /** One entry per dynamic category (see `AttributeCategory`) — replaces that category's full selection. */
  attributes?: Record<string, string[]>
  /**
   * Display label for any key in `attributes` that isn't an existing
   * category yet (see `Profiles::setAttributeValues()`'s auto-create
   * behavior) — `ProfilePage.vue` fills this in when the user typed a brand
   * new category name via the combobox's "create" option.
   */
  newCategoryLabels?: Record<string, string>
  /**
   * Spans per `range` category (`hour_of_day`). An empty array clears that
   * category, which means "unconstrained" rather than "unchanged" — see
   * `Profiles::setRanges()`.
   */
  ranges?: RangeMap
}

/** Every value `two_factor_method` can hold, including the legacy `'none'` a pre-existing account may still carry. */
export type TwoFactorMethod = 'none' | 'email' | 'sms' | 'whatsapp'

/** What a user is actually allowed to *choose* on `ProfilePage.vue` — `'none'` isn't an option going forward, see `RequestProcessor::actionUpdateAccount()`. */
export type TwoFactorChannel = 'email' | 'sms' | 'whatsapp'

/**
 * One entry from `api.attributeCategories()` — a "list of lists": a
 * category (Languages, Interests, Food allergies, ...) and the fixed set of
 * options a user can pick from within it. `multi` is currently always
 * `true` for every seeded category (see `007_dynamic_attributes.sql`) but
 * is read from the server rather than assumed, so a future single-select
 * category renders as radios instead of checkboxes without a client change.
 */
export interface AttributeCategory {
  key: string
  label: string
  /**
   * `options` — pick values from `options` below. `range` — add one or more
   * numeric spans between `rangeMin`/`rangeMax` (hours of the day), which
   * the UI renders as a slider plus an Add button rather than a tag list.
   */
  kind: 'options' | 'range'
  /**
   * What this category is called on the provider side of a match: a guest's
   * "Interests" is the same category as a guide's "Specialities". `null`
   * where the one label serves both.
   */
  providerLabel: string | null
  multi: boolean
  rangeMin: number | null
  rangeMax: number | null
  options: { value: string; label: string }[]
}

/**
 * What `api.myProfile()` returns: the full self-view `ProfilePage.vue`
 * loads on mount — `users` columns (including contact info the discovery
 * deck never exposes), `user_profiles`' scalar fields, and the taxonomy in
 * the same two maps `ProfileAccount` carries: `attributes` (option picks)
 * and `ranges` (spans, e.g. the hours a guide works), both keyed by
 * category `key`.
 */
export interface MyProfile {
  id: number
  name: string | null
  email: string
  /** A single combined string (e.g. "+350 56002731") — country code and local number are a `ProfilePage.vue`-only split, not stored separately. */
  phone: string | null
  two_factor_method: TwoFactorMethod
  image: string | null
  created_at: string
  /** ISO `YYYY-MM-DD`, or `null` if never set — the raw date, not a computed age (unlike `ProfileAccount.age`), so the edit form's calendar input can prefill exactly. */
  date_of_birth: string | null
  gender: Gender | null
  headline: string | null
  bio: string | null
  price_amount: number | null
  price_label: string | null
  price_note: string | null
  includes: string | null
  party: string | null
  duration_hours: number | null
  role: string | null
  attributes: AttributeMap
  ranges: RangeMap
}

/** Fields `RequestProcessor::actionUpdateAccount()` accepts (beyond the session-derived caller identity) — every one optional and independent. */
export interface AccountUpdatePayload {
  name?: string
  email?: string
  /** A single combined string — `ProfilePage.vue` concatenates its country-code picker and local-number input before sending this. */
  phone?: string
  twoFactorMethod?: TwoFactorChannel
  /** A `data:image/(png|jpeg|webp);base64,...` string, same validation as `RegisterPayload.image`. */
  image?: string
}

export interface ChangePasswordPayload {
  currentPassword: string
  newPassword: string
}

/** Mirrors `PaymentState` shape returned by `Payments`/`RequestProcessor::paymentStateFor()`. */
export interface PaymentState {
  packsUnlocked: number
  capacity: number
  picksUsed: number
  selectionsLeft: number
  /**
   * Guide ids this visitor has already kept / dismissed. The counts above
   * aren't enough to rebuild `stores/shortlist.ts` after a reload — it needs
   * the ids themselves — and they're cheap here (one indexed read of
   * `selections`) compared with pulling `matchOverview()`, whose rows carry
   * the counterpart's photo.
   */
  pickedIds: number[]
  passedIds: number[]
}

export type SelectionDecision = 'interested' | 'pass'

export type BookingStatus = 'confirmed' | 'cancelled'
/** `none` is a free guide's booking — nothing was charged, so nothing is refunded. */
export type BookingPaymentStatus = 'none' | 'paid' | 'refunded'
/** Advanced by the guide (a later feature); reviews open up once it's `finished`. */
export type TripStatus = 'pending' | 'started' | 'finished'

/**
 * One `bookings` row (see `Bookings.php`) — what a match turns into once
 * contact details are unlocked: the guest paid the guide's fee, or the guide
 * is free. Date-times are UTC `YYYY-MM-DD HH:MM:SS` strings.
 */
export interface Booking {
  id: number
  selection_id: number
  guest_id: number
  guide_id: number
  amount: number
  meeting_at: string | null
  status: BookingStatus
  payment_status: BookingPaymentStatus
  refund_amount: number | null
  cancel_reason: string | null
  cancelled_at: string | null
  trip_status: TripStatus
  created_at: string
  updated_at: string
}

/** An in-app notification (`Notifications::takeUnread()`) — shown once as a toast by `App.vue`, then it's read. */
export interface AppNotification {
  id: number
  kind: string
  message: string
  created_at: string
}

/** One `selections` row — a (guest, guide) pair with each side's decision. */
export interface SelectionRow {
  id: number
  guest_id: number
  guide_id: number
  guest_decision: SelectionDecision | null
  guide_decision: SelectionDecision | null
  active: 0 | 1
  created_at: string
  updated_at: string
}

/** One active (mutual) match, from the calling user's point of view. `email` is `null` until the pair has a booking. */
export interface MatchRow {
  id: number
  counterpart_id: number
  name: string | null
  email: string | null
}

/**
 * One pair from `api.matchOverview()` (`Selections::getOverview()`), from
 * the calling user's point of view — what `MatchesPage.vue` groups into
 * matched / likes-you / awaiting / passed. `their_decision` never reads
 * `'pass'` (masked server-side, a rejection is never revealed), and
 * `email`/`phone` are `null` unless `active === 1` *and* the pair has a
 * confirmed booking — hidden from both sides until the guest pays the
 * guide's fee (or straight away, for a free guide).
 */
export interface MatchOverviewRow {
  id: number
  counterpart_id: number
  my_decision: SelectionDecision | null
  their_decision: 'interested' | null
  active: 0 | 1
  updated_at: string
  name: string | null
  image: string | null
  email: string | null
  phone: string | null
  age: number | null
  /** Guide-side fields — only filled when the counterpart is a guide. */
  headline: string | null
  price_label: string | null
  /** Visitor-side fields — only filled when the counterpart is a visitor. */
  party: string | null
  duration_hours: number | null
  /** When the pair last became an active match (UTC). */
  matched_at: string | null
  /** The guide's current fee — 0 means a free guide, no payment step. */
  fee_amount: number
  /** The pair's latest booking, flattened; all `null` when there's never been one. */
  booking_id: number | null
  booking_status: BookingStatus | null
  payment_status: BookingPaymentStatus | null
  booking_amount: number | null
  meeting_at: string | null
  refund_amount: number | null
  trip_status: TripStatus | null
  /** Guide only: what cancelling the open paid booking would refund right now (includes any late-cancellation extra). */
  refund_if_cancelled: number | null
}

/**
 * Reviews and public profiles (`Reviews.php`, 013_reviews.sql).
 *
 * `PublicProfile` is what `api.publicProfile()` returns: the same fields a
 * deck card gets, plus `role`, minus every contact detail — it's what anyone,
 * signed in or not, may see about a guest or guide.
 */
export interface PublicProfile extends Omit<ProfileAccount, 'email'> {
  role: 'guest' | 'guide'
}

/** One review, with its author's public face (name and photo only). */
export interface Review {
  id: number
  booking_id: number
  author_id: number
  subject_id: number
  /** A whole number, 1–5. */
  rating: number
  /** Up to 256 characters; `null` when the author left only a grade. */
  comment: string | null
  /** ISO 8601, UTC (`2026-09-24T10:00:00Z`). */
  created_at: string
  updated_at: string
  author_name: string
  author_image: string | null
}

/** Count of reviews per grade, every grade present (zeros included). */
export type ReviewHistogram = Record<'1' | '2' | '3' | '4' | '5', number>

/** Whether the caller may review the profile being viewed — always "no" signed out. */
export interface ReviewViewerState {
  canReview: boolean
  /** The finished booking a review would attach to (see `Reviews::eligibleBookingId()`). */
  bookingId: number | null
  /** The caller's existing review on that booking — present means "Edit your review". */
  myReview: Review | null
}

/** What `api.reviews()` returns: one page, newest first, plus the summary header. */
export interface ReviewsPage {
  reviews: Review[]
  total: number
  average: number | null
  histogram: ReviewHistogram
  viewer: ReviewViewerState
}

export interface SubmitReviewPayload {
  subjectId: number
  rating: number
  comment?: string
}

/** The server's cap on one `api.reviews()` page — the "Show all" modal's page size. */
export const REVIEWS_PAGE_SIZE = 20

/** Mirrors `AGE_CATEGORY_KEY` in `stores/categories.ts`; duplicated so the api layer doesn't import a store. */
const AGE_FILTER_KEY = 'age'

const BASE_URL =(import.meta.env.VITE_API_BASE_URL ?? 'https://api.rockguide.com').replace(
  /\/+$/,
  '',
)

function isErrorBody(body: unknown): body is { error: string } {
  return !!body && typeof body === 'object' && typeof (body as Record<string, unknown>).error === 'string'
}

/**
 * Attaches the current session hash (if any) to every request — the one
 * place that needs to, rather than every call site remembering to. Reading
 * `useAuthStore()` here, from a plain function rather than a component/store
 * setup body, works specifically *because* Pinia stores are looked up
 * through a module-level "active pinia" set once by `app.use(pinia)` in
 * main.ts, not through Vue's component-instance-scoped `inject()` — unlike
 * `useApi()` itself (see the long comment in `stores/guides.ts` about why
 * *that* breaks when called outside a component's synchronous setup). So
 * this is safe to call from anywhere `call()` runs, including from inside a
 * debounced `setTimeout` callback.
 */
async function call<T>(action: string, payload: object = {}): Promise<T> {
  const sessionHash = useAuthStore().sessionHash

  let response: Response
  try {
    response = await fetch(BASE_URL + '/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...(sessionHash ? { sessionHash } : {}), ...payload }),
    })
  } catch {
    throw new ApiError('Could not reach the server. Check your connection and try again.', 0)
  }

  let body: unknown = null
  try {
    body = await response.json()
  } catch {
    if (!response.ok) {
      throw new ApiError('Something went wrong.', response.status)
    }
    throw new ApiError('The server returned an unexpected response.', response.status)
  }

  if (!response.ok) {
    // A 401 means the session RequestProcessor::requireUserId() looked for
    // is missing, unknown, or expired (see AuthenticationException.php) —
    // the client's own idea of "logged in" is now wrong, so correct it here
    // rather than leaving every page's catch block to remember to. Also
    // push the shared "session expired" toast here, centrally, so every
    // caller gets it — see `useMessagesStore().sessionExpired()` for the
    // redirect-to-/login-on-close behavior. Individual pages' own catch
    // blocks route through `reportApiError()` (src/utils/reportApiError.ts),
    // which skips its own toast for a 401 so this doesn't double up.
    if (response.status === 401) {
      useAuthStore().clear()
      useMessagesStore().sessionExpired()
    }
    throw new ApiError(isErrorBody(body) ? body.error : 'Something went wrong.', response.status)
  }

  return body as T
}

/**
 * Turns the filter state into the request body `RequestProcessor::
 * readFilters()` expects. The only reshaping is `age`: the UI keeps it in
 * `ranges` alongside the real range categories so the filter bar can treat
 * every category the same way, but the server matches it against an age
 * computed from `date_of_birth` rather than stored spans, so it travels in
 * its own `ageRanges` field.
 */
function deckRequest(filters?: DiscoveryFilters): Record<string, unknown> {
  if (!filters) return {}

  const { [AGE_FILTER_KEY]: ageRanges, ...ranges } = filters.ranges

  return {
    ...(filters.gender ? { gender: filters.gender } : {}),
    attributes: filters.attributes,
    ranges,
    ...(ageRanges?.length ? { ageRanges } : {}),
  }
}

/** The api "module": one client, backed by RequestProcessor's `action` dispatch. */
export const api = {
  register: (payload: RegisterPayload) => call<AuthResult>('register', payload),
  login: (payload: LoginPayload) => call<AuthResult>('login', payload),
  /**
   * Filtered in SQL (`Users::searchByRole()`), not client-side — pass the
   * current `DiscoveryFilters` as-is, or omit for everyone.
   *
   * For a signed-in caller the server also drops anyone they've already
   * decided on, so the deck doesn't re-serve matched/passed cards after a
   * reload. `includeDecided` opts out of *that* part: pages which resolve a
   * specific, already-picked card by id (`ShortlistPage`)
   * need those rows back. Filters still apply either way.
   */
  guides: (filters?: DiscoveryFilters, includeDecided = false) =>
    call<{ guides: ProfileAccount[] }>('guides', {
      ...deckRequest(filters),
      ...(includeDecided ? { includeDecided: true } : {}),
    }),
  visitors: (filters?: DiscoveryFilters, includeDecided = false) =>
    call<{ visitors: ProfileAccount[] }>('visitors', {
      ...deckRequest(filters),
      ...(includeDecided ? { includeDecided: true } : {}),
    }),
  updateProfile: (payload: ProfileUpdatePayload) => call<{ ok: true }>('updateProfile', payload),
  attributeCategories: () => call<{ categories: AttributeCategory[] }>('attributeCategories'),
  myProfile: () => call<MyProfile>('myProfile'),
  updateAccount: (payload: AccountUpdatePayload) => call<{ ok: true }>('updateAccount', payload),
  changePassword: (payload: ChangePasswordPayload) => call<{ ok: true }>('changePassword', payload),
  /** Destroys the current session server-side — best-effort from the caller's point of view, see `AppHeader.vue`. */
  logout: () => call<{ ok: true }>('logout'),
  /** Validates the locally-cached session (see `stores/auth.ts`) against the server and slides its inactivity window forward — what `App.vue` calls on boot. */
  session: () => call<AuthResult>('session'),

  unlockPack: () => call<PaymentState>('unlockPack'),
  paymentState: () => call<PaymentState>('paymentState'),
  /** Guest pays a matched guide's fee; `meetingAt` is an ISO date-time. Unlocks both sides' contact details. */
  payGuideFee: (payload: { targetId: number; meetingAt: string }) => call<Booking>('payGuideFee', payload),
  /** Guide cancels the booking with a visitor — they're refunded (plus 5% if late) and get their pick back. */
  cancelBooking: (payload: { targetId: number; reason?: string }) => call<Booking>('cancelBooking', payload),
  /** Unread in-app notifications, marked read by this same call. */
  notifications: () => call<{ notifications: AppNotification[] }>('notifications'),

  decide: (payload: { targetId: number; decision: SelectionDecision }) => call<SelectionRow>('decide', payload),
  revokeSelection: (payload: { targetId: number }) => call<SelectionRow>('revokeSelection', payload),
  matches: () => call<{ matches: MatchRow[] }>('matches'),
  /** Every pair the caller is part of (matched, pending either way, passed) — see `MatchOverviewRow`. */
  matchOverview: () => call<{ selections: MatchOverviewRow[] }>('matchOverview'),

  /** One guest or guide by id, never with contact details — both public profile pages. */
  publicProfile: (userId: number) => call<PublicProfile>('publicProfile', { userId }),
  /** A page of someone's reviews (`limit` ≤ `REVIEWS_PAGE_SIZE`) with the summary and the caller's `viewer` state. */
  reviews: (payload: { userId: number; limit?: number; offset?: number }) => call<ReviewsPage>('reviews', payload),
  /** Creates or edits the caller's review of `subjectId` — only after a finished trip together. */
  submitReview: (payload: SubmitReviewPayload) => call<Review>('submitReview', payload),
  deleteReview: (payload: { reviewId: number }) => call<{ ok: true }>('deleteReview', payload),
}

export type Api = typeof api

export const API_KEY: InjectionKey<Api> = Symbol('api')

/** Installed on the app in main.ts (`app.use(apiPlugin)`) so `useApi()` works anywhere under it. */
export const apiPlugin = {
  install(app: App) {
    app.provide(API_KEY, api)
  },
}

export function useApi(): Api {
  const client = inject(API_KEY)
  if (!client) {
    throw new Error('useApi() was called without the api plugin installed (see main.ts).')
  }
  return client
}
