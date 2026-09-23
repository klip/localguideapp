<script setup lang="ts">
import { onMounted } from 'vue'
import AppFooter from '@/components/AppFooter.vue'
import AppHeader from '@/components/AppHeader.vue'
import MessageToaster from '@/components/MessageToaster.vue'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'

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
