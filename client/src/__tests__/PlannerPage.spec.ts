import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import PlannerPage from '../pages/PlannerPage.vue'
import {
  API_KEY,
  type Api,
  type PaymentState,
  type ProfileAccount,
  type TripPlan,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { fakeApi as baseFakeApi } from './fakeApi'

/** Stands in for the API socket so a test can fire `trip_plan` pushes. */
const fakeSocket = vi.hoisted(() => {
  const handlers = new Map<string, (message: Record<string, unknown>) => void>()
  return {
    on: (type: string, handler: (message: Record<string, unknown>) => void) => {
      handlers.set(type, handler)
      return () => handlers.delete(type)
    },
    push: (type: string, message: Record<string, unknown>) =>
      handlers.get(type)?.({ type, ...message }),
  }
})
vi.mock('@/plugins/socket', () => ({ socket: fakeSocket }))

function guide(id: number, name: string): ProfileAccount {
  return {
    id,
    name,
    image: null,
    created_at: '2026-09-01 10:00:00',
    age: 30,
    gender: null,
    headline: 'History walks',
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

const lisbon = { label: 'Lisbon, Portugal', countryCode: 'PT', latitude: 38.72, longitude: -9.14 }

function plan(overrides: Partial<TripPlan> = {}): TripPlan {
  return {
    id: 5,
    title: null,
    status: 'ready',
    aiRanked: true,
    generatedAt: '2026-10-19T10:00:00Z',
    createdAt: '2026-10-19T09:59:00Z',
    stops: [
      {
        id: 50,
        ...lisbon,
        arriveOn: '2030-05-03',
        departOn: '2030-05-05',
        suggestions: [{ guideId: 9, reason: 'Speaks your language.', distanceKm: 2 }],
      },
    ],
    guides: [guide(9, 'Ana')],
    ...overrides,
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

function fakeApi(overrides: Partial<Api> = {}): Api {
  return baseFakeApi({
    paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(payment(1)),
    decide: vi.fn<Api['decide']>().mockResolvedValue({
      id: 1,
      guest_id: 1,
      guide_id: 9,
      guest_decision: 'interested',
      guide_decision: null,
      active: 0,
      created_at: '',
      updated_at: '',
    }),
    ...overrides,
  })
}

async function mountPage(api: Api, role: 'guest' | 'guide' = 'guest', path = '/planner') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().setUser({ id: 1, email: 'me@example.com', role, sessionHash: 'hash' })
  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/planner', component: PlannerPage },
      { path: '/:pathMatch(.*)*', component: { template: '<div />' } },
    ],
  })
  router.push(path)
  await router.isReady()
  const wrapper = mount(PlannerPage, {
    global: { plugins: [pinia, router], provide: { [API_KEY]: api } },
  })
  await flushPromises()
  return { wrapper, router }
}

afterEach(() => {
  localStorage.clear()
})

describe('PlannerPage', () => {
  it('is for travellers only', async () => {
    const api = fakeApi()
    const { wrapper } = await mountPage(api, 'guide')

    expect(wrapper.find('[data-test="planner-travellers-only"]').exists()).toBe(true)
    expect(api.tripPlans).not.toHaveBeenCalled()
  })

  it('reads a described trip into stops, flagging a place it could not find', async () => {
    const api = fakeApi({
      parseItinerary: vi.fn<Api['parseItinerary']>().mockResolvedValue({
        stops: [
          {
            query: 'Lisbon',
            arriveOn: '2030-05-03',
            departOn: '2030-05-05',
            place: { ...lisbon, name: 'Lisbon', kind: 'city' },
          },
          { query: 'Atlantis', arriveOn: null, departOn: '2030-05-09', place: null },
        ],
      }),
    })
    const { wrapper } = await mountPage(api)

    await wrapper.find('[data-test="planner-describe"]').setValue('Lisbon 3–5 May, then Atlantis')
    await wrapper.find('[data-test="planner-read"]').trigger('click')
    await flushPromises()

    expect(api.parseItinerary).toHaveBeenCalledWith('Lisbon 3–5 May, then Atlantis', 'en')
    expect(wrapper.findAll('.stop')).toHaveLength(2)
    expect(wrapper.find('[data-test="planner-stop-1"]').text()).toContain('Atlantis')
    // The second stop starts when the first ends.
    expect((wrapper.find('[data-test="planner-from-1"]').element as HTMLInputElement).value).toBe(
      '2030-05-05',
    )
  })

  it('refuses to save a stop without a place', async () => {
    const api = fakeApi()
    const { wrapper } = await mountPage(api)

    await wrapper.find('[data-test="planner-find"]').trigger('click')
    await flushPromises()

    expect(api.saveTripPlan).not.toHaveBeenCalled()
    expect(useMessagesStore().items[0]?.text).toBe('Choose a place for stop 1.')
  })

  it('saves the trip, waits for its guides and shows them when the push arrives', async () => {
    const api = fakeApi({
      parseItinerary: vi.fn<Api['parseItinerary']>().mockResolvedValue({
        stops: [
          {
            query: 'Lisbon',
            arriveOn: '2030-05-03',
            departOn: '2030-05-05',
            place: { ...lisbon, name: 'Lisbon', kind: 'city' },
          },
        ],
      }),
      saveTripPlan: vi
        .fn<Api['saveTripPlan']>()
        .mockResolvedValue(
          plan({
            status: 'pending',
            guides: [],
            stops: [{ ...plan().stops[0]!, suggestions: null }],
          }),
        ),
      tripPlan: vi.fn<Api['tripPlan']>().mockResolvedValue(plan()),
    })
    const { wrapper, router } = await mountPage(api)

    await wrapper.find('[data-test="planner-describe"]').setValue('Lisbon 3–5 May')
    await wrapper.find('[data-test="planner-read"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="planner-title"]').setValue('Spring')
    await wrapper.find('[data-test="planner-find"]').trigger('click')
    await flushPromises()

    expect(api.saveTripPlan).toHaveBeenCalledWith({
      title: 'Spring',
      language: 'en',
      stops: [{ place: lisbon, arriveOn: '2030-05-03', departOn: '2030-05-05' }],
    })
    expect(wrapper.find('[data-test="planner-waiting"]').exists()).toBe(true)
    expect(router.currentRoute.value.query.trip).toBe('5')

    fakeSocket.push('trip_plan', { planId: 5, status: 'ready' })
    await flushPromises()

    expect(api.tripPlan).toHaveBeenCalledWith(5)
    expect(wrapper.find('[data-test="planner-waiting"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="planner-guide-9"]').text()).toContain('Ana')
    expect(wrapper.find('[data-test="planner-reason"]').text()).toBe('Speaks your language.')
    expect(wrapper.text()).toContain('Only 1 guide near Lisbon, Portugal so far.')
  })

  it('opens the newest saved trip, and Interested spends a pick', async () => {
    const api = fakeApi({
      tripPlans: vi.fn<Api['tripPlans']>().mockResolvedValue({
        plans: [
          {
            id: 5,
            title: 'Spring',
            status: 'ready',
            createdAt: '2026-10-19T09:59:00Z',
            firstDay: '2030-05-03',
            lastDay: '2030-05-05',
            stopCount: 1,
            places: ['Lisbon, Portugal'],
          },
        ],
      }),
      tripPlan: vi.fn<Api['tripPlan']>().mockResolvedValue(plan({ title: 'Spring' })),
    })
    const { wrapper } = await mountPage(api)

    expect(api.tripPlan).toHaveBeenCalledWith(5)
    expect(wrapper.find('[data-test="planner-trip-5"]').text()).toContain('Spring')
    // Nothing changed since it was generated: nothing to search again.
    expect(wrapper.find('[data-test="planner-find"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="planner-interested"]').trigger('click')
    await flushPromises()

    expect(api.decide).toHaveBeenCalledWith({ targetId: 9, decision: 'interested' })
    expect(wrapper.find('[data-test="planner-picked"]').exists()).toBe(true)
  })

  it('sends a traveller without a pack to unlock one', async () => {
    const api = fakeApi({
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(payment(0)),
      tripPlan: vi.fn<Api['tripPlan']>().mockResolvedValue(plan()),
    })
    const { wrapper, router } = await mountPage(api, 'guest', '/planner?trip=5')

    await wrapper.find('[data-test="planner-interested"]').trigger('click')
    await flushPromises()

    expect(api.decide).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/unlock')
  })

  it('deletes a trip after confirming', async () => {
    const api = fakeApi({ tripPlan: vi.fn<Api['tripPlan']>().mockResolvedValue(plan()) })
    const { wrapper } = await mountPage(api, 'guest', '/planner?trip=5')

    await wrapper.find('[data-test="planner-delete"]').trigger('click')
    await wrapper.find('[data-test="planner-delete-yes"]').trigger('click')
    await flushPromises()

    expect(api.deleteTripPlan).toHaveBeenCalledWith(5)
    expect(wrapper.find('[data-test="planner-results"]').exists()).toBe(false)
  })
})
