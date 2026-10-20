import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { t } from '@/i18n'
import { useApi, type ProfileAccount } from '@/plugins/api'
import type { DiscoveryFilters, Visitor } from '@/types'
import { standingOf } from '@/utils/standing'

/** Same idea as `toGuideCard` in `stores/guides.ts` — see that file's comment. */
function toVisitorCard(account: ProfileAccount): Visitor {
  return {
    id: String(account.id),
    name: account.name || t('common.visitor_card.default_name'),
    age: account.age ?? 0,
    party: account.party || t('common.visitor_card.default_party'),
    bio: account.bio || t('common.visitor_card.default_bio'),
    durationHours: account.duration_hours ?? 0,
    attributes: account.attributes ?? {},
    ranges: account.ranges ?? {},
    standing: standingOf(account),
    photo: account.image ? `url("${account.image}")` : 'var(--rg-photo-visitor)',
    areaLabel: account.area_label ?? null,
    distanceKm: account.distance_km ?? null,
    nextStop: account.next_stop ?? null,
  }
}

/**
 * Real visitors, fetched from `api.visitors()` and filtered server-side —
 * see `stores/guides.ts` for the full pattern, including why `useApi()` is
 * captured once here rather than called fresh inside `load()`.
 */
export const useVisitorsStore = defineStore('visitors', () => {
  const api = useApi()
  const byId = ref<Map<string, Visitor>>(new Map())
  const currentIds = ref<string[]>([])
  const loading = ref(false)

  const items = computed(() =>
    currentIds.value
      .map((id) => byId.value.get(id))
      .filter((visitor): visitor is Visitor => !!visitor),
  )

  let latestRequest = 0

  async function load(filters?: DiscoveryFilters) {
    // Fetches can overlap (a slider drag, the location arriving); only the
    // newest one may set the deck, or a slow wider answer overwrites it.
    const request = ++latestRequest
    loading.value = true
    try {
      const result = await api.visitors(filters)
      if (request !== latestRequest) return
      const mapped = result.visitors.map(toVisitorCard)
      for (const visitor of mapped) byId.value.set(visitor.id, visitor)
      currentIds.value = mapped.map((visitor) => visitor.id)
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }

  function find(id: string): Visitor | undefined {
    return byId.value.get(id)
  }

  return { items, loading, load, find }
})
