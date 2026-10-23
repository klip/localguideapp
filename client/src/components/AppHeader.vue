<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from './BrandMark.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'
import CurrencySwitcher from './CurrencySwitcher.vue'
import PicksButton from './PicksButton.vue'
import NavIcon from './NavIcon.vue'
import { currentLanguage, t, tn } from '@/i18n'
import { useApi, type Role } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useAvatarStore } from '@/stores/avatar'
import { useBadgesStore } from '@/stores/badges'
import { useMessagesStore } from '@/stores/messages'
import { activeCurrency } from '@/utils/money'

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
/** News that's only reachable through the menu: the counted links are tabs of their own. */
const hasMenuNews = computed(() => isAuthenticated.value && otherRoleNews.value)

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
 * The role switch: a dual-role account gets "Travel | Guide" as a segmented
 * control; a one-role account is offered the other role ("Start
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
 * Language and currency: on desktop one small "🌐 EN · €" button opening
 * both pickers; in the mobile menu the two pickers sit side by side.
 */
const localeOpen = ref(false)
const localeRef = ref<HTMLElement | null>(null)
const currencySymbol = computed(() => {
  try {
    const part = new Intl.NumberFormat('en', { style: 'currency', currency: activeCurrency.value })
      .formatToParts(0)
      .find((p) => p.type === 'currency')
    return part?.value ?? activeCurrency.value
  } catch {
    return activeCurrency.value
  }
})
const localeLabel = computed(
  () => `${currentLanguage.value.toUpperCase()} · ${currencySymbol.value}`,
)

function onDocumentPointer(event: PointerEvent) {
  if (localeOpen.value && !localeRef.value?.contains(event.target as Node)) localeOpen.value = false
}

function onLocaleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && localeOpen.value) {
    event.stopPropagation()
    localeOpen.value = false
  }
}

