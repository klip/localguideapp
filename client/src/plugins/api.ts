import { inject, type App, type InjectionKey } from 'vue'
import { t } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { socket, type SocketResponse } from '@/plugins/socket'
import type { AttributeMap, DiscoveryFilters, Gender, RangeMap } from '@/types'

/**
 * Thrown for every failure mode of a request: network failure, a
 * non-JSON/unexpected response, or the API's own `{ error }` payload.
 * `status` is 0 for failures that never got an HTTP response at all
 * (offline, DNS, CORS rejection). `code`/`field` come from errors the server
 * marks for the client to handle — today only `contact_details` (see
 * `lib/ContactFilter.php`), whose `field` names the input that held them.
 */
export class ApiError extends Error {
  status: number
  code?: string
  field?: string

  constructor(message: string, status: number, code?: string, field?: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.field = field
  }
}

export interface RegisterPayload {
  email: string
  password: string
  role: 'guest' | 'guide'
  name?: string
  /** A `data:image/(png|jpeg|webp);base64,...` string from ImageCropper.vue — see RequestProcessor::validateImage(). */
  image?: string
  /** The `LegalDocumentView.version` of the terms the user accepted; must be the one in force. */
  termsVersion: string
  /** The starting role's agreement the user accepted: its slug (`roleAgreementSlug(role)`) and version. */
  agreementSlug: LegalSlug
  agreementVersion: string
  /** The language the terms were shown in, recorded with the acceptance. */
  language?: string
}

/** The legal documents there are: the terms, and each role's agreement. */
export type LegalSlug = 'terms' | 'guide_agreement' | 'traveller_agreement'

/** The agreement a role accepts on top of the terms (mirrors `LegalDocuments::ROLE_AGREEMENTS`). */
export function roleAgreementSlug(role: 'guest' | 'guide'): LegalSlug {
  return role === 'guide' ? 'guide_agreement' : 'traveller_agreement'
}

export interface LoginPayload {
  email: string
  password: string
  /** A remembered device's token (see `utils/trustedDevice.ts`) — skips the emailed code while it's valid. */
  deviceToken?: string
}

/** `verifyTwoFactor` with `rememberDevice: true` adds these to the `AuthResult`. */
export interface TrustedDeviceGrant {
  deviceToken: string
  /** ISO 8601 UTC — 30 days from now. */
  trustedUntil: string
}

/** Shape returned by `register`, `login`, and `session`. */
/** The two app roles. An account can hold both and acts as one at a time. */
export type Role = 'guest' | 'guide'

