import { describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, getActivePinia, setActivePinia } from 'pinia'
import ClearFilters from '../components/ClearFilters.vue'
import DeckFilters from '../components/DeckFilters.vue'
import FilterPanel from '../components/FilterPanel.vue'
import { API_KEY, type Api, type AttributeCategory } from '@/plugins/api'
import { DEFAULT_MAX_KM, useFiltersStore } from '@/stores/filters'
import { fakeApi as baseFakeApi } from './fakeApi'

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

/** The shared stub `Api` (see `fakeApi.ts`) with this spec's defaults. */
function fakeApi(overrides: Partial<Api> = {}): Api {
  return baseFakeApi({
    attributeCategories: vi
      .fn<Api['attributeCategories']>()
      .mockResolvedValue({ categories: sampleCategories }),
    ...overrides,
  })
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

/** The accordion row (a `<details>`) for one category, by its summary text. */
function category(wrapper: Awaited<ReturnType<typeof mountPanel>>, label: string) {
  const found = wrapper
    .findAll('details.filter-cat')
    .find((row) => row.find('summary').text().includes(label))
  if (!found) throw new Error(`No category matching ${label}`)
  return found
}

function option(wrapper: Awaited<ReturnType<typeof mountPanel>>, label: string) {
  const found = wrapper.findAll('.option').find((row) => row.text().includes(label))
  if (!found) throw new Error(`No option matching ${label}`)
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
  it('lists every server category as an accordion row, with Age pulled out beside gender', async () => {
    const wrapper = await mountPanel()

    const rows = wrapper.findAll('details.filter-cat summary').map((summary) => summary.text())
    expect(rows).toEqual(['Languages', 'Hour of the day'])
    expect(wrapper.find('.person').text()).toContain('Age')
    // No pills: categories are rows, not tag buttons.
    expect(wrapper.find('.tag').exists()).toBe(false)
  })

  it('moves a picked option out of its list into a chip under the category name, and back on ×', async () => {
    const wrapper = await mountPanel()
    const store = useFiltersStore()

    const languages = category(wrapper, 'Languages')
    expect(option(wrapper, 'English').find('.option-mark').text()).toBe('+')
    expect(languages.find('[data-test="chips-languages"]').exists()).toBe(false)

    await option(wrapper, 'English').trigger('click')
    expect(store.valuesFor('languages')).toEqual(['English'])
    expect(languages.findAll('.option').map((row) => row.text())).toEqual(['Spanish+'])
    expect(languages.find('.count').text()).toBe('1')
    // The chip sits in the row's <summary>, under the name and count.
    const chips = languages.get('summary [data-test="chips-languages"]')
    expect(chips.findAll('.cat-chip').map((chip) => chip.text())).toEqual(['English'])

    // Its × takes the filter out without opening or closing the row.
    const click = new MouseEvent('click', { bubbles: true, cancelable: true })
    chips.get('.cat-chip-remove').element.dispatchEvent(click)
    await flushPromises()
    expect(click.defaultPrevented).toBe(true)
    expect(store.valuesFor('languages')).toEqual([])
    expect(languages.findAll('.option')).toHaveLength(2)
    expect(languages.find('.count').exists()).toBe(false)
    expect(languages.find('[data-test="chips-languages"]').exists()).toBe(false)
  })

  it('shows an hours span as a chip in its row too, removable', async () => {
    const wrapper = await mountPanel()
    const store = useFiltersStore()
    store.addRange('hour_of_day', [9, 13])
    await flushPromises()

    const chips = category(wrapper, 'Hour of the day').get('[data-test="chips-hour_of_day"]')
    expect(chips.text()).toBe('09:00–13:00')
    await chips.get('.cat-chip-remove').trigger('click')
    expect(store.filters.ranges).not.toHaveProperty('hour_of_day')
  })

  it('has no apply button — picking a filter is the query — and “clear ×” in the heading only while one is set', async () => {
    const wrapper = await mountPanel()
    const clear = mount(ClearFilters, { global: { plugins: [getActivePinia()!] } })

    expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
    expect(clear.find('[data-test="clear-filters"]').exists()).toBe(false)

    await option(wrapper, 'Spanish').trigger('click')
    const link = clear.find('[data-test="clear-filters"]')
    expect(link.text()).toBe('clear')
    expect(link.find('svg').exists()).toBe(true)

    await link.trigger('click')
    expect(useFiltersStore().isPristine).toBe(true)
    expect(clear.find('[data-test="clear-filters"]').exists()).toBe(false)
  })

  it('filters a range category with one two-handle slider, applied as it moves', async () => {
    const wrapper = await mountPanel()
    const store = useFiltersStore()

    const hours = category(wrapper, 'Hour of the day')
    const [from, to] = hours.findAll('input[type="range"]')
    expect(hours.find('.add-btn').exists()).toBe(false)
    expect(from?.attributes('min')).toBe('0')
    expect(to?.attributes('max')).toBe('23')
    expect(hours.find('[data-test="span-hour_of_day"]').text()).toBe('00:00–23:00')

    await from?.setValue(9)
    await to?.setValue(13)
    expect(store.rangesFor('hour_of_day')).toEqual([[9, 13]])
    expect(hours.find('[data-test="span-hour_of_day"]').text()).toBe('09:00–13:00')
    expect(hours.find('.count').text()).toBe('1')

    // Back to the full day means no hours filter.
    await from?.setValue(0)
    await to?.setValue(23)
    expect(store.filters.ranges).not.toHaveProperty('hour_of_day')
  })

  it('searches near a place, with one distance slider: 10 km by default, 0 is Unlimited', async () => {
    const wrapper = await mountPanel()
    const store = useFiltersStore()
    const location = wrapper.find('[data-test="location-filter"]')
    const slider = location.find<HTMLInputElement>('[data-test="distance-slider"]')
    const value = () => location.find('[data-test="distance-value"]').text()

    // No switch: the slider is always live, at the default.
    expect(location.find('[data-test="distance-unlimited"]').exists()).toBe(false)
    expect(location.text()).toContain('Anywhere')
    expect(slider.element.disabled).toBe(false)
    expect(slider.element.value).toBe('10')
    expect(store.filters.maxKm).toBe(DEFAULT_MAX_KM)
    expect(value()).toBe('Within 10 km')

    store.setNear({
      label: 'Lisbon, Portugal',
      countryCode: 'PT',
      latitude: 38.72,
      longitude: -9.14,
    })
    await flushPromises()
    expect(location.find('[data-test="near-label"]').text()).toContain('Lisbon, Portugal')
    // "Location" and the place share a line; the place itself opens the search.
    expect(location.find('.near').text()).toContain('Location')
    expect(location.find('.near-edit').exists()).toBe(false)
    await location.get('[data-test="near-change"]').trigger('click')
    expect(location.find('.near-edit').exists()).toBe(true)
    // A place ranks, it doesn't filter — and the default distance isn't a choice either.
    expect(store.isPristine).toBe(true)

    await slider.setValue(60)
    expect(store.filters.maxKm).toBe(60)
    expect(value()).toBe('Within 60 km')
    expect(store.activeCount).toBe(1)

    // All the way down is no limit.
    await slider.setValue(0)
    expect(store.filters.maxKm).toBeNull()
    expect(value()).toBe('Unlimited')

    // Clear filters goes back to the default and keeps where.
    store.reset()
    await flushPromises()
    expect(store.filters.maxKm).toBe(DEFAULT_MAX_KM)
    expect(store.filters.near?.label).toBe('Lisbon, Portugal')

    // The limit stays as set when the place goes — it applies to whatever point there is.
    store.setMaxKm(30)
    store.setNear(null)
    expect(store.filters.maxKm).toBe(30)
  })

  it('offers location on the guide’s deck too — travellers say where they’re looking', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mount(FilterPanel, {
      props: { targetRole: 'guest' },
      global: { plugins: [pinia], provide: { [API_KEY]: fakeApi() } },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="location-filter"]').exists()).toBe(true)
  })

  it('filters age with one two-handle slider, 16 to 99, kept in the store', async () => {
    const wrapper = await mountPanel()
    const store = useFiltersStore()

    const [low, high] = wrapper.findAll('.person input[type="range"]')
    expect(low?.attributes('min')).toBe('16')
    expect(high?.attributes('max')).toBe('99')
    expect(wrapper.find('[data-test="age-value"]').text()).toBe('16–99')
    expect(store.rangesFor('age')).toEqual([])

    await low?.setValue(25)
    await high?.setValue(40)
    expect(store.rangesFor('age')).toEqual([[25, 40]])
    expect(wrapper.find('[data-test="age-value"]').text()).toBe('25–40')

    // A remount (the rail collapsing on mobile) reads it back from the store.
    const again = mount(FilterPanel, {
      global: { plugins: [getActivePinia()!], provide: { [API_KEY]: fakeApi() } },
    })
    await flushPromises()
    expect(again.find('[data-test="age-value"]').text()).toBe('25–40')

    // Back to both ends means no age filter at all.
    await low?.setValue(16)
    await high?.setValue(99)
    expect(store.filters.ranges).not.toHaveProperty('age')
  })

  it('makes gender a three-way switch, since it is a constant rather than a tag list', async () => {
    const wrapper = await mountPanel()
    const store = useFiltersStore()

    const group = wrapper.get('[data-test="gender-filter"]')
    expect(group.get('legend').text()).toBe('Gender')
    const radios = group.findAll<HTMLInputElement>('input[type="radio"]')
    expect(group.findAll('.segment').map((segment) => segment.text())).toEqual([
      'Male',
      'Female',
      'Any',
    ])
    // No filter = "Any".
    expect(radios[2]!.element.checked).toBe(true)

    await radios[1]!.setValue(true)
    expect(store.filters.gender).toBe('female')

    await radios[2]!.setValue(true)
    expect(store.filters.gender).toBe(null)
  })
})

