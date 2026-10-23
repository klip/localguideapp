<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute, useRouter } from 'vue-router'
import { t } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { useMatchesStore } from '@/stores/matches'

/**
 * The traveller's picks counter, in the middle of the header on every page
 * in traveller mode: a check-box icon and "2/10 left" — "Free picks on"
 * during a free-picks bonus (its end date and the picks left after it in
 * the tooltip), "Get picks" before the first pack. On Discover it opens the
 * add-picks dialog (`matchesStore.addPicksOpen`, which `DiscoverPage.vue`
 * shows); anywhere else it goes to Payments, which has the whole picture.
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const matchesStore = useMatchesStore()
const { hasAccess, selectionsLeft, capacity, freePicksActive, freePicksUntil } =
  storeToRefs(matchesStore)

const shown = computed(() => auth.isAuthenticated && auth.activeRole === 'guest')

function open() {
  if (route.name === 'discover') matchesStore.addPicksOpen = true
  else void router.push('/payments')
}

const text = computed(() => {
  if (freePicksActive.value) return t('common.picks_button.free_on')
  return hasAccess.value
    ? t('common.picks_button.left', { left: selectionsLeft.value, capacity: capacity.value })
    : t('common.picks_button.get')
})

const title = computed(() =>
  freePicksActive.value && freePicksUntil.value
    ? `${t('common.picks_button.free_until', {
        date: new Intl.DateTimeFormat(undefined, {
          day: 'numeric',
          month: 'short',
          hour: '2-digit',
          minute: '2-digit',
        }).format(new Date(freePicksUntil.value)),
      })} · ${t('common.picks_button.left', { left: selectionsLeft.value, capacity: capacity.value })}`
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
    @click="open"
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
// A soft honey pill (docs/design/sketches/04-…png), 32 px like the
// header's other controls.
.picks-button {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  width: auto;
  height: var(--rg-control-height-sm);
  margin: 0;
  padding: 0 0.75rem;
  border: 1px solid var(--rg-orange-100);
  border-radius: var(--rg-radius-control);
  background: var(--rg-orange-50);
  color: var(--rg-ink);
  font-size: 0.875rem;
  font-weight: 600;
  white-space: nowrap;

  svg {
    color: var(--rg-orange-500);
  }

  &:hover,
  &:focus-visible {
    background: var(--rg-orange-100);
    border-color: var(--rg-orange-300);
    color: var(--rg-ink);
  }
}
</style>
