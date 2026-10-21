<script setup lang="ts">
import { onMounted, watch } from 'vue'
import AppFooter from '@/components/AppFooter.vue'
import AppHeader from '@/components/AppHeader.vue'
import CookieNotice from '@/components/CookieNotice.vue'
import TermsDialog from '@/components/TermsDialog.vue'
import MessageToaster from '@/components/MessageToaster.vue'
import { useApi, type AppNotification, type ChatMessage } from '@/plugins/api'
import { socket } from '@/plugins/socket'
import { useBadgesStore } from '@/stores/badges'
import { useLegalStore } from '@/stores/legal'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { useMatchesStore } from '@/stores/matches'
import { notificationLink, withRole } from '@/utils/notificationLink'
import { syncPendingRejects } from '@/utils/pendingRejects'
import { markBrowseIntroSeen } from '@/utils/browseIntro'
import { t } from '@/i18n'
import { useRoute } from 'vue-router'

/**
 * `stores/auth.ts` restores `user` from localStorage optimistically on
 * store creation — but the sign-in itself is the HttpOnly refresh cookie,
 * which may have expired or been revoked while the tab was closed.
 * Confirming it here, once at boot (`api.refresh()`, which also brings the
 * first access token), avoids a misleading logged-in-looking header; a
 * sign-in that ends *during* the visit surfaces reactively — a refresh or
 * request comes back 401 and `plugins/api.ts` signs out.
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
const currentRoute = useRoute()

socket.on('notification', (message) => {
  const note = message.notification as AppNotification | undefined
  if (!note) return
  messages.info(
    note.message,
    undefined,
    NOTIFICATION_TOAST_MS,
    undefined,
    notificationLink(note, auth.activeRole),
  )
  badges.bump()
})
socket.on('badges', (message) => badges.set(message))
// A chat message for me: a toast, unless that conversation is already open
// (MessagesPage shows it there). The counter itself comes as `badges`.
socket.on('message', (payload) => {
  const message = payload.message as
    | (ChatMessage & { sender_name?: string | null; role?: string | null })
    | undefined
  if (!message || message.mine) return
  const route = currentRoute
  if (route.name === 'messages' && Number(route.params.id) === message.sender_id) return
  messages.info(
    t('common.chat.new_message', { name: message.sender_name || t('common.chat.someone') }),
    undefined,
    NOTIFICATION_TOAST_MS,
    undefined,
    message.role
      ? withRole(`/messages/${message.sender_id}`, message.role)
      : `/messages/${message.sender_id}`,
  )
})

// Every renewed access token re-binds the socket (the binding lasts as long
// as the token). Signed out, it unbinds.
watch(
  () => auth.accessToken,
  (token) => {
    socket.identify(token)
    if (!auth.isAuthenticated) badges.reset()
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
    if (id && id !== previous) void syncPendingRejects(api, auth.activeRole)
  },
)

// Someone has an account on this browser: no "Browse freely" intro on
// Discover again (utils/browseIntro.ts).
watch(
  () => auth.user?.id,
  (id) => {
    if (id) markBrowseIntroSeen()
  },
  { immediate: true },
)

onMounted(async () => {
  if (!auth.isAuthenticated) return

  try {
    // The sign-in is the HttpOnly refresh cookie: this confirms it and gets
    // the first access token (the cached user only drew the header).
    // Signed-out-ness is handled inside (`null`, the shared toast).
    await api.refresh()
  } catch {
    // A network hiccup: keep the optimistic cached user rather than signing
    // out over it; the next request tries again.
  }

  void syncPendingRejects(api, auth.activeRole)
  await syncGuestPacks()
})

/**
 * `stores/matches.ts` is in-memory only (unlike `auth`, which persists to
 * localStorage), so a plain refresh dropped a paid-up visitor back to "0
 * packs": the deck showed the paywall and the header read "0 / 0 picked"
 * even though the purchases were recorded server-side. Only LoginPage and
 * UnlockPage re-synced it, so nothing covered F5 — and this is the one place
 * every page passes through. Acting as a guest only: guides never buy packs.
 * Runs at boot and again whenever a dual-role account switches to traveller.
 */
async function syncGuestPacks() {
  if (!auth.isAuthenticated || auth.activeRole !== 'guest') return

  try {
    const state = await api.paymentState()
    matchesStore.applyPaymentState(state)
  } catch {
    // Best-effort, exactly like LoginPage's equivalent: worst case the user
    // briefly sees "0 unlocked" and can revisit /unlock. A 401 has already
    // been surfaced centrally by call().
  }
}

watch(
  () => auth.activeRole,
  (role, previous) => {
    if (previous && role !== previous) void syncGuestPacks()
  },
)
</script>

<template>
  <div class="app-shell">
    <MessageToaster />
    <AppHeader />
    <main>
      <!-- Keyed by role: switching mode re-renders the page as the other role. -->
      <RouterView :key="auth.activeRole ?? 'signed-out'" />
    </main>
    <AppFooter />
    <CookieNotice />
    <!-- The footer's and the cookie notice's terms; registration has its own, with Accept. -->
    <TermsDialog :open="legal.termsOpen" :section="legal.section" @close="legal.hideTerms()" />
  </div>
</template>
