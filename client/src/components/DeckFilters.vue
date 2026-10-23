<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import ClearFilters from './ClearFilters.vue'
import FilterPanel from './FilterPanel.vue'
import { LG_QUERY, useMediaQuery } from '@/composables/useMediaQuery'
import { t } from '@/i18n'
import type { Role } from '@/plugins/api'
import { useFiltersStore } from '@/stores/filters'

/**
 * Both decks' filters (docs/design/sketches/04/05). From `lg` up a side rail
 * that's always open, its heading a plain title. Below, a "Filters · 3"
 * button that slides a bottom sheet up over the deck: the same panel, then
 * "Clear filters" and "Show 24 guides" — the deck refetches as filters
 * change, so the count is already the answer.
 */
const props = defineProps<{
  title: string
  hint: string
  targetRole: Role
  /** "Show 24 guides" — the page counts its own deck. */
  showLabel: string
  compact?: boolean
}>()

const filtersStore = useFiltersStore()
const { activeCount } = storeToRefs(filtersStore)

const isDesktop = useMediaQuery(LG_QUERY)
const open = ref(isDesktop.value)
watch(isDesktop, (desktop) => {
  open.value = desktop
})

function onToggle(event: Event) {
  open.value = (event.target as HTMLDetailsElement).open
}

function close() {
  if (!isDesktop.value) open.value = false
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && open.value && !isDesktop.value) {
    event.preventDefault()
    close()
  }
}

// The sheet covers the page: lock its scroll while it's up.
watch(
  () => open.value && !isDesktop.value,
  (sheet) => {
    document.body.style.overflow = sheet ? 'hidden' : ''
  },
)
onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

defineExpose({ close, props })
</script>

<template>
  <aside class="rail deck-filters" @keydown="onKeydown">
    <details class="filters-disclosure" :open="open" @toggle="onToggle">
      <summary data-test="filters-summary">
        <strong>{{ title }}</strong>
        <span v-if="activeCount" class="filters-count" data-test="filters-count">{{
          activeCount
        }}</span>
        <ClearFilters />
      </summary>
      <div class="filters-body">
        <div class="sheet-head">
          <span class="grab" aria-hidden="true" />
          <strong>{{ title }}</strong>
          <ClearFilters />
        </div>
        <p class="tiny muted hint">{{ hint }}</p>
        <FilterPanel :compact="compact" :target-role="targetRole" />
        <div class="sheet-actions">
          <button type="button" class="secondary" @click="filtersStore.reset()">
            {{ t('common.filter_panel.clear') }}
          </button>
          <button type="button" data-test="filters-show" @click="close">{{ showLabel }}</button>
        </div>
      </div>
    </details>
    <div
      v-if="open && !isDesktop"
      class="sheet-backdrop"
      aria-hidden="true"
      data-test="filters-backdrop"
      @click="close"
    />
  </aside>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.filters-disclosure {
  margin: 0;
  padding: 0.75rem 1rem;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-panel);
  background: #fff;

  // "Filters", the count, then "clear ×" (while any are set) before the chevron.
  > summary {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-family: var(--rg-font-display);
    font-size: 1rem;

    strong {
      flex: 1;
    }
  }
}

.filters-count {
  display: inline-grid;
  place-items: center;
  min-width: 1.35rem;
  height: 1.35rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: var(--rg-honey);
  color: var(--rg-ink);
  font-family: var(--pico-font-family);
  font-size: 0.75rem;
  font-weight: 700;
}

.hint {
  margin: 0.25rem 0 0.75rem;
}

// --- Mobile: a bottom sheet ---------------------------------------------------

.sheet-head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  position: relative;
  padding-top: 0.9rem;
  font-family: var(--rg-font-display);
  font-size: 1.125rem;

  strong {
    flex: 1;
  }
}

.grab {
  position: absolute;
  top: 0;
  left: 50%;
  width: 2.5rem;
  height: 0.3rem;
  border-radius: 999px;
  background: var(--rg-line-strong);
  transform: translateX(-50%);
}

.sheet-actions {
  position: sticky;
  bottom: 0;
  display: grid;
  // Clear sizes to its words (long in some languages); Show takes the rest.
  grid-template-columns: auto 1fr;
  gap: 0.625rem;
  margin: 0.5rem -1.25rem 0;
  padding: 0.75rem 1.25rem calc(0.75rem + env(safe-area-inset-bottom));
  background: #fff;
  border-top: 1px solid var(--rg-line);

  button {
    height: 3rem;
    margin: 0;
    padding-inline: 1rem;
    border-radius: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
    white-space: nowrap;
  }
}

@media (max-width: 1023.98px) {
  .filters-disclosure[open] > .filters-body {
    position: fixed;
    inset-inline: 0;
    bottom: 0;
    z-index: 46;
    max-height: 88dvh;
    overflow-y: auto;
    padding: 0.5rem 1.25rem 0;
    background: #fff;
    border-radius: 1.25rem 1.25rem 0 0;
    box-shadow: 0 -12px 40px rgba(42, 31, 23, 0.18);
    animation: sheet-up 0.22s ease;
  }

  .sheet-backdrop {
    position: fixed;
    inset: 0;
    z-index: 45;
    background: rgba(41, 30, 24, 0.45);
  }
}

@keyframes sheet-up {
  from {
    transform: translateY(100%);
  }
}

@media (prefers-reduced-motion: reduce) {
  .filters-disclosure[open] > .filters-body {
    animation: none;
  }
}

// --- Desktop: an always-open rail ---------------------------------------------

@include bp.lg {
  .filters-disclosure > summary {
    list-style: none;
    cursor: default;
    pointer-events: none;

    &::-webkit-details-marker {
      display: none;
    }

    &::after {
      display: none;
    }

    // "clear ×" still works there.
    :deep(.clear-filters) {
      pointer-events: auto;
    }
  }

  .sheet-head,
  .sheet-actions {
    display: none;
  }
}
</style>
