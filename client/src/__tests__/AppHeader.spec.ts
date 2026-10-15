import { afterEach, describe, it, expect } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import { API_KEY } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useBadgesStore } from '@/stores/badges'
import { fakeApi } from './fakeApi'

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [{ path: '/:pathMatch(.*)*', name: 'any', component: { template: '<div />' } }],
  })
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
    expect(signedOut.wrapper.find('a[href="/about#how"]').exists()).toBe(true)
    signedOut.wrapper.unmount()

    const signedIn = await mountHeader(true)
    expect(signedIn.wrapper.find('a[href="/matches"]').exists()).toBe(true)
    expect(signedIn.wrapper.find('a[href="/profile"]').exists()).toBe(true)
    expect(signedIn.wrapper.find('a[href="/about#how"]').exists()).toBe(false)
    expect(signedIn.wrapper.text()).toContain('Signed in as')

    // Log out is always the last nav item: the foot of the mobile drawer,
    // and the far right of the desktop bar.
    const items = signedIn.wrapper.findAll('.nav li')
    const last = items[items.length - 1]
    expect(last?.text()).toContain('Log out')
    expect(last?.classes()).toContain('account')
    signedIn.wrapper.unmount()
  })

  it('puts red counters on Matches and My trips for unseen ones, and a dot on the burger', async () => {
    const { wrapper } = await mountHeader(true)
    expect(wrapper.find('[data-test="matches-count"]').exists()).toBe(false)
    expect(wrapper.find('.burger-dot').exists()).toBe(false)

    useBadgesStore().set({ matches: 3, trips: 120 })
    await flushPromises()

    const matches = wrapper.find('a[href="/matches"] [data-test="matches-count"]')
    expect(matches.text()).toContain('3')
    expect(matches.find('.visually-hidden').text()).toBe('3 new')
    expect(wrapper.find('a[href="/trips"] [data-test="trips-count"]').text()).toContain('99+')
    expect(wrapper.find('.burger-dot').exists()).toBe(true)

    useBadgesStore().set({ matches: 0, trips: 0 })
    await flushPromises()
    expect(wrapper.find('[data-test="matches-count"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('counts unread chat messages on Messages, with a plural label, and lights the burger dot', async () => {
    const { wrapper } = await mountHeader(true)
    expect(wrapper.find('a[href="/messages"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="messages-count"]').exists()).toBe(false)

    useBadgesStore().set({ messages: 1 })
    await flushPromises()
    const count = wrapper.find('a[href="/messages"] [data-test="messages-count"]')
    expect(count.find('.visually-hidden').text()).toBe('1 unread')
    expect(wrapper.find('.burger-dot').exists()).toBe(true)

    useBadgesStore().set({ messages: 0 })
    await flushPromises()
    expect(wrapper.find('[data-test="messages-count"]').exists()).toBe(false)
    expect(wrapper.find('.burger-dot').exists()).toBe(false)
    wrapper.unmount()
  })
})
