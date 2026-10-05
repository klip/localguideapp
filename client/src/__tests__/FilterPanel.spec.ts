import { describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import FilterPanel from '../components/FilterPanel.vue'
import { API_KEY, type Api, type AttributeCategory } from '@/plugins/api'
import { useFiltersStore } from '@/stores/filters'

/**
 * The filter bar is built entirely from what the server says exists, so these
 * fixtures are the only place a category name appears — swap them and the
 * panel renders whatever you put here, which is the property being tested.
 */
const sampleCategories: AttributeCategory[] = [
  {
    key: 'languages',
    label: 'Languages',
    kind: 'options',
    providerLabel: null,
    multi: true,
    rangeMin: null,
    rangeMax: null,
    options: [
      { value: 'English', label: 'English' },
      { value: 'Spanish', label: 'Spanish' },
    ],
  },
  {
    key: 'hour_of_day',
    label: 'Hour of the day',
    kind: 'range',
    providerLabel: 'Available hours',
    multi: true,
    rangeMin: 0,
    rangeMax: 23,
    options: [],
  },
]

/** A stub `Api` — every method is a no-op `vi.fn()` unless overridden. */
function fakeApi(overrides: Partial<Api> = {}): Api {
  return {
    register: vi.fn<Api['register']>(),
    login: vi.fn<Api['login']>(),
    guides: vi.fn<Api['guides']>(),
    visitors: vi.fn<Api['visitors']>(),
    updateProfile: vi.fn<Api['updateProfile']>(),
    attributeCategories: vi.fn<Api['attributeCategories']>().mockResolvedValue({ categories: sampleCategories }),
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

async function mountPanel() {
  const pinia = createPinia()
  setActivePinia(pinia)

  const wrapper = mount(FilterPanel, {
    global: { plugins: [pinia], provide: { [API_KEY]: fakeApi() } },
  })
  await flushPromises()
  return wrapper
}

function chip(wrapper: Awaited<ReturnType<typeof mountPanel>>, label: string) {
  const found = wrapper.findAll('.tag').find((tag) => tag.text().includes(label))
  if (!found) throw new Error(`No tag matching ${label}`)
  return found
}

describe('filters store', () => {
  it('keys everything by category, dropping a group once it empties', () => {
    setActivePinia(createPinia())
    const store = useFiltersStore()

    store.toggleValue('languages', 'English')
    store.toggleValue('languages', 'Spanish')
    expect(store.valuesFor('languages')).toEqual(['English', 'Spanish'])
    expect(store.activeCategories).toEqual(['languages'])

    store.toggleValue('languages', 'English')
    store.toggleValue('languages', 'Spanish')
    expect(store.filters.attributes).not.toHaveProperty('languages')
    expect(store.isPristine).toBe(true)
  })

  it('collects several spans per category and ignores an exact duplicate', () => {
    setActivePinia(createPinia())
    const store = useFiltersStore()

    store.addRange('hour_of_day', [9, 13])
    store.addRange('hour_of_day', [18, 21])
    store.addRange('hour_of_day', [9, 13])
    expect(store.rangesFor('hour_of_day')).toEqual([
      [9, 13],
      [18, 21],
    ])

    store.removeRange('hour_of_day', 0)
    expect(store.rangesFor('hour_of_day')).toEqual([[18, 21]])

    store.removeRange('hour_of_day', 0)
    expect(store.filters.ranges).not.toHaveProperty('hour_of_day')
  })

  it('normalises a back-to-front span and counts every selection', () => {
    setActivePinia(createPinia())
    const store = useFiltersStore()

    store.addRange('age', [40, 25])
    expect(store.rangesFor('age')).toEqual([[25, 40]])

    store.setGender('female')
    store.toggleValue('interests', 'hiking')
    expect(store.activeCount).toBe(3)

    store.reset()
    expect(store.isPristine).toBe(true)
    expect(store.filters.gender).toBe(null)
  })
})

describe('FilterPanel', () => {
  it('offers whatever categories the server returned, plus Age', async () => {
    const wrapper = await mountPanel()

    const addable = wrapper.findAll('.tag--add').map((tag) => tag.text())
    expect(addable).toEqual(['+ Languages', '+ Hour of the day', '+ Age'])
  })

  it('opens a category first, then filters by the values inside it', async () => {
    const wrapper = await mountPanel()
    const store = useFiltersStore()

    // Nothing is offered as a value until its category is picked.
    expect(wrapper.text()).not.toContain('English')

    await chip(wrapper, '+ Languages').trigger('click')
    expect(wrapper.text()).toContain('English')

    await chip(wrapper, 'English').trigger('click')
    expect(store.valuesFor('languages')).toEqual(['English'])
  })

  it('renders a range category as a slider that adds spans', async () => {
    const wrapper = await mountPanel()
    const store = useFiltersStore()

    await chip(wrapper, '+ Hour of the day').trigger('click')
    const sliders = wrapper.findAll('input[type="range"]')
    expect(sliders).toHaveLength(2)

    await sliders[0]?.setValue(9)
    await sliders[1]?.setValue(13)
    await wrapper.find('.add-btn').trigger('click')

    expect(store.rangesFor('hour_of_day')).toEqual([[9, 13]])
  })

  it('keeps gender a dropdown, since it is a constant rather than a tag list', async () => {
    const wrapper = await mountPanel()
    const store = useFiltersStore()

    const select = wrapper.find('select')
    expect(select.findAll('option').map((option) => option.text())).toEqual([
      'No preference',
      'Male',
      'Female',
    ])

    await select.setValue('female')
    expect(store.filters.gender).toBe('female')

    await select.setValue('')
    expect(store.filters.gender).toBe(null)
  })
})
