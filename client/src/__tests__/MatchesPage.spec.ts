import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import MatchesPage from '../pages/MatchesPage.vue'
import { API_KEY, type Api, type MatchOverviewRow, type PaymentState, type SelectionRow } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { useShortlistStore } from '@/stores/shortlist'

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/matches', name: 'matches', component: MatchesPage },
      { path: '/:pathMatch(.*)*', name: 'other', component: { template: '<div />' } },
    ],
  })
}

/** A stub `Api` — every method is a no-op `vi.fn()` unless overridden. */
function fakeApi(overrides: Partial<Api> = {}): Api {
  return {
    register: vi.fn<Api['register']>(),
    login: vi.fn<Api['login']>(),
    guides: vi.fn<Api['guides']>(),
    visitors: vi.fn<Api['visitors']>(),
    updateProfile: vi.fn<Api['updateProfile']>(),
    attributeCategories: vi.fn<Api['attributeCategories']>(),
    myProfile: vi.fn<Api['myProfile']>(),
    updateAccount: vi.fn<Api['updateAccount']>(),
    changePassword: vi.fn<Api['changePassword']>(),
    logout: vi.fn<Api['logout']>(),
    session: vi.fn<Api['session']>(),
    unlockPack: vi.fn<Api['unlockPack']>(),
    paymentState: vi.fn<Api['paymentState']>(),
    decide: vi.fn<Api['decide']>(),
    revokeSelection: vi.fn<Api['revokeSelection']>(),
    matches: vi.fn<Api['matches']>(),
    matchOverview: vi.fn<Api['matchOverview']>(),
    ...overrides,
  }
}

function row(overrides: Partial<MatchOverviewRow>): MatchOverviewRow {
  return {
    id: 1,
    counterpart_id: 100,
    my_decision: null,
    their_decision: null,
    active: 0,
    updated_at: '2026-09-10 12:00:00',
    name: 'Someone',
    image: null,
    email: null,
    phone: null,
    age: null,
    headline: null,
    price_label: null,
    party: null,
    duration_hours: null,
    ...overrides,
  }
}

const matched = row({
  id: 1,
  counterpart_id: 7,
  name: 'Igor',
  my_decision: 'interested',
  their_decision: 'interested',
  active: 1,
  email: 'igor@example.com',
  phone: '+350 56002731',
})
const incoming = row({ id: 2, counterpart_id: 8, name: 'Ana', their_decision: 'interested' })
const awaiting = row({ id: 3, counterpart_id: 9, name: 'Ben', my_decision: 'interested' })
const passed = row({ id: 4, counterpart_id: 10, name: 'Cy', my_decision: 'pass' })

function selectionRow(overrides: Partial<SelectionRow> = {}): SelectionRow {
  return {
    id: 2,
    guest_id: 1,
    guide_id: 8,
    guest_decision: null,
    guide_decision: null,
    active: 0,
    created_at: '',
    updated_at: '',
    ...overrides,
  }
}

function paymentState(selectionsLeft: number): PaymentState {
  return { packsUnlocked: 1, capacity: 5, picksUsed: 5 - selectionsLeft, selectionsLeft }
}

async function mountPage(api: Api, role: 'guest' | 'guide' | null) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (role) useAuthStore().setUser({ id: 1, email: 'me@example.com', role, sessionHash: 'hash' })

  const router = testRouter()
  router.push('/matches')
  await router.isReady()

  const wrapper = mount(MatchesPage, {
    global: { plugins: [pinia, router], provide: { [API_KEY]: api } },
  })
  await flushPromises()
  return wrapper
}

function tab(wrapper: Awaited<ReturnType<typeof mountPage>>, label: string) {
  const found = wrapper.findAll('.tab').find((button) => button.text().startsWith(label))
  if (!found) throw new Error(`No tab labelled ${label}`)
  return found
}

function button(wrapper: Awaited<ReturnType<typeof mountPage>>, label: string) {
  const found = wrapper.findAll('.actions button').find((candidate) => candidate.text().includes(label))
  if (!found) throw new Error(`No button labelled ${label}`)
  return found
}

afterEach(() => {
  localStorage.clear()
})

