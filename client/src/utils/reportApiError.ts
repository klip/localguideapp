import { ApiError } from '@/plugins/api'
import { useMessagesStore, type MessagePlacement } from '@/stores/messages'

/**
 * The shared shape of a page's `catch (err) { ... }` after an `api.*()`
 * call: shows `err.message` (an `ApiError`) or `fallback` (anything else)
 * as an error toast — except for a 401, which `plugins/api.ts`'s `call()`
 * already turned into the shared "session expired" toast (see
 * `useMessagesStore().sessionExpired()`) and already cleared the local
 * session for. Showing a page-specific "could not save/load/..." on top of
 * that would just be noise about a problem that isn't the real one, so this
 * skips its own toast entirely in that case.
 */
export function reportApiError(err: unknown, fallback: string, placement?: MessagePlacement): void {
  if (err instanceof ApiError && err.status === 401) return
  useMessagesStore().error(err instanceof ApiError ? err.message : fallback, placement)
}
