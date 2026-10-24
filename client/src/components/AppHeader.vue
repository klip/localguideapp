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
import { honeycombPath } from '@/utils/honeycomb'

/** The hand-drawn honeycomb behind the logo — same drawing every load (fixed seed). */
const COMB = honeycombPath(360, 120, 15)

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

/**
 * The header's layout is measured, not set by a breakpoint:
 *   - `wide`    — one row: logo, the links (icon + label) centred, the controls;
 *   - `compact` — the same row with each label under its icon;
 *   - `mobile`  — the links move to the tab bar at the foot, the menu button
 *                 and the drawer take the rest.
 * It steps down when the links overflow their room and back up once the
 * window is as wide as the step needed (remembered when it stepped down).
 * A change of role, sign-in or language changes the links, so it measures
 * afresh from `wide`. Before mounting it guesses from the window.
 */
type HeaderMode = 'wide' | 'compact' | 'mobile'
const mode = ref<HeaderMode>(
  typeof window !== 'undefined' && window.innerWidth >= 1024 ? 'wide' : 'mobile',
)
const isDesktop = computed(() => mode.value !== 'mobile')
const linksRef = ref<HTMLElement | null>(null)
/** Window widths at which `wide` / `compact` were last seen to fit. */
let wideNeeds = 0
let compactNeeds = 0
let fitting = false

function overflow(): number {
  const list = linksRef.value
  return list ? list.scrollWidth - list.clientWidth : 0
}

async function fit() {
  if (fitting || typeof window === 'undefined') return
  fitting = true
  try {
    const width = window.innerWidth
    // Step up while there's room for the next mode…
    if (mode.value === 'mobile' && compactNeeds && width >= compactNeeds) mode.value = 'compact'
    if (mode.value === 'compact' && width >= wideNeeds) mode.value = 'wide'
    await nextTick()
    // …and down while the links don't fit.
    if (mode.value === 'wide' && overflow() > 1) {
      wideNeeds = width + overflow()
      mode.value = 'compact'
      await nextTick()
    }
    if (mode.value === 'compact' && overflow() > 1) {
      compactNeeds = width + overflow()
      mode.value = 'mobile'
    }
  } finally {
    fitting = false
  }
}

/** The links themselves changed: measure from the top again. */
function remeasure() {
  wideNeeds = 0
  compactNeeds = 0
  mode.value = 'wide'
  void nextTick(fit)
}
watch([isAuthenticated, activeRole, currentLanguage], remeasure)

watch(isDesktop, (desktop) => {
  if (desktop) closeMenu()
  // The page keeps clear of the tab bar only while there is one (_layout.scss).
  document.documentElement.classList.toggle('has-tabbar', !desktop)
})

function onResize() {
  void fit()
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointer)
  document.documentElement.classList.toggle('has-tabbar', !isDesktop.value)
  window.addEventListener('resize', onResize)
  remeasure()
  // Web fonts change the links' widths once they arrive.
  void document.fonts?.ready.then(remeasure)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointer)
  window.removeEventListener('resize', onResize)
  document.documentElement.classList.remove('has-tabbar')
  document.body.style.overflow = ''
})
</script>

