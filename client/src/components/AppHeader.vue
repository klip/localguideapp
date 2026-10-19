<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from './BrandMark.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'
import PillBadge from './PillBadge.vue'
import { t, tn } from '@/i18n'
import { useApi, type Role } from '@/plugins/api'
import { useMatchesStore } from '@/stores/matches'
import { useAuthStore } from '@/stores/auth'
import { useBadgesStore } from '@/stores/badges'
import { useMessagesStore } from '@/stores/messages'

const api = useApi()
const matchesStore = useMatchesStore()
const { hasAccess, picksUsed, capacity, freePicksActive } = storeToRefs(matchesStore)

const auth = useAuthStore()
const { isAuthenticated, user } = storeToRefs(auth)
const messages = useMessagesStore()
const router = useRouter()
const route = useRoute()

/** Guides never pay/browse guides — send them to their own deck instead of the visitor-side /discover (see DiscoverPage.vue's redirect guard). */
/** Unseen matches and trips (pushed by the server, see `stores/badges.ts`), as the red counters on their menu items. */
const badges = useBadgesStore()
const {
  matches: newMatches,
  trips: newTrips,
  messages: unreadMessages,
  otherRoleNews,
} = storeToRefs(badges)
const hasNews = computed(
  () =>
    isAuthenticated.value &&
    (newMatches.value + newTrips.value + unreadMessages.value > 0 || otherRoleNews.value),
)

function badgeText(count: number) {
  return count > 99 ? '99+' : String(count)
}

const discoverTo = computed(() => (auth.activeRole === 'guide' ? '/guide/discover' : '/discover'))

/** An account holding both roles switches between them; one holding one is offered the other. */
const { roles, activeRole } = storeToRefs(auth)
const canSwitch = computed(() => roles.value.length > 1)
const missingRole = computed<Role | null>(() => {
  if (!isAuthenticated.value || roles.value.length !== 1) return null
  return roles.value[0] === 'guide' ? 'guest' : 'guide'
})

/** The decks are per role; every other page re-renders in place (App.vue keys the view by role). */
const DECK_FOR: Record<Role, string> = { guest: '/discover', guide: '/guide/discover' }

function switchRole(role: Role) {
  if (role === activeRole.value) return
  auth.setActiveRole(role)
  menuOpen.value = false
  messages.info(
    t(role === 'guide' ? 'common.app_header.switched_guide' : 'common.app_header.switched_guest'),
  )
  const other = DECK_FOR[role === 'guide' ? 'guest' : 'guide']
  if (route.path === other) void router.replace(DECK_FOR[role])
}

async function logout() {
  try {
    await api.logout()
  } catch {
    // best-effort — the session will expire on its own after 5 minutes of
    // inactivity even if this round-trip fails; clearing local state below
    // is what actually matters to the person clicking the button.
  }
  auth.clear()
  messages.success(t('common.app_header.logged_out'))
  router.push('/')
}

/**
 * Mobile menu. Below `xl` the same `.drawer` that sits inline on desktop
 * becomes an off-canvas panel sliding in from the right (pure CSS — see the
 * styles below), so the links are only written once. While open: page
 * scroll is locked, Tab cycles within the drawer, Escape / the backdrop /
 * the close button / following any link closes it.
 */
// `xl` (1280px): a dual-role traveller's row — Discover, Trip planner, Matches,
// My trips, Messages, Profile, the mode switch, picks, language, Log out — needs
// about 1,290px in English; narrower, it wrapped.
const DESKTOP_QUERY = '(min-width: 1280px)' // keep in sync with `bp.$xl`

const menuOpen = ref(false)
const burgerRef = ref<HTMLButtonElement | null>(null)
const drawerRef = ref<HTMLElement | null>(null)

function focusables(): HTMLElement[] {
  return Array.from(
    drawerRef.value?.querySelectorAll<HTMLElement>('a[href], button:not([disabled])') ?? [],
  )
}

async function openMenu() {
  menuOpen.value = true
  await nextTick()
  focusables()[0]?.focus()
}

