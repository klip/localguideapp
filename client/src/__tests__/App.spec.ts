import { describe, it, expect, vi } from 'vitest'

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import App from '../App.vue'
import HomePage from '../pages/HomePage.vue'
import { API_KEY, type Api, type AuthResult, type PaymentState } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { useShortlistStore } from '@/stores/shortlist'

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
    notifications: vi.fn<Api['notifications']>(),
    decide: vi.fn<Api['decide']>(),
    revokeSelection: vi.fn<Api['revokeSelection']>(),
    matches: vi.fn<Api['matches']>(),
    matchOverview: vi.fn<Api['matchOverview']>(),
    trips: vi.fn<Api['trips']>(),
    publicProfile: vi.fn<Api['publicProfile']>(),
    reviews: vi.fn<Api['reviews']>(),
    submitReview: vi.fn<Api['submitReview']>(),
    deleteReview: vi.fn<Api['deleteReview']>(),
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
    const shortlist = useShortlistStore()
    expect(shortlist.packsUnlocked).toBe(2)
    expect(shortlist.hasAccess).toBe(true)
    // Picks come back too, so the header reads "2 / 10 picked" rather than
    // "0 / 10" for a visitor who has already spent some of the pack.
    expect(shortlist.picks).toEqual(['18', '42'])
    expect(shortlist.passed).toEqual(['7'])
    expect(shortlist.isPicked('18')).toBe(true)
    expect(shortlist.isDecided('7')).toBe(true)
    expect(shortlist.selectionsLeft).toBe(8)
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

  it('shows unread notifications as toasts on boot', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)

    const session: AuthResult = { id: 18, email: 'guide@example.com', role: 'guide', sessionHash: 'hash' }
    useAuthStore().setUser(session)

    const api = fakeApi({
      session: vi.fn<Api['session']>().mockResolvedValue(session),
      notifications: vi.fn<Api['notifications']>().mockResolvedValue({
        notifications: [
          { id: 1, kind: 'booking_paid', message: 'Ana paid your £50.00 fee.', created_at: '2026-09-24 10:00:00' },
        ],
      }),
    })

    const router = testRouter()
    router.push('/')
    await router.isReady()

    mount(App, { global: { plugins: [pinia, router], provide: { [API_KEY]: api } } })
    await flushPromises()

    expect(api.notifications).toHaveBeenCalledTimes(1)
    expect(useMessagesStore().items.map((item) => item.text)).toContain('Ana paid your £50.00 fee.')
    localStorage.clear()
  })

  it('does not ask for notifications when signed out', async () => {
    const api = fakeApi()
    const router = testRouter()
    router.push('/')
    await router.isReady()

    mount(App, { global: { plugins: [createPinia(), router], provide: { [API_KEY]: api } } })
    await flushPromises()

    expect(api.notifications).not.toHaveBeenCalled()
  })
})
