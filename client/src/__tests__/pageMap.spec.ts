import { afterEach, describe, it, expect, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'
import { createMemoryHistory, createRouter } from 'vue-router'
// @ts-expect-error — a plain Node script, no type declarations.
import { checkMap } from '../../scripts/i18n-check.mjs'
import {
  BUNDLED_MAP,
  fetchAppMap,
  installAppMap,
  toRouteRecords,
  type AppMap,
} from '@/router/appMap'
import { scopesForRoute, setScopeMap } from '@/i18n'

const tiny: AppMap = {
  version: 't',
  routes: [
    {
      name: 'root',
      path: '/',
      component: null,
      redirect: '/trips',
      props: false,
      access: 'public',
      role: null,
    },
    {
      name: 'trips',
      path: '/my-trips',
      component: 'TripsPage',
      redirect: null,
      props: false,
      access: 'signed_in',
      role: null,
    },
    {
      name: 'ghost',
      path: '/ghost',
      component: 'NoSuchPage',
      redirect: null,
      props: false,
      access: 'public',
      role: null,
    },
  ],
  blocks: { _shell: ['common.app_header'], trips: ['trips.tabs'] },
}

describe('the page map (owned by the CMS) and the code', () => {
  it('lists every text block each page uses, and puts all text on some page — `yarn i18n:check` says what to add', () => {
    expect(checkMap()).toEqual({ missing: [], unplaced: [], unknownPages: [] })
  })

  it('becomes router records, leaving out a page this build lacks', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const records = toRouteRecords(tiny)
    expect(records.map((r) => r.name)).toEqual(['root', 'trips'])
    expect(records[1]!.meta).toEqual({ access: 'signed_in', role: null })
    expect(warn).toHaveBeenCalledOnce()
    warn.mockRestore()
  })

  it('replaces the routes a router has', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: toRouteRecords(BUNDLED_MAP),
    })
    expect(router.resolve('/matches').name).toBe('matches')
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    installAppMap(router, tiny)
    expect(router.resolve('/my-trips').name).toBe('trips')
    expect(router.hasRoute('matches')).toBe(false)
  })

  it('names the shell plus the route as a page’s text to load first', () => {
    setScopeMap(tiny.blocks)
    expect(scopesForRoute('trips')).toEqual(['common.app_header', 'trips.tabs'])
    expect(scopesForRoute(undefined)).toEqual(['common.app_header'])
    setScopeMap(BUNDLED_MAP.blocks)
  })

  it('falls back (null) when the live map fails or is too slow', async () => {
    expect(await fetchAppMap(() => Promise.reject(new Error('down')), 50)).toBeNull()
    expect(await fetchAppMap(() => new Promise(() => {}), 10)).toBeNull()
    expect(await fetchAppMap(async () => tiny, 50)).toBe(tiny)
  })
})

describe('the map’s access rules', () => {
  // jsdom has no scrolling; the router's scrollBehavior calls it.
  window.scrollTo = () => {}

  const rules = (
    overrides: Partial<Record<string, Partial<AppMap['routes'][number]>>>,
  ): AppMap => ({
    ...BUNDLED_MAP,
    routes: BUNDLED_MAP.routes.map((route) => ({ ...route, ...overrides[route.name] })),
  })

  afterEach(async () => {
    installAppMap(router, BUNDLED_MAP)
    localStorage.clear()
  })

  async function go(path: string) {
    await router.push(path)
    return router.currentRoute.value
  }

  it('sends a signed-out visitor from a signed-in page to /login, and a signed-in one away from a signed-out page', async () => {
    setActivePinia(createPinia())
    installAppMap(
      router,
      rules({ trips: { access: 'signed_in' }, register: { access: 'signed_out' } }),
    )
    expect((await go('/trips')).path).toBe('/login')
    expect((await go('/register')).path).toBe('/register')

    useAuthStore().setUser({ id: 1, email: 'a@example.test', role: 'guest', roles: ['guest'] })
    expect((await go('/trips')).path).toBe('/trips')
    expect((await go('/register')).name).toBe('discover')
  })

  it('switches a two-role account to the page’s role, and shows anyone else “not found”', async () => {
    setActivePinia(createPinia())
    installAppMap(router, rules({ planner: { role: 'guest' } }))
    const auth = useAuthStore()
    auth.setUser({ id: 1, email: 'a@example.test', role: 'guide', roles: ['guide', 'guest'] })
    auth.setActiveRole('guide')
    expect((await go('/planner')).name).toBe('planner')
    expect(auth.activeRole).toBe('guest')

    auth.setUser({ id: 2, email: 'b@example.test', role: 'guide', roles: ['guide'] })
    const landed = await go('/planner?x=1')
    expect(landed.name).toBe('not-found')
  })
})
