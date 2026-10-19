import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import { API_KEY, type Api, type MyProfile } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useBadgesStore } from '@/stores/badges'
import { fakeApi } from './fakeApi'

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [{ path: '/:pathMatch(.*)*', name: 'any', component: { template: '<div />' } }],
  })
}

async function mountHeader(signedIn: boolean, api: Api = fakeApi()) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (signedIn)
    useAuthStore().setUser({ id: 1, email: 'me@example.com', role: 'guide', sessionHash: 'hash' })

  const router = testRouter()
  router.push('/')
  await router.isReady()

  const wrapper = mount(AppHeader, {
    attachTo: document.body,
    global: { plugins: [pinia, router], provide: { [API_KEY]: api } },
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

    // Log out is always last: the foot of the mobile drawer, and the far
    // right of the desktop header.
    const last = signedIn.wrapper.find('.drawer').element.lastElementChild
    expect(last?.classList.contains('account')).toBe(true)
    expect(last?.textContent).toContain('Log out')
    // The language comes first in the drawer, right after its head.
    expect(signedIn.wrapper.find('.drawer-head + .language').exists()).toBe(true)
    signedIn.wrapper.unmount()
  })

  it('shows the profile circle with the photo, or the initial without one', async () => {
    const noPhoto = await mountHeader(true)
    await flushPromises()
    expect(noPhoto.wrapper.get('[data-test="nav-profile"] .avatar').text()).toBe('M')
    noPhoto.wrapper.unmount()

    const api = fakeApi({
      myProfile: vi
        .fn<Api['myProfile']>()
        .mockResolvedValue({ image: 'data:image/png;base64,AAAA' } as MyProfile),
    })
    const withPhoto = await mountHeader(true, api)
    await flushPromises()
    const img = withPhoto.wrapper.get('[data-test="nav-profile"] .avatar img')
    expect(img.attributes('src')).toBe('data:image/png;base64,AAAA')
    expect(withPhoto.wrapper.get('[data-test="nav-profile"]').attributes('href')).toBe('/profile')
    withPhoto.wrapper.unmount()
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

describe('AppHeader roles', () => {
  it('offers the missing role to a one-role account, linking to the profile page', async () => {
    const { wrapper } = await mountHeader(true)

    const link = wrapper.find('[data-test="add-role-link"]')
    expect(link.text()).toBe('Start travelling')
    expect(link.attributes('href')).toBe('/profile?addRole=guest')
    expect(wrapper.find('[data-test="role-switch"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('switches a dual-role account with Travel [switch] Guide, swapping the deck', async () => {
    const { wrapper, router } = await mountHeader(true)
    const auth = useAuthStore()
    auth.setUser({ ...auth.user!, roles: ['guide', 'guest'] })
    await router.push('/guide/discover')
    await flushPromises()

    expect(wrapper.find('[data-test="add-role-link"]').exists()).toBe(false)
    // "Travel [switch] Guide": on means guiding.
    const toggle = wrapper.get<HTMLInputElement>('[data-test="role-switch"]')
    expect(toggle.attributes('role')).toBe('switch')
    expect(toggle.element.checked).toBe(true)
    expect(wrapper.get('.role-toggle').text()).toBe('TravelGuide')
    expect(wrapper.find('a[href="/guide/discover"]').exists()).toBe(true)

    await toggle.setValue(false)
    await flushPromises()

    expect(auth.activeRole).toBe('guest')
    expect(router.currentRoute.value.path).toBe('/discover')
    expect(wrapper.find('a[href="/discover"]').exists()).toBe(true)
    expect(toggle.element.checked).toBe(false)
    expect(wrapper.get('.role-word--on').text()).toBe('Travel')
    wrapper.unmount()
  })
})