describe('MatchesPage', () => {
  it('groups every pair into tabs and shows contact details only on the matched tab', async () => {
    const api = fakeApi({
      matchOverview: vi.fn<Api['matchOverview']>().mockResolvedValue({
        selections: [matched, incoming, awaiting, passed],
      }),
    })
    const wrapper = await mountPage(api, 'guide')

    expect(api.paymentState).not.toHaveBeenCalled() // guides never pay
    expect(tab(wrapper, 'Matched').find('.tab-count').text()).toBe('1')
    expect(tab(wrapper, 'Likes you').find('.tab-count').text()).toBe('1')
    expect(tab(wrapper, 'Awaiting reply').find('.tab-count').text()).toBe('1')
    expect(tab(wrapper, 'Passed').find('.tab-count').text()).toBe('1')

    // Opens on Matched when there's a match.
    expect(tab(wrapper, 'Matched').attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('a[href="mailto:igor@example.com"]').exists()).toBe(true)
    expect(wrapper.find('a[href="tel:+35056002731"]').exists()).toBe(true)

    await tab(wrapper, 'Likes you').trigger('click')
    expect(wrapper.text()).toContain('Ana')
    expect(wrapper.find('a[href^="mailto:"]').exists()).toBe(false)
  })

  it('opens on "Likes you" when there are no matches yet but someone is waiting on an answer', async () => {
    const api = fakeApi({
      matchOverview: vi.fn<Api['matchOverview']>().mockResolvedValue({ selections: [incoming, awaiting] }),
    })
    const wrapper = await mountPage(api, 'guide')

    expect(tab(wrapper, 'Likes you').attributes('aria-pressed')).toBe('true')
  })

  it('lets a guest accept an incoming like, then reloads and confirms the match', async () => {
    const matchOverview = vi
      .fn<Api['matchOverview']>()
      .mockResolvedValueOnce({ selections: [incoming] })
      .mockResolvedValueOnce({
        selections: [{ ...incoming, my_decision: 'interested', active: 1, email: 'ana@example.com' }],
      })
    const decide = vi
      .fn<Api['decide']>()
      .mockResolvedValue(selectionRow({ guest_decision: 'interested', guide_decision: 'interested', active: 1 }))
    const api = fakeApi({
      matchOverview,
      decide,
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState(3)),
    })
    const wrapper = await mountPage(api, 'guest')

    expect(wrapper.text()).toContain('3 selections left')
    await button(wrapper, 'Accept').trigger('click')
    await flushPromises()

    expect(decide).toHaveBeenCalledWith({ targetId: 8, decision: 'interested' })
    expect(matchOverview).toHaveBeenCalledTimes(2)
    expect(useShortlistStore().isPicked('8')).toBe(true)
    expect(useMessagesStore().items.map((item) => item.text).join(' ')).toContain('now matched with Ana')
  })

  it('offers an unlock link instead of Accept when a guest is out of selections', async () => {
    const api = fakeApi({
      matchOverview: vi.fn<Api['matchOverview']>().mockResolvedValue({ selections: [incoming] }),
      paymentState: vi.fn<Api['paymentState']>().mockResolvedValue(paymentState(0)),
    })
    const wrapper = await mountPage(api, 'guest')

    expect(wrapper.findAll('.actions button').some((candidate) => candidate.text().includes('Accept'))).toBe(false)
    expect(wrapper.find('.actions a[href="/unlock"]').text()).toContain('Unlock to accept')
  })

  it('withdraws interest from someone who has not answered yet', async () => {
    const revokeSelection = vi.fn<Api['revokeSelection']>().mockResolvedValue(selectionRow())
    const api = fakeApi({
      matchOverview: vi
        .fn<Api['matchOverview']>()
        .mockResolvedValueOnce({ selections: [awaiting] })
        .mockResolvedValueOnce({ selections: [] }),
      revokeSelection,
    })
    const wrapper = await mountPage(api, 'guide')

    await tab(wrapper, 'Awaiting reply').trigger('click')
    await button(wrapper, 'Withdraw').trigger('click')
    await flushPromises()

    expect(revokeSelection).toHaveBeenCalledWith({ targetId: 9 })
    expect(tab(wrapper, 'Awaiting reply').find('.tab-count').text()).toBe('0')
  })

  it('asks a signed-out visitor to log in without calling the API', async () => {
    const api = fakeApi()
    const wrapper = await mountPage(api, null)

    expect(api.matchOverview).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Log in to see your matches')
  })
})
