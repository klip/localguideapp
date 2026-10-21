import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PagesPage from '../pages/PagesPage.vue'
import { ApiError, type AppRoute, type PageMap } from '@/api'

vi.mock('@/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api')>()
  type Api = typeof actual.api
  return {
    ...actual,
    api: {
      pageMap: vi.fn<Api['pageMap']>(),
      routeSave: vi.fn<Api['routeSave']>(),
      routeDelete: vi.fn<Api['routeDelete']>(),
    },
  }
})
const { api } = await import('@/api')

function route(overrides: Partial<AppRoute>): AppRoute {
  return {
    id: 1,
    name: 'trips',
    kind: 'page',
    path: '/trips',
    component: 'TripsPage',
    redirect: null,
    title: 'My trips',
    passParams: false,
    access: 'public',
    role: null,
    enabled: true,
    system: false,
    position: 10,
    updatedAt: '2026-10-21T09:00:00Z',
    updatedBy: '',
    ...overrides,
  }
}

const MAP: PageMap = {
  routes: [
    route({}),
    route({
      id: 2,
      name: 'login',
      path: '/login',
      component: 'LoginPage',
      title: 'Log in',
      system: true,
    }),
    route({
      id: 3,
      name: 'onboarding',
      kind: 'redirect',
      path: '/onboarding',
      component: null,
      redirect: '/profile',
      title: 'Onboarding',
    }),
  ],
  components: ['LoginPage', 'TripsPage'],
}

async function open(query: Record<string, string> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/pages', component: PagesPage }],
  })
  await router.push({ path: '/pages', query })
  const wrapper = mount(PagesPage, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  vi.mocked(api.pageMap).mockResolvedValue(MAP)
})
afterEach(() => {
  vi.clearAllMocks()
})

describe('the Pages screen', () => {
  it('lists every route with where it goes and who for', async () => {
    const { wrapper } = await open()
    expect(wrapper.find('[data-route="trips"]').text()).toContain('/trips · TripsPage · everyone')
    expect(wrapper.find('[data-route="login"]').text()).toContain('system')
    expect(wrapper.find('[data-route="onboarding"]').text()).toContain('/onboarding → /profile')
  })

  it('saves a route’s rules, and says the old address will redirect after a path change', async () => {
    const saved = route({ path: '/my-trips', access: 'signed_in', role: 'guest' })
    vi.mocked(api.routeSave).mockResolvedValue({
      ...MAP,
      route: saved,
      routes: [saved, MAP.routes[1]!, MAP.routes[2]!],
    })
    const { wrapper } = await open({ route: '1' })
    await wrapper.find('[data-test="path"]').setValue('/my-trips')
    expect(wrapper.find('[data-test="path-moved"]').text()).toContain('/trips')
    await wrapper.find('[data-test="access"]').setValue('signed_in')
    await wrapper.find('[data-test="role"]').setValue('guest')
    await wrapper.find('[data-test="route-form"]').trigger('submit.prevent')
    await flushPromises()
    expect(api.routeSave).toHaveBeenCalledWith(
      expect.objectContaining({
        id: 1,
        path: '/my-trips',
        access: 'signed_in',
        role: 'guest',
        component: 'TripsPage',
        redirect: null,
      }),
    )
    expect(wrapper.find('[data-test="notice"]').text()).toContain('old address now redirects')
  })

  it('locks a system route’s address and offers no Remove', async () => {
    const { wrapper } = await open({ route: '2' })
    expect(wrapper.find('[data-test="path"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="delete"]').exists()).toBe(false)
  })

  it('pins a field error from the server under its input', async () => {
    vi.mocked(api.routeSave).mockRejectedValue(
      new ApiError('“Log in” already has that path.', 400, 'path'),
    )
    const { wrapper } = await open({ route: '1' })
    await wrapper.find('[data-test="path"]').setValue('/login')
    await wrapper.find('[data-test="route-form"]').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('[data-test="route-form"] .field-error').text()).toContain(
      'already has that path',
    )
  })

  it('removes a route only after a confirm', async () => {
    vi.mocked(api.routeDelete).mockResolvedValue({
      ...MAP,
      routes: [MAP.routes[0]!, MAP.routes[2]!],
    })
    const { wrapper, router } = await open({ route: '1' })
    await wrapper.find('[data-test="delete"]').trigger('click')
    expect(api.routeDelete).not.toHaveBeenCalled()
    await wrapper.find('[data-test="confirm-delete"]').trigger('click')
    await flushPromises()
    expect(api.routeDelete).toHaveBeenCalledWith(1)
    expect(router.currentRoute.value.query.route).toBeUndefined()
  })

  it('edits no content — a page links to its texts under Content instead', async () => {
    const { wrapper } = await open({ route: '1' })
    expect(wrapper.find('[data-test="blocks"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="texts-link"] a').attributes('href')).toBe(
      '/content?page=trips',
    )
  })
})
