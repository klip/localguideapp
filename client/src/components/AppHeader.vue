<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from './BrandMark.vue'
import PillBadge from './PillBadge.vue'
import { useApi } from '@/plugins/api'
import { useShortlistStore } from '@/stores/shortlist'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'

const api = useApi()
const shortlist = useShortlistStore()
const { hasAccess, picks, capacity } = storeToRefs(shortlist)

const auth = useAuthStore()
const { isAuthenticated, user } = storeToRefs(auth)
const messages = useMessagesStore()
const router = useRouter()
const route = useRoute()

/** Guides never pay/browse guides — send them to their own deck instead of the visitor-side /discover (see DiscoverPage.vue's redirect guard). */
const discoverTo = computed(() => (auth.user?.role === 'guide' ? '/guide/discover' : '/discover'))

async function logout() {
  try {
    await api.logout()
  } catch {
    // best-effort — the session will expire on its own after 5 minutes of
    // inactivity even if this round-trip fails; clearing local state below
    // is what actually matters to the person clicking the button.
  }
  auth.clear()
  messages.success('Logged out.')
  router.push('/')
}

/**
 * Mobile menu. Below `lg` the same `.drawer` that sits inline on desktop
 * becomes an off-canvas panel sliding in from the right (pure CSS — see the
 * styles below), so the links are only written once. While open: page
 * scroll is locked, Tab cycles within the drawer, Escape / the backdrop /
 * the close button / following any link closes it.
 */
// `lg`, not `md`: with "My trips" a guest's row is 7 items, too many for 760px.
const DESKTOP_QUERY = '(min-width: 1024px)' // keep in sync with `bp.$lg`

const menuOpen = ref(false)
const burgerRef = ref<HTMLButtonElement | null>(null)
const drawerRef = ref<HTMLElement | null>(null)

function focusables(): HTMLElement[] {
  return Array.from(drawerRef.value?.querySelectorAll<HTMLElement>('a[href], button:not([disabled])') ?? [])
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

watch(() => route.fullPath, () => closeMenu())

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
      <nav aria-label="Main">
        <ul class="logo">
          <li><BrandMark /></li>
        </ul>

        <button
          ref="burgerRef"
          type="button"
          class="burger"
          aria-controls="site-menu"
          :aria-expanded="menuOpen"
          aria-label="Open menu"
          @click="openMenu"
        >
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <line x1="4" y1="7" x2="20" y2="7" />
            <line x1="4" y1="12" x2="20" y2="12" />
            <line x1="4" y1="17" x2="20" y2="17" />
          </svg>
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
            <button type="button" class="drawer-close" aria-label="Close menu" @click="closeMenu(true)">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <line x1="6" y1="6" x2="18" y2="18" />
                <line x1="18" y1="6" x2="6" y2="18" />
              </svg>
            </button>
          </div>

          <p v-if="user" class="drawer-user tiny muted">
            Signed in as <strong>{{ user.name || user.email }}</strong>
          </p>

          <ul class="nav">
            <!-- Marketing copy for prospects only — a signed-in user is past it. -->
            <li v-if="!isAuthenticated"><RouterLink to="/#how" class="nav-link">How it works</RouterLink></li>
            <li><RouterLink :to="discoverTo" class="nav-link">Discover</RouterLink></li>
            <li v-if="isAuthenticated"><RouterLink to="/matches" class="nav-link">Matches</RouterLink></li>
            <li v-if="isAuthenticated"><RouterLink to="/trips" class="nav-link">My trips</RouterLink></li>
            <li v-if="isAuthenticated"><RouterLink to="/profile" class="nav-link">Profile</RouterLink></li>
            <li v-if="hasAccess">
              <RouterLink to="/shortlist" class="nav-link">
                <PillBadge>{{ picks.length }} / {{ capacity }} picked</PillBadge>
              </RouterLink>
            </li>
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
                Log out
              </button>
            </li>
            <template v-else>
              <li class="account"><RouterLink to="/register" role="button">Get started</RouterLink></li>
              <li><RouterLink to="/login" class="nav-link tiny">Log in</RouterLink></li>
            </template>
          </ul>
        </div>
      </nav>
    </div>

    <div class="backdrop" :class="{ 'backdrop--open': menuOpen }" aria-hidden="true" @click="closeMenu()" />
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

@include bp.lg {
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
      margin: calc(var(--pico-nav-link-spacing-vertical) * -1) calc(var(--pico-nav-link-spacing-horizontal) * -1);
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
</style>
