import { createRouter, createWebHistory } from 'vue-router'
import { initialLanguage, isLanguage, type Language } from '@/i18n'
import { useAuthStore } from '@/stores/auth'

/**
 * One route per interface in the prototype.
 *
 * The prototype presents everything as sections of a single scrolling page; the
 * `Screen NN` eyebrows mark the boundaries, and each becomes a route here:
 *
 *   hero + how it works + pricing  →  /about
 *   Screen 01 (registration)       →  /register  (+ /login for returning users)
 *   Screen 01 (unlock panel)       →  /unlock
 *   Screen 02 (visitor config)     →  /profile   (/onboarding redirects there)
 *   Screen 03 (visitor discovery)  →  /discover
 *   Screen 04 (guide-side deck)    →  /guide/discover
 *
 * One more comes from a link the prototype draws but never resolves:
 * "Full profile →". (Its "View matches" leads to /matches.)
 *
 * The landing page is the discovery deck itself (`/` redirects to
 * /discover): signed-out visitors browse straight away and are asked to sign
 * up on their first "Interested" (see DiscoverPage.vue). The marketing page
 * lives on at /about.
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
  routes: [
    {
      path: '/',
      redirect: '/discover',
    },
    {
      path: '/about',
      name: 'home',
      component: () => import('@/pages/HomePage.vue'),
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/pages/RegisterPage.vue'),
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/pages/LoginPage.vue'),
    },
    {
      // Not from the prototype: password reset by email (see AccountSecurity.php).
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/pages/ForgotPasswordPage.vue'),
    },
    {
      // The page the reset email links to, with `?token=`.
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/pages/ResetPasswordPage.vue'),
    },
    {
      path: '/unlock',
      name: 'unlock',
      component: () => import('@/pages/UnlockPage.vue'),
    },
    {
      // The prototype's contact form never saved anything; ProfilePage.vue's
      // "Personal & contact info" is the real version. Kept as a redirect so
      // old links still land somewhere useful.
      path: '/onboarding',
      redirect: '/profile',
    },
    {
      path: '/discover',
      name: 'discover',
      component: () => import('@/pages/DiscoverPage.vue'),
    },
    {
      path: '/guides/:id',
      name: 'guide-profile',
      component: () => import('@/pages/GuideProfilePage.vue'),
      // `props: true` keeps the page a plain component with an `id` prop.
      props: true,
    },
    {
      // Not from the prototype: the guest-side counterpart of /guides/:id,
      // linked from the guide deck's "Full profile →" (VisitorProfilePage.vue).
      path: '/visitors/:id',
      name: 'visitor-profile',
      component: () => import('@/pages/VisitorProfilePage.vue'),
      props: true,
    },
    {
      path: '/guide/discover',
      name: 'guide-discover',
      component: () => import('@/pages/GuideDiscoverPage.vue'),
    },
    {
      // Not from the prototype: match management for both roles (see MatchesPage.vue).
      path: '/matches',
      name: 'matches',
      component: () => import('@/pages/MatchesPage.vue'),
    },
    {
      // Confirmed trips, both roles — where a match goes once it's booked (see TripsPage.vue).
      path: '/trips',
      name: 'trips',
      component: () => import('@/pages/TripsPage.vue'),
    },
    {
      // Chat with the people you're matched with (see MessagesPage.vue);
      // `:id` opens the conversation with that user.
      path: '/messages/:id?',
      name: 'messages',
      component: () => import('@/pages/MessagesPage.vue'),
      props: true,
    },
    {
      // A traveller's trips and the guides suggested for each stop (see PlannerPage.vue).
      path: '/planner',
      name: 'planner',
      component: () => import('@/pages/PlannerPage.vue'),
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('@/pages/ProfilePage.vue'),
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/pages/NotFoundPage.vue'),
    },
  ],
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
