/**
 * A stand-in `WebSocket` for tests: opens on the next tick, records what's
 * sent, and answers each `request` with whatever `respond` returns (set it
 * per test). `instances` lists every socket created, newest last.
 */
export class FakeWebSocket {
  static instances: FakeWebSocket[] = []
  static respond: (request: Record<string, unknown>) => { status: number; body: unknown } | null = () => ({
    status: 200,
    body: { ok: true },
  })

  readyState = 0
  sent: Record<string, unknown>[] = []
  onopen: (() => void) | null = null
  onmessage: ((event: { data: string }) => void) | null = null
  onclose: (() => void) | null = null
  onerror: (() => void) | null = null

  constructor(public url: string) {
    FakeWebSocket.instances.push(this)
    setTimeout(() => {
      if (this.readyState !== 0) return
      this.readyState = 1
      this.onopen?.()
    }, 0)
  }

  send(data: string) {
    const message = JSON.parse(data) as Record<string, unknown>
    this.sent.push(message)
    if (message.type !== 'request') return
    const reply = FakeWebSocket.respond(message)
    if (!reply) return
    setTimeout(() => this.receive({ type: 'response', id: message.id, ...reply }), 0)
  }

  /** Delivers a server message. */
  receive(message: Record<string, unknown>) {
    this.onmessage?.({ data: JSON.stringify(message) })
  }

  /** The server (or the network) drops the connection. */
  drop() {
    this.readyState = 3
    this.onclose?.()
  }

  close() {
    this.drop()
  }
}
