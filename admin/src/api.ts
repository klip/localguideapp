import { reactive } from 'vue'

/**
 * The CMS's connection to its server (docs/auth-cms-plan.md, phase 6).
 *
 * - Sign-in is `POST /auth/<action>` on this origin (the auth service's
 *   `CmsAuthHttp`): the password, then an emailed code — always. The refresh
 *   token is an HttpOnly cookie (`__Host-rg_cms_refresh`) no script sees; a
 *   staff sign-in lasts 12 hours.
 * - Everything else is `POST /api` (`cms/classes/CmsApi.php`) with the
 *   15-minute access token, kept **in memory only** — a reload gets a new one
 *   from the cookie (`refresh()`).
 * - The refresh token is single-use, so only one tab renews at a time (a Web
 *   Lock), and tabs hand each other the result over a `BroadcastChannel`.
 */

export type StaffRole = 'admin' | 'manager' | 'editor'

export interface StaffMember {
  id: number
  email: string
  name: string
  role: StaffRole
  disabled: boolean
  hasPassword: boolean
  lastSignInAt: string | null
  createdAt: string
}

export interface Me extends StaffMember {
  capabilities: string[]
}

export interface AuditEntry {
  id: number
  staffId: number | null
  staffEmail: string
  action: string
  targetType: string
  targetId: string
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  ip: string | null
  createdAt: string
}

/** The page map as the content editor draws it (from `app_routes`, see `AppMap`). */
export interface ContentLayout {
  shell: { header: string[]; footer: string[]; always: string[] }
  pages: {
    route: string
    path: string
    title: string
    enabled: boolean
    main: string[]
    shared: string[]
  }[]
  unplaced: string[]
  languages: string[]
}

export interface ContentEntry {
  key: string
  /** The key's last part — what the editor shows. */
  name: string
  value: string | null
  english: string
  missing: boolean
}

export interface ContentBlock {
  prefix: string
  language: string
  entries: ContentEntry[]
  missingPluralForms: { base: string; missing: string[] }[]
}

export interface ContentChange {
  id: number
  language: string
  key: string
  oldValue: string | null
  newValue: string | null
  staffEmail: string
  createdAt: string
}

export type RouteAccess = 'public' | 'signed_in' | 'signed_out'

/** One route of the app (`app_routes`). What the page shows is the code's, not managed here. */
export interface AppRoute {
  id: number
  name: string
  kind: 'page' | 'redirect'
  path: string | null
  component: string | null
  redirect: string | null
  title: string
  passParams: boolean
  access: RouteAccess
  role: 'guest' | 'guide' | null
  enabled: boolean
  system: boolean
  position: number
  updatedAt: string
  updatedBy: string
}

export interface PageMap {
  routes: AppRoute[]
  /** Page components routes already use (the app's code may have more). */
  components: string[]
}

export type RouteInput = Partial<
  Pick<
    AppRoute,
    | 'id'
    | 'name'
    | 'kind'
    | 'path'
    | 'component'
    | 'redirect'
    | 'title'
    | 'passParams'
    | 'access'
    | 'role'
    | 'enabled'
    | 'position'
  >
>

export type AppRole = 'guest' | 'guide'

/** One row of the Users list (`searchUsers`). */
export interface UserRow {
  id: number
  name: string
  email: string
  phone: string | null
  image: string | null
  roles: AppRole[]
  /** Effective level per role held (null for a role not held). */
  levels: { guest: number | null; guide: number | null }
  location: string | null
  verified: boolean
  frozen: boolean
  /** When the freeze ends; null with `frozen` = permanently. */
  frozenUntil: string | null
  createdAt: string
}

export interface UserSearch {
  q?: string
  role?: '' | AppRole | 'both'
  country?: string
  /** `"PT|Lisbon"` — a value from `userLocations`. */
  city?: string
  levelRole?: '' | AppRole
  levelMin?: number | ''
  levelMax?: number | ''
  verified?: '' | 'yes' | 'no'
  frozen?: '' | 'yes' | 'no'
  createdFrom?: string
  createdTo?: string
  sort?: 'id' | 'name' | 'email' | 'created' | 'guestLevel' | 'guideLevel'
  dir?: 'asc' | 'desc'
  page?: number
}

export interface PersonRef {
  id: number
  name: string
}

