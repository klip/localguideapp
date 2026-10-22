<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from './BrandMark.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'
import NavIcon from './NavIcon.vue'
import { t, tn } from '@/i18n'
import { useApi, type Role } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useAvatarStore } from '@/stores/avatar'
import { useBadgesStore } from '@/stores/badges'
import { useMessagesStore } from '@/stores/messages'

const api = useApi()
const auth = useAuthStore()
const { isAuthenticated, user } = storeToRefs(auth)
const messages = useMessagesStore()
const router = useRouter()
const route = useRoute()

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

/** Guides never browse guides — their deck is /guide/discover (see DiscoverPage.vue's redirect guard). */
const discoverTo = computed(() => (auth.activeRole === 'guide' ? '/guide/discover' : '/discover'))

/** The profile circle: the user's photo, else their initial (see `stores/avatar.ts`). */
const avatar = useAvatarStore()
const { image: avatarImage } = storeToRefs(avatar)
const initial = computed(() =>
  Array.from((user.value?.name || user.value?.email || '?').trim())[0]!.toUpperCase(),
)
watch(
  () => user.value?.id ?? null,
  (id) => {
    if (id === null) avatar.clear()
    else void avatar.load(api, id)
  },
  { immediate: true },
)

/**
 * The role switch: a dual-role account gets "Travel [switch] Guide" (on =
 * guiding); a one-role account is offered the other role ("Start
 * travelling" / "Become a guide", through the profile page).
 */
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
 * Mobile menu. Below `xl` the same `.drawer` whose parts sit in the desktop
 * header's two rows becomes an off-canvas panel sliding in from the right
 * (pure CSS — see the styles below), so everything is only written once.
 * While open: page scroll is locked, Tab cycles within the drawer, Escape /
 * the backdrop / the close button / following any link closes it.
 */
const DESKTOP_QUERY = '(min-width: 1280px)' // keep in sync with `bp.$xl`

const menuOpen = ref(false)
const burgerRef = ref<HTMLButtonElement | null>(null)
const drawerRef = ref<HTMLElement | null>(null)

