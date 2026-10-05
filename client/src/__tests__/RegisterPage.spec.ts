import { describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory, type Router } from 'vue-router'
import RegisterPage from '../pages/RegisterPage.vue'
import { API_KEY, ApiError, type Api } from '@/plugins/api'
import { useMessagesStore } from '@/stores/messages'

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/register', name: 'register', component: RegisterPage },
      { path: '/unlock', name: 'unlock', component: { template: '<div />' } },
      { path: '/guide/discover', name: 'guide-discover', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
}

/** A stub `Api` — every method is a no-op `vi.fn()` unless overridden. */
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

async function mountRegisterPage(path: string, api: Api): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = testRouter()
  router.push(path)
  await router.isReady()

  const wrapper = mount(RegisterPage, {
    global: {
      plugins: [pinia, router],
      provide: { [API_KEY]: api },
    },
  })
  await flushPromises()

  return { wrapper, router }
}

async function fillAndSubmit(wrapper: ReturnType<typeof mount>, email: string, password: string) {
  await wrapper.find('input[type="email"]').setValue(email)
  await wrapper.find('input[type="password"]').setValue(password)
  await wrapper.find('form').trigger('submit.prevent')
  await flushPromises()
}

describe('RegisterPage', () => {
  it('defaults to the visitor flow and links to the guide flow', async () => {
    const { wrapper } = await mountRegisterPage('/register', fakeApi())

    expect(wrapper.text()).toContain('Create your visitor account')

    const guideLink = wrapper.get('a[href="/register?role=guide"]')
    expect(guideLink.text()).toBe('I am a guide')
  })

  it('switches to the guide flow when the route carries ?role=guide', async () => {
    const { wrapper } = await mountRegisterPage('/register?role=guide', fakeApi())

    expect(wrapper.text()).toContain('Create your guide account')
    expect(wrapper.text()).not.toContain('Create your visitor account')
  })

  it('registers as a guest by default and redirects to /unlock', async () => {
    const register = vi
      .fn<Api['register']>()
      .mockResolvedValue({ id: 1, email: 'visitor@example.com', role: 'guest', sessionHash: 'test-session-hash' })
    const { wrapper, router } = await mountRegisterPage('/register', fakeApi({ register }))

    await fillAndSubmit(wrapper, 'visitor@example.com', 'correcthorse')

    expect(register).toHaveBeenCalledWith({
      email: 'visitor@example.com',
      password: 'correcthorse',
      role: 'guest',
    })
    expect(router.currentRoute.value.path).toBe('/unlock')
  })

  it('registers as a guide via ?role=guide and redirects to /guide/discover instead of /unlock', async () => {
    const register = vi
      .fn<Api['register']>()
      .mockResolvedValue({ id: 2, email: 'guide@example.com', role: 'guide', sessionHash: 'test-session-hash' })
    const { wrapper, router } = await mountRegisterPage('/register?role=guide', fakeApi({ register }))

    await fillAndSubmit(wrapper, 'guide@example.com', 'correcthorse')

    expect(register).toHaveBeenCalledWith({
      email: 'guide@example.com',
      password: 'correcthorse',
      role: 'guide',
    })
    expect(router.currentRoute.value.path).toBe('/guide/discover')
  })

  it('shows an error toast and stays put when registration fails', async () => {
    const register = vi.fn<Api['register']>().mockRejectedValue(new ApiError('Password must be at least 8 characters.', 400))
    const { wrapper, router } = await mountRegisterPage('/register', fakeApi({ register }))

    await fillAndSubmit(wrapper, 'visitor@example.com', 'short')

    expect(router.currentRoute.value.path).toBe('/register')

    const messages = useMessagesStore()
    expect(messages.items.map((item) => item.text)).toContain('Password must be at least 8 characters.')
  })
})
