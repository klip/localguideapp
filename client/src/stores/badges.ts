import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { Role } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'

interface Counts {
  matches: number
  trips: number
  messages: number
}

const ZERO: Counts = { matches: 0, trips: 0, messages: 0 }

function toCounts(raw: unknown): Counts {
  const value = (raw ?? {}) as Record<string, unknown>
  const count = (key: string) => Math.max(0, Number(value[key]) || 0)
  return { matches: count('matches'), trips: count('trips'), messages: count('messages') }
}

/**
 * What the server pushes over the socket (`App.vue` feeds it): the red
 * counters on the Matches, My trips and Messages menu items — new matches and trips
 * the user hasn't opened yet and unread chat messages (`Badges.php`) — and `revision`, bumped on every
 * pushed notification, so an open /matches or /trips page can refetch when
 * something changed underneath it.
 *
 * Counts arrive **per role** (`byRole`) as well as in total: the menu shows
 * the role the account is acting as, and `otherRoleNews` says the other one
 * has something (a dot on the mode switch). Without `byRole` (an older
 * server) the totals are shown.
 */
export const useBadgesStore = defineStore('badges', () => {
  const auth = useAuthStore()
  const totals = ref<Counts>({ ...ZERO })
  const byRole = ref<Record<Role, Counts> | null>(null)
  const revision = ref(0)

  const shown = computed<Counts>(() => {
    const role = auth.activeRole
    return byRole.value && role ? byRole.value[role] : totals.value
  })

  const matches = computed(() => shown.value.matches)
  const trips = computed(() => shown.value.trips)
  const messages = computed(() => shown.value.messages)

  /** Something new for the role the account isn't acting as. */
  const otherRoleNews = computed(() => {
    const role = auth.activeRole
    if (!byRole.value || !role || auth.roles.length < 2) return false
    const other = byRole.value[role === 'guide' ? 'guest' : 'guide']
    return other.matches + other.trips + other.messages > 0
  })

  function set(counts: {
    matches?: unknown
    trips?: unknown
    messages?: unknown
    byRole?: unknown
  }) {
    totals.value = toCounts(counts)
    const roles = counts.byRole as Record<string, unknown> | undefined
    byRole.value = roles ? { guest: toCounts(roles.guest), guide: toCounts(roles.guide) } : null
  }

  function bump() {
    revision.value++
  }

  function reset() {
    totals.value = { ...ZERO }
    byRole.value = null
  }

  return { matches, trips, messages, otherRoleNews, revision, set, bump, reset }
})
