<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import ActiveFilters from './ActiveFilters.vue'
import ClearFilters from './ClearFilters.vue'
import FilterPanel from './FilterPanel.vue'
import { LG_QUERY, useMediaQuery } from '@/composables/useMediaQuery'
import { t } from '@/i18n'
import type { Role } from '@/plugins/api'
import { useFiltersStore } from '@/stores/filters'

/**
 * Both decks' filters (docs/design/sketches/04/05). From `lg` up a side rail
 * that's always open, its heading a plain title. Below, a white bar —
 * "Filters", the filters in force as one horizontally scrolling line of
 * pills, "clear ×" and the chevron — that drops a pane down from itself
 * (same place as the button, never from the other end of the screen): the
 * same panel, then "Clear filters" and "Show 24 guides". The deck refetches
 * as filters change, so the count is already the answer.
 */
defineProps<{
  title: string
  hint: string
  targetRole: Role
  /** "Show 24 guides" — the page counts its own deck. */
  showLabel: string
  compact?: boolean
}>()

const filtersStore = useFiltersStore()

const isDesktop = useMediaQuery(LG_QUERY)
const open = ref(isDesktop.value)
watch(isDesktop, (desktop) => {
  open.value = desktop
})

const summaryRef = ref<HTMLElement | null>(null)
/** How tall the pane may be: from under the bar to just above the tab bar. */
const paneMaxHeight = ref<string | undefined>(undefined)

function measurePane() {
  const bar = summaryRef.value?.getBoundingClientRect()
  if (!bar) return
  // 4.5rem ≈ the tab bar plus a little air.
  const tabBar = 72
  paneMaxHeight.value = `${Math.max(240, window.innerHeight - bar.bottom - 8 - tabBar)}px`
}

function onToggle(event: Event) {
  open.value = (event.target as HTMLDetailsElement).open
  if (open.value && !isDesktop.value) void nextTick(measurePane)
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

/** A pill's × (or "clear") inside the bar mustn't open or close the pane. */
function onStripClick(event: MouseEvent) {
  if ((event.target as Element | null)?.closest('button')) event.preventDefault()
}

// The pane covers the page: lock its scroll while it's open.
watch(
  () => open.value && !isDesktop.value,
  (pane) => {
    document.body.style.overflow = pane ? 'hidden' : ''
  },
)
onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

defineExpose({ close })
</script>

<template>
  <aside
    class="rail deck-filters"
    :class="{ 'deck-filters--open': open && !isDesktop }"
    @keydown="onKeydown"
  >
    <Transition name="fade">
      <div
        v-if="open && !isDesktop"
        class="pane-backdrop"
        aria-hidden="true"
        data-test="filters-backdrop"
        @click="close"
      />
    </Transition>
    <details class="filters-disclosure" :open="open" @toggle="onToggle">
      <summary ref="summaryRef" data-test="filters-summary">
        <strong>{{ title }}</strong>
        <!-- Mobile: what's set, one scrolling line, fading out under "clear" and the chevron. -->
        <span class="summary-pills" data-test="filters-pills" @click="onStripClick">
          <ActiveFilters class="summary-pills-list" />
        </span>
        <ClearFilters />
      </summary>
      <div class="filters-body" :style="{ '--pane-max-height': paneMaxHeight }">
        <p class="tiny muted hint">{{ hint }}</p>
        <FilterPanel :compact="compact" :target-role="targetRole" />
        <div class="pane-actions">
          <button type="button" class="secondary" @click="filtersStore.reset()">
            {{ t('common.filter_panel.clear') }}
          </button>
          <button type="button" data-test="filters-show" @click="close">{{ showLabel }}</button>
        </div>
      </div>
    </details>
  </aside>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

// The pane hangs from this on mobile; on desktop the rail stays sticky
// (`.rail`, _layout.scss) — `relative` there would turn its `top` into an offset.
.deck-filters {
  min-width: 0;

  @media (max-width: 1023.98px) {
    position: relative;
  }
}

.filters-disclosure {
  position: relative;
  min-width: 0;
  margin: 0;
  padding: 0.75rem 1rem;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-panel);
  background: #fff;

  // "Filters", the pills, then "clear ×" (while any are set) before the chevron.
  > summary {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
    font-family: var(--rg-font-display);
    font-size: 1rem;
    // It opens a pane rather than collapsing in place, so no chevron.
    list-style: none;

    &::-webkit-details-marker {
      display: none;
    }

    &::after {
      display: none;
    }

    strong {
      flex: 0 0 auto;
    }
  }
}

// One line of pills that scrolls sideways; its end fades out where "clear"
// and the chevron sit over it.
.summary-pills {
  flex: 1 1 auto;
  min-width: 0;
  overflow-x: auto;
  scrollbar-width: none;
  font-family: var(--pico-font-family);
  mask-image: linear-gradient(to right, #000 calc(100% - 1.5rem), transparent);

  &::-webkit-scrollbar {
    display: none;
  }

  // Beats ActiveFilters' own wrapping and margin.
  .summary-pills-list {
    flex-wrap: nowrap;
    margin: 0;
    padding-inline-end: 1.5rem;

    :deep(.filter-pill) {
      flex: 0 0 auto;
      white-space: nowrap;
    }
  }
}

.hint {
  margin: 0.25rem 0 0.75rem;
}

.pane-actions {
  position: sticky;
  bottom: 0;
  display: grid;
  // Clear sizes to its words (long in some languages); Show takes the rest.
  grid-template-columns: auto 1fr;
  gap: 0.625rem;
  margin: 0.5rem -1rem -1rem;
  padding: 0.75rem 1rem;
  background: #fff;
  border-top: 1px solid var(--rg-line);
  border-radius: 0 0 var(--rg-radius-panel) var(--rg-radius-panel);

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

// --- Mobile: a pane dropping down from the bar ------------------------------

@media (max-width: 1023.98px) {
  // Open, the bar and its pane sit over the dimmed page.
  .deck-filters--open {
    z-index: 46;
  }

  .filters-disclosure[open] > .filters-body {
    position: absolute;
    top: calc(100% + 0.5rem);
    // The page's own side padding keeps it off the screen's edges.
    inset-inline: 0;
    z-index: 2;
    max-height: var(--pane-max-height, 70dvh);
    overflow-y: auto;
    padding: 0.75rem 1rem 1rem;
    background: #fff;
    border: 1px solid var(--rg-line);
    border-radius: var(--rg-radius-panel);
    box-shadow: 0 16px 40px rgba(42, 31, 23, 0.18);
    animation: pane-down 0.22s ease;
  }

  .pane-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1;
    background: rgba(41, 30, 24, 0.45);
  }

  .filters-disclosure {
    z-index: 2;
  }

  // The bar keeps its height when its pane opens (Pico spaces an open summary).
  .filters-disclosure[open] > summary {
    margin-bottom: 0;
  }
}

@keyframes pane-down {
  from {
    opacity: 0;
    transform: translateY(-0.75rem);
  }
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .filters-disclosure[open] > .filters-body {
    animation: none;
  }

  .fade-enter-active,
  .fade-leave-active {
    transition: none;
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

    strong {
      flex: 1;
    }

    // "clear ×" still works there.
    :deep(.clear-filters) {
      pointer-events: auto;
    }
  }

  // The pills are above the card (DeckBar) on desktop.
  .summary-pills,
  .pane-actions {
    display: none;
  }
}
</style>
