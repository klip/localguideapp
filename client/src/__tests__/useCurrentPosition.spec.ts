import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useCurrentPosition } from '@/composables/useCurrentPosition'

type Success = Parameters<Geolocation['getCurrentPosition']>[0]

describe('useCurrentPosition', () => {
  let answers: Success[]

  beforeEach(() => {
    answers = []
    // A browser that answers only when the test says so.
    const getCurrentPosition = vi.fn<Geolocation['getCurrentPosition']>((success) => {
      answers.push(success)
    })
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition },
    })
    vi.stubGlobal('isSecureContext', true)
  })

  afterEach(() => {
    Reflect.deleteProperty(navigator, 'geolocation')
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  const at = {
    coords: { latitude: 36.14, longitude: -5.35, accuracy: Number.NaN },
  } as GeolocationPosition

  it('resolves the position, with no accuracy when the device gives none', async () => {
    const position = useCurrentPosition()
    const result = position.locate()
    expect(position.locating.value).toBe(true)
    answers[0]!(at)
    await expect(result).resolves.toEqual({
      ok: true,
      position: { latitude: 36.14, longitude: -5.35, accuracy: null },
    })
    expect(position.locating.value).toBe(false)
  })

  it('settles a cancelled request with null, and ignores the late answer', async () => {
    const position = useCurrentPosition()
    const result = position.locate()
    position.cancel()
    await expect(result).resolves.toBeNull()
    answers[0]!(at)
    expect(position.locating.value).toBe(false)
  })

  it('settles a superseded request with null; the newer one gets the answer', async () => {
    const position = useCurrentPosition()
    const first = position.locate()
    const second = position.locate()
    await expect(first).resolves.toBeNull()
    answers[0]!(at)
    answers[1]!(at)
    await expect(second).resolves.toMatchObject({ ok: true })
  })

  it('gives up after 60 s when the browser never answers', async () => {
    vi.useFakeTimers()
    const position = useCurrentPosition()
    const result = position.locate()
    vi.advanceTimersByTime(60_000)
    await expect(result).resolves.toEqual({ ok: false, reason: 'timeout' })
    expect(position.locating.value).toBe(false)
  })
})
