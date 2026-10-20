import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { AuthResult, Role } from '@/plugins/api'

const STORAGE_KEY = 'belikelocal.session'
const ROLE_KEY = 'belikelocal.activeRole'

/** What's kept in `localStorage`: who's signed in — never a credential. */
type UserSummary = Omit<AuthResult, 'accessToken' | 'accessExpiresAt'>

function readStoredRole(): Role | null {
  try {
    const raw = localStorage.getItem(ROLE_KEY)
    return raw === 'guest' || raw === 'guide' ? raw : null
  } catch {
    return null
  }
}

function readStoredSession(): UserSummary | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const stored = JSON.parse(raw) as UserSummary & { sessionHash?: unknown }
    // A summary saved before tokens still carries the old session hash; drop it.
    delete stored.sessionHash
    return stored
  } catch {
    return null
  }
}

/**
 * The signed-in visitor/guide.
 *
 * - **`user`** — who it is (id, name, roles), kept in `localStorage` so the
 *   header renders straight away after a reload. Not a credential: the
 *   sign-in itself is an HttpOnly refresh cookie the page can't read, which
 *   `App.vue` confirms at boot (`plugins/tokens.ts`'s `refreshAccessToken()`).
 * - **`accessToken`** — the 15-minute token `call()` sends with every request,
 *   in memory only (never in storage), renewed from the cookie before it
 *   expires and shared between tabs. See docs/auth-cms-plan.md.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<UserSummary | null>(readStoredSession())
  const accessToken = ref<string | null>(null)
  /** Unix seconds. */
  const accessExpiresAt = ref<number | null>(null)

  const isAuthenticated = computed(() => user.value !== null)

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

  /** Who's signed in; an access token in `next` is kept in memory, not stored. */
  function setUser(next: AuthResult) {
    const { accessToken: token, accessExpiresAt: expires, ...summary } = next
    user.value = summary
    if (token && expires) setTokens(token, expires)
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(summary))
    } catch {
      // best-effort — a private-browsing storage quota shouldn't break login
    }
  }

  function setTokens(token: string, expiresAt: number) {
    accessToken.value = token
    accessExpiresAt.value = expiresAt
  }

  function clear() {
    user.value = null
    accessToken.value = null
    accessExpiresAt.value = null
    chosenRole.value = null
    try {
      localStorage.removeItem(STORAGE_KEY)
      localStorage.removeItem(ROLE_KEY)
    } catch {
      // best-effort, see setUser()
    }
  }

  return {
    user,
    accessToken,
    accessExpiresAt,
    isAuthenticated,
    roles,
    activeRole,
    setActiveRole,
    setUser,
    setTokens,
    clear,
  }
})
