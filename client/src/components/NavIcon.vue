<script setup lang="ts">
/**
 * The menu links' icons (`AppHeader.vue`): line icons on a 24px grid,
 * stroked in the link's own colour. Decorative — the link's text names it.
 */
export type NavIconName =
  | 'how'
  | 'discover'
  | 'planner'
  | 'calendar'
  | 'matches'
  | 'trips'
  | 'messages'
  | 'wallet'
  | 'menu'
  | 'login'

defineProps<{ name: NavIconName }>()
</script>

<template>
  <svg
    class="nav-icon"
    viewBox="0 0 24 24"
    width="18"
    height="18"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <!-- How it works: a light bulb. -->
    <template v-if="name === 'how'">
      <path d="M9 18h6" />
      <path d="M10 21.5h4" />
      <path
        d="M12 2.5a6.5 6.5 0 0 0-3.8 11.8c.5.4.8 1 .8 1.6V18h6v-2.1c0-.6.3-1.2.8-1.6A6.5 6.5 0 0 0 12 2.5z"
      />
    </template>
    <!-- Discover: a compass. -->
    <template v-else-if="name === 'discover'">
      <circle cx="12" cy="12" r="9.5" />
      <path d="M15.9 8.1l-2 5.8-5.8 2 2-5.8z" />
    </template>
    <!-- Trip planner: a folded map. -->
    <template v-else-if="name === 'planner'">
      <path d="M2.5 6.5v14l6-3 7 3 6-3v-14l-6 3-7-3z" />
      <path d="M8.5 3.5v14" />
      <path d="M15.5 6.5v14" />
    </template>
    <!-- Payments: a wallet. -->
    <template v-else-if="name === 'wallet'">
      <path d="M19 7V5.5A1.5 1.5 0 0 0 17.5 4H5a2 2 0 0 0 0 4h14a1 1 0 0 1 1 1v3" />
      <path d="M3 6v12a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-3" />
      <path d="M21 12v4h-4a2 2 0 0 1 0-4z" />
    </template>
    <!-- Calendar: a page of a calendar with a tick. -->
    <template v-else-if="name === 'calendar'">
      <rect x="3" y="4.5" width="18" height="16.5" rx="2" />
      <path d="M3 9.5h18" />
      <path d="M8 2.5v4" />
      <path d="M16 2.5v4" />
      <path d="m9 15 2 2 4-4" />
    </template>
    <!-- Matches: a handshake (Lucide's, ISC licence). -->
    <template v-else-if="name === 'matches'">
      <path d="m11 17 2 2a1 1 0 1 0 3-3" />
      <path
        d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"
      />
      <path d="m21 3 1 11h-2" />
      <path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3" />
      <path d="M3 4h8" />
    </template>
    <!-- My trips: a suitcase. -->
    <template v-else-if="name === 'trips'">
      <rect x="3" y="7" width="18" height="13" rx="2" />
      <path d="M8.5 7V5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v2" />
      <path d="M8 7v13" />
      <path d="M16 7v13" />
    </template>
    <!-- Menu (the mobile tab bar): three lines. -->
    <template v-else-if="name === 'menu'">
      <path d="M4 7h16" />
      <path d="M4 12h16" />
      <path d="M4 17h16" />
    </template>
    <!-- Log in: an arrow into a door. -->
    <template v-else-if="name === 'login'">
      <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
      <path d="m10 17 5-5-5-5" />
      <path d="M15 12H3" />
    </template>
    <!-- Messages: a speech bubble. -->
    <template v-else>
      <path d="M20.5 14.5a2 2 0 0 1-2 2H8l-4.5 4v-15a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z" />
      <path d="M8 8.5h8" />
      <path d="M8 12h5" />
    </template>
  </svg>
</template>

<style scoped lang="scss">
.nav-icon {
  flex-shrink: 0;
  margin-inline-end: 0.4rem;
  vertical-align: -0.25em;
}
</style>
