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

  it('omits sessionHash when signed out, and resolves with the body', async () => {
    setActivePinia(createPinia())
    FakeWebSocket.respond = () => ({ status: 200, body: { guides: [] } })

    await expect(api.guides()).resolves.toEqual({ guides: [] })
    expect(lastRequest()).not.toHaveProperty('sessionHash')
  })

  it('clears the local session when a request comes back 401', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({ id: 1, email: 'a@example.com', sessionHash: 'expired-hash' })
    FakeWebSocket.respond = () => ({ status: 401, body: { error: 'Your session has expired. Please log in again.' } })

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
