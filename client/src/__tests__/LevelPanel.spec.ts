import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import LevelPanel from '../components/LevelPanel.vue'
import { API_KEY, type Api, type LevelState, type PaymentState } from '@/plugins/api'
import { useMatchesStore } from '@/stores/matches'
import { useMessagesStore } from '@/stores/messages'
import { fakeApi } from './fakeApi'

function state(overrides: Partial<LevelState> = {}): LevelState {
  return {
    level: 2,
    name: 'explorer',
    trips_completed: 4,
    paid_trips: 4,
    points: 4,
    next_level_points: 6,
    next_level: 3,
    volunteer: false,
    sanction: null,
    picks_per_pack: 7,
    commission_rate: null,
    free_picks_until: null,
    no_fee_until: null,
    bonuses: [
      {
        id: 9,
        kind: 'free_picks',
        days: 3,
        reason: 'guest_level_2',
        state: 'available',
        starts_at: null,
        ends_at: null,
      },
    ],
    ...overrides,
  }
}

function mountPanel(api: Api, role: 'guest' | 'guide' = 'guest') {
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(LevelPanel, {
    props: { role },
    global: { plugins: [pinia], provide: { [API_KEY]: api } },
  })
}

describe('LevelPanel', () => {
  afterEach(() => vi.useRealTimers())

  it('shows the level, progress to the next, a guest’s pack size and an available bonus', async () => {
    const wrapper = mountPanel(
      fakeApi({ myLevel: vi.fn<Api['myLevel']>().mockResolvedValue(state()) }),
    )
    await flushPromises()

    expect(wrapper.text()).toContain('Level 2 · Explorer')
    expect(wrapper.text()).toContain('4 successful trips')
    expect(wrapper.text()).toContain(
      '2 more points to reach Level 3 · Pathfinder — every paid trip earns 1.',
    )
    expect(wrapper.get('[data-test="level-points"]').text()).toBe('4 level points')
    expect(wrapper.text()).toContain('7 picks in every £5 pack')
    const bonus = wrapper.find('[data-test="bonus-9"]')
    expect(bonus.text()).toContain('3 days of free picks')
    expect(bonus.text()).toContain('For reaching Level 2 · Explorer')
    expect(bonus.find('button').text()).toBe('Claim')
    expect(bonus.text()).toContain('For reaching Level 2 · Explorer')
  })

  it('shows only the badge until the "?" is hovered or clicked', async () => {
    const wrapper = mountPanel(
      fakeApi({ myLevel: vi.fn<Api['myLevel']>().mockResolvedValue(state()) }),
    )
    await flushPromises()
    // v-show; isVisible() would need the wrapper attached to the document.
    const shown = () =>
      (wrapper.find('[data-test="level-popover"]').element as HTMLElement).style.display !== 'none'
    const help = wrapper.find('[data-test="level-help"]')

    expect(wrapper.find('.level-badge').text()).toBe('◆ Level 2 · Explorer')
    expect(shown()).toBe(false)

    await help.element.parentElement!.dispatchEvent(
      Object.assign(new Event('pointerenter'), { pointerType: 'mouse' }),
    )
    await wrapper.vm.$nextTick()
    expect(shown()).toBe(true)
    expect(help.attributes('aria-expanded')).toBe('true')

    vi.useFakeTimers()
    help.element.parentElement!.dispatchEvent(
      Object.assign(new Event('pointerleave'), { pointerType: 'mouse' }),
    )
    vi.advanceTimersByTime(200)
    await wrapper.vm.$nextTick()
    expect(shown()).toBe(false)

    // Clicking pins it open (touch, keyboard); Escape closes it.
    await help.trigger('click')
    expect(shown()).toBe(true)
    await wrapper.find('[data-test="level-panel"]').trigger('keydown', { key: 'Escape' })
    expect(shown()).toBe(false)
  })

  it('scrolls itself into view when a notification linked to /profile#level', async () => {
    const scrollIntoView = vi.fn<(arg?: boolean | ScrollIntoViewOptions) => void>()
    Element.prototype.scrollIntoView = scrollIntoView
    history.replaceState(null, '', '#level')
    const wrapper = mountPanel(
      fakeApi({ myLevel: vi.fn<Api['myLevel']>().mockResolvedValue(state()) }),
    )
    await flushPromises()
    expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' })
    expect(
      (wrapper.find('[data-test="level-popover"]').element as HTMLElement).style.display,
    ).not.toBe('none')
    history.replaceState(null, '', location.pathname)
    Reflect.deleteProperty(Element.prototype, 'scrollIntoView')
  })

  it('names the level the next trips earn, not the sanctioned badge + 1', async () => {
    const sanctioned = state({
      level: 1,
      name: 'starter',
      next_level: 3,
      sanction: { levels: 1, until: new Date(Date.now() + 86400e3).toISOString(), reason: null },
    })
    const wrapper = mountPanel(
      fakeApi({ myLevel: vi.fn<Api['myLevel']>().mockResolvedValue(sanctioned) }),
    )
    await flushPromises()
    expect(wrapper.text()).toContain('to reach Level 3 · Pathfinder')
    // Bonuses are paused while sanctioned: no Claim button, and a note says why.
    expect(wrapper.find('[data-test="bonuses-paused"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="bonus-9"] button').exists()).toBe(false)
  })

  it('claims a bonus, shows it active and refreshes the visitor’s picks', async () => {
    const ends = new Date(Date.now() + 3 * 86400e3).toISOString()
    const claimed = state({
      free_picks_until: ends,
      bonuses: [
        {
          id: 9,
          kind: 'free_picks',
          days: 3,
          reason: 'level_2',
          state: 'active',
          starts_at: new Date().toISOString(),
          ends_at: ends,
        },
      ],
    })
    const payment: PaymentState = {
      packsUnlocked: 1,
      capacity: 5,
      picksUsed: 2,
      selectionsLeft: 3,
      pickedIds: [7, 8],
      passedIds: [],
      freePickIds: [],
      packSize: 7,
      freePicksUntil: ends,
    }
    const api = fakeApi({
      myLevel: vi.fn<Api['myLevel']>().mockResolvedValue(state()),
      claimBonus: vi.fn<Api['claimBonus']>().mockResolvedValue(claimed),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(payment),
    })
    const wrapper = mountPanel(api)
    await flushPromises()

    await wrapper.find('[data-test="bonus-9"] button').trigger('click')
    await flushPromises()

    expect(api.claimBonus).toHaveBeenCalledWith({ bonusId: 9 })
    expect(wrapper.find('[data-test="bonus-9"]').text()).toMatch(/Active until/)
    expect(useMessagesStore().items.map((i) => i.text)).toContain(
      'Free picks are on — pick as many guides as you like.',
    )
    expect(useMatchesStore().freePicksActive).toBe(true)
    expect(useMatchesStore().canPick).toBe(true)
  })

  it('shows a guide’s platform fee and a sanction lowering the level', async () => {
    const wrapper = mountPanel(
      fakeApi({
        myLevel: vi.fn<Api['myLevel']>().mockResolvedValue(
          state({
            level: 1,
            name: 'starter',
            picks_per_pack: null,
            commission_rate: 0.15,
            sanction: { levels: 1, until: null, reason: 'Asked a guest to pay outside the app' },
            bonuses: [],
          }),
        ),
      }),
      'guide',
    )
    await flushPromises()

    expect(wrapper.text()).toContain('Platform fee on your bookings: 15%')
    expect(wrapper.find('.sanction').text()).toContain('Your level is lowered by 1')
    expect(wrapper.find('.sanction').text()).toContain('Asked a guest to pay outside the app')
    expect(wrapper.text()).toContain('Reach level 4')
  })

  it('shows a guide who has only guided for free as a Volunteer, with fractional guide points to go', async () => {
    const wrapper = mountPanel(
      fakeApi({
        myLevel: vi.fn<Api['myLevel']>().mockResolvedValue(
          state({
            level: 0,
            name: 'new',
            trips_completed: 2,
            paid_trips: 0,
            points: 0,
            next_level_points: 1,
            next_level: 1,
            volunteer: true,
            picks_per_pack: null,
            commission_rate: 0.15,
            bonuses: [],
          }),
        ),
      }),
      'guide',
    )
    await flushPromises()

    expect(wrapper.get('[data-test="level-volunteer"]').text()).toContain('Volunteer')
    expect(wrapper.text()).toContain('2 successful trips')
    expect(wrapper.text()).toContain('Your first paid trip starts your level.')
    expect(wrapper.text()).toContain(
      '1 more points to reach Level 1 · Starter — every paid trip earns 1 to 1.5',
    )
  })
})
