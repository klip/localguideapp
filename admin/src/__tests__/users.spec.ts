import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import UsersPage from '../pages/UsersPage.vue'
import UserPage from '../pages/UserPage.vue'
import { ApiError, session, type Me, type UserDetail, type UserRow } from '@/api'

vi.mock('@/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api')>()
  type Api = typeof actual.api
  return {
    ...actual,
    api: {
      searchUsers: vi.fn<Api['searchUsers']>(),
      userLocations: vi.fn<Api['userLocations']>(),
      userDetail: vi.fn<Api['userDetail']>(),
      userEdit: vi.fn<Api['userEdit']>(),
      userFreeze: vi.fn<Api['userFreeze']>(),
      userUnfreeze: vi.fn<Api['userUnfreeze']>(),
      bookingDecision: vi.fn<Api['bookingDecision']>(),
    },
  }
})
const { api } = await import('@/api')

function signIn(capabilities: string[]) {
  session.ready = true
  session.me = {
    id: 1,
    email: 'staff@example.test',
    name: 'Staff',
    role: 'manager',
    disabled: false,
    hasPassword: true,
    lastSignInAt: null,
    createdAt: '2026-10-21T09:00:00Z',
    capabilities,
  } satisfies Me
}

function row(overrides: Partial<UserRow> = {}): UserRow {
  return {
    id: 156,
    name: 'Morris Reid',
    email: 'seed.guide.39@example.test',
    phone: null,
    image: null,
    roles: ['guide'],
    levels: { guest: null, guide: 6 },
    location: 'Málaga, Spain',
    verified: false,
    frozen: false,
    frozenUntil: null,
    createdAt: '2026-04-19T19:30:34Z',
    ...overrides,
  }
}

const role = (held: boolean) => ({
  held,
  headline: held ? 'Málaga like a local' : null,
  bio: null,
  announcedLevel: 6,
  trips: 61,
  paidTrips: 61,
  points: 88.45,
  earnedLevel: 6,
  adjustment: 0,
  effectiveLevel: 6,
  reviews: { count: 37, average: 4.38 },
  topRated: false,
  volunteer: false,
  attributes: (held ? { Languages: ['English', 'Spanish'] } : {}) as Record<string, string[]>,
})

function detail(overrides: Partial<UserDetail> = {}): UserDetail {
  return {
    account: {
      id: 156,
      name: 'Morris Reid',
      email: 'seed.guide.39@example.test',
      phone: '+61 495081750',
      image: null,
      twoFactorMethod: 'none',
      createdAt: '2026-04-19T19:30:34Z',
      roles: ['guide'],
    },
    profile: {
      dateOfBirth: null,
      gender: null,
      priceAmount: 40,
      priceLabel: null,
      priceNote: null,
      includes: null,
      party: null,
      durationHours: null,
      guideBase: { label: 'Málaga, Spain', countryCode: 'ES', radiusKm: null },
      travellerArea: null,
    },
    roles: { guest: role(false), guide: role(true) },
    verification: { verifiedAt: null, method: null },
    sanctions: [],
    adjustments: [],
    badges: [],
    bonuses: [],
    freeze: null,
    freezes: [],
    activity: {
      counts: {
        matches: 25,
        tripsFinished: 61,
        reviewsWritten: 30,
        reviewsReceived: 37,
        complaintsBy: 0,
        complaintsAbout: 1,
      },
      bookings: [],
      reviews: [],
      complaints: [],
    },
    openBookings: [
      {
        id: 465,
        side: 'guide',
        other: { id: 217, name: 'Ryan Novak' },
        status: 'pending',
        tripStatus: 'pending',
        paid: true,
        amount: 40,
        meetingAt: '2026-10-24T10:00:00Z',
      },
    ],
    ...overrides,
  }
}

async function mountAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/users', component: UsersPage },
      { path: '/users/:id', component: UserPage, props: true },
    ],
  })
  await router.push(path)
  const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  vi.mocked(api.userLocations).mockResolvedValue({
    cities: [
      { value: 'PT|Lisbon', city: 'Lisbon', countryCode: 'PT', users: 9 },
      { value: 'ES|Barcelona', city: 'Barcelona', countryCode: 'ES', users: 9 },
    ],
    countries: [
      { code: 'ES', users: 17 },
      { code: 'PT', users: 13 },
    ],
  })
  vi.mocked(api.searchUsers).mockResolvedValue({
    users: [
      row(),
      row({
        id: 217,
        name: 'Ryan Novak',
        roles: ['guest'],
        levels: { guest: 3, guide: null },
        frozen: true,
        frozenUntil: null,
      }),
    ],
    total: 2,
    page: 1,
    pageSize: 50,
  })
  vi.mocked(api.userDetail).mockResolvedValue(detail())
})

afterEach(() => {
  session.me = null
  vi.clearAllMocks()
})