export interface AuthResult {
  id: number
  email: string
  name?: string | null
  /** The role the account registered with (its first). */
  role?: string
  /**
   * Every role the account holds, registration role first. The one it acts
   * as is `stores/auth.ts`'s `activeRole`, sent with every request by
   * `call()`.
   */
  roles?: Role[]
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

/**
 * What `login` returns instead of an `AuthResult` when the account has
 * two-factor on (see `AccountSecurity.php`): a code went to `destination`
 * (a masked email), and `challengeToken` + that code go to
 * `api.verifyTwoFactor()` to get the session.
 */
export interface TwoFactorChallenge {
  twoFactorRequired: true
  challengeToken: string
  method: 'email'
  destination: string
  expiresInSeconds: number
  resendAfterSeconds: number
}

export type LoginResult = AuthResult | TwoFactorChallenge

export function isTwoFactorChallenge(result: LoginResult): result is TwoFactorChallenge {
  return 'twoFactorRequired' in result && result.twoFactorRequired === true
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
/** No `email`/`phone`: contact details are never shared with other users — matched people chat (`Messages`). */
export interface ProfileAccount extends Omit<BasicAccount, 'email'> {
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
  /** Identity checked (`users.verified_at`) — the ✓ Verified badge. */
  verified: boolean
  /** ≥ 5 reviews averaging ≥ 4.6 (see `Ranking.php`). */
  top_rated: boolean
  /** Finished trips in the role shown — free ones too. */
  trips_completed: number
  /** Effective level 0–7 in the role shown (`Levels.php`): from paid trips' points, minus any active sanction. */
  level: number
  /** A guide who has only guided for free so far: "Volunteer" instead of a level. */
  volunteer?: boolean
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
   * Who has it: `shared` (one set for the person — languages), `per_role`
   * (a set per role — interests/specialities), or only `guest` / `guide`.
   * Optional so a fixture can leave it out (reads as `per_role`).
   */
  scope?: 'shared' | 'per_role' | 'guest' | 'guide'
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
  /**
   * A `range` category the filter bar can leave "Unlimited" (a switch, on by
   * default, muting the slider) — `distance`. Always sent by the server;
   * optional so a fixture can leave it out.
   */
  allowUnlimited?: boolean
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
  /** Unexpired "remembered" devices that skip the login code. */
  trusted_device_count: number
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
  roles?: Role[]
  /** Identity checked — set by the ID verification (not by the user). */
  verified: boolean
  verification_method: 'id_card' | 'passport' | 'driving_licence' | null
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
   * aren't enough to rebuild `stores/matches.ts` after a reload — it needs
   * the ids themselves — and they're cheap here (one indexed read of
   * `selections`) compared with pulling `matchOverview()`, whose rows carry
   * the counterpart's photo.
   */
  pickedIds: number[]
  passedIds: number[]
  /** Picks made during a free-picks bonus — they don't use the pack. */
  freePickIds?: number[]
  /** What the next £5 pack holds at this visitor's level (`Levels::picksPerPack()`). */
  packSize?: number
  /** An active free-picks bonus ends then (ISO UTC); null without one. */
  freePicksUntil?: string | null
}

export type SelectionDecision = 'interested' | 'pass'

/**
 * `pending` — paid, waiting for the guide to accept; `declined`/`withdrawn` —
 * a pending booking the guide turned down (or let lapse) / the guest took back,
 * both refunded in full; `cancelled` — a confirmed booking called off.
 */
export type BookingStatus = 'pending' | 'confirmed' | 'declined' | 'withdrawn' | 'cancelled'
/** `none` is a free guide's booking — nothing was charged, so nothing is refunded. */
export type BookingPaymentStatus = 'none' | 'paid' | 'refunded'
/** `started` once the meeting time passes; `finished` on its own (time + length known) or by both sides agreeing. Reviews open up once it's `finished`. */
export type TripStatus = 'pending' | 'started' | 'finished'

/** Every step `api.bookingAction()` can take — see `RequestProcessor::actionBooking()` for who may take which. */
export type BookingOp =
  | 'accept'
  | 'decline'
  | 'withdraw'
  | 'requestCancellation'
  | 'withdrawCancellation'
  | 'approveCancellation'
  | 'refuseCancellation'
  | 'requestFinish'
  | 'confirmFinish'
  | 'dismissFinish'
  | 'setLocation'

/**
 * One `bookings` row (see `Bookings.php`) — a date the guide has committed
 * to: the guest paid the guide's fee (and the guide accepted), or the guide
 * is free. Date-times are UTC `YYYY-MM-DD HH:MM:SS` strings.
 */
export interface Booking {
  id: number
  selection_id: number
  guest_id: number
  guide_id: number
  amount: number
  meeting_at: string | null
  duration_hours: number | null
  /** Short free-text meeting place; `null` until someone sets it. */
  location: string | null
  /** A map pin for the meeting place, shared from someone's device. */
  location_pin: MapPin | null
  status: BookingStatus
  payment_status: BookingPaymentStatus
  accepted_at: string | null
  refund_amount: number | null
  /** The guide's platform fee (0–1) and what they receive — guide side only; null otherwise. */
  commission_rate?: number | null
  guide_payout?: number | null
  cancel_reason: string | null
  cancelled_at: string | null
  cancel_requested_at: string | null
  cancel_request_reason: string | null
  trip_status: TripStatus
  finish_requested_by: number | null
  finish_requested_at: string | null
  finished_at: string | null
  created_at: string
  updated_at: string
}

/** A point on the map (a booking's meeting pin). */
export interface MapPin {
  latitude: number
  longitude: number
}

/** An in-app notification, pushed over the socket once (`Realtime`) — `App.vue` shows it as a toast. */
export interface AppNotification {
  id: number
  kind: string
  /** The person it's about, when there is one — its toast links to them. */
  about_user_id?: number | null
  /** The recipient's side in it — opening its link switches to that mode (`?as=`). */
  role?: Role | null
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
  /** The guide's platform fee (0–1) and what they receive — guide side only; null otherwise. */
  commission_rate?: number | null
  guide_payout?: number | null
  trip_status: TripStatus | null
  /** Guide only: what cancelling the open paid booking would refund right now (includes any late-cancellation extra). */
  refund_if_cancelled: number | null
  /** What the guest gets back if the guide approves their cancellation request (the fee minus 5%). */
  refund_if_guest_cancels: number | null
  /** The booking's own trip length (not the visitor's profile `duration_hours`). */
  booking_duration_hours: number | null
  /** Where the trip meets — short free text, either side can set it while the booking is open. */
  location: string | null
  /** …and/or a map pin, shared from a device (`useCurrentPosition`). */
  location_pin: MapPin | null
  accepted_at: string | null
  cancel_requested_at: string | null
  cancel_request_reason: string | null
  /** Who asked to mark the trip finished, from the caller's point of view. */
  finish_requested_by: 'me' | 'them' | null
  finished_at: string | null
  /** The trip finishes on its own — meeting time and trip length are both known. */
  auto_finish: boolean
}

/**
 * The booking fields `BookingPanel.vue` reads — common to a `MatchOverviewRow`
 * (the pair's latest booking, on `/matches`) and a `TripRow` (one confirmed
 * trip, on `/trips`).
 */
export type BookingView = Pick<
  MatchOverviewRow,
  | 'counterpart_id'
  | 'active'
  | 'fee_amount'
  | 'booking_status'
  | 'payment_status'
  | 'booking_amount'
  | 'meeting_at'
  | 'booking_duration_hours'
  | 'location'
  | 'location_pin'
  | 'trip_status'
  | 'refund_if_cancelled'
  | 'refund_if_guest_cancels'
  | 'cancel_requested_at'
  | 'cancel_request_reason'
  | 'finish_requested_by'
  | 'auto_finish'
  | 'commission_rate'
  | 'guide_payout'
>

/** `scheduled` — confirmed, not started; `current` — started; `past` — finished or cancelled. */
export type TripPhase = 'scheduled' | 'current' | 'past'

/**
 * One trip from `api.trips()` (`Bookings::tripsFor()`): a booking that was
 * confirmed at some point, from the caller's point of view. `id` is the
 * booking's. No contact details — the pair chats in Messages. `is_latest`
 * marks the pair's most recent booking.
 */
export interface TripRow extends BookingView {
  id: number
  selection_id: number
  phase: TripPhase
  is_latest: boolean
  name: string | null
  image: string | null
  booking_status: BookingStatus
  booking_amount: number
  refund_amount: number | null
  /** The guide's platform fee (0–1) and what they receive — guide side only; null otherwise. */
  commission_rate?: number | null
  guide_payout?: number | null
  accepted_at: string
  cancel_reason: string | null
  cancelled_at: string | null
  finished_at: string | null
  matched_at: string | null
}

/** A legal document in force (`LegalDocuments::current()`), placeholders already filled. */
export interface LegalDocumentView {
  slug: string
  /** The date it took effect, `YYYY-MM-DD` — sent back as `termsVersion` on register. */
  version: string
  /** The language actually served (English when there's no translation). */
  language: string
  title: string
  /** A small Markdown subset — see `components/LegalDocument.vue`. */
  body: string
}

/** Narrows a `translations` fetch to key prefixes (`discover`, `common.app_header`), or leaves some out. */
export interface TranslationScope {
  scopes?: readonly string[]
  except?: readonly string[]
}

/**
 * Reviews and public profiles (`Reviews.php`, 013_reviews.sql).
 *
 * `PublicProfile` is what `api.publicProfile()` returns: the same fields a
 * deck card gets, plus `role`, minus every contact detail — it's what anyone,
 * signed in or not, may see about a guest or guide.
 */
export interface PublicProfile extends Omit<ProfileAccount, 'email'> {
  /** Their first role. */
  role: Role
  /** Every role they hold — both profile pages resolve for someone who is both. */
  roles?: Role[]
}

/** One review, with its author's public face (name and photo only). */
export interface Review {
  id: number
  booking_id: number
  author_id: number
  subject_id: number
  /** Which side of the trip the subject was on — ratings are kept per role. */
  subject_role?: Role
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
  /** The side the review is about — the subject's role on the finished trip. */
  role: Role
  rating: number
  comment?: string
}

/**
 * One chat message (`Messages.php`), as the viewer sees it — `mine` says
 * which side of the conversation it sits on. `body` has contact details
 * already replaced by `[•••]`. Times are ISO 8601 UTC.
 */
/** One bonus package (`bonus_grants`): earned, then claimed by the user. */
export interface BonusGrant {
  id: number
  /** `free_picks` (guests): unlimited picks; `no_fee` (guides): no platform fee. */
  kind: 'free_picks' | 'no_fee'
  days: number
  /** Why it was granted: `level_<n>` or `reviews_<n>`. */
  reason: string
  /** `queued`: claimed, starts when an active one of the same kind ends. */
  state: 'available' | 'queued' | 'active' | 'used'
  starts_at: string | null
  ends_at: string | null
}

export type LevelName =
  | 'new'
  | 'starter'
  | 'explorer'
  | 'pathfinder'
  | 'trailblazer'
  | 'pioneer'
  | 'legend'
  | 'icon'

/** What `api.myLevel()` returns — see `Levels.php`. */
export interface LevelState {
  /** Levels are per role: this is the acting role's. */
  role?: Role
  level: number
  name: LevelName
  /** Finished trips in this role, free ones included. */
  trips_completed: number
  /** The paid ones — the only trips that earn points. */
  paid_trips: number
  /** Level points: 1 per paid trip as a traveller; 1–1.5 as a guide, more for higher fees. */
  points: number
  /** Points needed for the next level; null at the top. */
  next_level_points: number | null
  /** The level `next_level_points` leads to (by earned level, so above a sanctioned badge + 1). */
  next_level: number | null
  /** A guide with finished trips but no paid one yet. */
  volunteer: boolean
  /** Active sanctions, summed — they lower `level`. */
  sanction: { levels: number; until: string | null; reason: string | null } | null
  /** Guests: picks in the next £5 pack. */
  picks_per_pack: number | null
  /** Guides: the platform fee on bookings, 0–1. */
  commission_rate: number | null
  free_picks_until: string | null
  no_fee_until: string | null
  bonuses: BonusGrant[]
}

export interface ChatMessage {
  id: number
  sender_id: number
  recipient_id: number
  mine: boolean
  /** `location`: a shared point (`location` set, `body` empty) — see `utils/mapsLink.ts`. */
  kind: 'text' | 'location'
  body: string
  location: { latitude: number; longitude: number; accuracy_m: number | null } | null
  created_at: string
  read_at: string | null
}

/** A row on the Messages list: someone you're matched (or have chatted) with. */
export interface Conversation {
  counterpart_id: number
  name: string | null
  image: string | null
  role: 'guest' | 'guide' | null
  /** The match is active — once it dissolves the history stays, read-only. */
  can_send: boolean
  unread: number
  last_message: {
    id: number
    mine: boolean
    kind: ChatMessage['kind']
    body: string
    created_at: string
  } | null
}

/** One conversation's page (`api.messages()`), oldest first. */
export interface ChatThread {
  counterpart: {
    id: number
    name: string | null
    image: string | null
    role: 'guest' | 'guide' | null
  }
  can_send: boolean
  /** Older messages exist — ask again with `beforeId` = the first one's id. */
  has_more: boolean
  messages: ChatMessage[]
}

/** The server's cap on one `api.reviews()` page — the "Show all" modal's page size. */
export const REVIEWS_PAGE_SIZE = 20

/** Mirrors `AGE_CATEGORY_KEY` in `stores/categories.ts`; duplicated so the api layer doesn't import a store. */
const AGE_FILTER_KEY = 'age'

function isErrorBody(body: unknown): body is { error: string; code?: string; field?: string } {
  return (
    !!body &&
    typeof body === 'object' &&
    typeof (body as Record<string, unknown>).error === 'string'
  )
}

/**
 * Every request goes over the one WebSocket (`plugins/socket.ts`) — not
 * `fetch` — as `{ action, sessionHash?, ...payload }`, exactly the body the
 * HTTP endpoint takes, and comes back with the status HTTP would have sent.
 *
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
  const auth = useAuthStore()
  const sessionHash = auth.sessionHash
  // The role this request acts as — the gateway checks the account holds it.
  const actingRole = sessionHash ? auth.activeRole : null

  let response: SocketResponse
  try {
    response = await socket.request({
      action,
      ...(sessionHash ? { sessionHash } : {}),
      ...(actingRole ? { actingRole } : {}),
      ...payload,
    })
  } catch {
    throw new ApiError(t('common.api.unreachable'), 0)
  }

  const { status, body } = response
  if (!body || typeof body !== 'object') {
    throw new ApiError(t('common.api.unexpected_response'), status)
  }

  if (status < 200 || status >= 300) {
    // A 401 means the session RequestProcessor::requireUserId() looked for
    // is missing, unknown, or expired (see AuthenticationException.php) —
    // the client's own idea of "logged in" is now wrong, so correct it here
    // rather than leaving every page's catch block to remember to. Also
    // push the shared "session expired" toast here, centrally, so every
    // caller gets it — see `useMessagesStore().sessionExpired()` for the
    // redirect-to-/login-on-close behavior. Individual pages' own catch
    // blocks route through `reportApiError()` (src/utils/reportApiError.ts),
    // which skips its own toast for a 401 so this doesn't double up.
    if (status === 401) {
      useAuthStore().clear()
      useMessagesStore().sessionExpired()
    }
    if (!isErrorBody(body)) throw new ApiError(t('common.api.something_went_wrong'), status)
    // Server text is English; the one error a user is meant to act on gets
    // the viewer's language (see `ContactFilter`).
    const message = body.code === 'contact_details' ? t('common.api.contact_details') : body.error
    throw new ApiError(message, status, body.code, body.field)
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
  /** An `AuthResult`, or a `TwoFactorChallenge` when the account needs an emailed code first. */
  login: (payload: LoginPayload) => call<LoginResult>('login', payload),
  /** `rememberDevice` makes this browser skip the code for 30 days — the result then carries a `TrustedDeviceGrant`. */
  verifyTwoFactor: (payload: { challengeToken: string; code: string; rememberDevice?: boolean }) =>
    call<AuthResult & Partial<TrustedDeviceGrant>>('verifyTwoFactor', payload),
  /** Emails a fresh code for the same challenge; the previous code stops working. */
  resendTwoFactor: (payload: { challengeToken: string }) =>
    call<TwoFactorChallenge>('resendTwoFactor', payload),
  /** Always `{ ok: true }` — never says whether the email has an account. */
  requestPasswordReset: (payload: { email: string }) =>
    call<{ ok: true }>('requestPasswordReset', payload),
  resetPassword: (payload: { token: string; newPassword: string }) =>
    call<{ ok: true }>('resetPassword', payload),
  /** Every remembered device asks for a login code again. */
  forgetTrustedDevices: () => call<{ ok: true }>('forgetTrustedDevices'),
  /**
   * Filtered in SQL (`Users::searchByRole()`), not client-side — pass the
   * current `DiscoveryFilters` as-is, or omit for everyone.
   *
   * For a signed-in caller the server also drops anyone they've already
   * decided on, so the deck doesn't re-serve matched/passed cards after a
   * reload. `includeDecided` opts out of *that* part, for resolving
   * already-picked cards by id (`stores/guides.ts`'s `hydrateDecided()`).
   * Filters still apply either way. (The guide deck never needs it.)
   */
  guides: (filters?: DiscoveryFilters, includeDecided = false) =>
    call<{ guides: ProfileAccount[] }>('guides', {
      ...deckRequest(filters),
      ...(includeDecided ? { includeDecided: true } : {}),
    }),
  visitors: (filters?: DiscoveryFilters) =>
    call<{ visitors: ProfileAccount[] }>('visitors', deckRequest(filters)),
  updateProfile: (payload: ProfileUpdatePayload) => call<{ ok: true }>('updateProfile', payload),
  attributeCategories: () => call<{ categories: AttributeCategory[] }>('attributeCategories'),
  myProfile: () => call<MyProfile>('myProfile'),
  updateAccount: (payload: AccountUpdatePayload) => call<{ ok: true }>('updateAccount', payload),
  changePassword: (payload: ChangePasswordPayload) => call<{ ok: true }>('changePassword', payload),
  /**
   * "Become a guide" / "I want to travel": grants the other role, recording
   * the acceptance of its agreement (`agreementVersion`, the one shown).
   * Returns every role the account now holds.
   */
  addRole: (payload: {
    role: Role
    agreementSlug: LegalSlug
    agreementVersion: string
    language?: string
  }) => call<{ roles: Role[] }>('addRole', payload),
  /** Destroys the current session server-side — best-effort from the caller's point of view, see `AppHeader.vue`. */
  logout: () => call<{ ok: true }>('logout'),
  /** Validates the locally-cached session (see `stores/auth.ts`) against the server and slides its inactivity window forward — what `App.vue` calls on boot. */
  session: () => call<AuthResult>('session'),

