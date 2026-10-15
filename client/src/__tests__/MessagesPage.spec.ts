import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import MessagesPage from '../pages/MessagesPage.vue'
import { API_KEY, ApiError, type Api, type ChatMessage, type ChatThread, type Conversation } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { fakeApi } from './fakeApi'

/** Stands in for the API socket so a test can fire `message` pushes. */
const fakeSocket = vi.hoisted(() => {
  const handlers = new Map<string, (message: Record<string, unknown>) => void>()
  return {
    on: (type: string, handler: (message: Record<string, unknown>) => void) => {
      handlers.set(type, handler)
      return () => handlers.delete(type)
    },
    push: (type: string, message: Record<string, unknown>) => handlers.get(type)?.({ type, ...message }),
  }
})
vi.mock('@/plugins/socket', () => ({ socket: fakeSocket }))

function message(overrides: Partial<ChatMessage> = {}): ChatMessage {
  return {
    id: 1,
    sender_id: 101,
    recipient_id: 7,
    mine: false,
    body: 'Hi! Happy to show you the Rock.',
    created_at: '2026-10-15T10:00:00Z',
    read_at: null,
    ...overrides,
  }
}

const conversation: Conversation = {
  counterpart_id: 101,
  name: 'Gina',
  image: null,
  role: 'guide',
  can_send: true,
  unread: 2,
  last_message: { id: 1, mine: false, body: 'Hi! Happy to show you the Rock.', created_at: '2026-10-15T10:00:00Z' },
}

function thread(overrides: Partial<ChatThread> = {}): ChatThread {
  return {
    counterpart: { id: 101, name: 'Gina', image: null, role: 'guide' },
    can_send: true,
    has_more: false,
    messages: [message()],
    ...overrides,
  }
}

async function mountPage(api: Api, path = '/messages/101') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().setUser({ id: 7, email: 'v@example.com', name: 'Victor', role: 'guest', sessionHash: 'h' })

  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/messages/:id?', name: 'messages', component: MessagesPage, props: true },
      { path: '/:pathMatch(.*)*', name: 'other', component: { template: '<div />' } },
    ],
  })
  router.push(path)
  await router.isReady()

  const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [pinia, router], provide: { [API_KEY]: api } } })
  await flushPromises()
  return { wrapper, router }
}

