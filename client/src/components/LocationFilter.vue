<script setup lang="ts">
import { computed, ref } from 'vue'
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

const MAX_KM = 200

const editing = ref(false)
const near = computed(() => filtersStore.filters.near)
/** 0 on the slider = no limit. */
const km = computed(() => filtersStore.filters.maxKm ?? 0)
const distanceLabel = computed(() =>
  km.value === 0
    ? t('common.filter_panel.unlimited')
    : t('common.filter_panel.within', { km: km.value }),
)
/** How far along the track the handle is, for the orange fill. */
const fill = computed(() => `${(km.value / MAX_KM) * 100}%`)

function choose(place: PlacePoint) {
  filtersStore.setNear(place)
  editing.value = false
}

function onKmInput(event: Event) {
  const value = Number((event.target as HTMLInputElement).value)
  filtersStore.setMaxKm(value === 0 ? null : value)
}
</script>

<template>
  <fieldset class="location" data-test="location-filter">
    <legend class="tiny">{{ t('common.filter_panel.location') }}</legend>

    <div v-if="!editing" class="near">
      <span v-if="near" class="near-label" data-test="near-label">📍 {{ near.label }}</span>
      <span v-else class="near-label muted">{{ t('common.filter_panel.anywhere') }}</span>
      <button type="button" class="link-btn tiny" data-test="near-change" @click="editing = true">
        {{
          near ? t('common.filter_panel.change_location') : t('common.filter_panel.set_location')
        }}
      </button>
    </div>
    <div v-else class="near-edit">
      <PlaceSearch :model-value="near" :near="near" autofocus @update:model-value="choose" />
      <button type="button" class="link-btn tiny" @click="editing = false">
        {{ t('common.filter_panel.cancel') }}
      </button>
    </div>

    <div class="distance">
      <div class="distance-head">
        <span>{{ t('common.filter_panel.distance') }}</span>
        <strong data-test="distance-value">{{ distanceLabel }}</strong>
      </div>
      <input
        type="range"
        class="distance-slider"
        :min="0"
        :max="MAX_KM"
        step="1"
        :value="km"
        :style="{ '--fill': fill }"
        :aria-label="t('common.filter_panel.distance')"
        :aria-valuetext="distanceLabel"
        data-test="distance-slider"
        @input="onKmInput"
      />
      <small v-if="!near" class="tiny muted">{{
        t('common.filter_panel.distance_needs_location')
      }}</small>
    </div>
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
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.25rem 0.5rem;
  margin-bottom: 0.5rem;
}

.near-label {
  font-weight: 600;
}

.near-edit {
  margin-bottom: 0.5rem;
}

.distance-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-size: 0.875rem;

  span {
    font-weight: 600;
  }
}

// One handle on an orange-filled track (docs/design/sketches/04/05) — the
// same look as the age slider beside it.
.distance-slider {
  --thumb: 1.1rem;
  width: 100%;
  height: var(--thumb);
  margin: 0.45rem 0 0.2rem;
  padding: 0;
  border: 0;
  background: transparent;
  appearance: none;
  -webkit-appearance: none;
  cursor: pointer;

  &::-webkit-slider-runnable-track {
    height: 0.3rem;
    border-radius: 999px;
    background: linear-gradient(
      to right,
      var(--rg-orange-500) var(--fill),
      var(--rg-line) var(--fill)
    );
  }

  &::-moz-range-track {
    height: 0.3rem;
    border-radius: 999px;
    background: var(--rg-line);
  }

  &::-moz-range-progress {
    height: 0.3rem;
    border-radius: 999px;
    background: var(--rg-orange-500);
  }

  &::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: var(--thumb);
    height: var(--thumb);
    margin-top: calc((0.3rem - var(--thumb)) / 2);
    border: 2px solid var(--rg-orange-500);
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(42, 31, 23, 0.18);
    transition: transform 0.12s ease;
  }

  &::-moz-range-thumb {
    width: var(--thumb);
    height: var(--thumb);
    border: 2px solid var(--rg-orange-500);
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(42, 31, 23, 0.18);
  }

  &:active::-webkit-slider-thumb {
    transform: scale(1.12);
  }

  &:focus-visible {
    outline: none;

    &::-webkit-slider-thumb {
      box-shadow: 0 0 0 4px var(--pico-primary-focus);
    }
  }
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