describe('DeckFilters', () => {
  // jsdom has no matchMedia, so this is the mobile layout: a button and a bottom sheet.
  async function mountDeckFilters() {
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mount(DeckFilters, {
      attachTo: document.body,
      props: {
        title: 'Filters',
        hint: 'Keep them light.',
        targetRole: 'guide',
        showLabel: 'Show 3 guides',
      },
      global: { plugins: [pinia], provide: { [API_KEY]: fakeApi() } },
    })
    await flushPromises()
    return wrapper
  }

  it('is the design’s row — where, and Filters with a count — and drops a pane down that “Show N” closes', async () => {
    const wrapper = await mountDeckFilters()
    const details = wrapper.get<HTMLDetailsElement>('details')
    expect(details.element.open).toBe(false)
    expect(wrapper.get('[data-test="filters-place"]').text()).toContain('Anywhere')
    expect(wrapper.find('[data-test="filters-count"]').exists()).toBe(false)

    useFiltersStore().setNear({
      label: 'Lisbon, Portugal',
      countryCode: 'PT',
      latitude: 38.72,
      longitude: -9.14,
    })
    useFiltersStore().setGender('female')
    await flushPromises()
    // The town alone, and only a count of what's set — the filters themselves are in the pane.
    expect(wrapper.get('[data-test="filters-place"]').text()).toContain('Lisbon')
    expect(wrapper.get('[data-test="filters-place"]').text()).not.toContain('Portugal')
    expect(wrapper.get('[data-test="filters-count"]').text()).toContain('1')

    details.element.open = true
    await details.trigger('toggle')
    expect(wrapper.find('[data-test="filters-backdrop"]').exists()).toBe(true)
    expect(document.body.style.overflow).toBe('hidden')

    const show = wrapper.get('[data-test="filters-show"]')
    expect(show.text()).toBe('Show 3 guides')
    await show.trigger('click')
    expect(details.element.open).toBe(false)
    expect(wrapper.find('[data-test="filters-backdrop"]').exists()).toBe(false)
    expect(document.body.style.overflow).toBe('')

    wrapper.unmount()
  })
})