describe('the Users list', () => {
  it('searches as you type (after a pause), keeping the search in the URL', async () => {
    signIn(['users.view'])
    const { wrapper, router } = await mountAt('/users')
    expect(api.searchUsers).toHaveBeenCalledWith(
      expect.objectContaining({ q: '', sort: 'id', dir: 'desc', page: 1 }),
    )
    expect(wrapper.find('[data-user="156"]').text()).toContain('Morris Reid')
    expect(wrapper.find('[data-user="217"]').text()).toContain('Frozen permanently')

    vi.useFakeTimers()
    try {
      vi.mocked(api.searchUsers).mockClear()
      await wrapper.find('[data-test="q"]').setValue('#15')
      await wrapper.find('[data-test="q"]').setValue('#156')
      await vi.advanceTimersByTimeAsync(200)
      expect(api.searchUsers).not.toHaveBeenCalled()
      await vi.advanceTimersByTimeAsync(400)
    } finally {
      vi.useRealTimers()
    }
    await flushPromises()
    expect(router.currentRoute.value.query.q).toBe('#156')
    // One search for the whole word, not one per keystroke.
    expect(api.searchUsers).toHaveBeenCalledOnce()
    expect(api.searchUsers).toHaveBeenLastCalledWith(expect.objectContaining({ q: '#156' }))
    expect(wrapper.find('[data-test="search"] button').exists()).toBe(false)
  })

  it('filters by country and city from dropdowns of where users are', async () => {
    signIn(['users.view'])
    const { wrapper, router } = await mountAt('/users')
    const country = wrapper.find('[data-test="country"]')
    expect(country.findAll('option').map((o) => o.text())).toEqual([
      'Any',
      'Portugal (13)',
      'Spain (17)',
    ])
    expect(
      wrapper
        .find('[data-test="city"]')
        .findAll('option')
        .map((o) => o.text()),
    ).toEqual(['Any', 'Lisbon, Portugal (9)', 'Barcelona, Spain (9)'])

    await wrapper.find('[data-test="city"]').setValue('ES|Barcelona')
    await country.setValue('PT')
    await country.trigger('change')
    // A city outside the chosen country is dropped; the list narrows to that country.
    expect(
      wrapper
        .find('[data-test="city"]')
        .findAll('option')
        .map((o) => o.text()),
    ).toEqual(['Any', 'Lisbon (9)'])
    await wrapper.find('[data-test="city"]').setValue('PT|Lisbon')
    await flushPromises()
    expect(router.currentRoute.value.query).toMatchObject({ country: 'PT', city: 'PT|Lisbon' })
    expect(api.searchUsers).toHaveBeenLastCalledWith(
      expect.objectContaining({ country: 'PT', city: 'PT|Lisbon' }),
    )
  })

  it('applies a filter as soon as it changes, sorts by a column and pages', async () => {
    signIn(['users.view'])
    vi.mocked(api.searchUsers).mockResolvedValue({
      users: [row()],
      total: 120,
      page: 1,
      pageSize: 50,
    })
    const { wrapper, router } = await mountAt('/users')
    await wrapper.find('[data-test="role"]').setValue('guide')
    await wrapper.find('[data-test="frozen"]').setValue('yes')
    await flushPromises()
    expect(router.currentRoute.value.query).toMatchObject({ role: 'guide', frozen: 'yes' })
    expect(api.searchUsers).toHaveBeenLastCalledWith(
      expect.objectContaining({ role: 'guide', frozen: 'yes' }),
    )
    expect(wrapper.find('[data-test="filters"] button[type="submit"]').exists()).toBe(false)

    await wrapper.find('[data-test="sort-name"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toMatchObject({
      sort: 'name',
      dir: 'asc',
      role: 'guide',
    })

    await wrapper.find('[data-test="next"]').trigger('click')
    await flushPromises()
    expect(api.searchUsers).toHaveBeenLastCalledWith(
      expect.objectContaining({ page: 2, sort: 'name', role: 'guide', frozen: 'yes' }),
    )
    // Refilling the form from the URL isn't a change: still on page 2.
    expect(router.currentRoute.value.query.page).toBe('2')

    // A real change starts again from page 1.
    await wrapper.find('[data-test="role"]').setValue('guest')
    await flushPromises()
    expect(router.currentRoute.value.query.page).toBeUndefined()
    expect(api.searchUsers).toHaveBeenLastCalledWith(
      expect.objectContaining({ page: 1, role: 'guest' }),
    )
  })
})

