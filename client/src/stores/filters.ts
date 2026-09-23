import { computed, reactive } from 'vue'
import { defineStore } from 'pinia'
import type { DiscoveryFilters, Gender, NumericRange } from '@/types'

function emptyFilters(): DiscoveryFilters {
  return { gender: null, attributes: {}, ranges: {} }
}

/** Two spans are the same span — used to keep a category's list free of exact duplicates. */
function sameRange(a: NumericRange, b: NumericRange) {
  return a[0] === b[0] && a[1] === b[1]
}

/**
 * Discovery filters, shared by the filter bar and both decks.
 *
 * Nothing here names a category: values live under whatever key the server
 * gave them (`languages`, `interests`, `hour_of_day`, `age`, or one a user
 * invented), so a new category becomes filterable without touching this file.
 * That's the whole point of the rewrite — the old store hardcoded
 * `LANGUAGES`, `ACTIVITY_OPTIONS` and `GENDER_OPTIONS` lists.
 */
export const useFiltersStore = defineStore('filters', () => {
  const filters = reactive<DiscoveryFilters>(emptyFilters())

  /** Category keys with at least one selection, in insertion order — what the bar renders as groups. */
  const activeCategories = computed(() => [
    ...Object.keys(filters.attributes).filter((key) => filters.attributes[key]?.length),
    ...Object.keys(filters.ranges).filter((key) => filters.ranges[key]?.length),
  ])

  const activeCount = computed(() => {
    let count = filters.gender ? 1 : 0
    for (const values of Object.values(filters.attributes)) count += values.length
    for (const ranges of Object.values(filters.ranges)) count += ranges.length
    return count
  })

  const isPristine = computed(() => activeCount.value === 0)

  function valuesFor(categoryKey: string): string[] {
    return filters.attributes[categoryKey] ?? []
  }

  function rangesFor(categoryKey: string): NumericRange[] {
    return filters.ranges[categoryKey] ?? []
  }

  function hasValue(categoryKey: string, value: string) {
    return valuesFor(categoryKey).includes(value)
  }

  /**
   * Adds or removes one option. Removing the last one drops the category key
   * entirely rather than leaving an empty array behind, so `activeCategories`
   * (and the request payload) never carry a group with nothing in it.
   */
  function toggleValue(categoryKey: string, value: string) {
    const current = filters.attributes[categoryKey] ?? []
    const next = current.includes(value)
      ? current.filter((candidate) => candidate !== value)
      : [...current, value]

    if (next.length) {
      filters.attributes[categoryKey] = next
    } else {
      delete filters.attributes[categoryKey]
    }
  }

  function addRange(categoryKey: string, range: NumericRange) {
    const normalised: NumericRange = range[0] <= range[1] ? range : [range[1], range[0]]
    const current = filters.ranges[categoryKey] ?? []
    if (current.some((candidate) => sameRange(candidate, normalised))) return

    filters.ranges[categoryKey] = [...current, normalised]
  }

  function removeRange(categoryKey: string, index: number) {
    const next = (filters.ranges[categoryKey] ?? []).filter((_, position) => position !== index)
    if (next.length) {
      filters.ranges[categoryKey] = next
    } else {
      delete filters.ranges[categoryKey]
    }
  }

  function clearCategory(categoryKey: string) {
    delete filters.attributes[categoryKey]
    delete filters.ranges[categoryKey]
  }

  function setGender(gender: Gender | null) {
    filters.gender = gender
  }

  function reset() {
    filters.gender = null
    for (const key of Object.keys(filters.attributes)) delete filters.attributes[key]
    for (const key of Object.keys(filters.ranges)) delete filters.ranges[key]
  }

  return {
    filters,
    activeCategories,
    activeCount,
    isPristine,
    valuesFor,
    rangesFor,
    hasValue,
    toggleValue,
    addRange,
    removeRange,
    clearCategory,
    setGender,
    reset,
  }
})
