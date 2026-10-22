<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { t } from '@/i18n'
import { useFiltersStore } from '@/stores/filters'

/**
 * "Clear ×" in the Filters heading on both decks — only while a filter is
 * set. Clears them all (`filtersStore.reset()`: the search point stays, as it
 * ranks rather than filters). It sits inside the disclosure's `<summary>`, so
 * a click mustn't open or close the panel.
 */
const filtersStore = useFiltersStore()
const { isPristine } = storeToRefs(filtersStore)
</script>

<template>
  <button
    v-if="!isPristine"
    type="button"
    class="clear-filters"
    data-test="clear-filters"
    :aria-label="t('common.filter_panel.clear')"
    @click.prevent.stop="filtersStore.reset()"
  >
    {{ t('common.filter_panel.clear_short') }}
    <svg viewBox="0 0 16 16" width="11" height="11" aria-hidden="true">
      <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </svg>
  </button>
</template>

<style scoped lang="scss">
.clear-filters {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  width: auto;
  margin: 0 0 0 auto;
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  font-size: 0.95rem;
  text-decoration: underline;
  text-underline-offset: 3px;
  // The summary is inert on desktop (pointer-events: none); this isn't.
  pointer-events: auto;
  cursor: pointer;
}
</style>
