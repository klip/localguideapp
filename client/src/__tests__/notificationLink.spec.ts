import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MessageToaster from '../components/MessageToaster.vue'
import type { AppNotification } from '@/plugins/api'
import { useMessagesStore } from '@/stores/messages'
import { notificationLink } from '@/utils/notificationLink'

const note = (kind: string, about: number | null = null): AppNotification => ({
  id: 1,
  kind,
  about_user_id: about,
  message: 'x',
  created_at: '2026-10-16 10:00:00',
})

describe('notificationLink', () => {
  it('sends waiting requests to Matches and anything about a trip to My trips', () => {
    expect(notificationLink(note('booking_requested'), 'guide')).toBe('/matches')
    expect(notificationLink(note('booking_declined'), 'guest')).toBe('/matches')
    expect(notificationLink(note('request_location'), 'guide')).toBe('/matches')
    expect(notificationLink(note('booking_accepted'), 'guest')).toBe('/trips')
    expect(notificationLink(note('cancel_requested'), 'guide')).toBe('/trips')
    expect(notificationLink(note('trip_location'), 'guest')).toBe('/trips')
  })

  it('opens the chat for a new match and the profile of someone who updated theirs', () => {
    expect(notificationLink(note('match', 101), 'guest')).toBe('/messages/101')
    expect(notificationLink(note('profile_updated', 101), 'guest')).toBe('/guides/101')
    expect(notificationLink(note('profile_updated', 102), 'guide')).toBe('/visitors/102')
    // Rows from before 043 name nobody.
    expect(notificationLink(note('match'), 'guest')).toBe('/matches')
  })

  it('opens "Your level" for levels and bonuses, and nothing for an unknown kind', () => {
    expect(notificationLink(note('level_up'), 'guest')).toBe('/profile#level')
    expect(notificationLink(note('bonus_granted'), 'guide')).toBe('/profile#level')
    expect(notificationLink(note('something_new'), 'guest')).toBeNull()
  })
})

describe('MessageToaster links', () => {
  it('renders a linked toast as a link that closes it when followed', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const messages = useMessagesStore()
    messages.info('Gina accepted your booking', undefined, 0, undefined, '/trips')
    messages.info('Saved', undefined, 0)

    const wrapper = mount(MessageToaster, {
      global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
    })
    await flushPromises()

    const links = wrapper.findAllComponents(RouterLinkStub)
    expect(links).toHaveLength(1)
    expect(links[0]!.props('to')).toBe('/trips')
    expect(links[0]!.text()).toContain('Gina accepted your booking')

    await links[0]!.trigger('click')
    expect(messages.items.map((item) => item.text)).toEqual(['Saved'])
  })

  afterEach(() => vi.useRealTimers())

  it('stays open while hovered or focused, then runs out', async () => {
    vi.useFakeTimers()
    const pinia = createPinia()
    setActivePinia(pinia)
    const messages = useMessagesStore()
    messages.info('Gina accepted your booking', undefined, 10_000, undefined, '/trips')
    const wrapper = mount(MessageToaster, {
      global: { plugins: [pinia], stubs: { RouterLink: RouterLinkStub } },
    })
    await flushPromises()

    const toast = wrapper.find('.toast')
    await toast.trigger('mouseenter')
    await toast.trigger('focusin')
    vi.advanceTimersByTime(30_000)
    expect(messages.items).toHaveLength(1)

    await toast.trigger('mouseleave')
    vi.advanceTimersByTime(30_000)
    expect(messages.items).toHaveLength(1) // still focused

    await toast.trigger('focusout')
    vi.advanceTimersByTime(10_000)
    expect(messages.items).toHaveLength(0)
  })
})
