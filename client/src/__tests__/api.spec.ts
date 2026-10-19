import { afterEach, beforeAll, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api, ApiError } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { FakeWebSocket } from './fakeWebSocket'

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
    // stores/auth.ts reads localStorage on creation — clear it so one test's
    // setUser() doesn't leak into the next test's fresh Pinia instance.
    localStorage.clear()
  })

  it('sends every request over the socket with the current session hash', async () => {
    setActivePinia(createPinia())
    useAuthStore().setUser({ id: 1, email: 'a@example.com', sessionHash: 'abc123' })

    await api.updateAccount({ name: 'New Name' })

    expect(FakeWebSocket.instances[0]!.url).toMatch(/^wss?:\/\/.+\/ws$/)
    expect(lastRequest()).toMatchObject({
      type: 'request',
      action: 'updateAccount',
      sessionHash: 'abc123',
      name: 'New Name',
    })
  })

  it('sends the role the account is acting as, and only that role', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({
      id: 1,
      email: 'a@example.com',
      role: 'guest',
      roles: ['guest', 'guide'],
      sessionHash: 'abc123',
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
      sessionHash: 'abc123',
    })
    expect(useAuthStore().activeRole).toBe('guest')
  })

  it('reads an older cached session (role only) as that one role', () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({ id: 1, email: 'a@example.com', role: 'guide', sessionHash: 'abc123' })
    expect(auth.roles).toEqual(['guide'])
    expect(auth.activeRole).toBe('guide')
  })

  it('omits sessionHash when signed out, and resolves with the body', async () => {
    setActivePinia(createPinia())
    FakeWebSocket.respond = () => ({ status: 200, body: { guides: [] } })

    await expect(api.guides()).resolves.toEqual({ guides: [] })
    expect(lastRequest()).not.toHaveProperty('sessionHash')
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

  it('clears the local session when a request comes back 401', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({ id: 1, email: 'a@example.com', sessionHash: 'expired-hash' })
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
    auth.setUser({ id: 1, email: 'a@example.com', sessionHash: 'still-good' })
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
    expect(error.message).toMatch(/can’t be shared on RockGuide/)
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
