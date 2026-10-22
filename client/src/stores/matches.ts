import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import type { PaymentState } from '@/plugins/api'
import { clearPendingRejects, readPendingRejects } from '@/utils/pendingRejects'

/**
 * Selections in a £5 pack at the lowest level, per the prototype's pricing.
 * A higher level buys more (`Levels::picksPerPack()` — the server says how
 * many in `PaymentState.packSize`); this is the marketing copy's number and
 * the fallback before the server has answered.
 */
export const PACK_SIZE = 5

/**
 * Tracks the visitor's paid selection packs: how many picks they have
 * bought, which guides they kept, and which they dismissed — plus a
 * free-picks bonus (`Levels.php`), during which picks don't use the pack.
 */
export const useMatchesStore = defineStore('matches', () => {
  /** Packs bought at £5 each. Zero means the visitor has not paid yet. */
  const packsUnlocked = ref(0)
  /** Picks bought in total — each pack's own size (from the server). */
  const capacity = ref(0)
  /** What the next £5 pack holds, for this visitor's level. */
  const packSize = ref(PACK_SIZE)
  /** An active free-picks bonus ends at this ISO time; null without one. */
  const freePicksUntil = ref<string | null>(null)
  const picks = ref<string[]>([])
  /**
   * The add-picks dialog (the pay modal on /discover) is open — set by the
   * header's picks counter (`PicksButton.vue`), read by `DiscoverPage.vue`.
   */
  const addPicksOpen = ref(false)
  /** Picks made during free picks — they don't use the pack. */
  const freePicks = ref<string[]>([])
  // Rejects made before signing in survive a reload (see utils/pendingRejects.ts).
  const passed = ref<string[]>(readPendingRejects())

  // `Date.now()` isn't reactive: `now` is re-read when the bonus ends, so
  // "Free picks on" turns off by itself on an open page.
  const now = ref(Date.now())
  let expiryTimer: ReturnType<typeof setTimeout> | undefined
  watch(
    freePicksUntil,
    (until) => {
      clearTimeout(expiryTimer)
      now.value = Date.now()
      if (until === null) return
      const arm = () => {
        now.value = Date.now()
        const left = new Date(until).getTime() - now.value
        // setTimeout overflows past ~24.8 days; re-arm in steps.
        if (left > 0) expiryTimer = setTimeout(arm, Math.min(left + 50, 2 ** 31 - 1))
      }
      arm()
    },
    { immediate: true },
  )
  const freePicksActive = computed(
    () => freePicksUntil.value !== null && new Date(freePicksUntil.value).getTime() > now.value,
  )
  const hasAccess = computed(() => packsUnlocked.value > 0 || freePicksActive.value)
  /** Picks that used the pack. */
  const picksUsed = computed(() => picks.value.filter((id) => !freePicks.value.includes(id)).length)
  const selectionsLeft = computed(() => Math.max(0, capacity.value - picksUsed.value))
  const canPick = computed(
    () => freePicksActive.value || (hasAccess.value && selectionsLeft.value > 0),
  )
  const isEmpty = computed(() => picks.value.length === 0)

  /**
   * Replaces the whole client-side pack state with the server's view: packs
   * and picks bought, the next pack's size, any free-picks bonus, and which
   * guides this visitor has already kept or dismissed.
   *
   * This store is in-memory, so this is what makes a refresh land on
   * "3 / 10 picked" rather than "0 / 10" — see `App.vue`'s boot call. The
   * arrays are seeded directly rather than through `pick()`/`pass()`, which
   * gate on remaining capacity: these decisions are already recorded in
   * `selections`, so re-applying that gate would drop them. Rejects from
   * before sign-in that haven't reached the server yet are kept as passed.
   */
  function applyPaymentState(state: PaymentState) {
    packsUnlocked.value = state.packsUnlocked
    capacity.value = state.capacity
    packSize.value = state.packSize ?? PACK_SIZE
    freePicksUntil.value = state.freePicksUntil ?? null
    picks.value = (state.pickedIds ?? []).map(String)
    freePicks.value = (state.freePickIds ?? []).map(String)
    passed.value = [...new Set([...(state.passedIds ?? []).map(String), ...readPendingRejects()])]
  }

  function pick(id: string) {
    if (!canPick.value || picks.value.includes(id)) return
    picks.value.push(id)
    // The server marks it free the same way while the bonus runs.
    if (freePicksActive.value) freePicks.value.push(id)
  }

  function pass(id: string) {
    if (passed.value.includes(id)) return
    passed.value.push(id)
  }

  function remove(id: string) {
    picks.value = picks.value.filter((pickId) => pickId !== id)
    freePicks.value = freePicks.value.filter((pickId) => pickId !== id)
  }

  function isPicked(id: string) {
    return picks.value.includes(id)
  }

  /** True once the card has been either kept or dismissed. */
  function isDecided(id: string) {
    return picks.value.includes(id) || passed.value.includes(id)
  }

  function reset() {
    packsUnlocked.value = 0
    capacity.value = 0
    packSize.value = PACK_SIZE
    freePicksUntil.value = null
    picks.value = []
    freePicks.value = []
    passed.value = []
  }

  function resetPassed() {
    passed.value = []
    clearPendingRejects()
  }

  return {
    addPicksOpen,
    packsUnlocked,
    capacity,
    packSize,
    freePicksUntil,
    freePicksActive,
    picks,
    freePicks,
    passed,
    hasAccess,
    picksUsed,
    selectionsLeft,
    canPick,
    isEmpty,
    applyPaymentState,
    pick,
    pass,
    remove,
    isPicked,
    isDecided,
    reset,
    resetPassed,
  }
})
