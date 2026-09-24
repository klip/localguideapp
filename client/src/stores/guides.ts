import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { useApi, type ProfileAccount, type PublicProfile } from '@/plugins/api'
import type { DiscoveryFilters, Guide } from '@/types'

/**
 * Maps a `ProfileAccount` (real `users` + `user_profiles` + friends, via
 * `Users::searchByRole()`) onto the `Guide` shape the deck/card UI expects.
 * Every scalar field falls back to a placeholder when the account hasn't
 * filled that part of its profile in, rather than rendering
 * `null`/`undefined`. The taxonomy halves (`attributes`/`ranges`) are copied
 * across untouched — there's no fixed list of categories to map onto any
 * more, so nothing here needs changing when a new one appears.
 */
export function toGuideCard(account: ProfileAccount | PublicProfile): Guide {
  return {
    id: String(account.id),
    // A public profile carries no email, so it has no fallback beyond this.
    name: account.name || ('email' in account ? account.email : '') || 'RockGuide guide',
    age: account.age ?? 0,
    headline: account.headline || 'New on RockGuide',
    ratingAverage: account.rating_average ?? 0,
    reviewCount: account.review_count ?? 0,
    priceLabel: account.price_label || 'Contact for pricing',
    priceNote: account.price_note ?? '',
    bio: account.bio || 'This guide hasn’t added a bio yet.',
    includes: account.includes ?? '',
    gender: account.gender ?? null,
    // Passed through exactly as the server grouped them: the card doesn't
    // know which categories exist, it just renders what a guide has picked
    // (see `stores/categories.ts` for the labels).
    attributes: account.attributes ?? {},
    ranges: account.ranges ?? {},
    photo: account.image ? `url("${account.image}")` : 'var(--rg-photo-guide)',
  }
}

/**
 * Real guides, fetched from `api.guides()` — filtered server-side
 * (`Users::searchByRole()`), so `load(filters)` re-fetches whenever the
 * filter selection changes rather than filtering a cached list in JS (see
 * DiscoverPage.vue's debounced watch). Results are cached by id (`byId`)
 * across calls so a guide seen once (e.g. picked while a filter was
 * active) stays resolvable via `find()` even after a later, narrower fetch
 * — `items` reflects only the *current* filtered set, `find()` searches
 * everything ever seen.
 *
 * Calls `useApi()` directly (unlike the page-calls-the-api pattern used
 * elsewhere) because this store is shared by multiple pages — see the
 * longer note in CLAUDE.md. Captured *once*, synchronously, right here in
 * the store's setup body — not inside `load()`. `load()` is called from a
 * debounced `watch` callback (DiscoverPage.vue), which runs via
 * `setTimeout` with no active component instance, so a fresh `useApi()`
 * call from inside `load()` throws ("called without the api plugin
 * installed"); capturing it here works because this setup body itself
 * only ever runs synchronously, during the first component's `<script
 * setup>` that calls `useGuidesStore()`.
 */
export const useGuidesStore = defineStore('guides', () => {
  const api = useApi()
  const byId = ref<Map<string, Guide>>(new Map())
  const currentIds = ref<string[]>([])
  const loading = ref(false)

  const items = computed(() =>
    currentIds.value.map((id) => byId.value.get(id)).filter((guide): guide is Guide => !!guide),
  )

  /**
   * `options.includeDecided` keeps guides this visitor has already picked
   * or passed in the result. The deck wants them gone (that's the default,
   * enforced server-side); `ShortlistPage` wants them,
   * since it renders cards the visitor has by definition already decided
   * on — without it its `find(id)` lookups come back empty after a reload.
   */
  async function load(filters?: DiscoveryFilters, options?: { includeDecided?: boolean }) {
    loading.value = true
    try {
      const result = await api.guides(filters, options?.includeDecided)
      const mapped = result.guides.map(toGuideCard)
      for (const guide of mapped) byId.value.set(guide.id, guide)
      currentIds.value = mapped.map((guide) => guide.id)
    } finally {
      loading.value = false
    }
  }

  /**
   * Pulls guides this visitor has already decided on into the id cache,
   * leaving `currentIds` — and therefore the deck — untouched.
   *
   * The deck fetch deliberately omits them (see `Users::searchByRole()`), so
   * once picks are restored after a reload (`App.vue`'s boot call) their ids
   * resolve to nothing and a rail can end up claiming "1 / 5 selected" above
   * "Nothing picked yet". Best-effort: this only fills in display names, so a
   * failure leaves the counts correct rather than breaking the page.
   */
  async function hydrateDecided() {
    try {
      const result = await api.guides(undefined, true)
      for (const guide of result.guides.map(toGuideCard)) byId.value.set(guide.id, guide)
    } catch {
      // Cosmetic only — callers fire and forget.
    }
  }

  function find(id: string): Guide | undefined {
    return byId.value.get(id)
  }

  return { items, loading, load, hydrateDecided, find }
})
