import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BookingPanel from '../components/BookingPanel.vue'
import CalendarPage from '../pages/CalendarPage.vue'
import { API_KEY, ApiError, type Api, type MatchOverviewRow, type MyCalendar } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { fakeApi } from './fakeApi'

/** A matched pair with a £50 guide and nothing booked. */
function row(): MatchOverviewRow {
  return {
    id: 1,
    counterpart_id: 7,
    my_decision: 'interested',
    their_decision: 'interested',
    active: 1,
    updated_at: '2026-09-10 12:00:00',
    name: 'Marta',
    image: null,
    age: null,
    headline: null,
    price_label: null,
    party: null,
    duration_hours: null,
    matched_at: '2026-09-01 12:00:00',
    fee_amount: 50,
    guide_place: null,
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
    location_pin: null,
  } as MatchOverviewRow
}

const START = '2030-01-15T10:00:00Z'

function calendarApi(overrides: Partial<Api> = {}) {
  return fakeApi({
    guideCalendar: vi
      .fn<Api['guideCalendar']>()
      .mockImplementation(async (_guide, month, hours) => ({
        hasCalendar: true,
        timeZone: 'UTC',
        defaultTripHours: 3,
        slotMinutes: 30,
        durationHours: hours ?? 3,
        month: month ?? '2030-01',
        days: ['2030-01-15', '2030-01-16'],
      })),
    guideStartTimes: vi
      .fn<Api['guideStartTimes']>()
      .mockImplementation(async (_guide, _day, hours) => ({
        hasCalendar: true,
        timeZone: 'UTC',
        defaultTripHours: 3,
        slotMinutes: 30,
        durationHours: hours,
        starts: [START, '2030-01-15T10:30:00Z'],
      })),
    payGuideFee: vi.fn<Api['payGuideFee']>().mockResolvedValue({} as never),
    ...overrides,
  })
}

function mountPanel(api: Api) {
  setActivePinia(createPinia())
  return mount(BookingPanel, {
    props: { row: row(), isGuest: true, name: 'Marta' },
    global: { provide: { [API_KEY]: api }, stubs: { RouterLink: RouterLinkStub } },
  })
}

async function openPay(wrapper: ReturnType<typeof mountPanel>) {
  await wrapper
    .findAll('button')
    .find((b) => b.text().includes('Pay £50.00'))!
    .trigger('click')
  await flushPromises()
}

afterEach(() => {
  localStorage.clear()
})

describe('paying a guide who keeps a calendar', () => {
  it('offers only their free days, then that day’s free times, and pays for the one picked', async () => {
    const api = calendarApi()
    const wrapper = mountPanel(api)
    await openPay(wrapper)

    expect(api.guideCalendar).toHaveBeenCalledWith(7, undefined, undefined)
    expect(wrapper.find('input[type="datetime-local"]').exists()).toBe(false)
    expect(wrapper.find('[data-day="2030-01-15"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-day="2030-01-14"]').attributes('disabled')).toBeDefined()
    expect((wrapper.find('[data-test="slot-length"]').element as HTMLSelectElement).value).toBe('3')

    await wrapper.find('[data-day="2030-01-15"]').trigger('click')
    await flushPromises()
    expect(api.guideStartTimes).toHaveBeenCalledWith(7, '2030-01-15', 3)
    // The calendar is in a modal; the form's "When" field shows what was picked.
    expect(wrapper.find('dialog [data-test="slot-picker"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="slot-done"]').attributes('disabled')).toBeDefined()
    await wrapper.find(`[data-start="${START}"]`).trigger('click')
    expect(wrapper.get('[data-test="slot-done"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="slot-open"]').text()).toContain('3 h')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.payGuideFee).toHaveBeenCalledWith({
      targetId: 7,
      meetingAt: new Date(START).toISOString(),
      durationHours: 3,
    })
  })

  it('reloads the free days and times when the trip length changes', async () => {
    const api = calendarApi()
    const wrapper = mountPanel(api)
    await openPay(wrapper)
    await wrapper.find('[data-day="2030-01-15"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="slot-length"]').setValue('4')
    await flushPromises()
    expect(api.guideCalendar).toHaveBeenLastCalledWith(7, '2030-01', 4)
    expect(api.guideStartTimes).toHaveBeenLastCalledWith(7, '2030-01-15', 4)
  })

  it('pays nothing until a time is picked', async () => {
    const api = calendarApi()
    const wrapper = mountPanel(api)
    await openPay(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(api.payGuideFee).not.toHaveBeenCalled()
    expect(useMessagesStore().items.map((m) => m.text)).toContain(
      'Pick a day and a start time first.',
    )
  })

  it('keeps the free date-time input for a guide without a calendar', async () => {
    const api = fakeApi()
    const wrapper = mountPanel(api)
    await openPay(wrapper)
    expect(wrapper.find('[data-test="slot-picker"]').exists()).toBe(false)
    expect(wrapper.find('input[type="datetime-local"]').exists()).toBe(true)
  })
})

