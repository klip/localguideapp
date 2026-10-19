import { currentLanguage, t } from '@/i18n'
import type { Api } from '@/plugins/api'
import type { useCurrentPosition } from '@/composables/useCurrentPosition'
import type { PlacePoint } from '@/types'

export type CurrentPlaceResult =
  | { ok: true; place: PlacePoint }
  | { ok: false; message: string }
  | null

/**
 * The device's position, once, named as a town (`api.reverseGeocode()`) —
 * "Use my current location" in `PlaceSearch` and the location prompt.
 * `null` when the request was cancelled. If naming fails the point is still
 * used, as "Current location", rounded to ~1 km like everything stored.
 */
export async function currentPlace(
  api: Api,
  position: ReturnType<typeof useCurrentPosition>,
): Promise<CurrentPlaceResult> {
  const result = await position.locate()
  if (!result) return null
  if (!result.ok) {
    return {
      ok: false,
      message: t(
        result.reason === 'denied'
          ? 'common.place_search.location_denied'
          : 'common.place_search.location_failed',
      ),
    }
  }

  const point = { latitude: result.position.latitude, longitude: result.position.longitude }
  try {
    const { place } = await api.reverseGeocode(point, currentLanguage.value)
    if (place) {
      return {
        ok: true,
        place: {
          label: place.label,
          countryCode: place.countryCode,
          latitude: place.latitude,
          longitude: place.longitude,
        },
      }
    }
  } catch {
    // Named or not, the point is what matters.
  }
  return {
    ok: true,
    place: {
      label: t('common.place_search.current_location'),
      countryCode: null,
      latitude: Math.round(point.latitude * 100) / 100,
      longitude: Math.round(point.longitude * 100) / 100,
    },
  }
}
