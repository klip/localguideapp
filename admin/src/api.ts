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
    const message =
      data && typeof data === 'object' && typeof (data as { error?: unknown }).error === 'string'
        ? (data as { error: string }).error
        : 'Something went wrong.'
    throw new ApiError(message, response.status)
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
  auditLog: (options: { beforeId?: number; filter?: string } = {}) =>
    call<{ entries: AuditEntry[]; hasMore: boolean; scope: 'all' | 'operations' | 'own' }>(
      'auditLog',
      options,
    ),
}