function closeMenu(restoreFocus = false) {
  if (!menuOpen.value) return
  menuOpen.value = false
  if (restoreFocus) burgerRef.value?.focus()
}

function onDrawerKeydown(event: KeyboardEvent) {
  if (!menuOpen.value) return

  if (event.key === 'Escape') {
    event.preventDefault()
    closeMenu(true)
    return
  }

  if (event.key !== 'Tab') return
  const items = focusables()
  const first = items[0]
  const last = items[items.length - 1]
  if (!first || !last) return

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

/** A link to the current route doesn't navigate, so the route watch below wouldn't close the menu for it. */
function onDrawerClick(event: MouseEvent) {
  if ((event.target as Element | null)?.closest('a')) closeMenu()
}

watch(
  () => route.fullPath,
  () => closeMenu(),
)

watch(menuOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
})

let desktopQuery: MediaQueryList | null = null
function onViewportChange(event: MediaQueryListEvent) {
  if (event.matches) closeMenu()
}

onMounted(() => {
  // Absent in jsdom (unit tests) — nothing to react to there anyway.
  if (typeof window.matchMedia !== 'function') return
  desktopQuery = window.matchMedia(DESKTOP_QUERY)
  desktopQuery.addEventListener('change', onViewportChange)
})

onBeforeUnmount(() => {
  desktopQuery?.removeEventListener('change', onViewportChange)
  document.body.style.overflow = ''
})
</script>

