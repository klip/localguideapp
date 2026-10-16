import { getCurrentInstance, onBeforeUnmount, ref } from 'vue'

export interface DevicePosition {
  latitude: number
  longitude: number
  /** Metres, as the device reports it; `null` when it doesn't. */
  accuracy: number | null
}

export type PositionFailure = 'unsupported' | 'denied' | 'timeout' | 'unavailable'

/** `null`: cancelled (or superseded by a newer request) — say nothing. */
export type PositionResult = { ok: true; position: DevicePosition } | { ok: false; reason: PositionFailure } | null

/** Some browsers never answer when a permission prompt is just dismissed. */
const WATCHDOG_MS = 60_000

/**
 * Reads the device's position once (`navigator.geolocation`, which needs
 * HTTPS or localhost) — chat's "Share my location" and a booking's meeting
 * pin. No tracking: nothing keeps running afterwards.
 *
 * `cancel()` (and unmounting the component) drops the request in flight, so
 * a permission prompt answered later never uses the position for something
 * the person has moved away from — `locate()` then resolves `null`.
 */
export function useCurrentPosition() {
  const locating = ref(false)
  let request = 0
  let watchdog: ReturnType<typeof setTimeout> | undefined
  /** Settles the request in flight with `null` when it's cancelled or superseded. */
  let dropPending: (() => void) | undefined

  function cancel() {
    request++
    clearTimeout(watchdog)
    locating.value = false
    dropPending?.()
    dropPending = undefined
  }

  function locate(): Promise<PositionResult> {
    if (!('geolocation' in navigator) || !window.isSecureContext) {
      return Promise.resolve({ ok: false, reason: 'unsupported' })
    }
    cancel()
    const mine = ++request
    locating.value = true

    return new Promise((resolve) => {
      dropPending = () => resolve(null)
      const finish = (result: Exclude<PositionResult, null>) => {
        if (mine !== request) return resolve(null)
        request++
        clearTimeout(watchdog)
        locating.value = false
        dropPending = undefined
        resolve(result)
      }
      watchdog = setTimeout(() => finish({ ok: false, reason: 'timeout' }), WATCHDOG_MS)
      navigator.geolocation.getCurrentPosition(
        ({ coords }) =>
          finish({
            ok: true,
            position: {
              latitude: coords.latitude,
              longitude: coords.longitude,
              accuracy: Number.isFinite(coords.accuracy) ? coords.accuracy : null,
            },
          }),
        (error) =>
          finish({
            ok: false,
            reason:
              error.code === error.PERMISSION_DENIED
                ? 'denied'
                : error.code === error.TIMEOUT
                  ? 'timeout'
                  : 'unavailable',
          }),
        { enableHighAccuracy: true, timeout: 15_000, maximumAge: 30_000 },
      )
    })
  }

  if (getCurrentInstance()) onBeforeUnmount(cancel)

  return { locating, locate, cancel }
}