describe('MessagesPage', () => {
  afterEach(() => {
    localStorage.clear()
    vi.unstubAllGlobals()
  })

  it('treats Enter as a new line on a touchscreen (no fine pointer)', async () => {
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [conversation] }),
      messages: vi.fn<Api['messages']>().mockResolvedValue(thread()),
    })
    const { wrapper } = await mountPage(api)

    await wrapper.find('textarea').setValue('Line one')
    await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
    expect(api.sendMessage).not.toHaveBeenCalled()
    expect(wrapper.find('#composer-hint').exists()).toBe(false)
  })

  it('keeps a message pushed while the conversation loads, and drops a stale load after switching', async () => {
    let resolveFirst: (value: ChatThread) => void = () => {}
    const messages = vi
      .fn<Api['messages']>()
      .mockImplementationOnce(() => new Promise((resolve) => (resolveFirst = resolve)))
      .mockResolvedValueOnce(thread({ counterpart: { id: 202, name: 'Bea', image: null, role: 'guide' }, messages: [] }))
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [conversation] }),
      messages,
    })
    const { wrapper, router } = await mountPage(api)

    // A push for the conversation that's still loading is held, not lost.
    fakeSocket.push('message', { message: message({ id: 9, body: 'Arrived mid-load' }) })
    // Switch away before the first load answers; its late answer must not show.
    await router.push('/messages/202')
    await flushPromises()
    resolveFirst(thread())
    await flushPromises()

    expect(wrapper.find('.thread-head').text()).toContain('Bea')
    expect(wrapper.text()).not.toContain('Arrived mid-load')

    // Back on the first conversation, a held push for it would merge in by id.
    messages.mockResolvedValueOnce(thread())
    await router.push('/messages/101')
    fakeSocket.push('message', { message: message({ id: 10, body: 'While loading again' }) })
    await flushPromises()
    expect(wrapper.findAll('.bubble').map((b) => b.text())).toEqual([
      expect.stringContaining('Happy to show you the Rock.'),
      expect.stringContaining('While loading again'),
    ])
  })

  it('loads earlier messages before the oldest one shown', async () => {
    const older = message({ id: 0, body: 'The very first message', created_at: '2026-10-14T09:00:00Z' })
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [conversation] }),
      messages: vi
        .fn<Api['messages']>()
        .mockResolvedValueOnce(thread({ has_more: true }))
        .mockResolvedValueOnce(thread({ has_more: false, messages: [older] })),
    })
    const { wrapper } = await mountPage(api)

    await wrapper.find('button.older').trigger('click')
    await flushPromises()

    expect(api.messages).toHaveBeenLastCalledWith({ targetId: 101, beforeId: 1 })
    expect(wrapper.findAll('.bubble')[0]!.text()).toContain('The very first message')
    expect(wrapper.find('button.older').exists()).toBe(false)
  })

  it('moves "Seen" when the other side reads, and patches the list from pushes without refetching', async () => {
    const mine = message({ id: 2, sender_id: 7, recipient_id: 101, mine: true, body: 'Ten works?' })
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [{ ...conversation }] }),
      messages: vi.fn<Api['messages']>().mockResolvedValue(thread({ messages: [message(), mine] })),
    })
    const { wrapper } = await mountPage(api)
    expect(wrapper.text()).not.toContain('Seen')

    fakeSocket.push('messages_read', { reader_id: 101, up_to_id: 2, read_at: '2026-10-15T10:05:00Z' })
    await flushPromises()
    expect(wrapper.find('.bubble-row--mine').text()).toContain('Seen')

  })

  it('patches the list from pushes — preview, unread count, order — without refetching', async () => {
    const other: Conversation = { ...conversation, counterpart_id: 202, name: 'Bea', unread: 0, last_message: null }
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [{ ...conversation }, other] }),
    })
    const { wrapper } = await mountPage(api, '/messages')

    fakeSocket.push('message', { message: message({ id: 3, sender_id: 202, body: 'Hi from Bea' }) })
    await flushPromises()

    expect(api.conversations).toHaveBeenCalledTimes(1)
    const rows = wrapper.findAll('.conversation')
    expect(rows[0]!.text()).toContain('Bea')
    expect(rows[0]!.find('.preview').text()).toBe('Hi from Bea')
    expect(rows[0]!.find('[data-test="unread"]').text()).toContain('1')
  })

  it('reloads the conversation when a send is refused, showing it read-only', async () => {
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [conversation] }),
      messages: vi
        .fn<Api['messages']>()
        .mockResolvedValueOnce(thread())
        .mockResolvedValueOnce(thread({ can_send: false })),
      sendMessage: vi
        .fn<Api['sendMessage']>()
        .mockRejectedValue(new ApiError('You can only message someone you’re matched with.', 400)),
    })
    const { wrapper } = await mountPage(api)

    await wrapper.find('textarea').setValue('Still there?')
    await wrapper.find('form.composer').trigger('submit.prevent')
    await flushPromises()

    expect(api.messages).toHaveBeenCalledTimes(2)
    expect(wrapper.find('textarea').exists()).toBe(false)
    expect(wrapper.find('.closed').exists()).toBe(true)
  })

  it('opens the conversation from the URL, clears its unread count and shows the stay-on-platform banner', async () => {
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [{ ...conversation }] }),
      messages: vi.fn<Api['messages']>().mockResolvedValue(thread()),
    })
    const { wrapper } = await mountPage(api)

    expect(api.messages).toHaveBeenCalledWith({ targetId: 101 })
    expect(wrapper.find('.bubble').text()).toContain('Happy to show you the Rock.')
    expect(wrapper.find('.stay-banner').text()).toMatch(/Keep the conversation on RockGuide/)
    expect(wrapper.find('[data-test="unread"]').exists()).toBe(false)
  })

  it('sends with Enter (not Shift+Enter) and appends the stored message', async () => {
    // A mouse/keyboard device: Enter sends (on a touchscreen it's a new line).
    vi.stubGlobal('matchMedia', (query: string) => ({
      matches: query === '(pointer: fine)',
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
    const sent = message({ id: 2, sender_id: 7, recipient_id: 101, mine: true, body: 'Call me on [•••]' })
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [conversation] }),
      messages: vi.fn<Api['messages']>().mockResolvedValue(thread()),
      sendMessage: vi.fn<Api['sendMessage']>().mockResolvedValue(sent),
    })
    const { wrapper } = await mountPage(api)

    const composer = wrapper.find('textarea')
    await composer.setValue('Call me on 56002731')
    await composer.trigger('keydown', { key: 'Enter', shiftKey: true })
    expect(api.sendMessage).not.toHaveBeenCalled()

    await composer.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(api.sendMessage).toHaveBeenCalledWith({ targetId: 101, body: 'Call me on 56002731' })
    const bubbles = wrapper.findAll('.bubble-row--mine')
    expect(bubbles[bubbles.length - 1]!.text()).toContain('Call me on [•••]')
    expect((composer.element as HTMLTextAreaElement).value).toBe('')
  })

  it('appends a pushed message for the open conversation once, and marks it read', async () => {
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [conversation] }),
      messages: vi.fn<Api['messages']>().mockResolvedValue(thread()),
    })
    const { wrapper } = await mountPage(api)

    const incoming = message({ id: 3, body: 'See you at 10?' })
    fakeSocket.push('message', { message: incoming })
    fakeSocket.push('message', { message: incoming })
    // Someone else's message isn't added to this conversation.
    fakeSocket.push('message', { message: message({ id: 4, sender_id: 555, body: 'Other chat' }) })
    await flushPromises()

    expect(wrapper.findAll('.bubble').map((b) => b.text())).toEqual([
      expect.stringContaining('Happy to show you the Rock.'),
      expect.stringContaining('See you at 10?'),
    ])
    expect(api.markMessagesRead).toHaveBeenCalledWith({ targetId: 101, upToId: 3 })
  })

  it('leaves a message that arrives in a hidden tab unread until the tab is shown', async () => {
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [conversation] }),
      messages: vi.fn<Api['messages']>().mockResolvedValue(thread()),
    })
    await mountPage(api)
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')

    fakeSocket.push('message', { message: message({ id: 5, body: 'Are you there?' }) })
    await flushPromises()
    expect(api.markMessagesRead).not.toHaveBeenCalled()

    visibility.mockReturnValue('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    expect(api.markMessagesRead).toHaveBeenCalledWith({ targetId: 101, upToId: 5 })
    visibility.mockRestore()
  })

  it('shows a read-only note instead of the composer once the match is gone', async () => {
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [{ ...conversation, can_send: false }] }),
      messages: vi.fn<Api['messages']>().mockResolvedValue(thread({ can_send: false })),
    })
    const { wrapper } = await mountPage(api)

    expect(wrapper.find('textarea').exists()).toBe(false)
    expect(wrapper.find('.closed').text()).toMatch(/read-only/)
  })

  it('lists conversations on /messages and offers Matches when there are none', async () => {
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [] }),
    })
    const { wrapper } = await mountPage(api, '/messages')

    expect(api.messages).not.toHaveBeenCalled()
    expect(wrapper.text()).toMatch(/No conversations yet/)
    expect(wrapper.find('a[href="/matches"]').exists()).toBe(true)
  })

  it('reports a failed send and keeps the draft', async () => {
    const api = fakeApi({
      conversations: vi.fn<Api['conversations']>().mockResolvedValue({ conversations: [conversation] }),
      messages: vi.fn<Api['messages']>().mockResolvedValue(thread()),
      sendMessage: vi.fn<Api['sendMessage']>().mockRejectedValue(new Error('offline')),
    })
    const { wrapper } = await mountPage(api)

    await wrapper.find('textarea').setValue('Hello?')
    await wrapper.find('form.composer').trigger('submit.prevent')
    await flushPromises()

    expect(useMessagesStore().items.map((item) => item.text)).toContain('Your message wasn’t sent. Please try again.')
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('Hello?')
  })
})
