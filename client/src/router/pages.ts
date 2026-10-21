import type { RouteComponent } from 'vue-router'

/**
 * The pages the app's code provides. Which URL shows which page — and for
 * whom — is the CMS's page map (`app_routes`, see ./appMap.ts); a route there
 * names one of these. Add a page here when you add a page component, then a
 * route for it in the CMS (or a migration).
 */
export const PAGES: Record<string, () => Promise<RouteComponent>> = {
  HomePage: () => import('@/pages/HomePage.vue'),
  RegisterPage: () => import('@/pages/RegisterPage.vue'),
  LoginPage: () => import('@/pages/LoginPage.vue'),
  ForgotPasswordPage: () => import('@/pages/ForgotPasswordPage.vue'),
  ResetPasswordPage: () => import('@/pages/ResetPasswordPage.vue'),
  SecureAccountPage: () => import('@/pages/SecureAccountPage.vue'),
  UnlockPage: () => import('@/pages/UnlockPage.vue'),
  DiscoverPage: () => import('@/pages/DiscoverPage.vue'),
  GuideProfilePage: () => import('@/pages/GuideProfilePage.vue'),
  VisitorProfilePage: () => import('@/pages/VisitorProfilePage.vue'),
  GuideDiscoverPage: () => import('@/pages/GuideDiscoverPage.vue'),
  MatchesPage: () => import('@/pages/MatchesPage.vue'),
  TripsPage: () => import('@/pages/TripsPage.vue'),
  MessagesPage: () => import('@/pages/MessagesPage.vue'),
  PlannerPage: () => import('@/pages/PlannerPage.vue'),
  ProfilePage: () => import('@/pages/ProfilePage.vue'),
  NotFoundPage: () => import('@/pages/NotFoundPage.vue'),
}