describe('the guide’s Calendar page', () => {
  const saved: MyCalendar = {
    calendar: {
      timeZone: 'Europe/Lisbon',
      slotMinutes: 30,
      bufferMinutes: 30,
      defaultTripHours: 3,
      minNoticeHours: 12,
      maxDaysAhead: 180,
      weekly: { '1': [[540, 780]] },
      daysOff: [],
    },
    suggestedHours: [],
    bookings: [
      {
        id: 5,
        status: 'pending',
        tripStatus: 'pending',
        start: '2030-01-15T10:00:00Z',
        hours: 2,
        guest: { id: 9, name: 'Ryan' },
      },
    ],
  }

  function mountPage(api: Api, role: 'guide' | 'guest' = 'guide') {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({ id: 1, email: 'g@example.test', role, roles: [role] })
    return mount(CalendarPage, { global: { provide: { [API_KEY]: api } } })
  }

  it('starts from the profile’s hours and saves the week in minutes', async () => {
    const api = fakeApi({
      myCalendar: vi
        .fn<Api['myCalendar']>()
        .mockResolvedValue({ calendar: null, suggestedHours: [[540, 780]], bookings: [] }),
      saveCalendar: vi.fn<Api['saveCalendar']>().mockResolvedValue(saved),
    })
    const wrapper = mountPage(api)
    await flushPromises()
    expect(wrapper.find('[data-test="no-calendar"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="exception-form"]').exists()).toBe(false)

    await wrapper.find('[data-test="use-profile-hours"]').trigger('click')
    await wrapper.find('[data-test="add-1"]').trigger('click')
    await wrapper.find('[data-test="calendar-form"]').trigger('submit')
    await flushPromises()

    const sent = vi.mocked(api.saveCalendar).mock.calls[0]![0]
    expect(sent.weekly.filter((w) => w.weekday === 1)).toEqual([
      { weekday: 1, start: 540, end: 780 },
      { weekday: 1, start: 780, end: 1020 },
    ])
    expect(sent.weekly).toHaveLength(8)
    expect(wrapper.find('[data-test="no-calendar"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="exception-form"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="upcoming"]').text()).toContain('Ryan')
  })

  it('pins a save error, adds a day off, and copies Monday to the weekdays', async () => {
    const api = fakeApi({
      myCalendar: vi.fn<Api['myCalendar']>().mockResolvedValue(saved),
      saveCalendar: vi
        .fn<Api['saveCalendar']>()
        .mockRejectedValue(
          new ApiError(
            'Two windows on the same day overlap — merge them.',
            400,
            undefined,
            'weekly',
          ),
        ),
      addDayOff: vi.fn<Api['addDayOff']>().mockResolvedValue(saved),
    })
    const wrapper = mountPage(api)
    await flushPromises()

    await wrapper.find('[data-test="copy-monday"]').trigger('click')
    expect(wrapper.find('[data-weekday="5"]').findAll('input[type="time"]')).toHaveLength(2)
    await wrapper.find('[data-test="calendar-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-test="weekly-error"]').text()).toContain('overlap')

    const form = wrapper.find('[data-test="exception-form"]')
    await form.find('input[type="date"]').setValue('2030-02-01')
    await form.trigger('submit')
    await flushPromises()
    expect(api.addDayOff).toHaveBeenCalledWith({ day: '2030-02-01', kind: 'off', note: '' })
  })

  it('tells a traveller it’s for guide mode', async () => {
    const api = fakeApi()
    const wrapper = mountPage(api, 'guest')
    await flushPromises()
    expect(api.myCalendar).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('switch to guiding')
  })
})