<template>
  <header class="topbar">
    <div class="shell">
      <nav :aria-label="t('common.app_header.nav_label')">
        <ul class="logo">
          <li><BrandMark /></li>
        </ul>

        <button
          ref="burgerRef"
          type="button"
          class="burger"
          aria-controls="site-menu"
          :aria-expanded="menuOpen"
          :aria-label="t('common.app_header.open_menu')"
          @click="openMenu"
        >
          <svg
            viewBox="0 0 24 24"
            width="22"
            height="22"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            aria-hidden="true"
          >
            <line x1="4" y1="7" x2="20" y2="7" />
            <line x1="4" y1="12" x2="20" y2="12" />
            <line x1="4" y1="17" x2="20" y2="17" />
          </svg>
          <!-- The counters live inside the drawer; the dot says there's something to open it for. -->
          <span v-if="hasNews" class="burger-dot" aria-hidden="true" />
        </button>

        <div
          id="site-menu"
          ref="drawerRef"
          class="drawer"
          :class="{ 'drawer--open': menuOpen }"
          @keydown="onDrawerKeydown"
          @click="onDrawerClick"
        >
          <div class="drawer-head">
            <BrandMark as="text" />
            <button
              type="button"
              class="drawer-close"
              :aria-label="t('common.app_header.close_menu')"
              @click="closeMenu(true)"
            >
              <svg
                viewBox="0 0 24 24"
                width="20"
                height="20"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                aria-hidden="true"
              >
                <line x1="6" y1="6" x2="18" y2="18" />
                <line x1="18" y1="6" x2="6" y2="18" />
              </svg>
            </button>
          </div>

          <p v-if="user" class="drawer-user tiny muted">
            {{ t('common.app_header.signed_in_as', { name: user.name || user.email }) }}
          </p>

          <ul class="nav">
            <!-- Marketing copy for prospects only — a signed-in user is past it. -->
            <li v-if="!isAuthenticated">
              <RouterLink to="/about#how" class="nav-link">{{
                t('common.app_header.how_it_works')
              }}</RouterLink>
            </li>
            <li>
              <RouterLink :to="discoverTo" class="nav-link">{{
                t('common.app_header.discover')
              }}</RouterLink>
            </li>
            <li v-if="isAuthenticated && activeRole === 'guest'">
              <RouterLink to="/planner" class="nav-link" data-test="nav-planner">{{
                t('common.app_header.planner')
              }}</RouterLink>
            </li>
            <li v-if="isAuthenticated">
              <RouterLink to="/matches" class="nav-link nav-link--counted">
                {{ t('common.app_header.matches')
                }}<span v-if="newMatches" class="nav-count" data-test="matches-count"
                  ><span aria-hidden="true">{{ badgeText(newMatches) }}</span
                  ><span class="visually-hidden">{{
                    t('common.app_header.new_count', { count: newMatches })
                  }}</span></span
                >
              </RouterLink>
            </li>
            <li v-if="isAuthenticated">
              <RouterLink to="/trips" class="nav-link nav-link--counted">
                {{ t('common.app_header.my_trips')
                }}<span v-if="newTrips" class="nav-count" data-test="trips-count"
                  ><span aria-hidden="true">{{ badgeText(newTrips) }}</span
                  ><span class="visually-hidden">{{
                    t('common.app_header.new_count', { count: newTrips })
                  }}</span></span
                >
              </RouterLink>
            </li>
            <li v-if="isAuthenticated">
              <RouterLink to="/messages" class="nav-link nav-link--counted">
                {{ t('common.app_header.messages')
                }}<span v-if="unreadMessages" class="nav-count" data-test="messages-count"
                  ><span aria-hidden="true">{{ badgeText(unreadMessages) }}</span
                  ><span class="visually-hidden">{{
                    tn('common.app_header.unread_count', unreadMessages)
                  }}</span></span
                >
              </RouterLink>
            </li>
            <li v-if="isAuthenticated">
              <RouterLink to="/profile" class="nav-link">{{
                t('common.app_header.profile')
              }}</RouterLink>
            </li>
            <li v-if="canSwitch" class="mode-switch">
              <div role="group" :aria-label="t('common.app_header.mode_label')" class="mode-toggle">
                <button
                  v-for="role in ['guest', 'guide'] as const"
                  :key="role"
                  type="button"
                  class="mode-option"
                  :class="{ 'mode-option--on': activeRole === role }"
                  :aria-pressed="activeRole === role"
                  :data-test="`mode-${role}`"
                  @click="switchRole(role)"
                >
                  {{
                    t(
                      role === 'guide'
                        ? 'common.app_header.mode_guide'
                        : 'common.app_header.mode_guest',
                    )
                  }}
                  <span
                    v-if="otherRoleNews && activeRole !== role"
                    class="mode-dot"
                    data-test="mode-news"
                    :title="t('common.app_header.other_role_news')"
                    ><span class="visually-hidden">{{
                      t('common.app_header.other_role_news')
                    }}</span></span
                  >
                </button>
              </div>
            </li>
            <li v-else-if="missingRole">
              <RouterLink
                :to="{ path: '/profile', query: { addRole: missingRole } }"
                class="nav-link add-role"
                data-test="add-role-link"
                >{{
                  t(
                    missingRole === 'guide'
                      ? 'common.app_header.become_guide'
                      : 'common.app_header.want_to_travel',
                  )
                }}</RouterLink
              >
            </li>
            <li v-if="hasAccess && activeRole === 'guest'">
              <RouterLink to="/matches" class="nav-link">
                <PillBadge>{{
                  freePicksActive
                    ? t('discover.deck.free_picks')
                    : t('common.app_header.picked', { picked: picksUsed, capacity })
                }}</PillBadge>
              </RouterLink>
            </li>
            <li class="language"><LanguageSwitcher /></li>
            <li v-if="isAuthenticated" class="account">
              <button type="button" class="outline logout-btn" @click="logout">
                <svg
                  class="logout-icon"
                  viewBox="0 0 24 24"
                  width="16"
                  height="16"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                  <polyline points="16 17 21 12 16 7" />
                  <line x1="21" y1="12" x2="9" y2="12" />
                </svg>
                {{ t('common.app_header.log_out') }}
              </button>
            </li>
            <template v-else>
              <li class="account">
                <RouterLink to="/register" role="button">{{
                  t('common.app_header.get_started')
                }}</RouterLink>
              </li>
              <li>
                <RouterLink to="/login" class="nav-link tiny">{{
                  t('common.app_header.log_in')
                }}</RouterLink>
              </li>
            </template>
          </ul>
        </div>
      </nav>
    </div>

    <div
      class="backdrop"
      :class="{ 'backdrop--open': menuOpen }"
      aria-hidden="true"
      @click="closeMenu()"
    />
  </header>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

