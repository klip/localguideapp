import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { PaymentState } from '@/plugins/api'
import { clearPendingRejects, readPendingRejects } from '@/utils/pendingRejects'

/** Selections granted per £5 pack, per the pricing in the prototype. */
export const PACK_SIZE = 5

/**
 * Tracks the visitor's paid selection pack: how many picks they have bought,
 * which guides they kept, and which they dismissed.
 */
export const useMatchesStore = defineStore('matches', () => {
  /** Packs bought at £5 each. Zero means the visitor has not paid yet. */
  const packsUnlocked = ref(0)
  const picks = ref<string[]>([])
  // Rejects made before signing in survive a reload (see utils/pendingRejects.ts).
  const passed = ref<string[]>(readPendingRejects())

  const hasAccess = computed(() => packsUnlocked.value > 0)
  const capacity = computed(() => packsUnlocked.value * PACK_SIZE)
  const selectionsLeft = computed(() => Math.max(0, capacity.value - picks.value.length))
  const canPick = computed(() => hasAccess.value && selectionsLeft.value > 0)
  const isEmpty = computed(() => picks.value.length === 0)

  /** Set from the server's authoritative count after `api.unlockPack()` — see UnlockPage.vue. */
  function setPacksUnlocked(n: number) {
    packsUnlocked.value = n
  }

  /**
   * Replaces the whole client-side pack state with the server's view: packs
   * bought, plus which guides this visitor has already kept or dismissed.
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
    picks.value = (state.pickedIds ?? []).map(String)
    passed.value = [...new Set([...(state.passedIds ?? []).map(String), ...readPendingRejects()])]
  }

  function pick(id: string) {
    if (!canPick.value || picks.value.includes(id)) return
    picks.value.push(id)
  }

  function pass(id: string) {
    if (passed.value.includes(id)) return
    passed.value.push(id)
  }

  function remove(id: string) {
    picks.value = picks.value.filter((pickId) => pickId !== id)
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
    picks.value = []
    passed.value = []
  }

  function resetPassed() {
    passed.value = []
    clearPendingRejects()
  }

  return {
    packsUnlocked,
    picks,
    passed,
    hasAccess,
    capacity,
    selectionsLeft,
    canPick,
    isEmpty,
    setPacksUnlocked,
    applyPaymentState,
    pick,
    pass,
    remove,
    isPicked,
    isDecided,
    reset,
    resetPassed
  }
})