export interface RoleDetail {
  held: boolean
  headline: string | null
  bio: string | null
  announcedLevel: number
  trips: number
  paidTrips: number
  points: number
  earnedLevel: number
  /** Net staff adjustment in force (`level_adjustments`). */
  adjustment: number
  effectiveLevel: number
  reviews: { count: number; average: number | null }
  topRated: boolean
  volunteer: boolean
  /** Category label → picked labels (or "09:00–13:00" spans). */
  attributes: Record<string, string[]>
}

export interface OpenBooking {
  id: number
  side: AppRole
  other: PersonRef
  status: 'pending' | 'confirmed'
  tripStatus: string
  paid: boolean
  amount: number | null
  meetingAt: string | null
}

export interface Freeze {
  id: number
  startsAt: string
  /** null = permanently. */
  until: string | null
  reason: string
}

/** Everything the user page shows (`userDetail`). */
export interface UserDetail {
  account: {
    id: number
    name: string
    email: string
    phone: string | null
    image: string | null
    twoFactorMethod: 'none' | 'email' | 'sms' | 'whatsapp'
    createdAt: string
    roles: AppRole[]
  }
  profile: {
    dateOfBirth: string | null
    gender: 'male' | 'female' | null
    priceAmount: number | null
    priceLabel: string | null
    priceNote: string | null
    includes: string | null
    party: string | null
    durationHours: number | null
    guideBase: { label: string; countryCode: string | null; radiusKm: number | null } | null
    travellerArea: { label: string; countryCode: string | null; updatedAt: string } | null
  }
  roles: Record<AppRole, RoleDetail>
  verification: {
    verifiedAt: string | null
    method: 'id_card' | 'passport' | 'driving_licence' | null
  }
  sanctions: {
    id: number
    levels: number
    reason: string
    complaintId: number | null
    createdAt: string
    expiresAt: string | null
    liftedAt: string | null
    by: string
    active: boolean
  }[]
  adjustments: {
    id: number
    role: AppRole | null
    delta: number
    reason: string
    createdAt: string
    expiresAt: string | null
    liftedAt: string | null
    by: string
    active: boolean
  }[]
  badges: {
    id: number
    label: string
    icon: string
    createdAt: string
    expiresAt: string | null
    revokedAt: string | null
    by: string
    active: boolean
  }[]
  bonuses: {
    id: number
    kind: 'free_picks' | 'no_fee'
    days: number
    reason: string
    note: string | null
    grantedAt: string
    startsAt: string | null
    endsAt: string | null
    revokedAt: string | null
    by: string | null
    state: 'unclaimed' | 'queued' | 'running' | 'used' | 'revoked'
  }[]
  freeze: Freeze | null
  freezes: {
    id: number
    startsAt: string
    endsAt: string | null
    reason: string
    by: string
    liftedAt: string | null
    liftedBy: string | null
    liftReason: string | null
  }[]
  activity: {
    counts: Record<
      | 'matches'
      | 'tripsFinished'
      | 'reviewsWritten'
      | 'reviewsReceived'
      | 'complaintsBy'
      | 'complaintsAbout',
      number
    >
    bookings: {
      id: number
      side: AppRole
      other: PersonRef
      status: string
      tripStatus: string
      amount: number | null
      meetingAt: string | null
      createdAt: string
    }[]
    reviews: {
      id: number
      direction: 'written' | 'received'
      other: PersonRef
      rating: number
      comment: string | null
      createdAt: string
    }[]
    complaints: {
      id: number
      direction: 'by' | 'about'
      other: PersonRef | null
      title: string
      status: string
      createdAt: string
    }[]
  }
  openBookings: OpenBooking[]
  /** Admins only (`users.security`). */
  security?: {
    devices: {
      id: number
      label: string
      place: string | null
      country: string | null
      ip: string | null
      firstSeenAt: string
      lastSeenAt: string
    }[]
    events: {
      id: number
      event: string
      ip: string | null
      place: string | null
      country: string | null
      userAgent: string
      riskScore: number | null
      riskReasons: string[] | null
      at: string
    }[]
    links: {
      id: number
      other: PersonRef & { email: string }
      signal: string
      strength: number
      hits: number
      lastSeenAt: string
      verdict: string | null
    }[]
  }
}

/** The changes `userEdit` makes (see `cms/classes/UserEditor.php`). */
export type UserEditOp =
  | 'account'
  | 'roleText'
  | 'profile'
  | 'addRole'
  | 'removeRole'
  | 'verification'
  | 'addSanction'
  | 'liftSanction'
  | 'addAdjustment'
  | 'liftAdjustment'
  | 'addBadge'
  | 'revokeBadge'
  | 'addBonus'
  | 'extendBonus'
  | 'revokeBonus'

