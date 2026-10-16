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
import { fakeApi } from './fakeApi'

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
    // …and tapping it opens the page it's about.
    expect(useMessagesStore().items.find((item) => item.text.startsWith('Ana'))?.to).toBe('/matches')
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

  it('toasts an incoming chat message, but not my own or one for the conversation already open', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const session: AuthResult = { id: 7, email: 'v@example.com', role: 'guest', sessionHash: 'hash' }
    useAuthStore().setUser(session)
    const api = fakeApi({
      session: vi.fn<Api['session']>().mockResolvedValue(session),
      paymentState: vi.fn<Api['paymentState']>().mockRejectedValue(new Error('not needed')),
    })

    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/', name: 'home', component: { template: '<div />' } },
        { path: '/messages/:id?', name: 'messages', component: { template: '<div />' } },
      ],
    })
    router.push('/')
    await router.isReady()
    mount(App, { global: { plugins: [pinia, router], provide: { [API_KEY]: api } } })
    await flushPromises()

    const chat = (overrides: Record<string, unknown>) => ({
      message: { id: 1, sender_id: 101, recipient_id: 7, mine: false, body: 'Hi', created_at: '', read_at: null, sender_name: 'Gina', ...overrides },
    })
    const texts = () => useMessagesStore().items.map((item) => item.text)

    fakeSocket.push('message', chat({}))
    expect(texts()).toContain('New message from Gina')
    expect(useMessagesStore().items[0]?.to).toBe('/messages/101')

    useMessagesStore().items.splice(0)
    fakeSocket.push('message', chat({ id: 2, mine: true, sender_id: 7, recipient_id: 101 }))
    await router.push('/messages/101')
    fakeSocket.push('message', chat({ id: 3 }))
    expect(texts()).toEqual([])
    localStorage.clear()
  })
})
