<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppFooter from '@/components/AppFooter.vue'
import AppHeader from '@/components/AppHeader.vue'
import MessageToaster from '@/components/MessageToaster.vue'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { useShortlistStore } from '@/stores/shortlist'

/**
 * `stores/auth.ts` restores `user` from localStorage optimistically on
 * store creation — but the real, 5-minute-inactivity expiry lives
 * server-side (see `Session.php`), so that cached copy may already be
 * stale (e.g. the tab was closed for a while). Confirming it here, once at
 * boot, avoids a misleading logged-in-looking header in the meantime; a
 * session that goes stale *during* the visit instead surfaces reactively —
 * any action gets a 401 and `plugins/api.ts`'s `call()` clears it — so no
 * polling loop is needed here.
 *
 * `useApi()` is captured here, synchronously in `<script setup>`, not
 * inside `onMounted()` itself — same reason as `stores/guides.ts`: Vue's
 * `inject()` only works while this component's setup is still running.
 */
const api = useApi()
const auth = useAuthStore()
const shortlist = useShortlistStore()
const messages = useMessagesStore()
const route = useRoute()

/**
 * In-app notifications (`Notifications.php`) are things that happened while
 * the user wasn't looking — a guide hearing their fee was paid, a visitor
 * hearing their booking was cancelled. There's no push channel, so they're
 * pulled: at boot and on every navigation, throttled so clicking around
 * doesn't turn into a request per click. The server marks them read as it
 * returns them, so each shows exactly once.
 */
const NOTIFICATION_PULL_INTERVAL_MS = 15_000
const NOTIFICATION_TOAST_MS = 10_000
let lastNotificationPull = 0

async function pullNotifications() {
  if (!auth.isAuthenticated) return
  if (Date.now() - lastNotificationPull < NOTIFICATION_PULL_INTERVAL_MS) return
  lastNotificationPull = Date.now()

  try {
    const { notifications } = await api.notifications()
    for (const note of notifications) messages.info(note.message, undefined, NOTIFICATION_TOAST_MS)
  } catch {
    // Best-effort: they stay unread server-side and come back next time.
  }
}

watch(() => route.fullPath, pullNotifications)

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

  await pullNotifications()

  // `stores/shortlist.ts` is in-memory only (unlike `auth`, which persists to
  // localStorage), so a plain refresh dropped a paid-up visitor back to "0
  // packs": the deck showed the paywall and the header read "0 / 0 picked"
  // even though the purchases were recorded server-side. Only LoginPage and
  // UnlockPage re-synced it, so nothing covered F5 — and this is the one
  // place every page passes through. Guests only: guides never buy packs.
  if (auth.user?.role !== 'guest') return

  try {
    const state = await api.paymentState()
    shortlist.applyPaymentState(state)
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
  </div>
</template>
