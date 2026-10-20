import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api, ApiError } from '@/plugins/api'
import type { AuthResult } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { FakeWebSocket } from './fakeWebSocket'

/** A signed-in user with an access token good for another 15 minutes. */
function signedIn(extra: Partial<AuthResult> = {}): AuthResult {
  return {
    id: 1,
    email: 'a@example.com',
    accessToken: 'token-1',
    accessExpiresAt: Math.floor(Date.now() / 1000) + 900,
    ...extra,
  }
}

/** Stands in for `fetch`: answers `/auth/<action>` from `routes` and records each call. */
function stubAuthHttp(routes: Record<string, { status: number; body: unknown }>) {
  const fetchMock = vi.fn<typeof fetch>(async (input) => {
    const action = String(input).replace(/^\/auth\//, '')
    const route = routes[action] ?? { status: 404, body: { error: 'Unknown action.' } }
    return new Response(JSON.stringify(route.body), { status: route.status })
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

/** The last request the (shared, lazily opened) API socket sent. */
function lastRequest() {
  const sent = FakeWebSocket.instances.flatMap((ws) => ws.sent).filter((m) => m.type === 'request')
  return sent[sent.length - 1]!
}

describe('plugins/api call()', () => {
  beforeAll(() => {
    // The socket is created on the first request, through the global.
    globalThis.WebSocket = FakeWebSocket as unknown as typeof WebSocket
  })

  afterEach(() => {
    FakeWebSocket.respond = () => ({ status: 200, body: { ok: true } })
    vi.unstubAllGlobals()
    // stores/auth.ts reads localStorage on creation — clear it so one test's
    // setUser() doesn't leak into the next test's fresh Pinia instance.
    localStorage.clear()
  })

  it('sends every request over the socket with the current access token', async () => {
    setActivePinia(createPinia())
    useAuthStore().setUser(signedIn())

    await api.updateAccount({ name: 'New Name' })

    expect(FakeWebSocket.instances[0]!.url).toMatch(/^wss?:\/\/.+\/ws$/)
    expect(lastRequest()).toMatchObject({
      type: 'request',
      action: 'updateAccount',
      accessToken: 'token-1',
      name: 'New Name',
    })
  })

  it('keeps the access token out of localStorage', () => {
    setActivePinia(createPinia())
    useAuthStore().setUser(signedIn({ name: 'Ana' }))

    const stored = localStorage.getItem('belikelocal.session')!
    expect(stored).not.toContain('token-1')
    expect(JSON.parse(stored)).toEqual({ id: 1, email: 'a@example.com', name: 'Ana' })
    expect(useAuthStore().accessToken).toBe('token-1')
  })

  it('renews a token about to expire before sending', async () => {
    setActivePinia(createPinia())
    useAuthStore().setUser(signedIn({ accessExpiresAt: Math.floor(Date.now() / 1000) + 10 }))
    const fetchMock = stubAuthHttp({
      refresh: {
        status: 200,
        body: {
          accessToken: 'token-2',
          accessExpiresAt: Math.floor(Date.now() / 1000) + 900,
          user: { id: 1, email: 'a@example.com', roles: ['guest'] },
        },
      },
    })

    await api.myProfile()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe('/auth/refresh')
    expect(init).toMatchObject({ method: 'POST', credentials: 'same-origin' })
    expect(lastRequest()).toMatchObject({ action: 'myProfile', accessToken: 'token-2' })
  })

  it('renews once and retries when a request comes back 401', async () => {
    setActivePinia(createPinia())
    useAuthStore().setUser(signedIn())
    stubAuthHttp({
      refresh: {
        status: 200,
        body: {
          accessToken: 'token-2',
          accessExpiresAt: Math.floor(Date.now() / 1000) + 900,
          user: { id: 1, email: 'a@example.com' },
        },
      },
    })
    FakeWebSocket.respond = (request) =>
      request.accessToken === 'token-2'
        ? { status: 200, body: { ok: true } }
        : { status: 401, body: { error: 'Your session has expired. Please log in again.' } }

    await expect(api.myProfile()).resolves.toEqual({ ok: true })
    expect(useAuthStore().isAuthenticated).toBe(true)
  })

  it('signs in over HTTP and keeps the token it brings', async () => {
    setActivePinia(createPinia())
    const fetchMock = stubAuthHttp({
      login: {
        status: 200,
        body: {
          id: 5,
          email: 'b@example.com',
          roles: ['guide'],
          accessToken: 'token-9',
          accessExpiresAt: Math.floor(Date.now() / 1000) + 900,
        },
      },
    })

    const result = await api.login({ email: 'b@example.com', password: 'secret123' })

    expect(fetchMock.mock.calls[0]![0]).toBe('/auth/login')
    expect(JSON.parse(String(fetchMock.mock.calls[0]![1]!.body))).toEqual({
      email: 'b@example.com',
      password: 'secret123',
    })
    expect(result).toMatchObject({ id: 5 })
    expect(useAuthStore().accessToken).toBe('token-9')
    expect(useAuthStore().user?.id).toBe(5)
  })

  it('sends the role the account is acting as, and only that role', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({
      id: 1,
      email: 'a@example.com',
      role: 'guest',
      roles: ['guest', 'guide'],
      accessToken: 'token-1',
      accessExpiresAt: Math.floor(Date.now() / 1000) + 900,
    })

    // The first (registration) role until another is chosen.
    expect(auth.activeRole).toBe('guest')
    await api.matchOverview()
    expect(lastRequest()).toMatchObject({ action: 'matchOverview', actingRole: 'guest' })

    auth.setActiveRole('guide')
    await api.matchOverview()
    expect(lastRequest()).toMatchObject({ actingRole: 'guide' })

    // A role the account doesn't hold is ignored; the choice survives a reload…
    setActivePinia(createPinia())
    expect(useAuthStore().activeRole).toBe('guide')
    // …but not a role the account lost.
    useAuthStore().setUser({
      id: 1,
      email: 'a@example.com',
      role: 'guest',
      roles: ['guest'],
    })
    expect(useAuthStore().activeRole).toBe('guest')
  })

  it('reads an older cached session (role only) as that one role', () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({ id: 1, email: 'a@example.com', role: 'guide' })
    expect(auth.roles).toEqual(['guide'])
    expect(auth.activeRole).toBe('guide')
  })

  it('omits the token when signed out, and resolves with the body', async () => {
    setActivePinia(createPinia())
    FakeWebSocket.respond = () => ({ status: 200, body: { guides: [] } })

    await expect(api.guides()).resolves.toEqual({ guides: [] })
    expect(lastRequest()).not.toHaveProperty('accessToken')
    expect(lastRequest()).not.toHaveProperty('actingRole')
  })

  it('sends the search point and the distance limit, and the limit only with a point', async () => {
    setActivePinia(createPinia())
    FakeWebSocket.respond = () => ({ status: 200, body: { guides: [] } })
    const lisbon = {
      label: 'Lisbon, Portugal',
      countryCode: 'PT',
      latitude: 38.72,
      longitude: -9.14,
    }
    const base = { gender: null, attributes: {}, ranges: {} }

    await api.guides({ ...base, near: lisbon, maxKm: 25 })
    expect(lastRequest()).toMatchObject({ near: { latitude: 38.72, longitude: -9.14 }, maxKm: 25 })
    // Only the point travels, not its name.
    expect((lastRequest().near as Record<string, unknown>).label).toBeUndefined()

    await api.guides({ ...base, near: null, maxKm: 25 })
    expect(lastRequest()).not.toHaveProperty('near')
    expect(lastRequest()).not.toHaveProperty('maxKm')
  })

  it('signs out when a request comes back 401 and the sign-in is over', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser(signedIn())
    stubAuthHttp({ refresh: { status: 401, body: { error: 'Your session has expired.' } } })
    FakeWebSocket.respond = () => ({
      status: 401,
      body: { error: 'Your session has expired. Please log in again.' },
    })

    await expect(api.myProfile()).rejects.toThrow(ApiError)
    expect(auth.isAuthenticated).toBe(false)
  })

  it('leaves the local session alone for a non-401 error, keeping the server message', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser(signedIn())
    FakeWebSocket.respond = () => ({ status: 400, body: { error: 'Something else went wrong.' } })

    const error = await api.myProfile().catch((err: unknown) => err)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).message).toBe('Something else went wrong.')
    expect((error as ApiError).status).toBe(400)
    expect(auth.isAuthenticated).toBe(true)
  })

  it("shows a contact_details refusal in the viewer's language, keeping code and field", async () => {
    setActivePinia(createPinia())
    FakeWebSocket.respond = () => ({
      status: 400,
      body: { error: 'English server text', code: 'contact_details', field: 'bio' },
    })

    const error = (await api
      .updateProfile({ bio: 'call 56002731' })
      .catch((err: unknown) => err)) as ApiError
    expect(error).toBeInstanceOf(ApiError)
    expect(error.message).toMatch(/can’t be shared on BeLikeLocal/)
    expect(error.code).toBe('contact_details')
    expect(error.field).toBe('bio')
  })

  it('fails with status 0 when the connection drops mid-request', async () => {
    setActivePinia(createPinia())
    FakeWebSocket.respond = () => null

    const pending = api.myProfile().catch((err: unknown) => err)
    await new Promise((resolve) => setTimeout(resolve, 5))
    FakeWebSocket.instances[FakeWebSocket.instances.length - 1]!.drop()

    const error = await pending
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(0)
  })
})