export interface FreezeInput {
  days?: number
  permanent?: boolean
  reason: string
  bookings: { id: number; decision: 'leave' | 'cancel'; refund?: number | null }[]
}

/** Where users are (guides' bases, travellers' search areas), for the list's filters. */
export interface UserLocations {
  cities: { value: string; city: string; countryCode: string; users: number }[]
  countries: { code: string; users: number }[]
}

export type ComplaintStatus = 'open' | 'reviewing' | 'resolved' | 'dismissed'

export interface ComplaintRow {
  id: number
  title: string
  status: ComplaintStatus
  createdAt: string
  reporter: PersonRef | null
  subject: (PersonRef & { frozen: boolean }) | null
  /** Complaints about this person in all. */
  aboutSubject: number
  notes: number
  assignedTo: string | null
}

/** A person in a complaint or a linked-accounts pair. */
export interface ModerationPerson {
  id: number
  name: string
  email: string
  image: string | null
  roles: AppRole[]
  createdAt: string
  verified: boolean
  freeze: Freeze | null
  complaintsAbout: number
  complaintsBy: number
  /** Levels taken off by sanctions in force. */
  activeSanctions: number
}

export interface Complaint {
  id: number
  title: string
  description: string
  status: ComplaintStatus
  resolution: string | null
  createdAt: string
  assignedTo: string | null
  assignedAt: string | null
  resolvedAt: string | null
  resolvedBy: string | null
  reporterToldAt: string | null
  reporter: ModerationPerson | null
  subject: ModerationPerson | null
  between: {
    matches: {
      guestId: number
      guideId: number
      active: boolean
      guestDecision: string | null
      guideDecision: string | null
      matchedAt: string | null
    }[]
    bookings: {
      id: number
      guestId: number
      status: string
      tripStatus: string
      paymentStatus: string
      amount: number | null
      refund: number | null
      meetingAt: string | null
      cancelReason: string | null
    }[]
    messageCount: number
  } | null
  /** A trip dispute: a started trip's cancellation the guide didn't agree to — staff decide the refund. */
  dispute: {
    bookingId: number
    /** Still waiting for the refund decision (resolving needs `refund`). */
    open: boolean
    amount: number
    paymentStatus: string
    status: string
    refund: number | null
    meetingAt: string | null
    startedAt: string | null
    guestReason: string | null
    disputedAt: string | null
  } | null
  notes: { id: number; body: string; by: string; createdAt: string }[]
  sanctions: {
    id: number
    levels: number
    reason: string
    expiresAt: string | null
    liftedAt: string | null
    by: string
  }[]
}

export interface ComplaintMessage {
  id: number
  from: 'reporter' | 'subject'
  kind: 'text' | 'location'
  body: string | null
  location: { latitude: number; longitude: number } | null
  createdAt: string
}

export interface AccountLink {
  id: number
  a: ModerationPerson | null
  b: ModerationPerson | null
  signal: 'device' | 'ip_fingerprint' | 'fingerprint'
  strength: number
  hits: number
  firstSeenAt: string
  lastSeenAt: string
  verdict: 'same_person' | 'different_people' | 'unsure' | null
  reviewedAt: string | null
  reviewedBy: string | null
  note: string | null
}

export type ComplaintOp = 'take' | 'note' | 'resolve' | 'dismiss' | 'reopen'

export interface CodeChallenge {
  codeRequired: true
  challengeToken: string
  expiresInSeconds: number
}

interface SignedIn {
  accessToken: string
  accessExpiresAt: number
  staff: StaffMember
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    /** The field a validation error is about (the content editor pins it there). */
    readonly field?: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

/** Who's signed in. `ready` turns true once the first `refresh()` has answered. */
export const session = reactive({
  ready: false,
  me: null as Me | null,
  accessToken: null as string | null,
  accessExpiresAt: 0,
})

/** Whether the signed-in member's role allows `capability` (the server checks it again). */
export function can(capability: string): boolean {
  return session.me?.capabilities.includes(capability) ?? false
}

/** Called when the sign-in ends unexpectedly — the router sends the member to the login page. */
let onSignedOut: () => void = () => {}
export function whenSignedOut(handler: () => void) {
  onSignedOut = handler
}

async function post<T>(
  url: string,
  body: object,
  headers: Record<string, string> = {},
): Promise<T> {
  let response: Response
  try {
    response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', ...headers },
      body: JSON.stringify(body),
    })
  } catch {
    throw new ApiError('Can’t reach the server. Check your connection and try again.', 0)
  }
  let data: unknown = null
  try {
    data = await response.json()
  } catch {
    throw new ApiError('The server sent an unexpected answer.', response.status)
  }
  if (!response.ok) {
    const error = (data && typeof data === 'object' ? data : {}) as {
      error?: unknown
      field?: unknown
    }
    throw new ApiError(
      typeof error.error === 'string' ? error.error : 'Something went wrong.',
      response.status,
      typeof error.field === 'string' ? error.field : undefined,
    )
  }
  return data as T
}

