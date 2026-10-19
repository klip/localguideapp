import { ref } from 'vue'
import { defineStore } from 'pinia'
import type { Api } from '@/plugins/api'

/**
 * The signed-in user's photo for the header's profile circle. It isn't part
 * of `AuthResult` (a data URL is far too big to keep in `localStorage`), so
 * it's fetched once per signed-in user from `myProfile`, and `ProfilePage`
 * calls `set()` when a new photo is saved.
 */
export const useAvatarStore = defineStore('avatar', () => {
  const image = ref<string | null>(null)
  let loadedFor: number | null = null

  async function load(api: Api, userId: number) {
    if (loadedFor === userId) return
    loadedFor = userId
    image.value = null
    try {
      const profile = await api.myProfile()
      if (loadedFor === userId) image.value = profile.image || null
    } catch {
      // Best-effort — the header falls back to an initial.
      if (loadedFor === userId) loadedFor = null
    }
  }

  function set(next: string | null) {
    image.value = next
  }

  function clear() {
    loadedFor = null
    image.value = null
  }

  return { image, load, set, clear }
})