<template>
  <header
    class="topbar"
    :class="{
      'topbar--desktop': isDesktop,
      'topbar--compact': mode === 'compact',
    }"
    :data-mode="mode"
  >
    <!-- The honeycomb behind the logo, clipped to the header. -->
    <div class="topbar-comb" aria-hidden="true">
      <div class="topbar-comb-inner">
        <svg viewBox="0 0 360 120" focusable="false"><path :d="COMB" /></svg>
      </div>
    </div>
    <div class="topbar-inner">
      <nav :aria-label="t('common.app_header.nav_label')">
        <BrandMark class="logo" />

        <!-- A traveller's picks counter, right of the links on desktop. -->
        <PicksButton class="picks" />

        <!-- Mobile: the menu button in the bar's top-right corner; the profile is the tab bar's "Me". -->
        <div class="bar-end">
          <button
            ref="burgerRef"
            type="button"
            class="burger"
            aria-controls="site-menu"
            :aria-expanded="menuOpen"
            :aria-label="t('common.app_header.open_menu')"
            @click="openMenu"
          >
            <NavIcon name="menu" />
            <!-- Things only the menu leads to (the other role's news). -->
            <span v-if="hasMenuNews" class="burger-dot" aria-hidden="true" />
          </button>
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
                :title="`${t('common.app_header.locale')} · ${localeLabel}`"
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
                ><span class="locale-label">{{ localeLabel }}</span>
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

          <!-- Desktop only; on mobile the profile is the tab bar's "Me". -->
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

          <ul ref="linksRef" class="nav" :class="{ 'nav--compact': mode === 'compact' }">
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
          ><NavIcon name="chat" /><span class="tab-label">{{ t('common.app_header.chat') }}</span
          ><span v-if="unreadMessages" class="tab-count" data-test="tab-messages-count"
            ><span aria-hidden="true">{{ badgeText(unreadMessages) }}</span
            ><span class="visually-hidden">{{
              tn('common.app_header.unread_count', unreadMessages)
            }}</span></span
          ></RouterLink
        >
        <!-- "Me": the profile, with the user's photo (else their initial) as the icon. -->
        <RouterLink
          to="/profile"
          class="tab tab-me"
          :title="t('common.app_header.profile')"
          data-test="tab-me"
        >
          <span class="tab-avatar" aria-hidden="true">
            <img v-if="avatarImage" :src="avatarImage" alt="" />
            <template v-else>{{ initial }}</template>
          </span>
          <span class="tab-label">{{ t('common.app_header.me') }}</span>
        </RouterLink>
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
    background: rgba(255, 255, 255, 0.9);
  }
}

// The honeycomb behind the logo: its own layer, clipped to the header's box,
// under everything in the bar (the links are opaque on top of it).
.topbar-comb {
  position: absolute;
  inset: 0;
  z-index: -1;
  overflow: hidden;
  pointer-events: none;
}

