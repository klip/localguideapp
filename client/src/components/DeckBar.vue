<script setup lang="ts">
import { computed, useSlots } from 'vue'
import ActiveFilters from '@/components/ActiveFilters.vue'
import { t } from '@/i18n'
import { useFiltersStore } from '@/stores/filters'

/**
 * The bar between the menu and a discovery deck: the filters in force as
 * removable pills on the left (`ActiveFilters`), and whatever the page puts
 * in the `end` slot on the right — the traveller's picks counter on
 * `/discover`. Hidden while there's nothing to show.
 */
const filtersStore = useFiltersStore()
const slots = useSlots()

const hasFilters = computed(() => {
  const { filters } = filtersStore
  return (
    (!!filters.near && !!filters.maxKm) ||
    !!filters.gender ||
    Object.values(filters.ranges).some((spans) => spans.length > 0) ||
    Object.values(filters.attributes).some((values) => values.length > 0)
  )
})
</script>

<template>
  <div
    v-if="hasFilters || slots.end"
    class="deck-bar"
    role="region"
    :aria-label="t('common.deck_bar.label')"
    data-test="deck-bar"
  >
    <ActiveFilters class="deck-bar-filters" />
    <div v-if="slots.end" class="deck-bar-end">
      <slot name="end" />
    </div>
  </div>
</template>

<style scoped lang="scss">
.deck-bar {
  display: flex;
  align-items: center;
  gap: 0.5rem 0.75rem;
  min-height: 3rem;
  margin: 0 0 1rem;
  padding: 0.35rem 0.4rem 0.35rem 0.5rem;
  border: 1px solid var(--rg-line);
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.85);
  box-shadow: 0 6px 18px rgba(68, 41, 22, 0.06);
}

// Inside the bar the pills sit on its centre line (beats ActiveFilters' own margin).
.deck-bar .deck-bar-filters {
  flex: 1 1 auto;
  min-width: 0;
  margin: 0;
}

.deck-bar-end {
  flex: 0 0 auto;
  margin-inline-start: auto;
}

@media (max-width: 640px) {
  .deck-bar {
    flex-wrap: wrap;
    border-radius: var(--rg-radius-panel);
  }
}
</style>
