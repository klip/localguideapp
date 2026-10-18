<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import DualRangeSlider from './DualRangeSlider.vue'
import { t } from '@/i18n'
import { AGE_CATEGORY, AGE_CATEGORY_KEY, useCategoriesStore } from '@/stores/categories'
import { useFiltersStore } from '@/stores/filters'
import { formatRange, rangeFormatFor } from '@/utils/formatRange'
import type { AttributeCategory, Role } from '@/plugins/api'
import type { Gender, NumericRange } from '@/types'

/**
 * The discovery filter form, in two parts:
 *
 * - **The person** — gender (a dropdown) and age (one slider, two handles).
 *   Both are human constants rather than tag lists, so they sit together at
 *   the top and are always visible.
 * - **"Add a filter"** — every attribute category as an accordion row (a
 *   native `<details>` with Pico's own chevron). Opening one lists its
 *   options as full-width link rows; picking one moves it out of the list and
 *   into a pill above the deck (`ActiveFilters.vue`), whose × puts it back. A
 *   `range` category (hours, distance) gets one two-handle slider, like age —
 *   a single span, applied as it's dragged, shown as a pill too.
 *
 * There's no "apply" step: both decks refetch (debounced) whenever the filter
 * store changes.
 *
 * Nothing here names a category: they come from `api.attributeCategories()`.
 * Age is the synthetic category from `stores/categories.ts`, pulled out of the
 * accordion and given its own slider. Every value lives in `stores/filters.ts`,
 * so collapsing a row (or the whole rail) never loses a selection.
 *
 * `compact` tightens the type for the 250px desktop rail.
 */
const props = withDefaults(
  defineProps<{
    compact?: boolean
    /**
     * Who the deck shows (`guide` on the traveller deck, `guest` on the
     * guide's): a category only the *other* role has can't narrow it, so it
     * isn't offered — hours on the guide's deck, food allergies on the
     * traveller's.
     */
    targetRole?: Role
  }>(),
  { compact: false, targetRole: undefined },
)

const categoriesStore = useCategoriesStore()
const filtersStore = useFiltersStore()
const { filters, isPristine } = storeToRefs(filtersStore)

onMounted(() => categoriesStore.load())

const ageMin = AGE_CATEGORY.rangeMin ?? 16
const ageMax = AGE_CATEGORY.rangeMax ?? 99

/** The age span, full width when unset. Dragging back to both ends clears the filter. */
const age = computed<NumericRange>({
  get: () => filtersStore.rangesFor(AGE_CATEGORY_KEY)[0] ?? [ageMin, ageMax],
  set: (range) => {
    const unconstrained = range[0] <= ageMin && range[1] >= ageMax
    filtersStore.setRange(AGE_CATEGORY_KEY, unconstrained ? null : range)
  },
})

/** A range category's span in the filter, or its full extent when unset. */
function spanFor(category: AttributeCategory): NumericRange {
  return (
    filtersStore.rangesFor(category.key)[0] ?? [category.rangeMin ?? 0, category.rangeMax ?? 100]
  )
}

/**
 * Live, like age: each drag is the query, and back to the full extent clears
 * it — except where the category offers "Unlimited", whose switch is the only
 * way back to no filter (so dragging can't flip the switch under the user).
 */
function setSpan(category: AttributeCategory, range: NumericRange) {
  const unconstrained =
    range[0] <= (category.rangeMin ?? 0) && range[1] >= (category.rangeMax ?? 100)
  filtersStore.setRange(category.key, unconstrained && !category.allowUnlimited ? null : range)
}

/**
 * "Unlimited" is on whenever the category has no span in the filter —
 * derived, so a pill's × or Clear filters turn it back on too.
 */
function isUnlimited(category: AttributeCategory) {
  return !!category.allowUnlimited && filtersStore.rangesFor(category.key).length === 0
}

/** Off activates the range at its full extent (the slider wakes up); on drops it. */
function onUnlimitedChange(category: AttributeCategory, event: Event) {
  const unlimited = (event.target as HTMLInputElement).checked
  filtersStore.setRange(
    category.key,
    unlimited ? null : [category.rangeMin ?? 0, category.rangeMax ?? 100],
  )
}

/** The accordion: every category but age, which has its own slider above. */
const accordionCategories = computed(() =>
  categoriesStore.filterable.filter(
    (category) => category.key !== AGE_CATEGORY_KEY && offeredForTarget(category),
  ),
)

function offeredForTarget(category: AttributeCategory) {
  if (!props.targetRole) return true
  return category.scope !== (props.targetRole === 'guide' ? 'guest' : 'guide')
}

// A filter left on a category this deck can't use (set on the other deck
// before a mode switch) would hide everyone — drop it.
watch(
  () => [categoriesStore.loaded, props.targetRole],
  () => {
    for (const category of categoriesStore.filterable) {
      if (!offeredForTarget(category)) filtersStore.clearCategory(category.key)
    }
  },
  { immediate: true },
)

/** What a category still offers: picked options have moved up to the deck as pills. */
function remainingOptions(category: AttributeCategory) {
  return category.options.filter((option) => !filtersStore.hasValue(category.key, option.value))
}

function selectedCount(key: string) {
  return filtersStore.valuesFor(key).length + filtersStore.rangesFor(key).length
}

function onGenderChange(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  filtersStore.setGender(value ? (value as Gender) : null)
}
</script>

