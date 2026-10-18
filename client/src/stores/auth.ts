import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { AuthResult, Role } from '@/plugins/api'

const STORAGE_KEY = 'rockguide.session'
const ROLE_KEY = 'rockguide.activeRole'

function readStoredRole(): Role | null {
  try {
    const raw = localStorage.getItem(ROLE_KEY)
    return raw === 'guest' || raw === 'guide' ? raw : null
  } catch {
    return null
  }
}

function readStoredSession(): AuthResult | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as AuthResult) : null
  } catch {
    return null
  }
}

/**
 * The signed-in visitor/guide, set by `RegisterPage`/`LoginPage` on a
 * successful `api.register()`/`api.login()` — and, now that the server has
 * real sessions (see `Session.php`), persisted to `localStorage` so a page
 * reload doesn't sign the user out the way it used to. This is only ever an
 * *optimistic* restore: the real expiry lives server-side (5 minutes of
 * inactivity), so `App.vue` confirms this cached copy against `api.session()`
 * once on boot, and `plugins/api.ts`'s `call()` clears it automatically the
 * moment any request comes back `401` (an expired/unknown session).
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthResult | null>(readStoredSession())

  const isAuthenticated = computed(() => user.value !== null)
  const sessionHash = computed(() => user.value?.sessionHash ?? null)

  /** Every role the account holds — an older cached session only has `role`. */
  const roles = computed<Role[]>(() => {
    if (!user.value) return []
    if (user.value.roles?.length) return user.value.roles
    const role = user.value.role
    return role === 'guest' || role === 'guide' ? [role] : []
  })

  const chosenRole = ref<Role | null>(readStoredRole())

  /**
   * The role the account is acting as: the one last chosen, if it still
   * holds it, else its first. Every page reads this, and `call()` sends it.
   */
  const activeRole = computed<Role | null>(() =>
    chosenRole.value && roles.value.includes(chosenRole.value)
      ? chosenRole.value
      : (roles.value[0] ?? null),
  )

  function setActiveRole(role: Role) {
    if (!roles.value.includes(role)) return
    chosenRole.value = role
    try {
      localStorage.setItem(ROLE_KEY, role)
    } catch {
      // best-effort, see setUser()
    }
  }

  function setUser(next: AuthResult) {
    user.value = next
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(next))
    } catch {
      // best-effort — a private-browsing storage quota shouldn't break login
    }
  }

  function clear() {
    user.value = null
    chosenRole.value = null
    try {
      localStorage.removeItem(STORAGE_KEY)
      localStorage.removeItem(ROLE_KEY)
    } catch {
      // best-effort, see setUser()
    }
  }

  return { user, isAuthenticated, sessionHash, roles, activeRole, setActiveRole, setUser, clear }
})
