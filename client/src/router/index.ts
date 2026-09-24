import { createRouter, createWebHistory } from 'vue-router'
import HomePage from '@/pages/HomePage.vue'

/**
 * One route per interface in the prototype.
 *
 * The prototype presents everything as sections of a single scrolling page; the
 * `Screen NN` eyebrows mark the boundaries, and each becomes a route here:
 *
 *   hero + how it works + pricing  →  /
 *   Screen 01 (registration)       →  /register  (+ /login for returning users)
 *   Screen 01 (unlock panel)       →  /unlock
 *   Screen 02 (visitor config)     →  /profile   (/onboarding redirects there)
 *   Screen 03 (visitor discovery)  →  /discover
 *   Screen 04 (guide-side deck)    →  /guide/discover
 *
 * Two more come from links the prototype draws but never resolves:
 * "Full profile →" and "View shortlist".
 */
const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomePage,
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
      path: '/shortlist',
      name: 'shortlist',
      component: () => import('@/pages/ShortlistPage.vue'),
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
    // The header links to /#how, so in-page anchors have to keep working.
    if (to.hash) return { el: to.hash, behavior: 'smooth' }
    return { top: 0 }
  },
})

export default router
