<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import ClearFilters from './ClearFilters.vue'
import FilterPanel from './FilterPanel.vue'
import { LG_QUERY, useMediaQuery } from '@/composables/useMediaQuery'
import { t } from '@/i18n'
import type { Role } from '@/plugins/api'
import { useFiltersStore } from '@/stores/filters'

/**
 * Both decks' filters (docs/design/sketches/04/05). From `lg` up a side rail
 * that's always open, its heading a plain title. Below, the design's row —
 * "📍 Lisbon ⌄" on the left, an outlined "Filters · 3" button on the right —
 * which drops a pane down from itself (same place as the button): the same
 * panel, then "Clear filters" and "Show 24 guides". The deck refetches as
 * filters change, so the count is already the answer. What's picked shows
 * inside the panel (under each category's name); on the row, only the count.
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
const { filters, activeCount } = storeToRefs(filtersStore)

/** The row's place: the search point's town ("Lisbon" of "Lisbon, Portugal"), else "Anywhere". */
const placeLabel = computed(
  () => filters.value.near?.label.split(',')[0]?.trim() || t('common.filter_panel.anywhere'),
)

const isDesktop = useMediaQuery(LG_QUERY)
const open = ref(isDesktop.value)
watch(isDesktop, (desktop) => {
  open.value = desktop
})

const summaryRef = ref<HTMLElement | null>(null)
/** How tall the pane may be: from under the row to just above the tab bar. */
const paneMaxHeight = ref<string | undefined>(undefined)

function measurePane() {
  const bar = summaryRef.value?.getBoundingClientRect()
  if (!bar) return
  const tabBar = 72 // the tab bar plus a little air
  paneMaxHeight.value = `${Math.max(240, window.innerHeight - bar.bottom - 8 - tabBar)}px`
}

function onToggle(event: Event) {
  const details = event.target as HTMLDetailsElement
  // On desktop the rail is always open: anything that closes it is undone.
  if (isDesktop.value && !details.open) {
    details.open = true
    return
  }
  open.value = details.open
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
        <!-- Mobile: where, then the Filters button with what's set counted. -->
        <span class="row-place" data-test="filters-place">
          <span aria-hidden="true">📍</span> {{ placeLabel }}
          <span class="row-place-caret" aria-hidden="true">⌄</span>
        </span>
        <span class="row-button">
          <svg
            viewBox="0 0 24 24"
            width="16"
            height="16"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            aria-hidden="true"
          >
            <path d="M4 6h16M7 12h10M10 18h4" />
          </svg>
          {{ title
          }}<span v-if="activeCount" class="row-count" data-test="filters-count">
            · {{ activeCount }}</span
          >
        </span>
        <!-- Desktop: a plain heading, "clear ×" while anything is set. -->
        <strong class="rail-title">{{ title }}</strong>
        <ClearFilters class="rail-clear" />
      </summary>
      <div class="filters-body" :style="{ '--pane-max-height': paneMaxHeight }">
        <!-- Only the filters scroll; the buttons are a solid footer under them. -->
        <div class="filters-scroll">
          <p class="tiny muted hint">{{ hint }}</p>
          <FilterPanel :compact="compact" :target-role="targetRole" />
        </div>
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

  > summary {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
    // It opens a pane rather than collapsing in place, so no chevron.
    list-style: none;

    &::-webkit-details-marker {
      display: none;
    }

    &::after {
      display: none;
    }
  }
}

.hint {
  margin: 0.25rem 0 0.75rem;
}

.pane-actions {
  flex: 0 0 auto;
  display: grid;
  // Clear sizes to its words (long in some languages); Show takes the rest.
  grid-template-columns: auto 1fr;
  gap: 0.625rem;
  padding: 0.75rem 1rem;
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

// --- Mobile: the design's row, and a pane dropping down from it -------------

@media (max-width: 1023.98px) {
  .filters-disclosure > summary {
    justify-content: space-between;
    padding: 0;
  }

  .rail-title,
  .rail-clear {
    display: none;
  }

  .row-place {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    min-width: 0;
    overflow: hidden;
    color: var(--rg-ink);
    font-size: 0.9375rem;
    font-weight: 600;
    white-space: nowrap;
    text-overflow: ellipsis;
  }

  .row-place-caret {
    color: var(--rg-muted);
  }

  // Pico's secondary small button: outlined, 32 px.
  .row-button {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 0.4rem;
    height: var(--rg-control-height-sm);
    padding: 0 0.75rem;
    border: 1px solid var(--rg-line-strong);
    border-radius: 0.5rem;
    background: #fff;
    color: var(--rg-ink);
    font-size: 0.8125rem;
    font-weight: 600;
    transition:
      background-color 0.15s ease,
      border-color 0.15s ease;
  }

  .deck-filters--open .row-button {
    background: var(--rg-orange-50);
    border-color: var(--rg-orange-300);
  }

  // Open, the row and its pane sit over the dimmed page.
  .deck-filters--open {
    z-index: 46;
  }

  .filters-disclosure {
    z-index: 2;
  }

  .filters-disclosure[open] > summary {
    margin-bottom: 0;
  }

  // A column: the filters scroll, the footer with the buttons stays put
  // (no gap under it for the list to show through).
  .filters-disclosure[open] > .filters-body {
    position: absolute;
    top: calc(100% + 0.5rem);
    // The page's own side padding keeps it off the screen's edges.
    inset-inline: 0;
    z-index: 2;
    display: flex;
    flex-direction: column;
    max-height: var(--pane-max-height, 70dvh);
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--rg-line);
    border-radius: var(--rg-radius-panel);
    box-shadow: 0 16px 40px rgba(42, 31, 23, 0.18);
    animation: pane-down 0.22s ease;
  }

  .filters-scroll {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 0.75rem 1rem;
  }

  .pane-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1;
    background: rgba(41, 30, 24, 0.45);
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

// --- Desktop: an always-open rail ---------------------------------------------

@include bp.lg {
  .filters-disclosure {
    padding: 0.75rem 1rem;
    border: 1px solid var(--rg-line);
    border-radius: var(--rg-radius-panel);
    background: #fff;

    > summary {
      cursor: default;
      pointer-events: none;
      font-family: var(--rg-font-display);
      font-size: 1rem;

      // "clear ×" still works there.
      :deep(.clear-filters) {
        pointer-events: auto;
      }
    }
  }

  .row-place,
  .row-button,
  .pane-actions {
    display: none;
  }

  .rail-title {
    flex: 1;
  }
}
</style>
