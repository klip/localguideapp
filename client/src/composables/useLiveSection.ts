import { onMounted, watch } from 'vue'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useBadgesStore } from '@/stores/badges'
import { debounce } from '@/utils/debounce'

/**
 * For /matches and /trips: loads the page, then tells the server everything
 * on it has been seen (`api.markSeen()` — clears that menu counter), and does
 * both again whenever the server pushes something while the page is open — a
 * notification, or the page's own counter going up — so a match, a payment
 * or an accepted booking shows up without a reload.
 *
 * Call it from the page's `<script setup>` in place of `onMounted(load)`.
 */
export function useLiveSection(section: 'matches' | 'trips', load: () => Promise<void>) {
  const api = useApi()
  const auth = useAuthStore()
  const badges = useBadgesStore()

  async function refresh() {
    await load()
    if (!auth.user) return
    try {
      await api.markSeen(section)
    } catch {
      // Best-effort: the counter just stays up until the next visit.
    }
  }

  const refreshSoon = debounce(() => void refresh(), 300)

  onMounted(refresh)
  watch(() => badges.revision, refreshSoon)
  watch(
    () => badges[section],
    (count, previous) => {
      if (count > previous) refreshSoon()
    },
  )
}
