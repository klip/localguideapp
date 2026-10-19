import { onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { t } from '@/i18n'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useFiltersStore } from '@/stores/filters'
import type { PlacePoint } from '@/types'

/** `?near=38.72,-9.14` → a point, or null when absent or malformed. */
function parseNear(value: unknown): { latitude: number; longitude: number } | null {
  if (typeof value !== 'string') return null
  const [lat, lng] = value.split(',').map(Number)
  if (lat === undefined || lng === undefined || !Number.isFinite(lat) || !Number.isFinite(lng))
    return null
  if (Math.abs(lat) > 90 || Math.abs(lng) > 180) return null
  return { latitude: lat, longitude: lng }
}

const sameNear = (a: PlacePoint | null, b: PlacePoint | null) =>
  a?.latitude === b?.latitude && a?.longitude === b?.longitude && a?.label === b?.label

/**
 * The deck's search point beyond the filter store, for both Discover pages:
 *
 * - **In the URL** (`?near=lat,lng&place=Lisbon, Portugal`), so a link
 *   reproduces the search. A URL that names a point wins on arrival; later
 *   changes rewrite the query in place (no history entry per change).
 * - **On the server, for a traveller** (`api.setSearchArea()`), so guides
 *   near where they're looking can find them. Best-effort.
 */
export function useDeckLocation() {
  const api = useApi()
  const auth = useAuthStore()
  const route = useRoute()
  const router = useRouter()
  const filtersStore = useFiltersStore()

  onMounted(() => {
    const point = parseNear(route.query.near)
    if (!point) {
      writeQuery(filtersStore.filters.near)
      return
    }
    const place = typeof route.query.place === 'string' ? route.query.place.slice(0, 160) : ''
    const fromUrl: PlacePoint = {
      ...point,
      label: place || t('common.place_search.current_location'),
      countryCode: null,
    }
    if (!sameNear(fromUrl, filtersStore.filters.near)) filtersStore.setNear(fromUrl)
  })

  function writeQuery(near: PlacePoint | null) {
    const query = { ...route.query }
    if (near) {
      query.near = `${near.latitude},${near.longitude}`
      query.place = near.label
    } else {
      delete query.near
      delete query.place
    }
    if (query.near === route.query.near && query.place === route.query.place) return
    void router.replace({ query, hash: route.hash })
  }

  watch(
    () => filtersStore.filters.near,
    (near, previous) => {
      writeQuery(near)
      if (sameNear(near, previous ?? null)) return
      if (auth.isAuthenticated && auth.activeRole === 'guest') {
        api.setSearchArea(near).catch(() => {
          // Guides just won't see where they're looking until the next change.
        })
      }
    },
  )
}
