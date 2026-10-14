import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * What the server pushes over the socket (`App.vue` feeds it): the red
 * counters on the Matches and My trips menu items — new matches and trips
 * the user hasn't opened yet (`Badges.php`) — and `revision`, bumped on every
 * pushed notification, so an open /matches or /trips page can refetch when
 * something changed underneath it.
 */
export const useBadgesStore = defineStore('badges', () => {
  const matches = ref(0)
  const trips = ref(0)
  const revision = ref(0)

  function set(counts: { matches?: unknown; trips?: unknown }) {
    matches.value = Math.max(0, Number(counts.matches) || 0)
    trips.value = Math.max(0, Number(counts.trips) || 0)
  }

  function bump() {
    revision.value++
  }

  function reset() {
    matches.value = 0
    trips.value = 0
  }

  return { matches, trips, revision, set, bump, reset }
})
