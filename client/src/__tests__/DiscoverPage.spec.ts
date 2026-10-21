import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import DiscoverPage from '../pages/DiscoverPage.vue'
import { API_KEY, type Api, type PaymentState, type ProfileAccount } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMatchesStore } from '@/stores/matches'
import { readPendingRejects } from '@/utils/pendingRejects'
import { fakeApi as baseFakeApi } from './fakeApi'

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/discover', name: 'discover', component: DiscoverPage },
      { path: '/guides/:id', name: 'guide-profile', component: { template: '<div />' } },
      { path: '/:pathMatch(.*)*', name: 'other', component: { template: '<div />' } },
    ],
  })
}

/** The shared stub `Api` (see `fakeApi.ts`) with this spec's defaults. */
function fakeApi(overrides: Partial<Api> = {}): Api {
  return baseFakeApi({
    guides: vi
      .fn<Api['guides']>()
      .mockResolvedValue({ guides: [guide(1, 'Ana'), guide(2, 'Ben')] }),
    attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: [] }),
    ...overrides,
  })
}

function guide(id: number, name: string): ProfileAccount {
  return {
    id,
    name,
    image: null,
    created_at: '2026-09-01 10:00:00',
    age: 30,
    gender: null,
    headline: null,
    bio: null,
    price_label: null,
    price_note: null,
    includes: null,
    party: null,
    duration_hours: null,
    rating_average: null,
    review_count: 0,
    verified: false,
    top_rated: false,
    trips_completed: 0,
    level: 0,
    attributes: {},
    ranges: {},
  }
}

function payment(packsUnlocked: number): PaymentState {
  return {
    packsUnlocked,
    capacity: packsUnlocked * 5,
    picksUsed: 0,
    selectionsLeft: packsUnlocked * 5,
    pickedIds: [],
    passedIds: [],
  }
}

async function mountDiscover(api: Api, signedIn = false) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (signedIn) useAuthStore().setUser({ id: 9, email: 'me@example.com', role: 'guest' })
  const router = testRouter()
  router.push('/discover')
  await router.isReady()
  const wrapper = mount(DiscoverPage, {
    attachTo: document.body,
    global: { plugins: [pinia, router], provide: { [API_KEY]: api } },
  })
  await flushPromises()
  return { wrapper, router }
}

/** The two `ModalDialog`s, in template order: sign-up prompt, then payment. */
function dialogs(wrapper: Awaited<ReturnType<typeof mountDiscover>>['wrapper']) {
  const [auth, pay] = wrapper.findAll('dialog')
  return { auth: auth!, pay: pay! }
}

// jsdom's <dialog> support is partial; stand in for the two methods used,
// including the `close` event the browser fires.
const originalShowModal = HTMLDialogElement.prototype.showModal
const originalClose = HTMLDialogElement.prototype.close

beforeEach(() => {
  HTMLDialogElement.prototype.showModal = vi.fn<() => void>(function (this: HTMLDialogElement) {
    this.setAttribute('open', '')
  })
  HTMLDialogElement.prototype.close = vi.fn<() => void>(function (this: HTMLDialogElement) {
    if (!this.hasAttribute('open')) return
    this.removeAttribute('open')
    this.dispatchEvent(new Event('close'))
  })
})

afterEach(() => {
  HTMLDialogElement.prototype.showModal = originalShowModal
  HTMLDialogElement.prototype.close = originalClose
  document.body.innerHTML = ''
  localStorage.clear()
})

