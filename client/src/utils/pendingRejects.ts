import { ApiError, type Api } from '@/plugins/api'

/**
 * Guides a signed-out visitor rejected on the open deck (see DiscoverPage.vue).
 * There's no account to record them against yet, so they're kept in this
 * browser until the visitor registers or logs in, then `syncPendingRejects()`
 * sends them to the server as `'pass'` decisions in the background and clears
 * them. Until then `stores/matches.ts` treats them as passed, so a reload —
 * or the server's view replacing the store at login — doesn't bring them back.
 *
 * Storage can be unavailable (private mode, blocked site data); then rejects
 * simply last for the visit, as they did before.
 */
const STORAGE_KEY = 'rockguide.pendingRejects'

export function readPendingRejects(): string[] {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]')
    return Array.isArray(parsed) ? parsed.map(String) : []
  } catch {
    return []
  }
}

function write(ids: string[]) {
  try {
    if (ids.length) localStorage.setItem(STORAGE_KEY, JSON.stringify(ids))
    else localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Not kept — the reject lasts for this visit only.
  }
}

export function addPendingReject(id: string) {
  const ids = readPendingRejects()
  if (!ids.includes(id)) write([...ids, id])
}

export function clearPendingRejects() {
  write([])
}

let syncing: Promise<void> | null = null

/**
 * Records every stored reject as a `'pass'` for the signed-in guest, then
 * drops it from storage. Best-effort and silent — the visitor never asked for
 * this, so a failure doesn't deserve a toast:
 *
 * - a guide that already has a decision from this visitor (say, picked on
 *   another device) is skipped rather than overwritten with a pass;
 * - one the server refuses outright (a `400` — the guide's gone, for
 *   instance) is dropped, since retrying would only fail again;
 * - a network failure, `5xx` or `401` keeps it for the next attempt (the
 *   next login, or App.vue's boot call).
 *
 * Only guests decide on guides; any other account just discards the list.
 * Concurrent calls share one run.
 */
export function syncPendingRejects(api: Api, role: string | undefined): Promise<void> {
  // Signed out (e.g. the boot session check just expired): keep them queued.
  if (!role || readPendingRejects().length === 0) return Promise.resolve()
  if (role !== 'guest') {
    clearPendingRejects()
    return Promise.resolve()
  }
  syncing ??= run(api).finally(() => {
    syncing = null
  })
  return syncing
}

async function run(api: Api) {
  let decided: Set<string>
  try {
    const state = await api.paymentState()
    decided = new Set([...(state.pickedIds ?? []), ...(state.passedIds ?? [])].map(String))
  } catch {
    return
  }

  const queued = readPendingRejects()
  const keep: string[] = []
  for (const id of queued) {
    if (decided.has(id)) continue
    try {
      await api.decide({ targetId: Number(id), decision: 'pass' })
    } catch (err) {
      if (!(err instanceof ApiError && err.status === 400)) keep.push(id)
    }
  }

  // Anything rejected while this ran (in another tab, say) stays queued too.
  const addedMeanwhile = readPendingRejects().filter((id) => !queued.includes(id))
  write([...keep, ...addedMeanwhile])
}
