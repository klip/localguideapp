import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BookingPanel from '../components/BookingPanel.vue'
import { API_KEY, type Api, type Booking, type MatchOverviewRow } from '@/plugins/api'
import { useMessagesStore } from '@/stores/messages'

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
    payGuideFee: vi.fn<Api['payGuideFee']>().mockResolvedValue(booking()),
    bookingAction: vi.fn<Api['bookingAction']>().mockResolvedValue(booking()),
    cancelBooking: vi.fn<Api['cancelBooking']>().mockResolvedValue(booking()),
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

function booking(overrides: Partial<Booking> = {}): Booking {
  return {
    id: 11,
    selection_id: 1,
    guest_id: 46,
    guide_id: 7,
    amount: 50,
    meeting_at: '2030-01-02 10:00:00',
    duration_hours: null,
    location: null,
    status: 'pending',
    payment_status: 'paid',
    accepted_at: null,
    refund_amount: null,
    cancel_reason: null,
    cancelled_at: null,
    cancel_requested_at: null,
    cancel_request_reason: null,
    trip_status: 'pending',
    finish_requested_by: null,
    finish_requested_at: null,
    finished_at: null,
    created_at: '',
    updated_at: '',
    ...overrides,
  }
}

/** A matched pair with a £50 guide; booking fields layered on per test. */
function row(overrides: Partial<MatchOverviewRow> = {}): MatchOverviewRow {
  return {
    id: 1,
    counterpart_id: 7,
    my_decision: 'interested',
    their_decision: 'interested',
    active: 1,
    updated_at: '2026-09-10 12:00:00',
    name: 'Igor',
    image: null,
    email: null,
    phone: null,
    age: null,
    headline: null,
    price_label: null,
    party: null,
    duration_hours: null,
    matched_at: '2026-09-01 12:00:00',
    fee_amount: 50,
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

const pendingBooking: Partial<MatchOverviewRow> = {
  booking_id: 11,
  booking_status: 'pending',
  payment_status: 'paid',
  booking_amount: 50,
  meeting_at: '2030-01-02 10:00:00',
  trip_status: 'pending',
}

const confirmedBooking: Partial<MatchOverviewRow> = {
  ...pendingBooking,
  booking_status: 'confirmed',
  accepted_at: '2026-09-20 10:00:00',
  refund_if_cancelled: 50,
  refund_if_guest_cancels: 47.5,
}

function mountPanel(api: Api, props: Partial<MatchOverviewRow>, isGuest: boolean) {
  setActivePinia(createPinia())
  return mount(BookingPanel, {
    props: { row: row(props), isGuest, name: 'Igor' },
    global: { provide: { [API_KEY]: api }, stubs: { RouterLink: RouterLinkStub } },
  })
}

function button(wrapper: ReturnType<typeof mountPanel>, label: string) {
  const found = wrapper.findAll('button').find((candidate) => candidate.text().includes(label))
  if (!found) throw new Error(`No button labelled ${label}`)
  return found
}

function hasButton(wrapper: ReturnType<typeof mountPanel>, label: string) {
  return wrapper.findAll('button').some((candidate) => candidate.text().includes(label))
}

function toasts() {
  return useMessagesStore()
    .items.map((item) => item.text)
    .join(' | ')
}

afterEach(() => {
  localStorage.clear()
})

describe('BookingPanel — paying', () => {
  it('lets a guest pay with a meeting time and trip length, then waits on the guide', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, {}, true)

    await button(wrapper, 'Pay £50.00').trigger('click')
    await wrapper.find('input[type="datetime-local"]').setValue('2030-01-02T10:00')
    await wrapper.find('input[type="number"]').setValue('3')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.payGuideFee).toHaveBeenCalledWith({
      targetId: 7,
      meetingAt: new Date('2030-01-02T10:00').toISOString(),
      durationHours: 3,
    })
    expect(toasts()).toContain('Payment of £50.00 received — Igor will now confirm the date.')
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('does not pay without a meeting time', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, {}, true)

    await button(wrapper, 'Pay £50.00').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.payGuideFee).not.toHaveBeenCalled()
    expect(toasts()).toContain('Choose when you’d like to meet.')
  })

  it('offers no payment for a free guide', () => {
    const wrapper = mountPanel(
      fakeApi(),
      {
        fee_amount: 0,
        booking_status: 'confirmed',
        payment_status: 'none',
        booking_amount: 0,
        trip_status: 'pending',
      },
      true,
    )

    expect(hasButton(wrapper, 'Pay')).toBe(false)
    expect(wrapper.text()).toContain('Free guide')
  })
})