<template>
  <div class="filter-panel" :class="{ compact }">
    <fieldset class="person">
      <label class="tiny">
        {{ t('common.filter_panel.gender') }}
        <select :value="filters.gender ?? ''" @change="onGenderChange">
          <option value="">{{ t('common.filter_panel.no_preference') }}</option>
          <option value="male">{{ t('common.gender.male') }}</option>
          <option value="female">{{ t('common.gender.female') }}</option>
        </select>
      </label>

      <div class="age">
        <div class="age-head tiny">
          <span>{{ AGE_CATEGORY.label }}</span>
          <strong data-test="age-value">{{ age[0] }}–{{ age[1] }}</strong>
        </div>
        <DualRangeSlider
          v-model="age"
          :min="ageMin"
          :max="ageMax"
          :min-label="t('common.filter_panel.age_min')"
          :max-label="t('common.filter_panel.age_max')"
        />
      </div>
    </fieldset>

    <div v-if="accordionCategories.length" class="add-section">
      <span class="tiny muted">{{ t('common.filter_panel.add_filter') }}</span>

      <details v-for="category in accordionCategories" :key="category.key" class="filter-cat">
        <summary>
          <span class="cat-label">{{ category.label }}</span>
          <span v-if="selectedCount(category.key)" class="count">{{
            selectedCount(category.key)
          }}</span>
        </summary>

        <div v-if="category.kind === 'range'" class="cat-body span">
          <div class="span-head tiny">
            <label v-if="category.allowUnlimited" class="unlimited">
              <input
                type="checkbox"
                role="switch"
                :checked="isUnlimited(category)"
                :data-test="`unlimited-${category.key}`"
                @change="onUnlimitedChange(category, $event)"
              />
              {{ t('common.filter_panel.unlimited') }}
            </label>
            <strong :class="{ muted: isUnlimited(category) }" :data-test="`span-${category.key}`">{{
              formatRange(spanFor(category), rangeFormatFor(category.key))
            }}</strong>
          </div>
          <DualRangeSlider
            :model-value="spanFor(category)"
            :disabled="isUnlimited(category)"
            :min="category.rangeMin ?? 0"
            :max="category.rangeMax ?? 100"
            :min-label="`${category.label}: ${t('common.range_picker.from')}`"
            :max-label="`${category.label}: ${t('common.range_picker.to')}`"
            @update:model-value="(range) => setSpan(category, range)"
          />
        </div>

        <ul v-else class="cat-body options">
          <li v-for="option in remainingOptions(category)" :key="option.value">
            <button
              type="button"
              class="option"
              @click="filtersStore.toggleValue(category.key, option.value)"
            >
              <span>{{ option.label }}</span>
              <span class="option-mark" aria-hidden="true">+</span>
            </button>
          </li>
          <li v-if="!category.options.length" class="tiny muted">
            {{ t('common.filter_panel.no_options') }}
          </li>
        </ul>
      </details>
    </div>

    <p v-if="categoriesStore.loading && !categoriesStore.loaded" class="tiny muted">
      {{ t('common.filter_panel.loading') }}
    </p>

    <button
      v-if="!isPristine"
      type="button"
      class="soft-btn full clear-btn"
      @click="filtersStore.reset()"
    >
      <svg
        viewBox="0 0 24 24"
        width="16"
        height="16"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="M2 4h13l-5 6.5V18l-3 2v-9.5z" />
        <path d="M17 4l5 5M22 4l-5 5" />
      </svg>
      {{ t('common.filter_panel.clear') }}
    </button>
  </div>
</template>

<style scoped lang="scss">
.filter-panel {
  margin-bottom: 0;
}

.person {
  margin: 0 0 0.75rem;
  padding: 0;
  border: 0;

  label {
    margin-bottom: 0.6rem;
  }

  select {
    margin-bottom: 0;
  }
}

.age-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}

.add-section {
  display: flex;
  flex-direction: column;
  padding: 0.6rem 0 0.75rem;
  border-top: 1px solid var(--rg-line);

  > .tiny {
    margin-bottom: 0.25rem;
  }
}

// Accordion rows: Pico's <details> and chevron, just tightened.
.filter-cat {
  margin: 0;
  padding: 0;
  border-bottom: 1px solid var(--rg-line);

  &:last-child {
    border-bottom: 0;
  }

  > summary {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0;
    font-size: 0.9rem;
    font-weight: 600;
    line-height: 1.3;
    color: var(--rg-ink);

    // Pico floats its chevron right; in a flex row it needs pushing instead.
    &::after {
      margin-left: auto;
    }
  }

  &[open] > summary {
    margin-bottom: 0;
  }
}

.span-head {
  display: flex;
  align-items: center;
  gap: 0.5rem;

  strong {
    margin-left: auto;
  }
}

// Pico's role="switch" checkbox, kept small for the rail.
.unlimited {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  cursor: pointer;

  input {
    margin: 0;
  }
}

.count {
  min-width: 1.2rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: var(--rg-orange-50);
  color: var(--rg-orange-ink);
  font-size: 0.72rem;
  line-height: 1.2rem;
  text-align: center;
}

.cat-body {
  margin: 0 0 0.5rem 0.85rem;
}

.options {
  list-style: none;
  padding: 0;

  li {
    margin: 0;
    padding: 0;
    list-style: none;
  }
}

// An option is a link-style row across the full width, mark on the right.
.option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  width: 100%;
  margin: 0;
  padding: 0.25rem 0;
  border: 0;
  border-radius: 0;
  background: none;
  color: var(--pico-primary);
  font-size: 0.85rem;
  line-height: 1.35;
  text-align: start;

  &:hover,
  &:focus-visible {
    background: none;
    color: var(--pico-primary-hover);
    text-decoration: underline;
  }
}

.option-mark {
  font-size: 0.95rem;
  line-height: 1;
  color: var(--rg-muted);
}

.compact .filter-cat > summary {
  font-size: 0.85rem;
}

.clear-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
}
</style>
