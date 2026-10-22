import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PaymentsPage from '../pages/PaymentsPage.vue'
import { API_KEY, type Api, type PaymentHistory } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { fakeApi } from './fakeApi'

const future = new Date(Date.now() + 86_400_000).toISOString()

function guestHistory(): PaymentHistory {
  return {
    role: 'guest',
    picks: {
      packsUnlocked: 1,
      capacity: 5,
      picksUsed: 2,
      selectionsLeft: 3,
      packSize: 6,
      freePicksUntil: future,
      freePicksUsed: 1,
    },
    entries: [
      {
        type: 'booking',
        id: 468,
        at: '2026-10-21T17:58:36Z',
        amount: 80,
        status: 'cancelled',
        tripStatus: 'pending',
        meetingAt: '2026-10-29T08:00:00Z',
        counterpart: { id: 137, name: 'Augusta' },
        refundAmount: 76,
        refundedAt: '2026-10-21T18:47:32Z',
        commissionAmount: null,
        commissionRate: null,
        payout: null,
      },
      { type: 'pack', id: 104, at: '2026-10-21T17:50:46Z', amount: 5, packSize: 5, status: 'paid' },
    ],
    bonuses: [
      {
        id: 68,
        kind: 'free_picks',
        days: 1,
        reason: 'staff_5a43',
        state: 'active',
        grantedAt: '2026-10-21T18:10:19Z',
        startsAt: '2026-10-21T18:10:19Z',
        endsAt: future,
        revokedAt: null,
      },
      {
        id: 69,
        kind: 'free_picks',
        days: 3,
        reason: 'guest_level_2',
        state: 'available',
        grantedAt: '2026-10-20T10:00:00Z',
        startsAt: null,
        endsAt: null,
        revokedAt: null,
      },
    ],
  }
}

function mountPage(api: Api, role: 'guest' | 'guide' = 'guest') {
  setActivePinia(createPinia())
  useAuthStore().setUser({ id: 1, email: 'me@example.test', role, roles: [role] })
  return mount(PaymentsPage, {
    global: { provide: { [API_KEY]: api }, stubs: { RouterLink: RouterLinkStub } },
  })
}

afterEach(() => {
  localStorage.clear()
})

describe('PaymentsPage', () => {
  it('shows a traveller their picks, bonuses and payment history', async () => {
    const api = fakeApi({
      paymentHistory: vi.fn<Api['paymentHistory']>().mockResolvedValue(guestHistory()),
    })
    const wrapper = mountPage(api)
    await flushPromises()

    expect(wrapper.get('[data-test="picks-left"]').text()).toBe('3')
    expect(wrapper.find('[data-test="free-picks"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Picks made with free picks: 1')
    expect(wrapper.get('[data-test="buy-picks"]').text()).toContain('Buy 6 picks')
    expect(wrapper.find('[data-bonus="active"]').text()).toContain(
      'A gift from the BeLikeLocal team',
    )
    expect(wrapper.get('[data-test="totals"]').text()).toContain('Paid in total: £85.00')
    expect(wrapper.text()).toContain('Fee for Augusta')
    expect(wrapper.text()).toContain('Refunded £76.00')
    expect(wrapper.text()).toContain('Pack of 5 picks')
  })

  it('starts a bonus that is ready, then reloads', async () => {
    const api = fakeApi({
      paymentHistory: vi.fn<Api['paymentHistory']>().mockResolvedValue(guestHistory()),
      claimBonus: vi.fn<Api['claimBonus']>().mockResolvedValue({} as never),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue({
        packsUnlocked: 1,
        capacity: 5,
        picksUsed: 2,
        selectionsLeft: 3,
        pickedIds: [],
        passedIds: [],
        freePickIds: [],
        packSize: 6,
        freePicksUntil: future,
      }),
    })
    const wrapper = mountPage(api)
    await flushPromises()

    await wrapper.get('[data-bonus="available"] button').trigger('click')
    await flushPromises()
    expect(api.claimBonus).toHaveBeenCalledWith({ bonusId: 69 })
    expect(api.paymentHistory).toHaveBeenCalledTimes(2)
  })

  it('shows a guide what travellers paid, the platform fee and the payout', async () => {
    const api = fakeApi({
      paymentHistory: vi.fn<Api['paymentHistory']>().mockResolvedValue({
        role: 'guide',
        picks: null,
        bonuses: [],
        entries: [
          {
            type: 'booking',
            id: 9,
            at: '2026-10-21T10:00:00Z',
            amount: 50,
            status: 'confirmed',
            tripStatus: 'finished',
            meetingAt: '2026-10-22T10:00:00Z',
            counterpart: { id: 4, name: 'Ryan' },
            refundAmount: null,
            refundedAt: null,
            commissionAmount: 7.5,
            commissionRate: 0.15,
            payout: 42.5,
          },
        ],
      }),
    })
    const wrapper = mountPage(api, 'guide')
    await flushPromises()

    expect(wrapper.find('[data-test="picks"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Fee from Ryan')
    expect(wrapper.text()).toContain('Platform fee £7.50 (15%) · you receive £42.50')
    expect(wrapper.get('[data-test="totals"]').text()).toContain('You received: £42.50')
    expect(wrapper.text()).toContain('+£50.00')
    expect(wrapper.text()).toContain('Guide levels 4 and 6')
  })
})
