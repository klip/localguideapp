import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { can, refresh, session, whenSignedOut } from '@/api'

/**
 * `meta.public` pages work signed out; `meta.signedOutOnly` ones (log in,
 * forgot password) send a signed-in member home instead of showing a sign-in
 * form under the menu. Every other page needs a signed-in member, and
 * `meta.capability` (see `cms/classes/Permissions.php`) one their
 * role has — the server checks the same capability on every action.
 */
export const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/pages/LoginPage.vue'),
    meta: { public: true, signedOutOnly: true },
  },
  {
    path: '/set-password',
    name: 'set-password',
    component: () => import('@/pages/SetPasswordPage.vue'),
    meta: { public: true },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('@/pages/ForgotPasswordPage.vue'),
    meta: { public: true, signedOutOnly: true },
  },
  { path: '/', name: 'home', component: () => import('@/pages/HomePage.vue') },
  {
    path: '/content',
    name: 'content',
    component: () => import('@/pages/ContentPage.vue'),
    meta: { capability: 'content.edit' },
  },
  {
    path: '/pages',
    name: 'pages',
    component: () => import('@/pages/PagesPage.vue'),
    meta: { capability: 'pages.manage' },
  },
  {
    path: '/users',
    name: 'users',
    component: () => import('@/pages/ComingSoonPage.vue'),
    meta: { capability: 'users.view', title: 'Users', phase: 8 },
  },
  {
    path: '/moderation',
    name: 'moderation',
    component: () => import('@/pages/ComingSoonPage.vue'),
    meta: { capability: 'disputes.resolve', title: 'Moderation', phase: 9 },
  },
  {
    path: '/staff',
    name: 'staff',
    component: () => import('@/pages/StaffPage.vue'),
    meta: { capability: 'staff.manage' },
  },
  { path: '/audit', name: 'audit', component: () => import('@/pages/AuditPage.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export function createCmsRouter() {
  const router = createRouter({ history: createWebHistory(), routes })

  router.beforeEach(async (to) => {
    // The first navigation waits for the cookie to say whether someone's signed in.
    if (!session.ready) await refresh().catch(() => false)
    if (to.meta.signedOutOnly && session.me) return { name: 'home' }
    if (to.meta.public) return true
    if (!session.me) return { name: 'login' }
    if (typeof to.meta.capability === 'string' && !can(to.meta.capability)) return { name: 'home' }
    return true
  })

  whenSignedOut(() => {
    void router.push({ name: 'login' })
  })

  return router
}
