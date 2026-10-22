<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@/i18n'
import { AGE_CATEGORY_KEY, useCategoriesStore } from '@/stores/categories'
import { useFiltersStore } from '@/stores/filters'
import { formatRange, rangeFormatFor } from '@/utils/formatRange'

/**
 * Every filter in force, as a removable pill above the deck's cards. This is
 * where a selection *lives* once it's made: `FilterPanel` drops a picked
 * option from its category's list, and removing the pill here puts it back.
 *
 * Reads and writes `stores/filters.ts` only, so it names no category; the
 * labels come from `stores/categories.ts`. Renders nothing while no filter is
 * set.
 */
interface Pill {
  id: string
  label: string
  remove: () => void
}

const categoriesStore = useCategoriesStore()
const filtersStore = useFiltersStore()

const pills = computed<Pill[]>(() => {
  const { filters } = filtersStore
  const out: Pill[] = []

  // The search point itself has no pill — the location filter and the deck's
  // header already show it; only a distance limit around it does.
  if (filters.near) {
    if (filters.maxKm) {
      out.push({
        id: 'maxKm',
        label: t('common.filter_panel.within', { km: filters.maxKm }),
        remove: () => filtersStore.setMaxKm(null),
      })
    }
  }

  if (filters.gender) {
    out.push({
      id: 'gender',
      label: t(`common.gender.${filters.gender}`),
      remove: () => filtersStore.setGender(null),
    })
  }

  for (const [key, ranges] of Object.entries(filters.ranges)) {
    ranges.forEach((range, index) => {
      out.push({
        id: `${key}:${range[0]}-${range[1]}`,
        label: `${categoriesStore.labelFor(key)} ${formatRange(range, rangeFormatFor(key))}`,
        remove: () =>
          key === AGE_CATEGORY_KEY
            ? filtersStore.setRange(key, null)
            : filtersStore.removeRange(key, index),
      })
    })
  }

  for (const [key, values] of Object.entries(filters.attributes)) {
    for (const value of values) {
      out.push({
        id: `${key}=${value}`,
        label: categoriesStore.optionLabelFor(key, value),
        remove: () => filtersStore.toggleValue(key, value),
      })
    }
  }

  return out
})
</script>

<template>
  <ul v-if="pills.length" class="active-filters" :aria-label="t('common.active_filters.label')">
    <li v-for="pill in pills" :key="pill.id" class="filter-pill">
      <span>{{ pill.label }}</span>
      <button
        type="button"
        class="filter-pill-remove"
        :aria-label="t('common.active_filters.remove', { value: pill.label })"
        @click="pill.remove()"
      >
        <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
          <path
            d="M4 4l8 8M12 4l-8 8"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
          />
        </svg>
      </button>
    </li>
  </ul>
</template>

<style scoped lang="scss">
.active-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin: 0 0 0.75rem;
  padding: 0;
  list-style: none;
}

.filter-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  margin: 0;
  padding: 0.25rem 0.35rem 0.25rem 0.7rem;
  border: 1px solid var(--rg-orange-200);
  border-radius: 999px;
  background: #fff;
  color: var(--rg-orange-ink);
  font-size: 0.8rem;
  font-weight: 600;
  line-height: 1.3;
  list-style: none;
}

.filter-pill-remove {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.25rem;
  height: 1.25rem;
  margin: 0;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: none;
  color: inherit;

  &:hover,
  &:focus-visible {
    background: var(--rg-orange-200);
    color: var(--rg-red);
  }
}
</style>