function focusables(): HTMLElement[] {
  return Array.from(
    drawerRef.value?.querySelectorAll<HTMLElement>(
      'a[href], button:not([disabled]), select:not([disabled])',
    ) ?? [],
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
        <BrandMark class="logo" />

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

        <!--
          Desktop: the drawer's parts are laid out on the header's grid —
          logo + language on the left and role switch, profile circle and
          Log out on the right of the top row, the links in a row below.
          Mobile: the same parts, top to bottom, in a slide-in panel.
        -->
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

          <div class="language"><LanguageSwitcher /></div>

          <RouterLink
            v-if="user"
            to="/profile"
            class="me"
            :title="t('common.app_header.profile')"
            data-test="nav-profile"
          >
            <span class="avatar" aria-hidden="true">
              <img v-if="avatarImage" :src="avatarImage" alt="" />
              <template v-else>{{ initial }}</template>
            </span>
            <span class="me-text">
              <strong class="me-label">{{ t('common.app_header.profile') }}</strong>
              <span class="me-name tiny muted">{{
                t('common.app_header.signed_in_as', { name: user.name || user.email })
              }}</span>
            </span>
          </RouterLink>

          <ul class="nav">
            <!-- Marketing copy for prospects only — a signed-in user is past it. -->
            <li v-if="!isAuthenticated">
              <RouterLink to="/about#how" class="nav-link"
                ><NavIcon name="how" />{{ t('common.app_header.how_it_works') }}</RouterLink
              >
            </li>
            <li>
              <RouterLink :to="discoverTo" class="nav-link"
                ><NavIcon name="discover" />{{ t('common.app_header.discover') }}</RouterLink
              >
            </li>
            <li v-if="isAuthenticated && activeRole === 'guest'">
              <RouterLink to="/planner" class="nav-link" data-test="nav-planner"
                ><NavIcon name="planner" />{{ t('common.app_header.planner') }}</RouterLink
              >
            </li>
            <li v-if="isAuthenticated && activeRole === 'guide'">
              <RouterLink to="/calendar" class="nav-link" data-test="nav-calendar"
                ><NavIcon name="calendar" />{{ t('common.app_header.calendar') }}</RouterLink
              >
            </li>
            <li v-if="isAuthenticated">
              <RouterLink to="/matches" class="nav-link nav-link--counted">
                <NavIcon name="matches" />{{ t('common.app_header.matches')
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
                <NavIcon name="trips" />{{ t('common.app_header.my_trips')
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
                <NavIcon name="messages" />{{ t('common.app_header.messages')
                }}<span v-if="unreadMessages" class="nav-count" data-test="messages-count"
                  ><span aria-hidden="true">{{ badgeText(unreadMessages) }}</span
                  ><span class="visually-hidden">{{
                    tn('common.app_header.unread_count', unreadMessages)
                  }}</span></span
                >
              </RouterLink>
            </li>
          </ul>

          <div v-if="canSwitch || missingRole" class="role-switch">
            <div v-if="canSwitch" class="role-toggle">
              <!-- The words switch too (mouse/touch); the switch itself is the control. -->
              <span
                class="role-word"
                :class="{ 'role-word--on': activeRole === 'guest' }"
                aria-hidden="true"
                @click="switchRole('guest')"
                >{{ t('common.app_header.switch_travel')
                }}<span
                  v-if="otherRoleNews && activeRole !== 'guest'"
                  class="role-dot"
                  data-test="mode-news"
                  :title="t('common.app_header.other_role_news')"
              /></span>
              <input
                type="checkbox"
                role="switch"
                class="role-switch-input"
                :checked="activeRole === 'guide'"
                :aria-label="t('common.app_header.mode_guide')"
                :aria-describedby="otherRoleNews ? 'other-role-news' : undefined"
                data-test="role-switch"
                @change="
                  switchRole(($event.target as HTMLInputElement).checked ? 'guide' : 'guest')
                "
              />
              <span
                class="role-word"
                :class="{ 'role-word--on': activeRole === 'guide' }"
                aria-hidden="true"
                @click="switchRole('guide')"
                >{{ t('common.app_header.mode_guide')
                }}<span
                  v-if="otherRoleNews && activeRole !== 'guide'"
                  class="role-dot"
                  data-test="mode-news"
                  :title="t('common.app_header.other_role_news')"
              /></span>
              <span v-if="otherRoleNews" id="other-role-news" class="visually-hidden">{{
                t('common.app_header.other_role_news')
              }}</span>
            </div>
            <RouterLink
              v-else-if="missingRole"
              :to="{ path: '/profile', query: { addRole: missingRole } }"
              class="role-btn"
              data-test="add-role-link"
              >{{
                t(
                  missingRole === 'guide'
                    ? 'common.app_header.become_guide'
                    : 'common.app_header.want_to_travel',
                )
              }}</RouterLink
            >
          </div>

          <div class="account">
            <button v-if="isAuthenticated" type="button" class="outline logout-btn" @click="logout">
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
            <template v-else>
              <RouterLink to="/login" class="login-link">{{
                t('common.app_header.log_in')
              }}</RouterLink>
              <RouterLink to="/register" role="button" class="get-started">{{
                t('common.app_header.get_started')
              }}</RouterLink>
            </template>
          </div>
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
$link-color: #000;

.topbar {
  position: sticky;
  top: 0;
  z-index: 30;
  border-bottom: 1px solid rgba(234, 223, 216, 0.8);

  // The blur lives on a pseudo-element, not .topbar itself: `backdrop-filter`
  // makes an element the containing block for its `position: fixed`
  // descendants, which would trap the mobile drawer inside the header
  // instead of the viewport.
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

.logo {
  font-size: 1.1rem;
}

// --- Menu links ------------------------------------------------------------

nav .nav {
  margin: 0;

  li {
    padding: 0;
  }
}

.nav-link {
  color: $link-color;
  font-size: 0.9rem;
  text-decoration: none;

  &:hover,
  &:focus-visible {
    color: $link-color;
    text-decoration: underline;
    text-underline-offset: 0.55em;
  }

  &.router-link-active {
    color: $link-color;
    font-weight: 600;
  }
}

// The red unseen-count bubble: beside the label in the mobile drawer, over
// the item's top-right corner in the desktop row.
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
  text-shadow: none;
  pointer-events: none;

  @include bp.xl {
    position: absolute;
    top: -0.55rem;
    inset-inline-end: -0.95rem;
    margin: 0;
  }
}

// --- Profile circle, role switch, account ------------------------------------

.me {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  color: var(--rg-ink);
  text-decoration: none;
}

.avatar {
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.4rem;
  height: 2.4rem;
  overflow: hidden;
  border-radius: 50%;
  border: 2px solid #fff;
  box-shadow: 0 0 0 1px var(--rg-line);
  background: var(--rg-orange-100);
  color: var(--rg-orange-500);
  font-weight: 800;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.me:hover .avatar,
.me:focus-visible .avatar,
.me.router-link-active .avatar {
  box-shadow: 0 0 0 2px var(--rg-orange-300);
}

.me-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.me-name {
  overflow-wrap: anywhere;
}

.role-btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: auto;
  margin: 0;
  padding: 0.4rem 1rem;
  border: 1px solid var(--rg-ink);
  border-radius: 999px;
  background: #fff;
  color: var(--rg-ink);
  font-size: 0.85rem;
  font-weight: 600;
  line-height: 1.3;
  text-decoration: none;

  &:hover,
  &:focus-visible {
    background: var(--rg-ink);
    border-color: var(--rg-ink);
    color: #fff;
  }
}

// "Travel [switch] Guide" — Pico's switch, the current role's word in bold.
.role-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  white-space: nowrap;

  .role-switch-input {
    margin: 0;
  }
}

.role-word {
  position: relative;
  color: var(--rg-muted);
  cursor: pointer;
}

.role-word--on {
  color: var(--rg-ink);
  font-weight: 600;
  cursor: default;
}

.role-dot {
  position: absolute;
  top: -0.3rem;
  inset-inline-end: -0.55rem;
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 50%;
  background: #e5332a;
  box-shadow: 0 0 0 2px #fff;
}

.account {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.logout-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.logout-icon {
  flex-shrink: 0;
}

.login-link {
  color: $link-color;
  font-size: 0.9rem;
}

// --- Burger and close buttons ----------------------------------------------

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
// Top to bottom: logo + close, language, profile, links, role switch, and
// Log out pinned to the foot.

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
  font-size: 1.05rem;
}

.language {
  margin-bottom: 0.75rem;

  :deep(.language-switcher) {
    width: 100%;
  }
}

.me {
  padding: 0.6rem 0.25rem;
  border-bottom: 1px solid var(--rg-line);
}

nav .nav {
  flex-direction: column;
  align-items: stretch;

  li {
    display: block;
  }

  .nav-link {
    display: block;
    margin: 0;
    padding: 0.8rem 0.25rem;
    border-radius: 0;
    border-bottom: 1px solid var(--rg-line);
  }
}

.role-switch {
  padding-top: 0.75rem;

  .role-btn {
    width: 100%;
  }

  .role-toggle {
    display: flex;
    justify-content: center;
    padding-block: 0.25rem;
  }
}

// Account actions sit at the foot of the drawer — `margin-top: auto` when
// the links are short, and stuck to the bottom edge (rather than scrolled
// out of reach) once they overflow a short viewport.
.account {
  position: sticky;
  bottom: 0;
  flex-direction: column-reverse;
  align-items: stretch;
  gap: 0.75rem;
  margin-top: auto;
  padding-top: 1.25rem;
  padding-bottom: 0.25rem;
  background: var(--rg-surface);

  .logout-btn,
  [role='button'] {
    width: 100%;
    margin: 0;
    justify-content: center;
    padding-block: 0.7rem;
  }

  .login-link {
    text-align: center;
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

// --- Desktop: two rows ---------------------------------------------------------
// Top: logo, language … role switch, profile circle, Log out. Below: links.

@include bp.xl {
  nav {
    display: grid;
    grid-template-columns: auto auto 1fr auto auto auto;
    grid-template-areas:
      'logo lang . role me account'
      'links links links links links links';
    column-gap: 1rem;
    padding-block: 0.6rem 0;
  }

  .logo {
    grid-area: logo;
  }

  .burger,
  .drawer-head,
  .me-text,
  .backdrop {
    display: none;
  }

  // Its parts are placed on the nav's grid directly.
  .drawer {
    display: contents;
    visibility: visible;
  }

  .language {
    grid-area: lang;
    margin: 0;

    :deep(.language-switcher) {
      width: auto;
    }
  }

  .role-switch {
    grid-area: role;
    padding: 0;

    .role-btn {
      width: auto;
    }
  }

  .me {
    grid-area: me;
    padding: 0;
    border: 0;
  }

  .account {
    grid-area: account;
    position: static;
    flex-direction: row;
    align-items: center;
    gap: 1rem;
    margin: 0;
    padding: 0;
    background: none;

    .logout-btn,
    [role='button'] {
      width: auto;
      padding: 0.4rem 0.9rem;
      font-size: 0.85rem;
    }
  }

  nav .nav {
    grid-area: links;
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    gap: 0 1.75rem;
    margin-top: 0.6rem;
    padding: 0.55rem 0 2px;

    li {
      display: inline-block;
    }

    .nav-link {
      display: inline-block;
      padding: 0.15rem 0 0;
      border-bottom: 0;

      &.router-link-active {
        font-weight: inherit;
        text-decoration: underline 4px;
        text-underline-offset: 0.35em;
      }
    }
  }
}
</style>
