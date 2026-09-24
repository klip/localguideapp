import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import { API_KEY, type Api } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [{ path: '/:pathMatch(.*)*', name: 'any', component: { template: '<div />' } }],
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
    cancelBooking: vi.fn<Api['cancelBooking']>(),
    notifications: vi.fn<Api['notifications']>(),
    decide: vi.fn<Api['decide']>(),
    revokeSelection: vi.fn<Api['revokeSelection']>(),
    matches: vi.fn<Api['matches']>(),
    matchOverview: vi.fn<Api['matchOverview']>(),
    publicProfile: vi.fn<Api['publicProfile']>(),
    reviews: vi.fn<Api['reviews']>(),
    submitReview: vi.fn<Api['submitReview']>(),
    deleteReview: vi.fn<Api['deleteReview']>(),
    ...overrides,
  }
}

async function mountHeader(signedIn: boolean) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (signedIn) useAuthStore().setUser({ id: 1, email: 'me@example.com', role: 'guide', sessionHash: 'hash' })

  const router = testRouter()
  router.push('/')
  await router.isReady()

  const wrapper = mount(AppHeader, {
    attachTo: document.body,
    global: { plugins: [pinia, router], provide: { [API_KEY]: fakeApi() } },
  })
  return { wrapper, router }
}

afterEach(() => {
  localStorage.clear()
  document.body.innerHTML = ''
})

describe('AppHeader mobile menu', () => {
  it('opens the drawer from the burger, focuses into it and locks page scroll', async () => {
    const { wrapper } = await mountHeader(false)
    const burger = wrapper.find('.burger')

    expect(burger.attributes('aria-expanded')).toBe('false')
    await burger.trigger('click')
    await flushPromises()

    expect(burger.attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('.drawer').classes()).toContain('drawer--open')
    expect(wrapper.find('.drawer').element.contains(document.activeElement)).toBe(true)
    expect(document.body.style.overflow).toBe('hidden')
    wrapper.unmount()
  })

  it('closes on Escape and hands focus back to the burger', async () => {
    const { wrapper } = await mountHeader(false)
    await wrapper.find('.burger').trigger('click')
    await flushPromises()

    await wrapper.find('.drawer').trigger('keydown', { key: 'Escape' })

    expect(wrapper.find('.drawer').classes()).not.toContain('drawer--open')
    expect(document.activeElement).toBe(wrapper.find('.burger').element)
    expect(document.body.style.overflow).toBe('')
    wrapper.unmount()
  })

  it('closes when the backdrop or a link is clicked', async () => {
    const { wrapper } = await mountHeader(false)

    await wrapper.find('.burger').trigger('click')
    await wrapper.find('.backdrop').trigger('click')
    expect(wrapper.find('.drawer').classes()).not.toContain('drawer--open')

    await wrapper.find('.burger').trigger('click')
    await wrapper.find('.drawer a[href="/login"]').trigger('click')
    expect(wrapper.find('.drawer').classes()).not.toContain('drawer--open')
    wrapper.unmount()
  })

  it('shows Matches and Profile only when signed in, and "How it works" only when signed out', async () => {
    const signedOut = await mountHeader(false)
    expect(signedOut.wrapper.find('a[href="/matches"]').exists()).toBe(false)
    expect(signedOut.wrapper.find('a[href="/#how"]').exists()).toBe(true)
    signedOut.wrapper.unmount()

    const signedIn = await mountHeader(true)
    expect(signedIn.wrapper.find('a[href="/matches"]').exists()).toBe(true)
    expect(signedIn.wrapper.find('a[href="/profile"]').exists()).toBe(true)
    expect(signedIn.wrapper.find('a[href="/#how"]').exists()).toBe(false)
    expect(signedIn.wrapper.text()).toContain('Signed in as')

    // Log out is always the last nav item: the foot of the mobile drawer,
    // and the far right of the desktop bar.
    const items = signedIn.wrapper.findAll('.nav li')
    const last = items[items.length - 1]
    expect(last?.text()).toContain('Log out')
    expect(last?.classes()).toContain('account')
    signedIn.wrapper.unmount()
  })
})
