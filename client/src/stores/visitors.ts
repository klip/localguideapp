import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { useApi, type ProfileAccount } from '@/plugins/api'
import type { DiscoveryFilters, Visitor } from '@/types'

/** Same idea as `toGuideCard` in `stores/guides.ts` — see that file's comment. */
function toVisitorCard(account: ProfileAccount): Visitor {
  return {
    id: String(account.id),
    name: account.name || account.email,
    age: account.age ?? 0,
    party: account.party || 'New visitor',
    bio: account.bio || 'No trip details yet.',
    durationHours: account.duration_hours ?? 0,
    attributes: account.attributes ?? {},
    ranges: account.ranges ?? {},
    photo: account.image ? `url("${account.image}")` : 'var(--rg-photo-visitor)',
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
    currentIds.value.map((id) => byId.value.get(id)).filter((visitor): visitor is Visitor => !!visitor),
  )

  async function load(filters?: DiscoveryFilters) {
    loading.value = true
    try {
      const result = await api.visitors(filters)
      const mapped = result.visitors.map(toVisitorCard)
      for (const visitor of mapped) byId.value.set(visitor.id, visitor)
      currentIds.value = mapped.map((visitor) => visitor.id)
    } finally {
      loading.value = false
    }
  }

  function find(id: string): Visitor | undefined {
    return byId.value.get(id)
  }

  return { items, loading, load, find }
})
