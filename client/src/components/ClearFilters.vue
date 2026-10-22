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
    <svg viewBox="0 0 14 14" width="12" height="12" aria-hidden="true">
      <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
    </svg>
  </button>
</template>

<style scoped lang="scss">
.clear-filters {
  display: inline-flex;
  align-items: center;
  align-self: center;
  gap: 0.2rem;
  width: auto;
  margin: 0 0 0 auto;
  padding: 0;
  border: 0;
  background: none;
  color: #000;
  font-size: 0.9rem;
  line-height: 1;
  text-decoration: none;
  // The summary is inert on desktop (pointer-events: none); this isn't.
  pointer-events: auto;
  cursor: pointer;

  svg {
    color: var(--rg-red);
  }
}
</style>
