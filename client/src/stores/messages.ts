import { ref } from 'vue'
import { defineStore } from 'pinia'
import router from '@/router'

export type MessageKind = 'success' | 'error' | 'info'

interface Rect {
  top: number
  left: number
  width: number
  bottom: number
}

/**
 * Where a toast renders: `page` (top-middle of the page — general/API
 * messages with no specific subject), `element` (top-middle of a specific
 * element, e.g. the form a message resulted from submitting), or `input`
 * (directly beneath a specific field, for messages about that field).
 */
export type MessagePlacement = { type: 'page' } | { type: 'element' | 'input'; rect: Rect }

export interface AppMessage {
  id: number
  kind: MessageKind
  text: string
  placement: MessagePlacement
  onDismiss?: () => void
}

const DEFAULT_DURATION_MS = 5000
const PAGE_PLACEMENT: MessagePlacement = { type: 'page' }

let nextId = 0

/**
 * Builds a placement anchored to `el`'s current position. Resolved eagerly
 * (call it right where the triggering action happens) rather than lazily
 * when the toast is pushed — by then the element (a list row that's about
 * to be removed, say) may already be gone. Falls back to page-level
 * placement if `el` isn't mounted.
 */
export function anchorTo(kind: 'element' | 'input', el: Element | null | undefined): MessagePlacement {
  if (!(el instanceof HTMLElement)) return PAGE_PLACEMENT
  const rect = el.getBoundingClientRect()
  return { type: kind, rect: { top: rect.top, left: rect.left, width: rect.width, bottom: rect.bottom } }
}

/**
 * Global toast queue. Any layer that catches an `ApiError` (or wants to
 * confirm something worked) calls `useMessagesStore().error(text)` /
 * `.success(text)` / `.info(text)`, optionally passing an `anchorTo(...)`
 * placement as the second argument; `MessageToaster`, mounted once in
 * `App.vue`, renders the queue as auto-dismissing (5s) toasts positioned
 * per placement. Nothing pushes to this store automatically — callers
 * decide the wording and placement.
 */
export const useMessagesStore = defineStore('messages', () => {
  const items = ref<AppMessage[]>([])

  function push(
    kind: MessageKind,
    text: string,
    placement: MessagePlacement = PAGE_PLACEMENT,
    durationMs = DEFAULT_DURATION_MS,
    onDismiss?: () => void,
  ): number {
    const id = ++nextId
    items.value.push({ id, kind, text, placement, onDismiss })
    if (durationMs > 0) {
      setTimeout(() => dismiss(id), durationMs)
    }
    return id
  }

  /** Runs the message's `onDismiss` (if any) whether it was closed by timeout or the toast's own "✕" button — both go through here. */
  function dismiss(id: number) {
    const item = items.value.find((candidate) => candidate.id === id)
    items.value = items.value.filter((candidate) => candidate.id !== id)
    item?.onDismiss?.()
  }

  function success(text: string, placement?: MessagePlacement, durationMs?: number, onDismiss?: () => void) {
    return push('success', text, placement, durationMs, onDismiss)
  }

  function error(text: string, placement?: MessagePlacement, durationMs?: number, onDismiss?: () => void) {
    return push('error', text, placement, durationMs, onDismiss)
  }

  function info(text: string, placement?: MessagePlacement, durationMs?: number, onDismiss?: () => void) {
    return push('info', text, placement, durationMs, onDismiss)
  }

  /**
   * The one shared "you got signed out" toast — `plugins/api.ts`'s `call()`
   * pushes this on every 401 (see `AuthenticationException`/
   * `requireUserId()` under Backend), and `App.vue`'s boot-time proactive
   * check reuses it too, so the wording and behavior stay identical no
   * matter which of the two paths actually catches the expiry. Closing it —
   * by the 5s timeout or the toast's own "✕" — sends the user to `/login`:
   * once signed out involuntarily, whatever the current page was showing is
   * no longer actionable, so there's nothing worth staying on it for.
   * Guarded against re-firing while one is already showing, since several
   * requests can 401 at once (e.g. ProfilePage.vue's parallel `Promise.all`
   * on mount) — without this, each would queue its own toast and its own
   * redundant `router.push('/login')`.
   */
  let sessionExpiredShowing = false
  function sessionExpired() {
    if (sessionExpiredShowing) return
    sessionExpiredShowing = true
    push('info', 'Your session expired — please log in again.', PAGE_PLACEMENT, DEFAULT_DURATION_MS, () => {
      sessionExpiredShowing = false
      router.push('/login')
    })
  }

  return { items, push, success, error, info, dismiss, sessionExpired }
})