  unlockPack: () => call<PaymentState>('unlockPack'),
  paymentState: () => call<PaymentState>('paymentState'),
  /** Guest pays a matched guide's fee; `meetingAt` is an ISO date-time, `durationHours` and `location` optional. The booking then waits for the guide to accept. */
  payGuideFee: (payload: {
    targetId: number
    meetingAt: string
    durationHours?: number
    location?: string
    latitude?: number
    longitude?: number
  }) => call<Booking>('payGuideFee', payload),
  /** Every other booking step — accept/decline, withdraw, cancellation requests, finishing the trip, the meeting place (`setLocation` + `location`). */
  bookingAction: (payload: {
    targetId: number
    op: BookingOp
    reason?: string
    /** `setLocation` replaces the text and the pin together; send neither to clear both. */
    location?: string
    latitude?: number
    longitude?: number
  }) => call<Booking>('bookingAction', payload),
  /** Every confirmed trip the caller has had, newest first — see `TripRow`. */
  trips: () => call<{ trips: TripRow[] }>('trips'),
  /** Guide cancels the booking with a visitor — they're refunded (plus 5% if late) and get their pick back. */
  cancelBooking: (payload: { targetId: number; reason?: string }) =>
    call<Booking>('cancelBooking', payload),
  /** The user opened /matches or /trips — clears that menu counter (the new counts arrive as a `badges` push). */
  markSeen: (section: 'matches' | 'trips') => call<{ ok: true }>('markSeen', { section }),

