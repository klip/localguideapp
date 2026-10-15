import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * The terms-and-conditions dialog App.vue renders once for the whole app,
 * opened from the footer and the cookie notice. `section` is a heading
 * number in the document ("9" — the privacy notice, "9.8" — storage on your
 * device) to scroll to. Registration has its own dialog, with Accept.
 */
export const useLegalStore = defineStore('legal', () => {
  const termsOpen = ref(false)
  const section = ref<string | null>(null)

  function showTerms(at: string | null = null) {
    section.value = at
    termsOpen.value = true
  }

  function hideTerms() {
    termsOpen.value = false
  }

  return { termsOpen, section, showTerms, hideTerms }
})
