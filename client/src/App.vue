<script setup lang="ts">
import { onMounted, watch } from 'vue'
import AppFooter from '@/components/AppFooter.vue'
import AppHeader from '@/components/AppHeader.vue'
import CookieNotice from '@/components/CookieNotice.vue'
import TermsDialog from '@/components/TermsDialog.vue'
import MessageToaster from '@/components/MessageToaster.vue'
import { useApi, type AppNotification } from '@/plugins/api'
import { socket } from '@/plugins/socket'
import { useBadgesStore } from '@/stores/badges'
import { useLegalStore } from '@/stores/legal'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { useMatchesStore } from '@/stores/matches'
import { syncPendingRejects } from '@/utils/pendingRejects'

/**
 * `stores/auth.ts` restores `user` from localStorage optimistically on
 * store creation — but the real, 5-minute-inactivity expiry lives
 * server-side (see `Session.php`), so that cached copy may already be
 * stale (e.g. the tab was closed for a while). Confirming it here, once at
 * boot, avoids a misleading logged-in-looking header in the meantime; a
 * session that goes stale *during* the visit instead surfaces reactively —
 * any action gets a 401 and `plugins/api.ts`'s `call()` clears it.
 *
 * `useApi()` is captured here, synchronously in `<script setup>`, not
 * inside `onMounted()` itself — same reason as `stores/guides.ts`: Vue's
 * `inject()` only works while this component's setup is still running.
 */
const api = useApi()
const auth = useAuthStore()
const matchesStore = useMatchesStore()
const messages = useMessagesStore()
const legal = useLegalStore()

/**
 * The server pushes over the API's socket (`plugins/socket.ts`):
 * notifications — a guide hearing a visitor matched with them or paid their
 * fee, a visitor hearing their booking was accepted, someone they're matched
 * with updating their details — each once, shown as a toast; and the menu
 * counters for unseen matches and trips (`stores/badges.ts`). Pushes reach
 * whoever the connection is identified as, so it's told whenever the session
 * changes — and cleared on sign-out.
 */
const NOTIFICATION_TOAST_MS = 10_000
const badges = useBadgesStore()

socket.on('notification', (message) => {
  const note = message.notification as AppNotification | undefined
  if (!note) return
  messages.info(note.message, undefined, NOTIFICATION_TOAST_MS)
  badges.bump()
})
socket.on('badges', (message) => badges.set(message))

watch(
  () => auth.sessionHash,
  (hash) => {
    socket.identify(hash ?? null)
    if (!hash) badges.reset()
  },
  { immediate: true },
)

/**
 * Guides rejected before signing in (utils/pendingRejects.ts) are sent to the
 * server in the background as soon as there's an account to record them
 * against — on register/login (the user changing), and once at boot, after
 * the session check below, to retry anything a failed attempt left queued.
 */
watch(
  () => auth.user?.id,
  (id, previous) => {
    if (id && id !== previous) void syncPendingRejects(api, auth.user?.role)
  },
)

onMounted(async () => {
  if (!auth.isAuthenticated) return

  try {
    auth.setUser(await api.session())
  } catch {
    // A 401 here already made call() clear auth.user AND push the shared
    // "session expired" toast (see plugins/api.ts / stores/messages.ts) —
    // nothing left to do. Any other failure (a network hiccup, say) leaves
    // the optimistic cached session in place rather than logging the user
    // out over it.
  }

  void syncPendingRejects(api, auth.user?.role)

  // `stores/matches.ts` is in-memory only (unlike `auth`, which persists to
  // localStorage), so a plain refresh dropped a paid-up visitor back to "0
  // packs": the deck showed the paywall and the header read "0 / 0 picked"
  // even though the purchases were recorded server-side. Only LoginPage and
  // UnlockPage re-synced it, so nothing covered F5 — and this is the one
  // place every page passes through. Guests only: guides never buy packs.
  if (auth.user?.role !== 'guest') return

  try {
    const state = await api.paymentState()
    matchesStore.applyPaymentState(state)
  } catch {
    // Best-effort, exactly like LoginPage's equivalent: worst case the user
    // briefly sees "0 unlocked" and can revisit /unlock. A 401 has already
    // been surfaced centrally by call().
  }
})
</script>

<template>
  <div class="app-shell">
    <MessageToaster />
    <AppHeader />
    <main>
      <RouterView />
    </main>
    <AppFooter />
    <CookieNotice />
    <!-- The footer's and the cookie notice's terms; registration has its own, with Accept. -->
    <TermsDialog :open="legal.termsOpen" :section="legal.section" @close="legal.hideTerms()" />
  </div>
</template>