  decide: (payload: { targetId: number; decision: SelectionDecision }) =>
    call<SelectionRow>('decide', payload),
  revokeSelection: (payload: { targetId: number }) =>
    call<SelectionRow>('revokeSelection', payload),
  /** Every pair the caller is part of (matched, pending either way, passed) — see `MatchOverviewRow`. */
  matchOverview: () => call<{ selections: MatchOverviewRow[] }>('matchOverview'),

  /** One guest or guide by id, never with contact details — both public profile pages. */
  /** One person's public face as `role` (their guide or traveller side — headline, picks and rating differ). */
  publicProfile: (userId: number, role?: Role) =>
    call<PublicProfile>('publicProfile', { userId, ...(role ? { role } : {}) }),
  /** A page of someone's reviews (`limit` ≤ `REVIEWS_PAGE_SIZE`) with the summary and the caller's `viewer` state. */
  reviews: (payload: { userId: number; role: Role; limit?: number; offset?: number }) =>
    call<ReviewsPage>('reviews', payload),
  /** Creates or edits the caller's review of `subjectId` — only after a finished trip together. */
  submitReview: (payload: SubmitReviewPayload) => call<Review>('submitReview', payload),
  deleteReview: (payload: { reviewId: number }) => call<{ ok: true }>('deleteReview', payload),

