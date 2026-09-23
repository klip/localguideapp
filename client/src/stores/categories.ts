import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { useApi, type AttributeCategory } from '@/plugins/api'
import type { AttributeMap } from '@/types'

/**
 * Age is filterable but isn't a category row: there's nothing to store per
 * user, since it's derived from `date_of_birth` (a human constant) at query
 * time — see `Users::searchByRole()`. Presenting it as a category anyway
 * keeps the filter bar uniform: one picker, one interaction, whether you're
 * narrowing by language or by age.
 */
export const AGE_CATEGORY_KEY = 'age'

export const AGE_CATEGORY: AttributeCategory = {
  key: AGE_CATEGORY_KEY,
  label: 'Age',
  kind: 'range',
  providerLabel: null,
  multi: true,
  rangeMin: 16,
  rangeMax: 90,
  options: [],
}

/**
 * The attribute taxonomy, fetched once and shared: the filter bar needs it to
 * know what can be filtered and how, `ProfilePage` to know what can be
 * edited, and the cards to label the values they show.
 *
 * Like `stores/guides.ts` this calls `useApi()` from a store rather than a
 * page, because several unrelated pages need the same list — and for the same
 * reason it captures it **once, synchronously, in the setup body**: `inject()`
 * only resolves while a component instance is current, which is not the case
 * inside a later `load()` call from a debounce or a watcher.
 */
export const useCategoriesStore = defineStore('categories', () => {
  const api = useApi()
  const items = ref<AttributeCategory[]>([])
  const loading = ref(false)
  const loaded = ref(false)

  /** What the filter bar offers: every real category, plus the synthetic Age one. */
  const filterable = computed<AttributeCategory[]>(() => [...items.value, AGE_CATEGORY])

  async function load(force = false) {
    if (loaded.value && !force) return

    loading.value = true
    try {
      const result = await api.attributeCategories()
      items.value = result.categories
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  function find(key: string): AttributeCategory | undefined {
    return filterable.value.find((category) => category.key === key)
  }

  /**
   * The same category reads differently depending on which side of the match
   * you're on — a guest's "Interests" is a guide's "Specialities" (see
   * `provider_label` in 010_profile_attributes.sql). `provider` picks the
   * latter where a category defines one.
   */
  function labelFor(key: string, provider = false): string {
    const category = find(key)
    if (!category) return key
    return (provider && category.providerLabel) || category.label
  }

  /** Display text for one stored value — falls back to the raw value for an option that's since been removed. */
  function optionLabelFor(key: string, value: string): string {
    return find(key)?.options.find((option) => option.value === value)?.label ?? value
  }

  /**
   * Flattens a card's whole attribute map into display labels, for the chip
   * rows on `ProfileCard`/`ShortlistPage`. Deliberately category-blind: it
   * shows whatever the person picked, in category order, rather than
   * privileging two categories the way the old `languages`/`activities`
   * card fields did. `limit` keeps a well-filled profile from burying the
   * rest of the card.
   */
  function tagsFor(attributes: AttributeMap, limit = 8): string[] {
    const tags: string[] = []
    for (const [key, values] of Object.entries(attributes ?? {})) {
      for (const value of values) tags.push(optionLabelFor(key, value))
    }

    return limit > 0 ? tags.slice(0, limit) : tags
  }

  return { items, filterable, loading, loaded, load, find, labelFor, optionLabelFor, tagsFor }
})