describe('BookingPanel — guide acceptance', () => {
  it('lets the guest withdraw a payment the guide has not accepted yet', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, pendingBooking, true)

    expect(wrapper.text()).toContain('Waiting for Igor to confirm')
    await button(wrapper, 'Withdraw payment').trigger('click')
    await flushPromises()

    expect(api.bookingAction).toHaveBeenCalledWith({ targetId: 7, op: 'withdraw' })
    expect(toasts()).toContain('refunded £50.00')
  })

  it('lets the guide accept a pending booking', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, pendingBooking, false)

    await button(wrapper, 'Accept').trigger('click')
    await flushPromises()

    expect(api.bookingAction).toHaveBeenCalledWith({ targetId: 7, op: 'accept' })
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('lets the guide decline with a reason, refunding in full', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, pendingBooking, false)

    await button(wrapper, 'Decline').trigger('click')
    expect(wrapper.find('form').text()).toContain('refunded £50.00 in full')
    await wrapper.find('textarea').setValue(' Away that week ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.bookingAction).toHaveBeenCalledWith({
      targetId: 7,
      op: 'decline',
      reason: 'Away that week',
    })
  })
})

describe('BookingPanel — cancelling', () => {
  it('lets a guest request a cancellation, which needs a reason and states the 95% refund', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, confirmedBooking, true)

    await button(wrapper, 'Request cancellation').trigger('click')
    expect(wrapper.find('form').text()).toContain('£47.50')

    await wrapper.find('form').trigger('submit')
    expect(api.bookingAction).not.toHaveBeenCalled()
    expect(toasts()).toContain('Tell Igor why you need to cancel.')

    await wrapper.find('textarea').setValue('Flight cancelled')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.bookingAction).toHaveBeenCalledWith({
      targetId: 7,
      op: 'requestCancellation',
      reason: 'Flight cancelled',
    })
  })

  it('lets a guest keep a booking they asked to cancel', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(
      api,
      {
        ...confirmedBooking,
        cancel_requested_at: '2026-09-29 10:00:00',
        cancel_request_reason: 'Ill',
      },
      true,
    )

    expect(wrapper.text()).toContain('you’re refunded £47.50')
    await button(wrapper, 'Keep my booking').trigger('click')
    await flushPromises()

    expect(api.bookingAction).toHaveBeenCalledWith({ targetId: 7, op: 'withdrawCancellation' })
  })

  it('shows the guide the guest’s reason and lets them approve or refuse', async () => {
    const api = fakeApi()
    const props = {
      ...confirmedBooking,
      cancel_requested_at: '2026-09-29 10:00:00',
      cancel_request_reason: 'Flight cancelled',
    }
    const wrapper = mountPanel(api, props, false)

    expect(wrapper.text()).toContain('Igor asked to cancel: “Flight cancelled”')
    expect(hasButton(wrapper, 'Cancel booking')).toBe(false)

    await button(wrapper, 'Approve cancellation').trigger('click')
    await flushPromises()
    expect(api.bookingAction).toHaveBeenCalledWith({ targetId: 7, op: 'approveCancellation' })
    expect(toasts()).toContain('refunded £47.50')

    await button(wrapper, 'Refuse').trigger('click')
    await flushPromises()
    expect(api.bookingAction).toHaveBeenLastCalledWith({ targetId: 7, op: 'refuseCancellation' })
  })

  it('lets a guide cancel, showing the late-cancellation refund first', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, { ...confirmedBooking, refund_if_cancelled: 52.5 }, false)

    await button(wrapper, 'Cancel booking').trigger('click')
    const form = wrapper.find('form')
    expect(form.text()).toContain('Igor will be refunded £52.50')
    expect(form.text()).toContain('includes 5% for cancelling at short notice')

    await form.find('textarea').setValue('Ill')
    await form.trigger('submit')
    await flushPromises()

    expect(api.cancelBooking).toHaveBeenCalledWith({ targetId: 7, reason: 'Ill' })
    expect(toasts()).toContain('Igor has been refunded £52.50')
  })
})

