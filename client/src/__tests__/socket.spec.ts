import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiSocket, SocketError } from '@/plugins/socket'
import { FakeWebSocket } from './fakeWebSocket'

function makeSocket() {
  FakeWebSocket.instances = []
  return new ApiSocket('ws://test/ws', (url) => new FakeWebSocket(url) as unknown as WebSocket)
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('plugins/socket ApiSocket', () => {
  afterEach(() => {
    vi.useRealTimers()
    FakeWebSocket.respond = () => ({ status: 200, body: { ok: true } })
  })

  it('stays disconnected until there is a request or a session', () => {
    const socket = makeSocket()
    socket.identify(null)
    expect(FakeWebSocket.instances).toHaveLength(0)
  })

  it('matches responses to requests by id', async () => {
    const socket = makeSocket()
    FakeWebSocket.respond = (request) => ({ status: 200, body: { echo: request.action } })

    const [a, b] = await Promise.all([socket.request({ action: 'one' }), socket.request({ action: 'two' })])

    expect(a).toEqual({ status: 200, body: { echo: 'one' } })
    expect(b).toEqual({ status: 200, body: { echo: 'two' } })
    expect(FakeWebSocket.instances).toHaveLength(1)
  })

  it('identifies the session before anything else, and again on change', async () => {
    const socket = makeSocket()
    socket.identify('hash-1')
    await tick()

    const ws = FakeWebSocket.instances[0]!
    expect(ws.sent[0]).toEqual({ type: 'auth', sessionHash: 'hash-1' })

    socket.identify(null)
    expect(ws.sent[1]).toEqual({ type: 'auth', sessionHash: '' })
  })

  it('delivers pushes to subscribers', async () => {
    const socket = makeSocket()
    const seen: unknown[] = []
    const off = socket.on('badges', (message) => seen.push(message.matches))
    socket.identify('hash')
    await tick()

    FakeWebSocket.instances[0]!.receive({ type: 'badges', matches: 3, trips: 0 })
    off()
    FakeWebSocket.instances[0]!.receive({ type: 'badges', matches: 4, trips: 0 })

    expect(seen).toEqual([3])
  })

  it('rejects in-flight requests on a drop, then reconnects and re-identifies', async () => {
    vi.useFakeTimers()
    const socket = makeSocket()
    socket.identify('hash')
    await vi.advanceTimersByTimeAsync(0)
    FakeWebSocket.respond = () => null

    const inFlight = socket.request({ action: 'slow' })
    const caught = inFlight.catch((err: unknown) => err)
    FakeWebSocket.instances[0]!.drop()
    expect(await caught).toBeInstanceOf(SocketError)

    await vi.advanceTimersByTimeAsync(1_000)
    await vi.advanceTimersByTimeAsync(1)
    expect(FakeWebSocket.instances).toHaveLength(2)
    expect(FakeWebSocket.instances[1]!.sent[0]).toEqual({ type: 'auth', sessionHash: 'hash' })
  })

  it('times a request out after 30 seconds', async () => {
    vi.useFakeTimers()
    const socket = makeSocket()
    FakeWebSocket.respond = () => null

    const caught = socket.request({ action: 'never' }).catch((err: unknown) => err)
    await vi.advanceTimersByTimeAsync(30_000)

    expect(await caught).toBeInstanceOf(SocketError)
  })
})