.topbar-comb-inner {
  position: relative;
  height: 100%;
  max-width: var(--rg-header-width);
  margin-inline: auto;

  svg {
    position: absolute;
    top: 50%;
    left: -4.5rem;
    width: 22.5rem;
    height: 7.5rem;
    transform: translateY(-50%);
    fill: none;
    stroke: #e2ae5c;
    stroke-width: 1.3;
    stroke-linejoin: round;
    opacity: 0.75;
    // Strongest behind the mark, gone by the edges.
    mask-image: radial-gradient(ellipse 45% 50% at 50% 50%, #000 25%, transparent 100%);
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

// Opaque, so the honeycomb never shows through a link.
.nav-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  background: #fff;
  color: var(--rg-ink);
  font-size: 0.9375rem;
  font-weight: 500;
  text-decoration: none;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;

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
  animation: count-pop 0.3s ease;
}

@keyframes count-pop {
  from {
    opacity: 0;
    transform: scale(0.6);
  }
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
  transition: box-shadow 0.15s ease;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.me:hover .avatar,
.me:focus-visible .avatar,
.me.router-link-active .avatar {
  box-shadow: 0 0 0 2px var(--rg-orange-500);
}

.bar-end {
  display: flex;
  align-items: center;
}

// The menu button: the bar's top-right corner on mobile.
.burger {
  position: relative;
  display: inline-grid;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  margin: 0;
  padding: 0;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-control);
  background: #fff;
  color: var(--rg-ink);

  :deep(.nav-icon) {
    width: 22px;
    height: 22px;
    margin: 0;
  }

  &:hover,
  &:focus-visible {
    background: var(--rg-sunken);
    border-color: var(--rg-line-strong);
    color: var(--rg-ink);
  }
}

// "Me" in the tab bar: the photo in place of an icon.
.tab-avatar {
  display: inline-grid;
  place-items: center;
  width: 1.5rem;
  height: 1.5rem;
  overflow: hidden;
  border-radius: 50%;
  background: var(--rg-orange-100);
  color: var(--rg-orange-ink);
  font-size: 0.75rem;
  font-weight: 800;
  box-shadow: 0 0 0 1px var(--rg-line-strong);
  transition:
    box-shadow 0.15s ease,
    transform 0.2s ease;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.tab-me.router-link-active .tab-avatar {
  box-shadow: 0 0 0 2px var(--rg-orange-500);
  transform: translateY(-2px);
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
  transition:
    background-color 0.2s ease,
    box-shadow 0.2s ease,
    color 0.2s ease;

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
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-control);
  background: #fff;
  color: var(--rg-ink);
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;

  &:hover,
  &:focus-visible,
  &[aria-expanded='true'] {
    background: var(--rg-sunken);
    border-color: var(--rg-line-strong);
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
  transition: color 0.15s ease;

  :deep(.nav-icon) {
    width: 22px;
    height: 22px;
    margin: 0;
    transition: transform 0.2s ease;
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
      transform: translateY(-2px);
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
  right: 0.3rem;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: var(--rg-coral);
  box-shadow: 0 0 0 2px #fff;
  animation: count-pop 0.3s ease;
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
  opacity: 0.6;
  // `visibility` keeps the off-screen links out of the tab order and the
  // accessibility tree; delaying it until the slide-out ends keeps the
  // closing animation visible.
  visibility: hidden;
  transition:
    transform $drawer-speed ease,
    opacity $drawer-speed ease,
    visibility 0s linear $drawer-speed;
}

.drawer--open {
  transform: none;
  opacity: 1;
  visibility: visible;
  transition:
    transform $drawer-speed ease,
    opacity $drawer-speed ease;
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

// --- Desktop (`topbar--desktop`, measured — see `fit()`): one row ----------------
// Logo, the links centred in the room left, then picks, language, role,
// profile and Log out. When the links don't fit on one line, `nav--compact`
// puts each label under its icon; when even that doesn't fit, it's mobile.

.topbar--desktop {
  nav {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto auto auto auto auto;
    grid-template-areas: 'logo links picks lang role me account';
    column-gap: 0.75rem;
    min-height: 3.75rem;
  }

  .logo {
    grid-area: logo;
    margin: 0 0.5rem 0 0;
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
    opacity: 1;
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
    display: flex;
    flex-direction: column;
    width: 14rem;
    padding: 0.75rem;
    background: #fff;
    border: 1px solid var(--rg-line);
    border-radius: var(--rg-radius-soft);
    box-shadow: var(--rg-shadow);
    // Drops down from the button: a short fade and slide, hidden (and out
    // of the tab order) until opened.
    opacity: 0;
    transform: translateY(-0.4rem);
    visibility: hidden;
    transition:
      opacity 0.18s ease,
      transform 0.18s ease,
      visibility 0s linear 0.18s;
  }

  .locale-panel--open {
    opacity: 1;
    transform: none;
    visibility: visible;
    transition:
      opacity 0.18s ease,
      transform 0.18s ease;
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
    flex-wrap: nowrap;
    align-items: center;
    // Centred between the logo and the controls; `safe` keeps an overflowing
    // row starting at its left, so its overflow can be measured (`fit()`).
    justify-content: safe center;
    gap: 0.25rem;
    overflow: hidden;

    li {
      display: block;
      flex: 0 0 auto;
    }

    .nav-link {
      min-height: 2.25rem;
      padding: 0 0.7rem;
      border-radius: 0.5rem;
      font-size: 0.875rem;
      white-space: nowrap;
    }
  }

  // Not enough room for one line: the label goes under its icon.
  nav .nav.nav--compact {
    gap: 0.125rem;

    .nav-link {
      flex-direction: column;
      justify-content: center;
      gap: 0.15rem;
      min-height: 3rem;
      padding: 0.25rem 0.35rem;
      font-size: 0.6875rem;
      line-height: 1.1;
    }

    .nav-count {
      position: absolute;
      top: 0.15rem;
      left: calc(50% + 0.3rem);
    }
  }
}

// Tight on room: the language button is just the globe (its label is the
// tooltip and screen-reader text), to leave the links theirs.
.topbar--compact .locale-label {
  display: none;
}

@media (prefers-reduced-motion: reduce) {
  .drawer,
  .drawer--open,
  .backdrop,
  .locale-panel,
  .locale-panel--open {
    transition: none;
  }

  .nav-count,
  .tab-count,
  .burger-dot {
    animation: none;
  }
}
</style>
