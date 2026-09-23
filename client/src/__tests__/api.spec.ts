import { afterEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api, ApiError } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'

/** A response object shaped just enough for call()'s `response.ok`/`.status`/`.json()` usage — not a real fetch Response. */
function jsonResponse(body: unknown, status = 200): Response {
  return { ok: status >= 200 && status < 300, status, json: async () => body } as Response
}

describe('plugins/api call()', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    // stores/auth.ts reads localStorage on creation — clear it so one test's
    // setUser() doesn't leak into the next test's fresh Pinia instance.
    localStorage.clear()
  })

  it('attaches the current session hash to every request', async () => {
    setActivePinia(createPinia())
    useAuthStore().setUser({ id: 1, email: 'a@example.com', sessionHash: 'abc123' })

    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({ ok: true }))
    vi.stubGlobal('fetch', fetchMock)

    await api.updateAccount({ name: 'New Name' })

    const [, init] = fetchMock.mock.calls[0]!
    const body = JSON.parse((init as RequestInit).body as string)
    expect(body).toMatchObject({ action: 'updateAccount', sessionHash: 'abc123', name: 'New Name' })
  })

  it('omits sessionHash when signed out', async () => {
    setActivePinia(createPinia())

    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({ guides: [] }))
    vi.stubGlobal('fetch', fetchMock)

    await api.guides()

    const [, init] = fetchMock.mock.calls[0]!
    const body = JSON.parse((init as RequestInit).body as string)
    expect(body).not.toHaveProperty('sessionHash')
  })

  it('clears the local session when a request comes back 401', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({ id: 1, email: 'a@example.com', sessionHash: 'expired-hash' })

    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse({ error: 'Your session has expired. Please log in again.' }, 401)),
    )

    await expect(api.myProfile()).rejects.toThrow(ApiError)
    expect(auth.isAuthenticated).toBe(false)
  })

  it('leaves the local session alone for a non-401 error', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({ id: 1, email: 'a@example.com', sessionHash: 'still-good' })

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ error: 'Something else went wrong.' }, 400)))

    await expect(api.myProfile()).rejects.toThrow(ApiError)
    expect(auth.isAuthenticated).toBe(true)
  })
})
