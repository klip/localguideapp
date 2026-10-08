import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import MatchesPage from '../pages/MatchesPage.vue'
import {
  API_KEY,
  type Api,
  type MatchOverviewRow,
  type PaymentState,
  type SelectionRow,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { useShortlistStore } from '@/stores/shortlist'

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/matches', name: 'matches', component: MatchesPage },
      { path: '/:pathMatch(.*)*', name: 'other', component: { template: '<div />' } },
    ],
  })
}

/** A stub `Api` — every method is a no-op `vi.fn()` unless overridden. */
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

function row(overrides: Partial<MatchOverviewRow>): MatchOverviewRow {
  return {
    id: 1,
    counterpart_id: 100,
    my_decision: null,
    their_decision: null,
    active: 0,
    updated_at: '2026-09-10 12:00:00',
    name: 'Someone',
    image: null,
    email: null,
    phone: null,
    age: null,
    headline: null,
    price_label: null,
    party: null,
    duration_hours: null,
    matched_at: null,
    fee_amount: 0,
    booking_id: null,
    booking_status: null,
    payment_status: null,
    booking_amount: null,
    meeting_at: null,
    refund_amount: null,
    trip_status: null,
    refund_if_cancelled: null,
    refund_if_guest_cancels: null,
    booking_duration_hours: null,
    accepted_at: null,
    cancel_requested_at: null,
    cancel_request_reason: null,
    finish_requested_by: null,
    finished_at: null,
    auto_finish: false,
    location: null,
    ...overrides,
  }
}

/** The booking fields of a paid, confirmed, not-yet-happened booking. */
const paidBooking: Partial<MatchOverviewRow> = {
  fee_amount: 50,
  booking_id: 11,
  booking_status: 'confirmed',
  payment_status: 'paid',
  booking_amount: 50,
  meeting_at: '2030-01-02 10:00:00',
  trip_status: 'pending',
}

// Contact details only ever arrive with a booking (see Selections::getOverview()).
const matched = row({
  id: 1,
  counterpart_id: 7,
  name: 'Igor',
  my_decision: 'interested',
  their_decision: 'interested',
  active: 1,
  email: 'igor@example.com',
  phone: '+350 56002731',
  ...paidBooking,
  refund_if_cancelled: 50,
})
/** Matched with a guide who charges £50, nobody has paid: contact still hidden. */
const unpaid = row({
  id: 5,
  counterpart_id: 18,
  name: 'Gil',
  my_decision: 'interested',
  their_decision: 'interested',
  active: 1,
  fee_amount: 50,
})
const incoming = row({ id: 2, counterpart_id: 8, name: 'Ana', their_decision: 'interested' })
const awaiting = row({ id: 3, counterpart_id: 9, name: 'Ben', my_decision: 'interested' })
const passed = row({ id: 4, counterpart_id: 10, name: 'Cy', my_decision: 'pass' })

function selectionRow(overrides: Partial<SelectionRow> = {}): SelectionRow {
  return {
    id: 2,
    guest_id: 1,
    guide_id: 8,
    guest_decision: null,
    guide_decision: null,
    active: 0,
    created_at: '',
    updated_at: '',
    ...overrides,
  }
}

function paymentState(selectionsLeft: number): PaymentState {
  return {
    packsUnlocked: 1,
    capacity: 5,
    picksUsed: 5 - selectionsLeft,
    selectionsLeft,
    pickedIds: [],
    passedIds: [],
  }
}

async function mountPage(api: Api, role: 'guest' | 'guide' | null) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (role) useAuthStore().setUser({ id: 1, email: 'me@example.com', role, sessionHash: 'hash' })

  const router = testRouter()
  router.push('/matches')
  await router.isReady()

  const wrapper = mount(MatchesPage, {
    global: { plugins: [pinia, router], provide: { [API_KEY]: api } },
  })
  await flushPromises()
  return wrapper
}

function tab(wrapper: Awaited<ReturnType<typeof mountPage>>, label: string) {
  const found = wrapper.findAll('.tab').find((button) => button.text().startsWith(label))
  if (!found) throw new Error(`No tab labelled ${label}`)
  return found
}