$drawer-speed: 0.28s;

.topbar {
  position: sticky;
  top: 0;
  z-index: 30;
  border-bottom: 1px solid rgba(234, 223, 216, 0.8);

  // The blur lives on a pseudo-element, not .topbar itself: `backdrop-filter`
  // makes an element the containing block for its `position: fixed`
  // descendants, which would trap the mobile drawer inside the header's
  // 60px height instead of the viewport.
  &::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    backdrop-filter: blur(14px);
    background: rgba(255, 250, 246, 0.82);
  }
}

nav {
  padding-block: 0.6rem;
  align-items: center;
}

.nav-link {
  text-decoration: none;
}

.logout-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.logout-icon {
  flex-shrink: 0;
}

// The red unseen-count bubble: beside the label in the mobile drawer, over
// the item's top-right corner in the desktop bar.
.nav-link--counted {
  position: relative;
}

.nav-count {
  display: inline-block;
  margin-inline-start: 0.5rem;
  vertical-align: 0.1em;
  min-width: 1.15rem;
  height: 1.15rem;
  padding: 0 0.3rem;
  border-radius: 999px;
  background: #e5332a;
  color: #fff;
  font-size: 0.7rem;
  font-weight: 700;
  line-height: 1.15rem;
  text-align: center;
  pointer-events: none;

  @include bp.xl {
    position: absolute;
    top: -0.1rem;
    inset-inline-end: -0.2rem;
    margin: 0;
  }
}

.burger {
  position: relative;
}

.burger-dot {
  position: absolute;
  top: 0.35rem;
  inset-inline-end: 0.35rem;
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 50%;
  background: #e5332a;
  box-shadow: 0 0 0 2px #fff;
}

// Pico marks the matched route link; keep it subtle in the top bar.
.nav-link.router-link-active {
  color: var(--rg-ink);
  font-weight: 600;
}

.burger,
.drawer-close {
  display: inline-grid;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  padding: 0;
  margin: 0;
  border-radius: 0.8rem;
  border: 1px solid var(--rg-line);
  background: #fff;
  color: var(--rg-ink);

  &:hover,
  &:focus-visible {
    background: var(--rg-orange-50);
    border-color: var(--rg-orange-300);
    color: var(--rg-ink);
  }
}

// --- Mobile: off-canvas drawer (default) -------------------------------------

.drawer {
  position: fixed;
  top: 0;
  right: 0;
  z-index: 2;
  width: min(20rem, 85vw);
  height: 100vh;
  height: 100dvh;
  display: flex;
  flex-direction: column;
  padding: 0.75rem 1.25rem calc(1.25rem + env(safe-area-inset-bottom));
  overflow-y: auto;
  background: var(--rg-surface);
  border-left: 1px solid var(--rg-line);
  box-shadow: -18px 0 50px rgba(100, 63, 39, 0.16);
  transform: translateX(100%);
  // `visibility` keeps the off-screen links out of the tab order and the
  // accessibility tree; delaying it until the slide-out ends keeps the
  // closing animation visible.
  visibility: hidden;
  transition:
    transform $drawer-speed ease,
    visibility 0s linear $drawer-speed;
}

.drawer--open {
  transform: none;
  visibility: visible;
  transition: transform $drawer-speed ease;
}

.drawer-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
  font-size: 1.15rem;
}

.drawer-user {
  margin: 0 0 0.5rem;
  overflow-wrap: anywhere;
}

