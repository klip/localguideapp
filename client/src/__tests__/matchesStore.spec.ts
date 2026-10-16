import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useMatchesStore } from '@/stores/matches'
import type { PaymentState } from '@/plugins/api'

function payment(overrides: Partial<PaymentState> = {}): PaymentState {
  return { packsUnlocked: 1, capacity: 6, picksUsed: 0, selectionsLeft: 6, pickedIds: [], passedIds: [], ...overrides }
}

describe('matches store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })
  afterEach(() => vi.useRealTimers())

  it('turns free picks off by itself when the bonus ends, without a reload', async () => {
    vi.useFakeTimers()
    const store = useMatchesStore()
    store.applyPaymentState(payment({ capacity: 0, packsUnlocked: 0, freePicksUntil: new Date(Date.now() + 60e3).toISOString() }))
    await Promise.resolve()
    expect(store.freePicksActive).toBe(true)
    expect(store.canPick).toBe(true)

    vi.advanceTimersByTime(61e3)
    expect(store.freePicksActive).toBe(false)
    expect(store.canPick).toBe(false)
  })

  it('takes capacity and the next pack’s size from the server (they grow with the level)', () => {
    const store = useMatchesStore()
    store.applyPaymentState(payment({ capacity: 11, packSize: 7, pickedIds: [1, 2] }))
    expect(store.capacity).toBe(11)
    expect(store.packSize).toBe(7)
    expect(store.selectionsLeft).toBe(9)
  })

  it('doesn’t count free picks against the pack, and lets a visitor pick freely while the bonus runs', () => {
    const store = useMatchesStore()
    const until = new Date(Date.now() + 86400e3).toISOString()
    store.applyPaymentState(payment({ capacity: 5, pickedIds: [1, 2, 3, 4, 5], freePickIds: [4, 5], freePicksUntil: until }))
    expect(store.picksUsed).toBe(3)
    expect(store.selectionsLeft).toBe(2)

    store.pick('9')
    expect(store.freePicks).toContain('9')
    expect(store.picksUsed).toBe(3)
    expect(store.canPick).toBe(true)
  })

  it('stops free picks once the bonus has ended', () => {
    const store = useMatchesStore()
    store.applyPaymentState(payment({ capacity: 5, pickedIds: [1, 2, 3, 4, 5], freePicksUntil: new Date(Date.now() - 1000).toISOString() }))
    expect(store.freePicksActive).toBe(false)
    expect(store.canPick).toBe(false)
  })
})
