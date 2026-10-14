import { describe, it, expect, vi } from 'vitest'

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import App from '../App.vue'
import HomePage from '../pages/HomePage.vue'
import { API_KEY, type Api, type AuthResult, type PaymentState } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { useMatchesStore } from '@/stores/matches'
import { useBadgesStore } from '@/stores/badges'

/** Stands in for the API socket: records `identify()` and lets a test fire pushes. */
const fakeSocket = vi.hoisted(() => {
  const handlers = new Map<string, (message: Record<string, unknown>) => void>()
  return {
    identify: vi.fn<(hash: string | null) => void>(),
    on: (type: string, handler: (message: Record<string, unknown>) => void) => {
      handlers.set(type, handler)
      return () => handlers.delete(type)
    },
    push: (type: string, message: Record<string, unknown>) => handlers.get(type)?.({ type, ...message }),
  }
})
vi.mock('@/plugins/socket', () => ({ socket: fakeSocket }))

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/', name: 'home', component: HomePage },
      { path: '/:pathMatch(.*)*', name: 'not-found', component: { template: '<div />' } },
    ],
  })
}

/** A stub `Api` — every method is a no-op `vi.fn()` unless overridden. App.vue only calls `session()`, and only when a cached session already exists (not the case for this anonymous-render test). */
function fakeApi(overrides: Partial<Api> = {}): Api {
  return {
    register: vi.fn<Api['register']>(),
    login: vi.fn<Api['login']>(),
    verifyTwoFactor: vi.fn<Api['verifyTwoFactor']>(),
    resendTwoFactor: vi.fn<Api['resendTwoFactor']>(),
    requestPasswordReset: vi.fn<Api['requestPasswordReset']>(),
    resetPassword: vi.fn<Api['resetPassword']>(),
    forgetTrustedDevices: vi.fn<Api['forgetTrustedDevices']>(),
    guides: vi.fn<Api['guides']>(),
    visitors: vi.fn<Api['visitors']>(),
    updateProfile: vi.fn<Api['updateProfile']>(),
    attributeCategories: vi.fn<Api['attributeCategories']>(),
    myProfile: vi.fn<Api['myProfile']>(),
    updateAccount: vi.fn<Api['updateAccount']>(),
    changePassword: vi.fn<Api['changePassword']>(),
    logout: vi.fn<Api['logout']>(),
    session: vi.fn<Api['session']>(),
    unlockPack: vi.fn<Api['unlockPack']>(),
    paymentState: vi.fn<Api['paymentState']>(),
    payGuideFee: vi.fn<Api['payGuideFee']>(),
    bookingAction: vi.fn<Api['bookingAction']>(),
    cancelBooking: vi.fn<Api['cancelBooking']>(),
    markSeen: vi.fn<Api['markSeen']>().mockResolvedValue({ ok: true }),
    decide: vi.fn<Api['decide']>(),
    revokeSelection: vi.fn<Api['revokeSelection']>(),
    matchOverview: vi.fn<Api['matchOverview']>(),
    trips: vi.fn<Api['trips']>(),
    publicProfile: vi.fn<Api['publicProfile']>(),
    reviews: vi.fn<Api['reviews']>(),
    submitReview: vi.fn<Api['submitReview']>(),
    deleteReview: vi.fn<Api['deleteReview']>(),
    translations: vi.fn<Api['translations']>(),
    ...overrides,
  }
}

describe('App', () => {
  it('renders the landing page inside the app shell', async () => {
    const router = testRouter()
    router.push('/')
    await router.isReady()

    const wrapper = mount(App, {
      global: {
        plugins: [createPinia(), router],
        provide: { [API_KEY]: fakeApi() },
      },
    })

    expect(wrapper.find('.app-shell').exists()).toBe(true)
    expect(wrapper.text()).toContain('RockGuide')
    expect(wrapper.text()).toContain('Find your local. Skip the tourist shuffle.')
  })

  /**
   * A paid-up visitor used to come back from a refresh with `packsUnlocked`
   * at 0 — the store is in-memory, and only LoginPage/UnlockPage re-synced
   * it, so F5 showed the paywall despite the packs existing server-side.
   */
  it('restores a guest\'s purchased packs on boot', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)

    const session: AuthResult = { id: 7, email: 'guest@example.com', role: 'guest', sessionHash: 'hash' }
    useAuthStore().setUser(session)

    const state: PaymentState = {
      packsUnlocked: 2,
      capacity: 10,
      picksUsed: 2,
      selectionsLeft: 8,
      pickedIds: [18, 42],
      passedIds: [7],
    }
    const api = fakeApi({
      session: vi.fn<Api['session']>().mockResolvedValue(session),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(state),
    })

    const router = testRouter()
    router.push('/')
    await router.isReady()

    mount(App, { global: { plugins: [pinia, router], provide: { [API_KEY]: api } } })
    await flushPromises()

    expect(api.paymentState).toHaveBeenCalled()
    const matchesStore = useMatchesStore()
    expect(matchesStore.packsUnlocked).toBe(2)
    expect(matchesStore.hasAccess).toBe(true)
    // Picks come back too, so the header reads "2 / 10 picked" rather than
    // "0 / 10" for a visitor who has already spent some of the pack.
    expect(matchesStore.picks).toEqual(['18', '42'])
    expect(matchesStore.passed).toEqual(['7'])
    expect(matchesStore.isPicked('18')).toBe(true)
    expect(matchesStore.isDecided('7')).toBe(true)
    expect(matchesStore.selectionsLeft).toBe(8)
    localStorage.clear()
  })

  it('does not ask for payment state as a guide', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)

    const session: AuthResult = { id: 18, email: 'guide@example.com', role: 'guide', sessionHash: 'hash' }
    useAuthStore().setUser(session)

    const api = fakeApi({ session: vi.fn<Api['session']>().mockResolvedValue(session) })

    const router = testRouter()
    router.push('/')
    await router.isReady()

    mount(App, { global: { plugins: [pinia, router], provide: { [API_KEY]: api } } })
    await flushPromises()

    expect(api.paymentState).not.toHaveBeenCalled()
    localStorage.clear()
  })

  it('identifies the session to the socket and shows pushed notifications as toasts', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)

    const session: AuthResult = { id: 18, email: 'guide@example.com', role: 'guide', sessionHash: 'hash' }
    useAuthStore().setUser(session)
    const api = fakeApi({ session: vi.fn<Api['session']>().mockResolvedValue(session) })

    const router = testRouter()
    router.push('/')
    await router.isReady()

    mount(App, { global: { plugins: [pinia, router], provide: { [API_KEY]: api } } })
    await flushPromises()

    expect(fakeSocket.identify).toHaveBeenLastCalledWith('hash')

    fakeSocket.push('notification', {
      notification: { id: 1, kind: 'booking_requested', message: 'Ana paid your £50.00 fee.', created_at: '2026-09-24 10:00:00' },
    })
    fakeSocket.push('badges', { matches: 2, trips: 1 })

    expect(useMessagesStore().items.map((item) => item.text)).toContain('Ana paid your £50.00 fee.')
    expect(useBadgesStore().matches).toBe(2)
    expect(useBadgesStore().trips).toBe(1)
    expect(useBadgesStore().revision).toBe(1)

    // Signing out tells the socket and clears the counters.
    useAuthStore().clear()
    await flushPromises()
    expect(fakeSocket.identify).toHaveBeenLastCalledWith(null)
    expect(useBadgesStore().matches).toBe(0)
    localStorage.clear()
  })
})
