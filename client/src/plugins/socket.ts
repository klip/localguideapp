/**
 * The one connection to the API: a WebSocket to the gateway (see
 * `gateway/classes/Gateway.php` for the protocol). It carries every request
 * `plugins/api.ts`'s `call()` makes — `{ type: 'request', id, action, ... }`
 * answered by `{ type: 'response', id, status, body }`, `status` being what
 * HTTP would have returned — and the server's pushes (`notification`,
 * `badges`), which `App.vue` subscribes to with `on()`.
 *
 * - **Lazy**: nothing connects until the first request, or until a session
 *   is identified (a signed-in user wants pushes even while idle).
 * - **Reconnects** with backoff (1 s doubling to 15 s) while it has a reason
 *   to — a signed-in user, or requests waiting — and re-identifies first.
 * - A request sent on a connection that then drops fails with status 0 (it
 *   may or may not have run, so it isn't retried); one still queued is sent
 *   once the connection is back. Requests time out after 30 s.
 */

export interface SocketResponse {
  status: number
  body: unknown
}

/** A failure that never got a response — `call()` turns it into `ApiError(…, 0)`. */
export class SocketError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'SocketError'
  }
}

type Handler = (message: Record<string, unknown>) => void

interface Pending {
  resolve: (response: SocketResponse) => void
  reject: (error: SocketError) => void
  timer: ReturnType<typeof setTimeout>
  sent: boolean
  data: string
}

/** `WebSocket.OPEN` — spelled out so a stand-in socket class (tests) needn't define it. */
const OPEN = 1
const REQUEST_TIMEOUT_MS = 30_000
const RECONNECT_MIN_MS = 1_000
const RECONNECT_MAX_MS = 15_000

export class ApiSocket {
  private ws: WebSocket | null = null
  private nextId = 1
  private pending = new Map<number, Pending>()
  private handlers = new Map<string, Set<Handler>>()
  private accessToken: string | null = null
  private reconnectDelay = RECONNECT_MIN_MS
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null

  constructor(
    private readonly url: string,
    private readonly createSocket: (url: string) => WebSocket = (target) => new WebSocket(target),
  ) {}

  /** Sends one API request; resolves with the server's status and body. */
  request(payload: Record<string, unknown>): Promise<SocketResponse> {
    const id = this.nextId++
    const data = JSON.stringify({ type: 'request', id, ...payload })

    return new Promise<SocketResponse>((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id)
        reject(new SocketError('timeout'))
      }, REQUEST_TIMEOUT_MS)
      this.pending.set(id, { resolve, reject, timer, sent: false, data })
      this.ensureConnected()
      this.flush()
    })
  }

  /**
   * Who is signed in on this connection, so the server can push to them: the
   * current access token (the gateway verifies it and keeps the binding until
   * it expires — so it's sent again with every renewed token). `null` signs
   * the connection out. Re-sent after every reconnect.
   */
  identify(accessToken: string | null) {
    if (accessToken === this.accessToken) return
    this.accessToken = accessToken
    if (this.isOpen()) {
      this.sendAuth()
    } else if (accessToken) {
      this.ensureConnected()
    }
  }

  /** Subscribes to a pushed message type (`notification`, `badges`, `auth`); returns the unsubscribe. */
  on(type: string, handler: Handler): () => void {
    let set = this.handlers.get(type)
    if (!set) this.handlers.set(type, (set = new Set()))
    set.add(handler)
    return () => set.delete(handler)
  }

  private isOpen() {
    return this.ws?.readyState === OPEN
  }

  private ensureConnected() {
    if (this.ws || this.reconnectTimer) return

    let ws: WebSocket
    try {
      ws = this.createSocket(this.url)
    } catch {
      this.onDisconnected()
      return
    }
    this.ws = ws

    ws.onopen = () => {
      this.reconnectDelay = RECONNECT_MIN_MS
      if (this.accessToken) this.sendAuth()
      this.flush()
    }
    ws.onmessage = (event: MessageEvent) => this.onMessage(event.data)
    ws.onclose = () => {
      if (this.ws !== ws) return
      this.ws = null
      this.onDisconnected()
    }
    // An error is always followed by a close, which does the clean-up.
    ws.onerror = () => {}
  }

  private flush() {
    if (!this.isOpen()) return
    for (const entry of this.pending.values()) {
      if (entry.sent) continue
      this.ws!.send(entry.data)
      entry.sent = true
    }
  }

  private sendAuth() {
    this.ws!.send(JSON.stringify({ type: 'auth', accessToken: this.accessToken ?? '' }))
  }

  private onMessage(raw: unknown) {
    let message: Record<string, unknown>
    try {
      message = JSON.parse(String(raw))
    } catch {
      return
    }

    if (message.type === 'response') {
      const entry = this.pending.get(Number(message.id))
      if (!entry) return
      this.pending.delete(Number(message.id))
      clearTimeout(entry.timer)
      entry.resolve({ status: Number(message.status), body: message.body })
      return
    }

    for (const handler of this.handlers.get(String(message.type)) ?? []) handler(message)
  }

  private onDisconnected() {
    // Requests already on the wire are lost with the connection.
    for (const [id, entry] of this.pending) {
      if (!entry.sent) continue
      this.pending.delete(id)
      clearTimeout(entry.timer)
      entry.reject(new SocketError('disconnected'))
    }

    if (!this.accessToken && this.pending.size === 0) return
    const delay = this.reconnectDelay
    this.reconnectDelay = Math.min(this.reconnectDelay * 2, RECONNECT_MAX_MS)
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null
      this.ensureConnected()
    }, delay)
  }
}

/**
 * `ws(s)://<api host>/ws`, from `VITE_API_BASE_URL` unless `VITE_WS_URL` says
 * otherwise. In development, a page opened on some other address — a phone
 * on the Wi-Fi through browser-sync (`yarn phone`), which can't look up
 * `api.belikelocal.club` — uses `/ws` on its own address instead, which the
 * dev server forwards to the gateway (vite.config.ts).
 */
function socketUrl(): string {
  if (import.meta.env.VITE_WS_URL) return import.meta.env.VITE_WS_URL
  if (
    import.meta.env.DEV &&
    typeof location !== 'undefined' &&
    !/(^|\.)belikelocal\.club$|^localhost$/.test(location.hostname)
  ) {
    return `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws`
  }
  const base = (import.meta.env.VITE_API_BASE_URL ?? 'https://api.belikelocal.club').replace(
    /\/+$/,
    '',
  )
  return base.replace(/^http/, 'ws') + '/ws'
}

export const socket = new ApiSocket(socketUrl())