function button(wrapper: Awaited<ReturnType<typeof mountPage>>, label: string) {
  const found = wrapper
    .findAll('.actions button')
    .find((candidate) => candidate.text().includes(label))
  if (!found) throw new Error(`No button labelled ${label}`)
  return found
}

afterEach(() => {
  localStorage.clear()
})

describe('MatchesPage', () => {
  it('groups every pair into tabs and leaves confirmed trips to My trips', async () => {
    const api = fakeApi({
      matchOverview: vi.fn<Api['matchOverview']>().mockResolvedValue({
        selections: [matched, unpaid, incoming, awaiting, passed],
      }),
    })
    const wrapper = await mountPage(api, 'guide')

    expect(api.paymentState).not.toHaveBeenCalled() // guides never pay
    // Igor has a confirmed booking, so he's a trip — only Gil is left under Matched.
    expect(tab(wrapper, 'Matched').find('.tab-count').text()).toBe('1')
    expect(tab(wrapper, 'Likes you').find('.tab-count').text()).toBe('1')
    expect(tab(wrapper, 'Awaiting reply').find('.tab-count').text()).toBe('1')
    expect(tab(wrapper, 'Passed').find('.tab-count').text()).toBe('1')

    // Opens on Matched when there's a match.
    expect(tab(wrapper, 'Matched').attributes('aria-pressed')).toBe('true')
    expect(wrapper.text()).toContain('Gil')
    expect(wrapper.text()).not.toContain('Igor')
    expect(wrapper.find('a[href^="mailto:"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/trips"]').text()).toBe('My trips')
    expect(wrapper.text()).toContain('1 confirmed trip')

    await tab(wrapper, 'Likes you').trigger('click')
    expect(wrapper.text()).toContain('Ana')
  })

  it('opens on "Likes you" when there are no matches yet but someone is waiting on an answer', async () => {
    const api = fakeApi({
      matchOverview: vi
        .fn<Api['matchOverview']>()
        .mockResolvedValue({ selections: [incoming, awaiting] }),
    })
    const wrapper = await mountPage(api, 'guide')

    expect(tab(wrapper, 'Likes you').attributes('aria-pressed')).toBe('true')
  })

  it('lets a guest accept an incoming like, then reloads and confirms the match', async () => {
    const matchOverview = vi
      .fn<Api['matchOverview']>()
      .mockResolvedValueOnce({ selections: [incoming] })
      .mockResolvedValueOnce({
        selections: [
          { ...incoming, my_decision: 'interested', active: 1, email: 'ana@example.com' },
        ],
      })
    const decide = vi
      .fn<Api['decide']>()
      .mockResolvedValue(
        selectionRow({ guest_decision: 'interested', guide_decision: 'interested', active: 1 }),
      )
    const api = fakeApi({
      matchOverview,
      decide,
      // The reload after accepting must see the pick the server has just
      // recorded — the real endpoint returns the accepted guide in
      // `pickedIds`, and `applyPaymentState()` replaces the local arrays with
      // that view. A static empty fixture would "un-pick" it.
      paymentState: vi
        .fn<Api['paymentState']>()
        .mockResolvedValueOnce(paymentState(3))
        .mockResolvedValueOnce({ ...paymentState(2), pickedIds: [8] }),
    })
    const wrapper = await mountPage(api, 'guest')

    expect(wrapper.text()).toContain('3 selections left')
    await button(wrapper, 'Accept').trigger('click')
    await flushPromises()

    expect(decide).toHaveBeenCalledWith({ targetId: 8, decision: 'interested' })
    expect(matchOverview).toHaveBeenCalledTimes(2)
    expect(useShortlistStore().isPicked('8')).toBe(true)
    expect(
      useMessagesStore()
        .items.map((item) => item.text)
        .join(' '),
    ).toContain('now matched with Ana')
  })

  it('offers an unlock link instead of Accept when a guest is out of selections', async () => {
    const api = fakeApi({
      matchOverview: vi.fn<Api['matchOverview']>().mockResolvedValue({ selections: [incoming] }),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState(0)),
    })
    const wrapper = await mountPage(api, 'guest')

    expect(
      wrapper.findAll('.actions button').some((candidate) => candidate.text().includes('Accept')),
    ).toBe(false)
    expect(wrapper.find('.actions a[href="/unlock"]').text()).toContain('Unlock to accept')
  })

  it('withdraws interest from someone who has not answered yet', async () => {
    const revokeSelection = vi.fn<Api['revokeSelection']>().mockResolvedValue(selectionRow())
    const api = fakeApi({
      matchOverview: vi
        .fn<Api['matchOverview']>()
        .mockResolvedValueOnce({ selections: [awaiting] })
        .mockResolvedValueOnce({ selections: [] }),
      revokeSelection,
    })
    const wrapper = await mountPage(api, 'guide')

    await tab(wrapper, 'Awaiting reply').trigger('click')
    await button(wrapper, 'Withdraw').trigger('click')
    await flushPromises()

    expect(revokeSelection).toHaveBeenCalledWith({ targetId: 9 })
    expect(tab(wrapper, 'Awaiting reply').find('.tab-count').text()).toBe('0')
  })

  it('moves a pair with a confirmed booking — upcoming or finished — off the page', async () => {
    const finished = row({
      ...matched,
      id: 6,
      counterpart_id: 19,
      name: 'Fay',
      trip_status: 'finished',
    })
    const api = fakeApi({
      matchOverview: vi
        .fn<Api['matchOverview']>()
        .mockResolvedValue({ selections: [matched, finished] }),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState(3)),
    })
    const wrapper = await mountPage(api, 'guest')

    expect(tab(wrapper, 'Matched').find('.tab-count').text()).toBe('0')
    expect(wrapper.find('.match-list').exists()).toBe(false)
    expect(wrapper.text()).toContain('2 confirmed trips')
    expect(wrapper.find('a[href="/trips"]').exists()).toBe(true)
  })

  it('lets a matched pair with nothing booked unmatch', async () => {
    const api = fakeApi({
      matchOverview: vi.fn<Api['matchOverview']>().mockResolvedValue({ selections: [unpaid] }),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState(3)),
    })
    const wrapper = await mountPage(api, 'guest')

    expect(button(wrapper, 'Unmatch').exists()).toBe(true)
    // Paying is BookingPanel's job (see BookingPanel.spec.ts).
    expect(wrapper.find('.booking').text()).toContain('Pay £50.00')
  })

  it('keeps a payment waiting on the guide here, and hands it to My trips once they accept', async () => {
    const pending = {
      ...unpaid,
      booking_id: 12,
      booking_status: 'pending' as const,
      payment_status: 'paid' as const,
      booking_amount: 50,
      meeting_at: '2030-01-02 10:00:00',
      trip_status: 'pending' as const,
    }
    const matchOverview = vi
      .fn<Api['matchOverview']>()
      .mockResolvedValueOnce({ selections: [pending] })
      .mockResolvedValueOnce({
        selections: [{ ...pending, booking_status: 'confirmed', email: 'gil@example.com' }],
      })
    const bookingAction = vi
      .fn<Api['bookingAction']>()
      .mockResolvedValue({} as Awaited<ReturnType<Api['bookingAction']>>)
    const api = fakeApi({ matchOverview, bookingAction })
    const wrapper = await mountPage(api, 'guide')

    expect(wrapper.find('a[href^="mailto:"]').exists()).toBe(false)
    expect(
      wrapper.findAll('.actions button').some((candidate) => candidate.text().includes('Unmatch')),
    ).toBe(false)

    const accept = wrapper
      .findAll('.booking button')
      .find((candidate) => candidate.text().includes('Accept'))
    await accept!.trigger('click')
    await flushPromises()

    expect(bookingAction).toHaveBeenCalledWith({ targetId: 18, op: 'accept' })
    expect(matchOverview).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).not.toContain('Gil')
    expect(wrapper.find('a[href="/trips"]').exists()).toBe(true)
  })

  it('links each name to that person’s public profile', async () => {
    const api = fakeApi({
      matchOverview: vi.fn<Api['matchOverview']>().mockResolvedValue({ selections: [unpaid] }),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState(3)),
    })
    const wrapper = await mountPage(api, 'guest')

    expect(wrapper.find('a.who-name').attributes('href')).toBe('/guides/18')
  })

  it('asks a signed-out visitor to log in without calling the API', async () => {
    const api = fakeApi()
    const wrapper = await mountPage(api, null)

    expect(api.matchOverview).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Log in to see your matches')
  })
})
