import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import TripsPage from '../pages/TripsPage.vue'
import { API_KEY, type Api, type PaymentState, type TripRow } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useShortlistStore } from '@/stores/shortlist'

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/trips', name: 'trips', component: TripsPage },
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
    trips: vi.fn<Api['trips']>().mockResolvedValue({ trips: [] }),
    publicProfile: vi.fn<Api['publicProfile']>(),
    reviews: vi.fn<Api['reviews']>(),
    submitReview: vi.fn<Api['submitReview']>(),
    deleteReview: vi.fn<Api['deleteReview']>(),
    ...overrides,
  }
}

/** A confirmed, paid, upcoming trip with a £50 guide; layered on per test. */
function trip(overrides: Partial<TripRow> = {}): TripRow {
  return {
    id: 11,
    selection_id: 1,
    counterpart_id: 7,
    phase: 'scheduled',
    is_latest: true,
    name: 'Igor',
    image: null,
    email: 'igor@example.com',
    phone: '+350 56002731',
    active: 1,
    fee_amount: 50,
    booking_status: 'confirmed',
    payment_status: 'paid',
    booking_amount: 50,
    meeting_at: '2030-01-02 10:00:00',
    booking_duration_hours: 3,
    location: 'Casemates Square',
    trip_status: 'pending',
    refund_amount: null,
    refund_if_cancelled: null,
    refund_if_guest_cancels: 47.5,
    accepted_at: '2026-09-20 10:00:00',
    cancel_reason: null,
    cancelled_at: null,
    cancel_requested_at: null,
    cancel_request_reason: null,
    finish_requested_by: null,
    finished_at: null,
    matched_at: '2026-09-01 12:00:00',
    auto_finish: true,
    ...overrides,
  }
}

const current = trip({
  id: 12,
  counterpart_id: 8,
  name: 'Ana',
  phase: 'current',
  trip_status: 'started',
})
const finished = trip({
  id: 13,
  counterpart_id: 9,
  name: 'Ben',
  phase: 'past',
  trip_status: 'finished',
})
const cancelled = trip({
  id: 14,
  counterpart_id: 10,
  name: 'Cy',
  phase: 'past',
  active: 0,
  email: null,
  phone: null,
  booking_status: 'cancelled',
  payment_status: 'refunded',
  refund_amount: 52.5,
  cancel_reason: 'Ill',
})

function paymentState(): PaymentState {
  return {
    packsUnlocked: 1,
    capacity: 5,
    picksUsed: 1,
    selectionsLeft: 4,
    pickedIds: [7],
    passedIds: [],
  }
}