// ------------------------------------------------------------- tokens

type AuthMessage = { type: 'signedIn'; result: SignedIn } | { type: 'signedOut' }
const channel: BroadcastChannel | null =
  typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel('belikelocal.cms.auth')
channel?.addEventListener('message', (event: MessageEvent<AuthMessage>) => {
  if (event.data?.type === 'signedIn') void adopt(event.data.result, false)
  else if (event.data?.type === 'signedOut') signedOut(false)
})

let inflight: Promise<boolean> | null = null
let timer: ReturnType<typeof setTimeout> | null = null

function secondsLeft(): number {
  return session.accessExpiresAt - Date.now() / 1000
}

async function adopt(result: SignedIn, broadcast = true) {
  session.accessToken = result.accessToken
  session.accessExpiresAt = result.accessExpiresAt
  // The full picture (capabilities) comes from `me`; keep what we have meanwhile.
  session.me =
    session.me && session.me.id === result.staff.id ? { ...session.me, ...result.staff } : null
  if (broadcast) channel?.postMessage({ type: 'signedIn', result } satisfies AuthMessage)
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => void refresh().catch(() => {}), Math.max(0, secondsLeft() - 60) * 1000)
  if (!session.me)
    session.me = await post<Me>(
      '/api',
      { action: 'me' },
      { Authorization: `Bearer ${result.accessToken}` },
    )
}

function signedOut(broadcast = true) {
  if (timer) clearTimeout(timer)
  timer = null
  const wasSignedIn = session.me !== null
  session.me = null
  session.accessToken = null
  session.accessExpiresAt = 0
  if (broadcast) channel?.postMessage({ type: 'signedOut' } satisfies AuthMessage)
  if (wasSignedIn) onSignedOut()
}

/**
 * A fresh access token from the cookie; `false` when there's no sign-in
 * (the cookie expired, was revoked, or never existed). Shared within a tab,
 * one tab at a time across tabs.
 */
export function refresh(): Promise<boolean> {
  inflight ??= renew().finally(() => {
    inflight = null
    session.ready = true
  })
  return inflight
}

async function renew(): Promise<boolean> {
  const stale = session.accessToken
  const run = async (): Promise<boolean> => {
    // Another tab may have renewed while this one waited for the lock.
    if (session.accessToken && session.accessToken !== stale && secondsLeft() > 60) return true
    try {
      await adopt(await post<SignedIn>('/auth/refresh', {}))
      return true
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        signedOut()
        return false
      }
      throw error
    }
  }
  const locks = typeof navigator === 'undefined' ? undefined : navigator.locks
  return locks ? locks.request('belikelocal.cms.refresh', run) : run()
}

/** One CMS action. Renews the token first when it's nearly spent, and once more on a 401. */
async function call<T>(action: string, payload: object = {}, retried = false): Promise<T> {
  if (!session.accessToken || secondsLeft() < 30) {
    if (!(await refresh())) throw new ApiError('Please sign in again.', 401)
  }
  try {
    return await post<T>(
      '/api',
      { action, ...payload },
      { Authorization: `Bearer ${session.accessToken}` },
    )
  } catch (error) {
    if (error instanceof ApiError && error.status === 401 && !retried) {
      session.accessToken = null
      return call<T>(action, payload, true)
    }
    throw error
  }
}

// ------------------------------------------------------------- the API

