<script setup lang="ts">
import { useSlots } from 'vue'
import ActiveFilters from '@/components/ActiveFilters.vue'
import { t } from '@/i18n'

/**
 * The bar between the menu and a discovery deck: the filters in force as
 * removable pills on the left (`ActiveFilters`), and whatever the page puts
 * in the `end` slot on the right. Transparent, and always there at one pill's
 * height, so picking a filter never moves the page.
 */
const slots = useSlots()
</script>

<template>
  <div class="deck-bar" role="region" :aria-label="t('common.deck_bar.label')" data-test="deck-bar">
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
  // Always there, one pill's height, so picking a filter never moves the page.
  min-height: 2.1rem;
  margin: 0 0 0.75rem;
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

// Below `lg` the pills ride in the filters bar itself (DeckFilters.vue).
@media (max-width: 1023.98px) {
  .deck-bar {
    display: none;
  }
}
</style>
