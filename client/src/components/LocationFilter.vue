<script setup lang="ts">
import { computed, ref } from 'vue'
import DistanceSlider from './DistanceSlider.vue'
import PlaceSearch from './PlaceSearch.vue'
import { t } from '@/i18n'
import { useFiltersStore } from '@/stores/filters'
import type { PlacePoint } from '@/types'

/**
 * Where the deck searches, at the top of the filter bar: the search point
 * (`filters.near` — closer people rank first, cards show the distance) with
 * Change / Set a location, and the distance limit: one slider, always on,
 * 10 km by default (`DEFAULT_MAX_KM`); dragged to 0 it reads "Unlimited" and
 * the limit is off. The limit applies once there's a search point. Both
 * live in `stores/filters.ts`.
 */
const filtersStore = useFiltersStore()

const editing = ref(false)
const near = computed(() => filtersStore.filters.near)
/** The limit, `null` = none (the slider at 0). */
const maxKm = computed({
  get: () => filtersStore.filters.maxKm,
  set: (km: number | null) => filtersStore.setMaxKm(km),
})

function choose(place: PlacePoint | null) {
  if (place) filtersStore.setNear(place)
  editing.value = false
}
</script>

<template>
  <fieldset class="location" data-test="location-filter">
    <legend class="visually-hidden">{{ t('common.filter_panel.location') }}</legend>

    <!-- "Location" and the place on one line; the place itself opens the search. -->
    <div class="near"
        v-if="!editing">
      <button
        type="button"
        class="near-button"
        :title="
          near ? t('common.filter_panel.change_location') : t('common.filter_panel.set_location')
        "
        :aria-label="`${t('common.filter_panel.location')}: ${near?.label ?? t('common.filter_panel.anywhere')} — ${
          near ? t('common.filter_panel.change_location') : t('common.filter_panel.set_location')
        }`"
        data-test="near-change"
        @click="editing = true"
      >
        <span aria-hidden="true">📍</span>
        <span v-if="near" class="near-label" data-test="near-label">{{ near.label }}</span>
        <span v-else class="near-label near-label--anywhere">{{
          t('common.filter_panel.anywhere')
        }}</span>
      </button>
    </div>
    <div v-if="editing" class="near-edit">
      <PlaceSearch
        :model-value="near"
        :near="near"
        autofocus
        cancelable
        @update:model-value="choose"
        @cancel="editing = false"
      />
    </div>

    <DistanceSlider v-model="maxKm" :label="t('common.filter_panel.distance')" />
    <small v-if="!near" class="tiny muted">{{
      t('common.filter_panel.distance_needs_location')
    }}</small>
  </fieldset>
</template>

<style scoped lang="scss">
.location {
  margin: 0 0 0.75rem;
  padding: 0 0 0.6rem;
  border: 0;
  border-bottom: 1px solid var(--rg-line);

  legend {
    margin-bottom: 0.25rem;
    color: var(--pico-muted-color);
  }
}

.near {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 1.2rem;
  min-width: 0;
}

.near-title {
  flex: 0 0 auto;
  font-size: 0.875rem;
  font-weight: 600;
}

// The place, as a quiet button: tap it to search for another.
.near-button {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  min-width: 0;
  width: auto;
  margin: 0;
  padding: 0.2rem 0rem;
  border: 0;
  border-radius: 0.4rem;
  background: none;
  color: var(--rg-ink);
  font-size: 0.875rem;
  cursor: pointer;

  &:hover,
  &:focus-visible {
    background: var(--rg-orange-50);
    color: var(--rg-ink);
  }
}

.near-label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 600;
}

.near-label--anywhere {
  color: var(--rg-muted);
}

.near-edit {
  margin-bottom: 0.5rem;
}

.link-btn {
  width: auto;
  margin: 0;
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  text-decoration: underline;
  cursor: pointer;
}
</style>