nav .nav {
  flex: 1;
  flex-direction: column;
  align-items: stretch;
  margin: 0;

  li {
    display: block;
    padding: 0;
  }

  .nav-link {
    display: block;
    margin: 0;
    padding: 0.85rem 0.25rem;
    border-radius: 0;
    border-bottom: 1px solid var(--rg-line);
    color: var(--rg-ink);
    font-size: 1rem;

    &.tiny {
      border-bottom: 0;
      text-align: center;
      font-size: 0.9rem;
    }
  }

  // Account actions sit at the foot of the drawer — `margin-top: auto` when
  // the links are short, and stuck to the bottom edge (rather than scrolled
  // out of reach) once they overflow a short viewport.
  .account {
    position: sticky;
    bottom: 0;
    margin-top: auto;
    padding-top: 1.25rem;
    padding-bottom: 0.25rem;
    background: var(--rg-surface);
  }

  .logout-btn,
  [role='button'] {
    width: 100%;
    margin: 0;
    justify-content: center;
    padding-block: 0.7rem;
  }
}

.backdrop {
  position: fixed;
  inset: 0;
  z-index: 1;
  background: rgba(41, 30, 24, 0.38);
  opacity: 0;
  pointer-events: none;
  transition: opacity $drawer-speed ease;
}

.backdrop--open {
  opacity: 1;
  pointer-events: auto;
}

@media (prefers-reduced-motion: reduce) {
  .drawer,
  .drawer--open,
  .backdrop {
    transition: none;
  }
}

// --- Desktop: inline nav ------------------------------------------------------

@include bp.xl {
  .burger,
  .drawer-head,
  .drawer-user,
  .backdrop {
    display: none;
  }

  .drawer {
    position: static;
    width: auto;
    height: auto;
    display: block;
    padding: 0;
    overflow: visible;
    background: none;
    border: 0;
    box-shadow: none;
    transform: none;
    visibility: visible;
    transition: none;
  }

  nav .nav {
    flex-direction: row;
    justify-content: end;
    align-items: baseline;
    margin: 0 calc(var(--pico-nav-element-spacing-horizontal) * -1) 0 0;

    li {
      display: inline-block;
      padding: var(--pico-nav-element-spacing-vertical) var(--pico-nav-element-spacing-horizontal);
    }

    .nav-link {
      display: inline-block;
      margin: calc(var(--pico-nav-link-spacing-vertical) * -1)
        calc(var(--pico-nav-link-spacing-horizontal) * -1);
      padding: var(--pico-nav-link-spacing-vertical) var(--pico-nav-link-spacing-horizontal);
      border-bottom: 0;
      border-radius: var(--pico-border-radius);
      color: var(--pico-primary);
      font-size: inherit;

      &.tiny {
        font-size: 0.8rem;
      }

      &.router-link-active {
        color: var(--rg-ink);
      }
    }

    // Pinned to the far right of the bar, whatever else is in the nav.
    .account {
      position: static;
      margin-top: 0;
      margin-left: auto;
      padding-top: var(--pico-nav-element-spacing-vertical);
      padding-bottom: var(--pico-nav-element-spacing-vertical);
      background: none;
    }

    .logout-btn,
    [role='button'] {
      width: auto;
      padding-block: calc(var(--pico-nav-link-spacing-vertical) - var(--pico-border-width) * 2);
    }
  }
}
.mode-toggle {
  display: inline-flex;
  padding: 0.15rem;
  border: 1px solid var(--rg-line);
  border-radius: 999px;
  background: var(--rg-surface);
}

.mode-option {
  width: auto;
  margin: 0;
  padding: 0.25rem 0.75rem;
  border: 0;
  border-radius: 999px;
  background: none;
  color: var(--rg-muted);
  font-size: 0.85rem;
  line-height: 1.3;

  &:hover,
  &:focus-visible {
    background: var(--rg-orange-50);
    color: var(--rg-ink);
  }
}

.mode-option--on,
.mode-option--on:hover {
  background: var(--pico-primary);
  color: #fff;
}

.add-role {
  font-weight: 600;
}
.mode-option {
  position: relative;
}

.mode-dot {
  position: absolute;
  top: 0.1rem;
  right: 0.25rem;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: var(--rg-red);
}
</style>
