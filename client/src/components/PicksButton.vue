<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute } from 'vue-router'
import { t } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { useMatchesStore } from '@/stores/matches'

/**
 * The traveller's picks counter, in the middle of the header on the
 * Discover page: a check-box icon and "2/10 left" — "Free picks on" during
 * a free-picks bonus (its end date as the tooltip), "Get picks" before the
 * first pack. It opens the add-picks dialog (`matchesStore.addPicksOpen`,
 * which `DiscoverPage.vue` shows — a first pack or a top-up).
 */
const route = useRoute()
const auth = useAuthStore()
const matchesStore = useMatchesStore()
const { hasAccess, selectionsLeft, capacity, freePicksActive, freePicksUntil } =
  storeToRefs(matchesStore)

const shown = computed(
  () => auth.isAuthenticated && auth.activeRole === 'guest' && route.name === 'discover',
)

const text = computed(() => {
  if (freePicksActive.value) return t('common.picks_button.free_on')
  return hasAccess.value
    ? t('common.picks_button.left', { left: selectionsLeft.value, capacity: capacity.value })
    : t('common.picks_button.get')
})

const title = computed(() =>
  freePicksActive.value && freePicksUntil.value
    ? t('common.picks_button.free_until', {
        date: new Intl.DateTimeFormat(undefined, {
          day: 'numeric',
          month: 'short',
          hour: '2-digit',
          minute: '2-digit',
        }).format(new Date(freePicksUntil.value)),
      })
    : t('common.picks_button.add'),
)
</script>

<template>
  <button
    v-if="shown"
    type="button"
    class="picks-button secondary outline"
    data-test="picks-button"
    :title="title"
    :aria-label="`${text} — ${t('common.picks_button.add')}`"
    @click="matchesStore.addPicksOpen = true"
  >
    <svg
      viewBox="0 0 24 24"
      width="18"
      height="18"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <rect x="3.5" y="3.5" width="17" height="17" rx="4" />
      <path d="m8 12.5 3 3 5-6" />
    </svg>
    {{ text }}
  </button>
</template>

<style scoped lang="scss">
.picks-button {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  width: auto;
  margin: 0;
  padding: 0.4rem 1rem;
  border-radius: 999px;
  font-size: 0.9rem;
  font-weight: 700;
  white-space: nowrap;
}
</style>