async function mountPage(api: Api, role: 'guest' | 'guide' | null) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (role) useAuthStore().setUser({ id: 1, email: 'me@example.com', role, sessionHash: 'hash' })

  const router = testRouter()
  router.push('/trips')
  await router.isReady()

  const wrapper = mount(TripsPage, {
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

afterEach(() => {
  localStorage.clear()
})

describe('TripsPage', () => {
  it('sorts trips into scheduled, in progress and past, opening on the one under way', async () => {
    const api = fakeApi({
      trips: vi
        .fn<Api['trips']>()
        .mockResolvedValue({ trips: [trip(), current, finished, cancelled] }),
    })
    const wrapper = await mountPage(api, 'guide')

    expect(api.paymentState).not.toHaveBeenCalled() // guides never pay
    expect(tab(wrapper, 'Scheduled').find('.tab-count').text()).toBe('1')
    expect(tab(wrapper, 'In progress').find('.tab-count').text()).toBe('1')
    expect(tab(wrapper, 'Past').find('.tab-count').text()).toBe('2')
    expect(tab(wrapper, 'In progress').attributes('aria-pressed')).toBe('true')
    expect(wrapper.text()).toContain('Ana')

    await tab(wrapper, 'Past').trigger('click')
    expect(wrapper.text()).toContain('Ben')
    expect(wrapper.text()).toContain('Finished')
    expect(wrapper.text()).toContain('Cy')
    expect(wrapper.text()).toContain('Cancelled')
  })

  it('shows the name as a profile link, the date, duration, location and price on each card', async () => {
    const api = fakeApi({
      trips: vi.fn<Api['trips']>().mockResolvedValue({ trips: [trip()] }),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState()),
    })
    const wrapper = await mountPage(api, 'guest')
    const card = wrapper.find('.trip-card')

    expect(card.find('a.who-name').attributes('href')).toBe('/guides/7')
    expect(card.find('a.who-name').text()).toBe('Igor')
    expect(card.text()).toContain('Scheduled')
    expect(card.text()).toContain('3 h')
    expect(card.text()).toContain('Casemates Square')
    expect(card.text()).toContain('£50.00 paid')
    expect(card.find('a[href="mailto:igor@example.com"]').exists()).toBe(true)
    // Actions on the trip come from BookingPanel, without its duplicate summary.
    expect(card.find('.booking').text()).toContain('Request cancellation')
    expect(card.find('.booking').text()).not.toContain('Paid £50.00')
  })

  it('links a visitor’s card to their visitor profile, for a guide', async () => {
    const api = fakeApi({ trips: vi.fn<Api['trips']>().mockResolvedValue({ trips: [trip()] }) })
    const wrapper = await mountPage(api, 'guide')

    expect(wrapper.find('a.who-name').attributes('href')).toBe('/visitors/7')
    expect(wrapper.text()).toContain('£50.00 fee')
  })

  it('fills in placeholders for a free trip with no date or place yet', async () => {
    const free = trip({
      payment_status: 'none',
      booking_amount: 0,
      fee_amount: 0,
      meeting_at: null,
      booking_duration_hours: null,
      location: null,
      auto_finish: false,
    })
    const api = fakeApi({
      trips: vi.fn<Api['trips']>().mockResolvedValue({ trips: [free] }),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState()),
    })
    const wrapper = await mountPage(api, 'guest')

    expect(wrapper.text()).toContain('Date to be arranged')
    expect(wrapper.text()).toContain('Length not set')
    expect(wrapper.text()).toContain('Meeting place not set yet')
    expect(wrapper.text()).toContain('Free')
  })

  it('shows what a cancelled trip refunded, with no actions or contact details', async () => {
    const api = fakeApi({
      trips: vi.fn<Api['trips']>().mockResolvedValue({ trips: [cancelled] }),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState()),
    })
    const wrapper = await mountPage(api, 'guest')

    expect(tab(wrapper, 'Past').attributes('aria-pressed')).toBe('true')
    expect(wrapper.text()).toContain('£50.00 paid · £52.50 refunded')
    expect(wrapper.text()).toContain('Ill')
    expect(wrapper.find('.booking').exists()).toBe(false)
    expect(wrapper.find('a[href^="mailto:"]').exists()).toBe(false)
  })

  it('offers a review on a finished trip, and "Book again" only on the pair’s latest', async () => {
    const older = trip({ ...finished, id: 9, is_latest: false, name: 'Ben' })
    const api = fakeApi({
      trips: vi.fn<Api['trips']>().mockResolvedValue({ trips: [finished, older] }),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState()),
    })
    const wrapper = await mountPage(api, 'guest')
    const cards = wrapper.findAll('.trip-card')

    expect(cards).toHaveLength(2)
    expect(cards[0]!.text()).toContain('Leave Ben a review')
    expect(cards[0]!.text()).toContain('Book again')
    expect(cards[1]!.text()).not.toContain('Book again')
  })

  it('reloads after a booking step, and re-syncs a guest’s picks', async () => {
    const trips = vi
      .fn<Api['trips']>()
      .mockResolvedValueOnce({ trips: [trip()] })
      .mockResolvedValueOnce({ trips: [{ ...trip(), cancel_requested_at: '2026-10-01 10:00:00' }] })
    const paymentStateFn = vi.fn<Api['paymentState']>().mockResolvedValue(paymentState())
    const bookingAction = vi
      .fn<Api['bookingAction']>()
      .mockResolvedValue({} as Awaited<ReturnType<Api['bookingAction']>>)
    const api = fakeApi({ trips, paymentState: paymentStateFn, bookingAction })
    const wrapper = await mountPage(api, 'guest')

    expect(useShortlistStore().picks).toEqual(['7'])

    const request = wrapper
      .findAll('.booking button')
      .find((b) => b.text().includes('Request cancellation'))
    await request!.trigger('click')
    await wrapper.find('.booking textarea').setValue('Flight moved')
    await wrapper.find('.booking form').trigger('submit')
    await flushPromises()

    expect(bookingAction).toHaveBeenCalledWith({
      targetId: 7,
      op: 'requestCancellation',
      reason: 'Flight moved',
    })
    expect(trips).toHaveBeenCalledTimes(2)
    expect(paymentStateFn).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Keep my booking')
  })

  it('points to Matches when nothing is scheduled', async () => {
    const wrapper = await mountPage(fakeApi(), 'guest')

    expect(wrapper.text()).toContain('No upcoming trips')
    expect(wrapper.find('a[href="/matches"]').exists()).toBe(true)
  })

  it('asks a signed-out visitor to log in without calling the API', async () => {
    const api = fakeApi()
    const wrapper = await mountPage(api, null)

    expect(api.trips).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Log in to see your trips')
  })
})
