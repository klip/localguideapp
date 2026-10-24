import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import DiscoverPage from '../pages/DiscoverPage.vue'
import {
  API_KEY,
  type Api,
  type MatchOverviewRow,
  type PaymentState,
  type ProfileAccount,
  type TripRow,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import PicksButton from '../components/PicksButton.vue'
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
    matchOverview: vi.fn<Api['matchOverview']>().mockResolvedValue({ selections: [] }),
    trips: vi.fn<Api['trips']>().mockResolvedValue({ trips: [] }),
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

  it('shows a signed-out visitor no "Browse freely" sign-up block — the header has Log in', async () => {
    const { wrapper } = await mountDiscover(fakeApi())
    expect(wrapper.text()).not.toContain('Browse freely')
    expect(wrapper.text()).not.toContain('create an account to pick your guides')
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

  it('keeps the picks counter out of the page (it’s in the header), and opens the add-picks dialog when asked', async () => {
    const api = fakeApi({
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(payment(1)),
    })
    const { wrapper } = await mountDiscover(api, true)

    // No counter on the page itself: not in the bar, the deck's header or the rail.
    expect(wrapper.find('[data-test="picks-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="picks-left"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rail-picks-left"]').exists()).toBe(false)

    expect(dialogs(wrapper).pay.attributes('open')).toBeUndefined()
    useMatchesStore().addPicksOpen = true
    await flushPromises()
    expect(dialogs(wrapper).pay.attributes('open')).toBeDefined()
    wrapper.unmount()
  })

  it('shows the picks counter in the header on Discover: “5/5 left”, opening the dialog', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().setUser({ id: 9, email: 'me@example.com', role: 'guest' })
    useMatchesStore().applyPaymentState(payment(1))
    const router = testRouter()
    await router.push('/discover')
    const button = mount(PicksButton, { global: { plugins: [pinia, router] } })
    expect(button.text()).toBe('5/5 left')
    expect(button.classes()).toEqual(expect.arrayContaining(['secondary', 'outline']))
    await button.trigger('click')
    expect(useMatchesStore().addPicksOpen).toBe(true)

    // Every page shows it now; off Discover it leads to Payments instead of the dialog.
    useMatchesStore().addPicksOpen = false
    await router.push('/matches')
    await flushPromises()
    expect(button.find('[data-test="picks-button"]').exists()).toBe(true)
    const push = vi.spyOn(router, 'push').mockResolvedValue(undefined)
    await button.trigger('click')
    expect(useMatchesStore().addPicksOpen).toBe(false)
    expect(push).toHaveBeenCalledWith('/payments')
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

  it('lists active matches and upcoming trips in the rail, each name linking to the guide', async () => {
    // Only the fields the rail reads; the rest don't matter here.
    const match = (fields: Partial<MatchOverviewRow>) =>
      ({
        active: 1,
        booking_status: null,
        location: null,
        meeting_at: null,
        guide_place: null,
        ...fields,
      }) as MatchOverviewRow
    const tripRow = (fields: Partial<TripRow>) =>
      ({ location: null, guide_place: null, meeting_at: null, ...fields }) as TripRow
    const api = fakeApi({
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(payment(1)),
      matchOverview: vi.fn<Api['matchOverview']>().mockResolvedValue({
        selections: [
          match({ id: 1, counterpart_id: 7, name: 'Igor', guide_place: 'Lisbon, Portugal' }),
          match({
            id: 2,
            counterpart_id: 8,
            name: 'Zoe',
            booking_status: 'pending',
            location: 'Café Nicola',
            meeting_at: '2030-01-02 10:00:00',
          }),
          // Waiting on their answer — not a match yet.
          match({ id: 3, counterpart_id: 9, name: 'Max', active: 0 }),
          // Confirmed: it's a trip now, listed under Upcoming instead.
          match({ id: 4, counterpart_id: 10, name: 'Ana', booking_status: 'confirmed' }),
        ],
      }),
      trips: vi.fn<Api['trips']>().mockResolvedValue({
        trips: [
          tripRow({
            id: 21,
            counterpart_id: 10,
            name: 'Ana',
            phase: 'scheduled',
            guide_place: 'Porto',
          }),
          tripRow({
            id: 22,
            counterpart_id: 12,
            name: 'Lea',
            phase: 'current',
            location: 'Old town',
            meeting_at: '2026-10-21 09:00:00',
          }),
          tripRow({ id: 20, counterpart_id: 11, name: 'Old', phase: 'past' }),
        ],
      }),
    })
    const { wrapper } = await mountDiscover(api, true)
    const rail = wrapper.find('.matches-rail')

    // Tabs show an icon and a count; the name is for tooltips and screen readers.
    const matchedTab = rail.find('[data-test="rail-tab-matched"]')
    expect(matchedTab.attributes('aria-selected')).toBe('true')
    expect(matchedTab.text()).toBe('2')
    expect(matchedTab.attributes('aria-label')).toBe('Matched (2)')
    expect(matchedTab.find('svg').exists()).toBe(true)
    expect(rail.find('[data-test="rail-tab-upcoming"]').text()).toBe('2')
    let items = rail.findAll('.rail-list li')
    expect(items.map((li) => li.find('a').text())).toEqual(['Igor', 'Zoe'])
    expect(items[0]!.find('a').attributes('href')).toBe('/guides/7')
    expect(items[0]!.text()).toContain('Lisbon, Portugal · No date yet')
    expect(items[1]!.text()).toContain('Café Nicola · ')
    expect(rail.find('[data-test="rail-link-matched"]').attributes('href')).toBe('/matches')

    await rail.find('[data-test="rail-tab-upcoming"]').trigger('click')
    items = rail.findAll('.rail-list li')
    // The trip under way first, marked "In progress" instead of a date.
    expect(items.map((li) => li.find('a').text())).toEqual(['Lea', 'Ana'])
    expect(items[0]!.find('[data-test="rail-in-progress"]').text()).toBe('In progress')
    expect(items[0]!.find('.rail-caption').text()).toBe('In progressOld town')
    expect(items[1]!.find('a').attributes('href')).toBe('/guides/10')
    expect(items[1]!.find('[data-test="rail-in-progress"]').exists()).toBe(false)
    expect(items[1]!.text()).toContain('Porto · No date yet')
    expect(rail.find('[data-test="rail-link-upcoming"]').attributes('href')).toBe('/trips')
    wrapper.unmount()
  })
})