describe('the user page', () => {
  it('shows the sections a viewer may see, read-only without users.edit', async () => {
    signIn(['users.view'])
    const { wrapper } = await mountAt('/users/156')
    expect(api.userDetail).toHaveBeenCalledWith(156)
    expect(wrapper.find('[data-test="name"]').text()).toContain('Morris Reid')
    expect(wrapper.find('[data-test="level-guide"]').text()).toContain('6 · Legend')
    expect(wrapper.find('[data-test="sanction-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="freeze-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="security"]').exists()).toBe(false)
    expect(
      (wrapper.find('[data-test="account-form"] input[type="email"]').element as HTMLInputElement)
        .disabled,
    ).toBe(true)
  })

  it('saves an edit and pins a field error from the server under its input', async () => {
    signIn(['users.view', 'users.edit'])
    vi.mocked(api.userEdit).mockRejectedValueOnce(
      new ApiError('Another account (#12) uses that email.', 400, 'email'),
    )
    const { wrapper } = await mountAt('/users/156')
    const form = wrapper.find('[data-test="account-form"]')
    await form.find('input[type="email"]').setValue('taken@example.test')
    await form.trigger('submit.prevent')
    await flushPromises()
    expect(form.find('.field-error').text()).toContain('#12')

    vi.mocked(api.userEdit).mockResolvedValueOnce(detail({ adjustments: [] }))
    const adjust = wrapper.find('[data-test="adjustment-form"]')
    await adjust.find('select').setValue('guide')
    await adjust.find('input[type="number"]').setValue(2)
    await adjust.find('input[type="text"]').setValue('Founding guide')
    await adjust.trigger('submit.prevent')
    await flushPromises()
    expect(api.userEdit).toHaveBeenLastCalledWith(
      156,
      'addAdjustment',
      expect.objectContaining({ role: 'guide', delta: 2, reason: 'Founding guide' }),
    )
    expect(wrapper.find('[data-test="notice"]').text()).toBe('Level adjusted.')
  })

  it('freezes only after a confirm, with a decision for every open booking', async () => {
    signIn(['users.view', 'users.freeze'])
    vi.mocked(api.userFreeze).mockResolvedValue({
      ...detail({
        freeze: { id: 9, startsAt: '2026-10-21T13:00:00Z', until: null, reason: 'No-shows' },
      }),
      result: {},
    })
    const { wrapper } = await mountAt('/users/156')
    const form = wrapper.find('[data-test="freeze-form"]')
    await form.find('[data-test="permanent"]').setValue(true)
    await form.find('textarea').setValue('No-shows')
    await form.find('[data-booking="465"] input[value="cancel"]').setValue(true)
    await form.find('[data-booking="465"] input[type="number"]').setValue(30)

    await form.find('[data-test="freeze"]').trigger('click')
    expect(api.userFreeze).not.toHaveBeenCalled()
    await wrapper.find('[data-test="freeze-form"]').trigger('submit.prevent')
    await flushPromises()
    expect(api.userFreeze).toHaveBeenCalledWith(156, {
      permanent: true,
      reason: 'No-shows',
      bookings: [{ id: 465, decision: 'cancel', refund: 30 }],
    })
    expect(wrapper.find('[data-test="frozen-pill"]').text()).toContain('permanently')
    expect(wrapper.find('[data-test="lift-form"]').exists()).toBe(true)
  })

  it('lifts a freeze, and offers bookings left open to cancel (with refunds)', async () => {
    signIn(['users.view', 'users.freeze', 'refunds'])
    vi.mocked(api.userDetail).mockResolvedValue(
      detail({
        freeze: {
          id: 9,
          startsAt: '2026-10-21T13:00:00Z',
          until: '2026-10-28T13:00:00Z',
          reason: 'No-shows',
        },
      }),
    )
    // Still frozen after the decision — only the booking is gone.
    vi.mocked(api.bookingDecision).mockResolvedValue({
      ...detail({
        openBookings: [],
        freeze: {
          id: 9,
          startsAt: '2026-10-21T13:00:00Z',
          until: '2026-10-28T13:00:00Z',
          reason: 'No-shows',
        },
      }),
      result: {},
    })
    vi.mocked(api.userUnfreeze).mockResolvedValue({ ...detail({ openBookings: [] }), result: {} })
    const { wrapper } = await mountAt('/users/156')
    expect(wrapper.find('[data-test="freeze-state"]').text()).toContain('No-shows')

    const booking = wrapper.find('[data-booking="465"] form')
    await booking.find('input[type="number"]').setValue(20)
    await booking.trigger('submit.prevent')
    await flushPromises()
    expect(api.bookingDecision).toHaveBeenCalledWith(156, 465, 20, '')

    vi.mocked(api.userDetail).mockClear()
    await wrapper.find('[data-test="lift-form"] input').setValue('Appeal accepted')
    await wrapper.find('[data-test="lift-form"]').trigger('submit.prevent')
    await flushPromises()
    expect(api.userUnfreeze).toHaveBeenCalledWith(156, 9, 'Appeal accepted')
  })

  it('shows Security only when the server sends it (admins)', async () => {
    signIn(['users.view'])
    vi.mocked(api.userDetail).mockResolvedValue(
      detail({
        security: {
          devices: [
            {
              id: 1,
              label: 'Chrome on macOS',
              place: 'Lisbon',
              country: 'PT',
              ip: '10.0.0.1',
              firstSeenAt: '2026-10-01T10:00:00Z',
              lastSeenAt: '2026-10-21T10:00:00Z',
            },
          ],
          events: [],
          links: [
            {
              id: 3,
              other: { id: 217, name: 'Ryan Novak', email: 'r@example.test' },
              signal: 'device',
              strength: 3,
              hits: 2,
              lastSeenAt: '2026-10-20T10:00:00Z',
              verdict: null,
            },
          ],
        },
      }),
    )
    const { wrapper } = await mountAt('/users/156')
    const security = wrapper.find('[data-test="security"]')
    expect(security.text()).toContain('Chrome on macOS')
    expect(security.text()).toContain('Ryan Novak')
  })
})
