import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import LocationPrompt from '../components/LocationPrompt.vue'
import { API_KEY, type Api } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useFiltersStore } from '@/stores/filters'
import type { Role } from '@/plugins/api'
import { fakeApi } from './fakeApi'

const lisbon = { label: 'Lisbon, Portugal', countryCode: 'PT', latitude: 38.72, longitude: -9.14 }

async function mountPrompt(api: Api, role: Role | null = 'guest') {
  localStorage.clear()
  const pinia = createPinia()
  setActivePinia(pinia)
  if (role) {
    useAuthStore().setUser({ id: 7, email: 'a@example.com', role, roles: [role] })
  }
  const wrapper = mount(LocationPrompt, {
    global: { plugins: [pinia], provide: { [API_KEY]: api } },
  })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  localStorage.clear()
})

describe('LocationPrompt', () => {
  it('asks a signed-in traveller once, explaining why, and remembers "Not now" for the session', async () => {
    const wrapper = await mountPrompt(fakeApi())
    expect(wrapper.find('[data-test="location-prompt"]').text()).toContain(
      'See guides near you first',
    )

    await wrapper.find('[data-test="prompt-not-now"]').trigger('click')
    expect(wrapper.find('[data-test="location-prompt"]').exists()).toBe(false)
    expect(sessionStorage.getItem('belikelocal.locationAsked.guest')).toBe('1')

    const again = mount(LocationPrompt, {
      global: { plugins: [createPinia()], provide: { [API_KEY]: fakeApi() } },
    })
    await flushPromises()
    expect(again.find('[data-test="location-prompt"]').exists()).toBe(false)
  })

  it('never asks signed out, or when a search point is already set', async () => {
    expect(
      (await mountPrompt(fakeApi(), null)).find('[data-test="location-prompt"]').exists(),
    ).toBe(false)

    sessionStorage.setItem('belikelocal.near.guest', JSON.stringify(lisbon))
    const wrapper = await mountPrompt(fakeApi())
    expect(useFiltersStore().filters.near?.label).toBe('Lisbon, Portugal')
    expect(wrapper.find('[data-test="location-prompt"]').exists()).toBe(false)
  })

  it('uses a guide’s own base without asking', async () => {
    const myGuideBase = vi
      .fn<Api['myGuideBase']>()
      .mockResolvedValue({ base: { ...lisbon, radiusKm: 30 } })
    const wrapper = await mountPrompt(fakeApi({ myGuideBase }), 'guide')
    expect(myGuideBase).toHaveBeenCalled()
    expect(useFiltersStore().filters.near).toEqual(lisbon)
    expect(wrapper.find('[data-test="location-prompt"]').exists()).toBe(false)
  })

  it('asks a guide with no base, in guide words', async () => {
    const wrapper = await mountPrompt(fakeApi(), 'guide')
    expect(wrapper.find('[data-test="location-prompt"]').text()).toContain(
      'See travellers near you first',
    )
  })

  it('takes a chosen place as the search point and goes away', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const geocode = vi.fn<Api['geocode']>().mockResolvedValue({
      places: [{ ...lisbon, name: 'Lisbon', kind: 'city' }],
    })
    const wrapper = await mountPrompt(fakeApi({ geocode }))
    await wrapper.find('[data-test="prompt-choose"]').trigger('click')
    await wrapper.find('[data-test="place-input"]').setValue('lisb')
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()
    await wrapper.find('[data-test="place-option-0"]').trigger('mousedown')

    expect(useFiltersStore().filters.near).toEqual(lisbon)
    expect(wrapper.find('[data-test="location-prompt"]').exists()).toBe(false)
    vi.useRealTimers()
  })
})

describe('the search point per role', () => {
  it('is kept per role for the session, and swaps when the role does', async () => {
    localStorage.clear()
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setUser({
      id: 7,
      email: 'a@example.com',
      role: 'guest',
      roles: ['guest', 'guide'],
    })
    const filters = useFiltersStore()

    filters.setNear(lisbon)
    await flushPromises()
    expect(JSON.parse(sessionStorage.getItem('belikelocal.near.guest')!)).toEqual(lisbon)

    auth.setActiveRole('guide')
    await flushPromises()
    expect(filters.filters.near).toBeNull()

    auth.setActiveRole('guest')
    await flushPromises()
    expect(filters.filters.near).toEqual(lisbon)
  })
})