export const api = {
  /** Step one: the password. Always answered with a code challenge. */
  login: (email: string, password: string) =>
    post<CodeChallenge>('/auth/login', { email, password }),
  /** Step two: the emailed code. Signs in. */
  verifyCode: async (challengeToken: string, code: string) => {
    await adopt(await post<SignedIn>('/auth/verifyCode', { challengeToken, code }))
  },
  logout: async () => {
    try {
      await post('/auth/logout', {})
    } finally {
      signedOut()
    }
  },
  setPassword: (token: string, password: string) =>
    post<{ ok: true }>('/auth/setPassword', { token, password }),
  requestReset: (email: string) => post<{ ok: true }>('/auth/requestReset', { email }),

  me: () => call<Me>('me'),
  staffList: () => call<{ staff: StaffMember[] }>('staffList'),
  staffInvite: (member: { email: string; name: string; role: StaffRole }) =>
    call<{ staff: StaffMember }>('staffInvite', member),
  staffUpdate: (id: number, change: { role?: StaffRole; disabled?: boolean }) =>
    call<{ staff: StaffMember }>('staffUpdate', { id, ...change }),
  staffResendInvite: (id: number) => call<{ ok: true }>('staffResendInvite', { id }),
  contentLayout: () => call<ContentLayout>('contentLayout'),
  contentBlock: (prefix: string, language: string) =>
    call<ContentBlock>('contentBlock', { prefix, language }),
  /** `values`: key → text; `''` removes a translation (the app falls back to English). */
  contentSave: (prefix: string, language: string, values: Record<string, string>) =>
    call<ContentBlock & { changed: number }>('contentSave', { prefix, language, values }),
  contentAddKey: (prefix: string, name: string, english: string, language: string) =>
    call<ContentBlock & { key: string }>('contentAddKey', { prefix, name, english, language }),
  contentHistory: (prefix: string) =>
    call<{ history: ContentChange[] }>('contentHistory', { prefix }),
  contentRevert: (historyId: number) =>
    call<{ language: string; key: string; value: string | null }>('contentRevert', { historyId }),
  searchUsers: (search: UserSearch) =>
    call<{ users: UserRow[]; total: number; page: number; pageSize: number }>(
      'searchUsers',
      search,
    ),
  userLocations: () => call<UserLocations>('userLocations'),
  userDetail: (id: number) => call<UserDetail>('userDetail', { id }),
  userEdit: (id: number, op: UserEditOp, payload: Record<string, unknown> = {}) =>
    call<UserDetail>('userEdit', { id, op, ...payload }),
  userFreeze: (id: number, freeze: FreezeInput) =>
    call<UserDetail & { result: unknown }>('userFreeze', { id, ...freeze }),
  userUnfreeze: (id: number, freezeId: number, reason: string) =>
    call<UserDetail & { result: unknown }>('userUnfreeze', { id, freezeId, reason }),
  bookingDecision: (id: number, bookingId: number, refund: number | null, note: string) =>
    call<UserDetail & { result: unknown }>('bookingDecision', { id, bookingId, refund, note }),
  pageMap: () => call<PageMap>('pageMap'),
  routeSave: (route: RouteInput) => call<PageMap & { route: AppRoute }>('routeSave', route),
  routeDelete: (id: number) => call<PageMap>('routeDelete', { id }),
  moderationComplaints: (options: { status?: string; subjectId?: number; page?: number } = {}) =>
    call<{
      complaints: ComplaintRow[]
      total: number
      page: number
      pageSize: number
      counts: Record<ComplaintStatus, number>
    }>('moderationComplaints', options),
  moderationComplaint: (id: number) => call<Complaint>('moderationComplaint', { id }),
  /** The pair's chat — every call is recorded in the audit log. */
  complaintMessages: (id: number) =>
    call<{ messages: ComplaintMessage[] }>('complaintMessages', { id }),
  complaintAction: (id: number, op: ComplaintOp, payload: Record<string, unknown> = {}) =>
    call<Complaint>('complaintAction', { id, op, ...payload }),
  moderationLinks: (options: { status?: 'unreviewed' | 'reviewed' | 'all'; page?: number } = {}) =>
    call<{
      links: AccountLink[]
      total: number
      unreviewed: number
      page: number
      pageSize: number
    }>('moderationLinks', options),
  linkVerdict: (id: number, verdict: NonNullable<AccountLink['verdict']>, note: string) =>
    call<{ ok: true }>('linkVerdict', { id, verdict, note }),
  auditLog: (options: { beforeId?: number; filter?: string; target?: string } = {}) =>
    call<{ entries: AuditEntry[]; hasMore: boolean; scope: 'all' | 'operations' | 'own' }>(
      'auditLog',
      options,
    ),
}