describe('DiscoverPage', () => {
  it('shows a signed-out visitor the first card, and Reject moves on without calling the API', async () => {
    const api = fakeApi()
    const { wrapper } = await mountDiscover(api)

    expect(wrapper.text()).toContain('Ana')
    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('Reject'))!
      .trigger('click')

    expect(wrapper.text()).toContain('Ben')
    expect(wrapper.text()).not.toContain('Ana')
    expect(api.decide).not.toHaveBeenCalled()
    expect(api.paymentState).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows the "Browse freely" intro once, on a first signed-out visit only', async () => {
    const first = await mountDiscover(fakeApi())
    expect(first.wrapper.text()).toContain('Browse freely')
    first.wrapper.unmount()

    // The next visit (a reload, another day) doesn't show it again.
    const second = await mountDiscover(fakeApi())
    expect(second.wrapper.text()).not.toContain('Browse freely')
    // …and nothing else stands in for it while signed out.
    expect(second.wrapper.text()).not.toContain('Get +')
    second.wrapper.unmount()
  })

  it('never shows the intro on a browser where someone has had an account', async () => {
    localStorage.clear()
    const { markBrowseIntroSeen } = await import('@/utils/browseIntro')
    markBrowseIntroSeen() // what App.vue does on any sign-in or registration
    const { wrapper } = await mountDiscover(fakeApi())
    expect(wrapper.text()).not.toContain('Browse freely')
    wrapper.unmount()
  })

  it('keeps a signed-out reject in localStorage, so a reload still skips that card', async () => {
    const first = await mountDiscover(fakeApi())
    await first.wrapper
      .findAll('button')
      .find((b) => b.text().includes('Reject'))!
      .trigger('click')
    first.wrapper.unmount()

    expect(readPendingRejects()).toEqual(['1'])

    // A fresh Pinia stands in for the reload.
    const second = await mountDiscover(fakeApi())
    expect(second.wrapper.text()).toContain('Ben')
    expect(second.wrapper.text()).not.toContain('Ana')
    second.wrapper.unmount()
  })

  it('asks a signed-out visitor to create an account or log in on Interested', async () => {
    const api = fakeApi()
    const { wrapper } = await mountDiscover(api)

    expect(dialogs(wrapper).auth.attributes('open')).toBeUndefined()
    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('Interested'))!
      .trigger('click')

    const { auth } = dialogs(wrapper)
    expect(auth.attributes('open')).toBeDefined()
    expect(auth.find('a[href="/register"]').exists()).toBe(true)
    expect(auth.find('a[href="/login"]').exists()).toBe(true)
    // The card stays put — nothing was picked.
    expect(wrapper.text()).toContain('Ana')
    expect(api.decide).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('opens the payment modal for a signed-in guest without a pack, and picks the liked card once paid', async () => {
    const api = fakeApi({
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(payment(0)),
      unlockPack: vi.fn<Api['unlockPack']>().mockResolvedValue(payment(1)),
      decide: vi.fn<Api['decide']>().mockResolvedValue({} as Awaited<ReturnType<Api['decide']>>),
    })
    const { wrapper } = await mountDiscover(api, true)

    expect(dialogs(wrapper).pay.attributes('open')).toBeDefined()

    // "Not now", then Interested on Ana brings it back.
    await dialogs(wrapper).pay.find('button[rel="prev"]').trigger('click')
    expect(dialogs(wrapper).pay.attributes('open')).toBeUndefined()
    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('Interested'))!
      .trigger('click')
    expect(dialogs(wrapper).pay.attributes('open')).toBeDefined()

    await dialogs(wrapper)
      .pay.findAll('button')
      .find((b) => b.text().includes('Pay £5'))!
      .trigger('click')
    await flushPromises()

    expect(api.unlockPack).toHaveBeenCalledOnce()
    expect(dialogs(wrapper).pay.attributes('open')).toBeUndefined()
    expect(useMatchesStore().picks).toEqual(['1'])
    expect(api.decide).toHaveBeenCalledWith({ targetId: 1, decision: 'interested' })
    wrapper.unmount()
  })

  it('goes straight to matching for a guest who already has a pack', async () => {
    const api = fakeApi({
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(payment(1)),
      decide: vi.fn<Api['decide']>().mockResolvedValue({} as Awaited<ReturnType<Api['decide']>>),
    })
    const { wrapper } = await mountDiscover(api, true)

    expect(dialogs(wrapper).pay.attributes('open')).toBeUndefined()
    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('Interested'))!
      .trigger('click')

    expect(useMatchesStore().picks).toEqual(['1'])
    expect(api.decide).toHaveBeenCalledWith({ targetId: 1, decision: 'interested' })
    wrapper.unmount()
  })
})
