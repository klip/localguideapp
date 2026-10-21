import { createRouter, createWebHistory } from 'vue-router'
import { initialLanguage, isLanguage, type Language } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { BUNDLED_MAP, toRouteRecords } from './appMap'

/**
 * The routes come from the CMS's page map (`app_routes`, see ./appMap.ts):
 * which path shows which page (./pages.ts), and the access rules below.
 * The router starts with the bundled copy (`appMap.json`); main.ts swaps in
 * the live map before the first navigation. `/` redirects to /discover — the
 * deck is the landing page; the marketing page lives at /about.
 */
/**
 * The UI language is the first segment of every URL (`/es/discover`). It's
 * the router's base rather than a route param, so routes and links stay
 * language-free (`to="/matches"` renders as `/es/matches`) and active-link
 * matching keeps working.
 *
 * A URL that names a supported language wins over the saved choice (and
 * becomes it — see main.ts). One that doesn't is rewritten in place, before
 * the router reads it, to the saved choice / browser language / English.
 * Switching language reloads under the new prefix (LanguageSwitcher.vue).
 */
function resolveUrlLanguage(): Language {
  if (typeof window === 'undefined') return 'en'
  const base = import.meta.env.BASE_URL
  const rest = window.location.pathname.slice(base.length)
  const first = rest.split('/')[0]
  if (isLanguage(first)) return first

  const language = initialLanguage()
  const { search, hash } = window.location
  window.history.replaceState(
    window.history.state,
    '',
    `${base}${language}/${rest}${search}${hash}`,
  )
  return language
}

export const urlLanguage = resolveUrlLanguage()

const router = createRouter({
  history: createWebHistory(`${import.meta.env.BASE_URL}${urlLanguage}/`),
  routes: toRouteRecords(BUNDLED_MAP),
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) return savedPosition
    // The header links to /about#how, so in-page anchors have to keep working.
    // A section that renders after its data loads (Profile's #level) scrolls
    // itself into view instead — see LevelPanel.vue (the header's level badge).
    if (to.hash && document.getElementById(decodeURIComponent(to.hash.slice(1)))) {
      return { el: to.hash, behavior: 'smooth' }
    }
    return { top: 0 }
  },
})

/**
 * A page's code is a separate chunk. After a deploy (or a dev-server reload)
 * the chunk a tab still points at may be gone, the import fails, and the
 * navigation silently ends on an empty page. Load the target as a fresh page
 * instead — at most once every 10 s, so a server that's really down can't
 * put the tab in a reload loop.
 */
const CHUNK_RELOAD_KEY = 'belikelocal.chunkReloadAt'
const CHUNK_RELOAD_EVERY_MS = 10_000

router.onError((error, to) => {
  const message = error instanceof Error ? error.message : String(error)
  if (!/dynamically imported module|Importing a module script failed/i.test(message)) return
  try {
    const last = Number(sessionStorage.getItem(CHUNK_RELOAD_KEY) ?? 0)
    if (Date.now() - last < CHUNK_RELOAD_EVERY_MS) return
    sessionStorage.setItem(CHUNK_RELOAD_KEY, String(Date.now()))
  } catch {
    // Storage blocked: reload anyway — a loop needs the same failure each time.
  }
  window.location.assign(router.resolve(to).href)
})

/**
 * `?as=guide` / `?as=guest` on any link (a notification's toast, an email)
 * opens it in that mode: an account holding both roles switches to it, then
 * the query is dropped. A role the account doesn't hold is just ignored.
 */
router.beforeEach((to) => {
  const as = to.query.as
  if (as !== 'guest' && as !== 'guide') return
  useAuthStore().setActiveRole(as)
  const query = { ...to.query }
  delete query.as
  return { path: to.path, query, hash: to.hash, replace: true }
})

/**
 * The page map's rules (`app_routes.access` / `role`, set in the CMS):
 * a `signed_in` page sends a signed-out visitor to /login, a `signed_out`
 * one (log in, register…) sends a signed-in user to the start. A page for one
 * role switches an account holding both to that role; anyone else gets "not
 * found" — not a redirect, which could loop between two rules.
 */
router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.access === 'signed_in' && !auth.isAuthenticated)
    return { path: '/login', replace: true }
  if (to.meta.access === 'signed_out' && auth.isAuthenticated) return { path: '/', replace: true }
  const role = to.meta.role
  if (role && auth.isAuthenticated && auth.activeRole !== role) {
    if (auth.roles.includes(role)) auth.setActiveRole(role)
    else
      return {
        name: 'not-found',
        params: { pathMatch: to.path.slice(1).split('/') },
        query: to.query,
        hash: to.hash,
      }
  }
})

/**
 * The name of the route the page was opened on, following redirects (`/` →
 * `discover`) — known before the router has navigated, so main.ts can fetch
 * that page's text in parallel with loading its code.
 */
export function initialRouteName(): string | undefined {
  if (typeof window === 'undefined') return undefined
  const base = `${import.meta.env.BASE_URL}${urlLanguage}/`
  let location: string = `/${window.location.pathname.slice(base.length)}`
  for (let hops = 0; hops < 5; hops++) {
    const route = router.resolve(location)
    const redirect = route.matched[route.matched.length - 1]?.redirect
    if (typeof redirect !== 'string') return typeof route.name === 'string' ? route.name : undefined
    location = redirect
  }
  return undefined
}

export default router
