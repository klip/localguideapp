import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import VisitorProfilePage from '../pages/VisitorProfilePage.vue'
import GuideDiscoverPage from '../pages/GuideDiscoverPage.vue'
import {
  API_KEY,
  ApiError,
  type Api,
  type ProfileAccount,
  type PublicProfile,
  type ReviewsPage,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'

/** A stub `Api` — every method is a no-op `vi.fn()` unless overridden. */
function fakeApi(overrides: Partial<Api> = {}): Api {
  return {
    register: vi.fn<Api['register']>(),
    login: vi.fn<Api['login']>(),
    guides: vi.fn<Api['guides']>(),
    visitors: vi.fn<Api['visitors']>(),
    updateProfile: vi.fn<Api['updateProfile']>(),
    attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: [] }),
    myProfile: vi.fn<Api['myProfile']>(),
    updateAccount: vi.fn<Api['updateAccount']>(),
    changePassword: vi.fn<Api['changePassword']>(),
    logout: vi.fn<Api['logout']>(),
    session: vi.fn<Api['session']>(),
    unlockPack: vi.fn<Api['unlockPack']>(),
    paymentState: vi.fn<Api['paymentState']>(),
    decide: vi.fn<Api['decide']>(),
    revokeSelection: vi.fn<Api['revokeSelection']>(),
    matches: vi.fn<Api['matches']>(),
    matchOverview: vi.fn<Api['matchOverview']>(),
    publicProfile: vi.fn<Api['publicProfile']>(),
    reviews: vi.fn<Api['reviews']>().mockResolvedValue(emptyReviews),
    submitReview: vi.fn<Api['submitReview']>(),
    deleteReview: vi.fn<Api['deleteReview']>(),
    ...overrides,
  }
}

const emptyReviews: ReviewsPage = {
  reviews: [],
  total: 0,
  average: null,
  histogram: { '5': 0, '4': 0, '3': 0, '2': 0, '1': 0 },
  viewer: { canReview: false, bookingId: null, myReview: null },
}

const guest: PublicProfile = {
  id: 5,
  name: 'Maya',
  image: null,
  created_at: '2026-01-01 00:00:00',
  age: 31,
  gender: 'female',
  headline: null,
  bio: 'Two of us, first time in Gibraltar.',
  price_label: null,
  price_note: null,
  includes: null,
  party: '2 adults · Aurora cruise',
  duration_hours: 4,
  rating_average: 4.5,
  review_count: 2,
  role: 'guest',
  attributes: { languages: ['English'] },
  ranges: {},
}

function testRouter() {
  const blank = { template: '<div />' }
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/', component: blank },
      { path: '/matches', component: blank },
      { path: '/guide/discover', component: blank },
      {
        path: '/visitors/:id',
        name: 'visitor-profile',
        component: VisitorProfilePage,
        props: true,
      },
    ],
  })
}

async function mountPage(api: Api, id = '5') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = testRouter()
  router.push(`/visitors/${id}`)
  await router.isReady()

  const wrapper = mount(VisitorProfilePage, {
    props: { id },
    global: { plugins: [pinia, router], provide: { [API_KEY]: api } },
  })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  localStorage.clear()
})

describe('VisitorProfilePage', () => {
  it("shows a guest's public details and their reviews, never contact details", async () => {
    const api = fakeApi({ publicProfile: vi.fn<Api['publicProfile']>().mockResolvedValue(guest) })
    const wrapper = await mountPage(api)

    expect(api.publicProfile).toHaveBeenCalledWith(5)
    const text = wrapper.text()
    expect(text).toContain('Maya, 31')
    expect(text).toContain('★ 4.5 · 2 reviews')
    expect(text).toContain('2 adults · Aurora cruise')
    expect(text).toContain('Looking for about 4 hours')
    expect(text).toContain('Female')
    expect(text).toContain('Two of us, first time in Gibraltar.')
    expect(text).toContain('English')
    expect(text).not.toContain('@')

    // The review block loads for the same user.
    expect(api.reviews).toHaveBeenCalledWith({ userId: 5, limit: 3 })
    expect(text).toContain('No reviews yet.')
  })

  it('treats a guide id as not found', async () => {
    const api = fakeApi({
      publicProfile: vi.fn<Api['publicProfile']>().mockResolvedValue({ ...guest, role: 'guide' }),
    })
    const wrapper = await mountPage(api)

    expect(wrapper.text()).toContain('Visitor not found')
    expect(api.reviews).not.toHaveBeenCalled()
  })

  it('shows not found for an unknown id', async () => {
    const api = fakeApi({
      publicProfile: vi
        .fn<Api['publicProfile']>()
        .mockRejectedValue(new ApiError('Profile not found.', 400)),
    })
    const wrapper = await mountPage(api, '999')

    expect(wrapper.text()).toContain('Visitor not found')
  })
})

describe('GuideDiscoverPage', () => {
  it("links each visitor card to the visitor's full profile", async () => {
    const account: ProfileAccount = { ...guest, email: 'maya@example.com' }
    const api = fakeApi({
      visitors: vi.fn<Api['visitors']>().mockResolvedValue({ visitors: [account] }),
    })

    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().setUser({
      id: 1,
      email: 'guide@example.com',
      role: 'guide',
      sessionHash: 'hash',
    })
    const router = testRouter()
    router.push('/guide/discover')
    await router.isReady()

    const wrapper = mount(GuideDiscoverPage, {
      global: { plugins: [pinia, router], provide: { [API_KEY]: api } },
    })
    await flushPromises()

    const link = wrapper.findAll('a').find((anchor) => anchor.text() === 'Full profile →')
    expect(link?.attributes('href')).toBe('/visitors/5')
  })
})
