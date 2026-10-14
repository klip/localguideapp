import { afterEach, describe, it, expect, vi } from 'vitest'
import { ApiError, type Api, type PaymentState, type SelectionRow } from '@/plugins/api'
import { addPendingReject, readPendingRejects, syncPendingRejects } from '@/utils/pendingRejects'

/** Only the two methods the sync uses; the rest are never touched. */
function fakeApi(state: Partial<PaymentState>, decide: Api['decide']): Api {
  return {
    paymentState: vi.fn<Api['paymentState']>().mockResolvedValue({
      packsUnlocked: 0,
      capacity: 0,
      picksUsed: 0,
      selectionsLeft: 0,
      pickedIds: [],
      passedIds: [],
      ...state,
    }),
    decide: vi.fn<Api['decide']>(decide),
  } as unknown as Api
}

const ok: Api['decide'] = async () => ({}) as SelectionRow

afterEach(() => localStorage.clear())

describe('pendingRejects', () => {
  it('stores each reject once', () => {
    addPendingReject('3')
    addPendingReject('3')
    addPendingReject('4')
    expect(readPendingRejects()).toEqual(['3', '4'])
  })

  it('sends every reject as a pass for a guest, then clears them', async () => {
    addPendingReject('3')
    addPendingReject('4')
    const api = fakeApi({}, ok)

    await syncPendingRejects(api, 'guest')

    expect(api.decide).toHaveBeenCalledWith({ targetId: 3, decision: 'pass' })
    expect(api.decide).toHaveBeenCalledWith({ targetId: 4, decision: 'pass' })
    expect(readPendingRejects()).toEqual([])
  })

  it("doesn't overwrite a decision the guest already made on the server", async () => {
    addPendingReject('3')
    addPendingReject('4')
    const api = fakeApi({ pickedIds: [3] }, ok)

    await syncPendingRejects(api, 'guest')

    expect(api.decide).toHaveBeenCalledTimes(1)
    expect(api.decide).toHaveBeenCalledWith({ targetId: 4, decision: 'pass' })
    expect(readPendingRejects()).toEqual([])
  })

  it('keeps a reject that failed on the network, drops one the server refused', async () => {
    addPendingReject('3')
    addPendingReject('4')
    const api = fakeApi({}, async ({ targetId }) => {
      throw targetId === 3 ? new ApiError('Network error', 0) : new ApiError('Not a guide.', 400)
    })

    await syncPendingRejects(api, 'guest')

    expect(readPendingRejects()).toEqual(['3'])
  })

  it('discards them for a guide, and leaves them alone while signed out', async () => {
    addPendingReject('3')
    const api = fakeApi({}, ok)

    await syncPendingRejects(api, undefined)
    expect(readPendingRejects()).toEqual(['3'])

    await syncPendingRejects(api, 'guide')
    expect(readPendingRejects()).toEqual([])
    expect(api.decide).not.toHaveBeenCalled()
  })
})