/**
 * Mobile menu. Below `xl` the same `.drawer` whose parts sit in the desktop
 * header becomes an off-canvas panel sliding in from the right (pure CSS —
 * see the styles below), opened by the tab bar's Menu button, so everything
 * is only written once. While open: page scroll is locked, Tab cycles
 * within the drawer, Escape / the backdrop / the close button / following
 * any link closes it.
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
  ).filter((el) => !el.closest('.locale-toggle-wrap'))
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
  () => {
    closeMenu()
    localeOpen.value = false
  },
)

watch(menuOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
})

let desktopQuery: MediaQueryList | null = null
function onViewportChange(event: MediaQueryListEvent) {
  if (event.matches) closeMenu()
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointer)
  // Absent in jsdom (unit tests) — nothing to react to there anyway.
  if (typeof window.matchMedia !== 'function') return
  desktopQuery = window.matchMedia(DESKTOP_QUERY)
  desktopQuery.addEventListener('change', onViewportChange)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointer)
  desktopQuery?.removeEventListener('change', onViewportChange)
  document.body.style.overflow = ''
})
</script>

<template>
  <header class="topbar">
    <div class="topbar-inner">
      <nav :aria-label="t('common.app_header.nav_label')">
        <BrandMark class="logo" comb />

        <!-- A traveller's picks counter, right of the links on desktop. -->
        <PicksButton class="picks" />

        <!-- Mobile: the profile circle sits in the bar; the menu is a tab. -->
        <div class="bar-end">
          <RouterLink
            v-if="user"
            to="/profile"
            class="bar-profile"
            :title="t('common.app_header.profile')"
            :aria-label="t('common.app_header.profile')"
            data-test="bar-profile"
          >
            <span class="avatar" aria-hidden="true">
              <img v-if="avatarImage" :src="avatarImage" alt="" />
              <template v-else>{{ initial }}</template>
            </span>
          </RouterLink>
        </div>

        <!--
          Desktop: the drawer's parts are laid out on the header's grid —
          logo and links on the left; picks, language, role, profile circle
          and Log out on the right. Mobile: the same parts, top to bottom, in
          a slide-in panel.
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

          <div ref="localeRef" class="language" @keydown="onLocaleKeydown">
            <span class="locale-toggle-wrap">
              <button
                type="button"
                class="locale-toggle"
                aria-controls="locale-panel"
                :aria-expanded="localeOpen"
                :title="t('common.app_header.locale')"
                data-test="locale-toggle"
                @click="localeOpen = !localeOpen"
              >
                <svg
                  viewBox="0 0 24 24"
                  width="16"
                  height="16"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  aria-hidden="true"
                >
                  <circle cx="12" cy="12" r="9" />
                  <path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18" />
                </svg>
                <span class="visually-hidden">{{ t('common.app_header.locale') }}: </span
                >{{ localeLabel }}
              </button>
            </span>
            <div
              id="locale-panel"
              class="locale-panel"
              :class="{ 'locale-panel--open': localeOpen }"
            >
              <LanguageSwitcher /><CurrencySwitcher />
            </div>
          </div>

          <!-- Desktop only; on mobile the circle is in the bar (.bar-profile). -->
          <RouterLink
            v-if="user"
            to="/profile"
            class="me"
            :title="t('common.app_header.profile')"
            :aria-label="t('common.app_header.profile')"
            data-test="nav-profile"
          >
            <span class="avatar" aria-hidden="true">
              <img v-if="avatarImage" :src="avatarImage" alt="" />
              <template v-else>{{ initial }}</template>
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
              <RouterLink to="/matches" class="nav-link">
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
              <RouterLink to="/trips" class="nav-link">
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
              <RouterLink to="/payments" class="nav-link" data-test="nav-payments"
                ><NavIcon name="wallet" />{{ t('common.app_header.payments') }}</RouterLink
              >
            </li>
            <li v-if="isAuthenticated">
              <RouterLink to="/messages" class="nav-link">
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
            <div
              v-if="canSwitch"
              class="role-toggle"
              role="group"
              :aria-label="t('common.app_header.role_label')"
              :aria-describedby="otherRoleNews ? 'other-role-news' : undefined"
              data-test="role-switch"
            >
              <button
                type="button"
                class="role-seg"
                :aria-pressed="activeRole === 'guest'"
                data-test="role-guest"
                @click="switchRole('guest')"
              >
                {{ t('common.app_header.switch_travel')
                }}<span
                  v-if="otherRoleNews && activeRole !== 'guest'"
                  class="role-dot"
                  data-test="mode-news"
                  :title="t('common.app_header.other_role_news')"
                />
              </button>
              <button
                type="button"
                class="role-seg"
                :aria-pressed="activeRole === 'guide'"
                data-test="role-guide"
                @click="switchRole('guide')"
              >
                {{ t('common.app_header.mode_guide')
                }}<span
                  v-if="otherRoleNews && activeRole !== 'guide'"
                  class="role-dot"
                  data-test="mode-news"
                  :title="t('common.app_header.other_role_news')"
                />
              </button>
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
            <button
              v-if="isAuthenticated"
              type="button"
              class="secondary logout-btn"
              :title="t('common.app_header.log_out')"
              @click="logout"
            >
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
              <span class="logout-label">{{ t('common.app_header.log_out') }}</span>
            </button>
            <!-- Mobile: just above Log out (the column runs bottom-up). -->
            <p v-if="user" class="signed-in tiny muted" data-test="signed-in-as">
              {{ t('common.app_header.signed_in_as', { name: user.name || user.email }) }}
            </p>
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

    <!-- Mobile: the main sections as a tab bar at the foot of the screen; Menu opens the drawer. -->
    <nav class="tabbar" :aria-label="t('common.app_header.nav_label')" data-test="tabbar">
      <RouterLink :to="discoverTo" class="tab"
        ><NavIcon name="discover" /><span class="tab-label">{{
          t('common.app_header.discover')
        }}</span></RouterLink
      >
      <template v-if="isAuthenticated">
        <RouterLink to="/matches" class="tab"
          ><NavIcon name="matches" /><span class="tab-label">{{
            t('common.app_header.matches')
          }}</span
          ><span v-if="newMatches" class="tab-count" data-test="tab-matches-count"
            ><span aria-hidden="true">{{ badgeText(newMatches) }}</span
            ><span class="visually-hidden">{{
              t('common.app_header.new_count', { count: newMatches })
            }}</span></span
          ></RouterLink
        >
        <RouterLink to="/trips" class="tab"
          ><NavIcon name="trips" /><span class="tab-label">{{
            t('common.app_header.my_trips')
          }}</span
          ><span v-if="newTrips" class="tab-count" data-test="tab-trips-count"
            ><span aria-hidden="true">{{ badgeText(newTrips) }}</span
            ><span class="visually-hidden">{{
              t('common.app_header.new_count', { count: newTrips })
            }}</span></span
          ></RouterLink
        >
        <RouterLink to="/messages" class="tab"
          ><NavIcon name="messages" /><span class="tab-label">{{
            t('common.app_header.messages')
          }}</span
          ><span v-if="unreadMessages" class="tab-count" data-test="tab-messages-count"
            ><span aria-hidden="true">{{ badgeText(unreadMessages) }}</span
            ><span class="visually-hidden">{{
              tn('common.app_header.unread_count', unreadMessages)
            }}</span></span
          ></RouterLink
        >
      </template>
      <template v-else>
        <RouterLink to="/about#how" class="tab"
          ><NavIcon name="how" /><span class="tab-label">{{
            t('common.app_header.how_it_works')
          }}</span></RouterLink
        >
        <RouterLink to="/login" class="tab"
          ><NavIcon name="login" /><span class="tab-label">{{
            t('common.app_header.log_in')
          }}</span></RouterLink
        >
      </template>
      <button
        ref="burgerRef"
        type="button"
        class="tab burger"
        aria-controls="site-menu"
        :aria-expanded="menuOpen"
        :aria-label="t('common.app_header.open_menu')"
        @click="openMenu"
      >
        <NavIcon name="menu" /><span class="tab-label" aria-hidden="true">{{
          t('common.app_header.menu')
        }}</span>
        <!-- Things only the menu leads to (the other role's news). -->
        <span v-if="hasMenuNews" class="burger-dot" aria-hidden="true" />
      </button>
    </nav>
  </header>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

$drawer-speed: 0.28s;
$tabbar-height: 4rem;

.topbar {
  position: sticky;
  top: 0;
  z-index: 30;
  border-bottom: 1px solid var(--rg-line);

  // The blur lives on a pseudo-element, not .topbar itself: `backdrop-filter`
  // makes an element the containing block for its `position: fixed`
  // descendants, which would trap the mobile drawer and the tab bar inside
  // the header instead of the viewport.
  &::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    backdrop-filter: blur(14px);
    background: rgba(255, 255, 255, 0.86);
  }
}

.topbar-inner {
  max-width: var(--rg-header-width);
  margin-inline: auto;
  padding-inline: 1rem;

  @include bp.md {
    padding-inline: 1.75rem;
  }
}

nav {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-height: 3.5rem;
}

.logo {
  font-size: 1.4rem;
  margin-inline-end: auto;
}

// --- Menu links ------------------------------------------------------------

nav .nav {
  margin: 0;
  padding: 0;

  li {
    padding: 0;
    list-style: none;
  }
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--rg-ink);
  font-size: 0.9375rem;
  font-weight: 500;
  text-decoration: none;

  :deep(.nav-icon) {
    margin: 0;
  }

  &:hover,
  &:focus-visible {
    color: var(--rg-ink);
    text-decoration: none;
    background: var(--rg-sunken);
  }

  &.router-link-active {
    color: var(--rg-ink);
    font-weight: 600;
    background: var(--rg-orange-50);
  }
}

// The coral unseen-count bubble, after the label.
.nav-count,
.tab-count {
  display: inline-grid;
  place-items: center;
  min-width: 1.15rem;
  height: 1.15rem;
  padding: 0 0.3rem;
  border-radius: 999px;
  background: var(--rg-coral);
  color: #fff;
  font-size: 0.6875rem;
  font-weight: 700;
  line-height: 1;
  pointer-events: none;
}

// --- Profile circle, role switch, account ------------------------------------

.me {
  display: flex;
  align-items: center;
  color: var(--rg-ink);
  text-decoration: none;
}

.avatar {
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  overflow: hidden;
  border-radius: 50%;
  border: 2px solid #fff;
  box-shadow: 0 0 0 1px var(--rg-line-strong);
  background: var(--rg-orange-100);
  color: var(--rg-orange-ink);
  font-weight: 800;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.me:hover .avatar,
.me:focus-visible .avatar,
.me.router-link-active .avatar,
.bar-profile:hover .avatar,
.bar-profile:focus-visible .avatar,
.bar-profile.router-link-active .avatar {
  box-shadow: 0 0 0 2px var(--rg-orange-500);
}

.bar-end {
  display: flex;
  align-items: center;
}

.bar-profile {
  display: inline-flex;
  text-decoration: none;
}

.signed-in {
  margin: 0;
  text-align: center;
  overflow-wrap: anywhere;
}

.role-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: var(--rg-control-height-sm);
  padding: 0 0.85rem;
  border: 1px solid var(--rg-line-strong);
  border-radius: var(--rg-radius-control);
  background: #fff;
  color: var(--rg-ink);
  font-size: 0.875rem;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;

  &:hover,
  &:focus-visible {
    background: var(--rg-orange-50);
    border-color: var(--rg-orange-300);
    color: var(--rg-ink);
  }
}

// "Travel | Guide": a segmented control, the current role raised.
.role-toggle {
  display: inline-flex;
  align-items: stretch;
  gap: 2px;
  height: var(--rg-control-height-sm);
  padding: 3px;
  border: 1px solid var(--rg-line-strong);
  border-radius: var(--rg-radius-control);
  background: var(--rg-sunken);
}

.role-seg {
  position: relative;
  display: inline-flex;
  align-items: center;
  width: auto;
  height: auto;
  margin: 0;
  padding: 0 0.75rem;
  border: 0;
  border-radius: 0.4rem;
  background: transparent;
  color: var(--rg-muted);
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;
  box-shadow: none;

  &:hover,
  &:focus-visible {
    background: transparent;
    color: var(--rg-ink);
  }

  &[aria-pressed='true'] {
    background: #fff;
    color: var(--rg-ink);
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(42, 31, 23, 0.14);
  }
}

.role-dot {
  position: absolute;
  top: 0.15rem;
  inset-inline-end: 0.15rem;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: var(--rg-coral);
}

.account {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.logout-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  margin: 0;
}

.logout-icon {
  flex-shrink: 0;
}

.login-link {
  color: var(--rg-ink);
  font-size: 0.9375rem;
  font-weight: 500;
}

// --- Language & currency ------------------------------------------------------

.locale-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  width: auto;
  height: var(--rg-control-height-sm);
  margin: 0;
  padding: 0 0.6rem;
  border: 1px solid transparent;
  border-radius: var(--rg-radius-control);
  background: transparent;
  color: var(--rg-ink);
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;

  &:hover,
  &:focus-visible,
  &[aria-expanded='true'] {
    background: var(--rg-sunken);
    border-color: var(--rg-line);
    color: var(--rg-ink);
  }
}

.locale-panel {
  display: flex;
  gap: 0.5rem;

  :deep(select) {
    height: var(--rg-control-height);
    margin: 0;
  }
}

// --- Close button --------------------------------------------------------------

.drawer-close {
  display: inline-grid;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  padding: 0;
  margin: 0;
  border-radius: var(--rg-radius-control);
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

// --- Mobile: tab bar ------------------------------------------------------------

.tabbar {
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: 1;
  display: grid;
  grid-auto-columns: 1fr;
  grid-auto-flow: column;
  gap: 0;
  min-height: $tabbar-height;
  padding: 0.35rem 0.25rem calc(0.35rem + env(safe-area-inset-bottom));
  background: #fff;
  border-top: 1px solid var(--rg-line);
}

.tab {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.2rem;
  width: auto;
  height: auto;
  margin: 0;
  padding: 0.25rem 0.1rem;
  border: 0;
  border-radius: var(--rg-radius-control);
  background: transparent;
  color: var(--rg-muted);
  font-size: 0.6875rem;
  font-weight: 500;
  line-height: 1.1;
  text-decoration: none;
  box-shadow: none;

  :deep(.nav-icon) {
    width: 22px;
    height: 22px;
    margin: 0;
  }

  &:hover,
  &:focus-visible {
    background: transparent;
    color: var(--rg-ink);
  }

  &.router-link-active {
    color: var(--rg-ink);
    font-weight: 700;

    :deep(.nav-icon) {
      color: var(--rg-orange-500);
    }
  }
}

.tab-label {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tab-count {
  position: absolute;
  top: 0.05rem;
  left: calc(50% + 0.35rem);
}

.burger-dot {
  position: absolute;
  top: 0.3rem;
  left: calc(50% + 0.5rem);
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: var(--rg-coral);
  box-shadow: 0 0 0 2px #fff;
}

// --- Mobile: off-canvas drawer (default) -------------------------------------
// Top to bottom: logo + close, language, links, role switch, and Log out
// pinned to the foot.

.drawer {
  position: fixed;
  top: 0;
  right: 0;
  z-index: 3;
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
  font-size: 1.3rem;
}

.language {
  margin-bottom: 0.75rem;

  .locale-toggle-wrap {
    display: none;
  }

  .locale-panel :deep(select) {
    flex: 1 1 0;
    min-width: 0;
  }
}

// The drawer's profile circle is the desktop one; mobile has it in the bar.
.me {
  display: none;
}

nav .nav {
  display: flex;
  flex-direction: column;
  align-items: stretch;

  li {
    display: block;
  }

  .nav-link {
    min-height: 2.75rem;
    margin: 0;
    padding: 0 0.6rem;
    border-radius: var(--rg-radius-control);
  }
}

.role-switch {
  padding-top: 0.75rem;

  .role-btn {
    width: 100%;
  }

  .role-toggle {
    display: flex;

    .role-seg {
      flex: 1;
      justify-content: center;
    }
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
  }

  .login-link {
    text-align: center;
  }
}

.backdrop {
  position: fixed;
  inset: 0;
  z-index: 2;
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

// --- Desktop ---------------------------------------------------------------------
// 1280–1439: logo … picks, language, role, profile, Log out on top; links
// below. From 1440: all of it on one row (docs/design/sketches/04-…png).

@include bp.xl {
  nav {
    display: grid;
    grid-template-columns: auto 1fr auto auto auto auto auto;
    grid-template-areas:
      'logo . picks lang role me account'
      'links links links links links links links';
    column-gap: 0.75rem;
    min-height: 0;
    padding-block: 0.5rem 0;
  }

  .logo {
    grid-area: logo;
    margin: 0;
  }

  .picks {
    grid-area: picks;
  }

  .bar-end,
  .drawer-head,
  .signed-in,
  .backdrop,
  .tabbar {
    display: none;
  }

  // Its parts are placed on the nav's grid directly.
  .drawer {
    display: contents;
    visibility: visible;
  }

  .language {
    grid-area: lang;
    position: relative;
    margin: 0;

    .locale-toggle-wrap {
      display: inline-flex;
    }
  }

  .locale-panel {
    position: absolute;
    top: calc(100% + 0.4rem);
    right: 0;
    z-index: 5;
    display: none;
    flex-direction: column;
    width: 14rem;
    padding: 0.75rem;
    background: #fff;
    border: 1px solid var(--rg-line);
    border-radius: var(--rg-radius-soft);
    box-shadow: var(--rg-shadow);
  }

  .locale-panel--open {
    display: flex;
  }

  .role-switch {
    grid-area: role;
    padding: 0;

    .role-btn {
      width: auto;
    }

    .role-toggle {
      display: inline-flex;

      .role-seg {
        flex: 0 0 auto;
      }
    }
  }

  .me {
    display: flex;
    grid-area: me;
  }

  .account {
    grid-area: account;
    position: static;
    flex-direction: row;
    align-items: center;
    gap: 0.75rem;
    margin: 0;
    padding: 0;
    background: none;

    [role='button'] {
      width: auto;
      height: var(--rg-control-height-sm);
      padding: 0 0.9rem;
      font-size: 0.875rem;
    }

    // Icon only on desktop; the label stays for screen readers.
    .logout-btn {
      width: var(--rg-control-height-sm);
      height: var(--rg-control-height-sm);
      padding: 0;
    }

    .logout-label {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
    }
  }

  nav .nav {
    grid-area: links;
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.25rem;
    padding: 0.4rem 0 0.5rem;

    li {
      display: inline-block;
    }

    .nav-link {
      min-height: 2.25rem;
      padding: 0 0.7rem;
      border-radius: 0.5rem;
      font-size: 0.875rem;
    }
  }
}

@include bp.xxl {
  nav {
    grid-template-columns: auto auto 1fr auto auto auto auto auto;
    grid-template-areas: 'logo links . picks lang role me account';
    min-height: 3.75rem;
    padding-block: 0;
  }

  .logo {
    margin-inline-end: 1rem;
  }

  nav .nav {
    padding: 0;
    flex-wrap: nowrap;
  }
}
</style>