  /** Everyone the caller can chat with or has chatted with, newest activity first. */
  conversations: () => call<{ conversations: Conversation[] }>('conversations'),
  /** One conversation, newest page (marks it read) or the page before `beforeId`. */
  messages: (payload: { targetId: number; beforeId?: number }) =>
    call<ChatThread>('messages', payload),
  /** Sends a message to someone the caller is actively matched with; returns it as stored (masked). */
  sendMessage: (payload: { targetId: number; body: string }) =>
    call<ChatMessage>('sendMessage', payload),
  /** Reports another user to the moderators (a title + what happened); returns the filed complaint. */
  reportUser: (payload: { subjectId: number; title: string; description: string }) =>
    call<{ id: number; status: 'open'; created_at: string }>('reportUser', payload),
  /** The caller's level, perks, bonus packages and any sanction (`Levels::stateFor()`). */
  myLevel: () => call<LevelState>('myLevel'),
  /** Starts one of the caller's available bonuses (now, or when an active one of its kind ends). */
  claimBonus: (payload: { bonusId: number }) => call<LevelState>('claimBonus', payload),
  /** Shares the caller's current position (from `navigator.geolocation`) as a `location` message. */
  shareLocation: (payload: {
    targetId: number
    latitude: number
    longitude: number
    accuracy?: number
  }) => call<ChatMessage>('shareLocation', payload),
  /** Marks what `targetId` sent the caller, up to message `upToId`, as read — messages that arrived while the conversation was open. */
  markMessagesRead: (payload: { targetId: number; upToId: number }) =>
    call<{ ok: true }>('markMessagesRead', payload),

  /** UI strings for one language, `{ key: value }` — all, or a `TranslationScope` of them (see `src/i18n`). No session needed. */
  translations: (language: string, scope: TranslationScope = {}) =>
    call<{ language: string; strings: Record<string, string> }>('translations', {
      language,
      ...scope,
    }),
  /** A legal document in force, in `language` or else English. Fetched on demand. No session needed. */
  legalDocument: (slug: LegalSlug, language: string) =>
    call<LegalDocumentView>('legalDocument', { slug, language }),
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