describe('BookingPanel — finishing the trip', () => {
  it('says when a trip with a known length finishes by itself, and offers no finish button', () => {
    const wrapper = mountPanel(
      fakeApi(),
      { ...confirmedBooking, booking_duration_hours: 3, auto_finish: true },
      true,
    )

    expect(wrapper.text()).toContain('The trip finishes by itself')
    expect(hasButton(wrapper, 'Mark trip finished')).toBe(false)
  })

  it('lets either side ask to finish a trip without a known length', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, confirmedBooking, false)

    await button(wrapper, 'Mark trip finished').trigger('click')
    await flushPromises()

    expect(api.bookingAction).toHaveBeenCalledWith({ targetId: 7, op: 'requestFinish' })
  })

  it('shows a waiting note and Undo to the side that asked', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, { ...confirmedBooking, finish_requested_by: 'me' }, true)

    expect(wrapper.text()).toContain('Waiting for Igor to confirm the trip is finished.')
    await button(wrapper, 'Undo').trigger('click')
    await flushPromises()
    expect(api.bookingAction).toHaveBeenCalledWith({ targetId: 7, op: 'dismissFinish' })
  })

  it('asks the other side to confirm or say "not yet"', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, { ...confirmedBooking, finish_requested_by: 'them' }, true)

    expect(wrapper.text()).toContain('Igor says the trip is finished.')
    await button(wrapper, 'Confirm finished').trigger('click')
    await flushPromises()
    expect(api.bookingAction).toHaveBeenCalledWith({ targetId: 7, op: 'confirmFinish' })

    await button(wrapper, 'Not yet').trigger('click')
    await flushPromises()
    expect(api.bookingAction).toHaveBeenLastCalledWith({ targetId: 7, op: 'dismissFinish' })
  })

  it('links to a review once the trip is finished, and lets the guest book again', () => {
    const wrapper = mountPanel(fakeApi(), { ...confirmedBooking, trip_status: 'finished' }, true)

    expect(wrapper.text()).toContain('Trip finished')
    expect(wrapper.findComponent(RouterLinkStub).props('to')).toBe('/guides/7')
    expect(hasButton(wrapper, 'Book again £50.00')).toBe(true)
  })
})

describe('BookingPanel — meeting place', () => {
  it('sends the meeting place along with the payment', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, {}, true)

    await button(wrapper, 'Pay £50.00').trigger('click')
    await wrapper.find('input[type="datetime-local"]').setValue('2030-01-02T10:00')
    await wrapper.find('input[type="text"]').setValue('  Casemates Square  ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.payGuideFee).toHaveBeenCalledWith({
      targetId: 7,
      meetingAt: new Date('2030-01-02T10:00').toISOString(),
      location: 'Casemates Square',
    })
  })

  it('lets either side change the meeting place of an open booking', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api, { ...confirmedBooking, location: 'Old Town' }, false)

    expect(hasButton(wrapper, 'Set meeting place')).toBe(false)
    await button(wrapper, 'Change meeting place').trigger('click')
    const input = wrapper.find('input[type="text"]')
    expect((input.element as HTMLInputElement).value).toBe('Old Town')
    await input.setValue('Cable car, top station')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.bookingAction).toHaveBeenCalledWith({
      targetId: 7,
      op: 'setLocation',
      location: 'Cable car, top station',
    })
    expect(toasts()).toContain('Meeting place saved — Igor has been told.')
  })

  it('offers no meeting place once the trip is finished', () => {
    const wrapper = mountPanel(fakeApi(), { ...confirmedBooking, trip_status: 'finished' }, true)

    expect(hasButton(wrapper, 'meeting place')).toBe(false)
  })
})

describe('BookingPanel — on a trip card', () => {
  function mountWith(
    props: Partial<MatchOverviewRow>,
    extra: { allowBooking?: boolean; summary?: boolean },
  ) {
    setActivePinia(createPinia())
    return mount(BookingPanel, {
      props: { row: row(props), isGuest: true, name: 'Igor', ...extra },
      global: { provide: { [API_KEY]: fakeApi() }, stubs: { RouterLink: RouterLinkStub } },
    })
  }

  it('lets a guest cancel a free trip, which unmatches them', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(
      api,
      {
        ...confirmedBooking,
        fee_amount: 0,
        payment_status: 'none',
        booking_amount: 0,
        meeting_at: null,
      },
      true,
    )

    expect(hasButton(wrapper, 'Request cancellation')).toBe(false)
    await button(wrapper, 'Cancel trip').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.revokeSelection).toHaveBeenCalledWith({ targetId: 7 })
    expect(toasts()).toContain('Trip cancelled — Igor has been told.')
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('only offers "Book again" when allowed — the pair’s latest trip', () => {
    const finished = { ...confirmedBooking, trip_status: 'finished' as const }

    expect(hasButton(mountWith(finished, { allowBooking: true }), 'Book again')).toBe(true)
    expect(hasButton(mountWith(finished, { allowBooking: false }), 'Book again')).toBe(false)
  })

  it('hides its summary line when the card shows it instead', () => {
    const wrapper = mountWith(confirmedBooking, { summary: false })

    expect(wrapper.text()).not.toContain('Paid £50.00')
    expect(hasButton(wrapper, 'Request cancellation')).toBe(true)
  })
})
