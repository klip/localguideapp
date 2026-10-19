<script setup lang="ts">
import { computed, ref } from 'vue'
import PlaceSearch from './PlaceSearch.vue'
import { t } from '@/i18n'
import { useFiltersStore } from '@/stores/filters'
import type { PlacePoint } from '@/types'

/**
 * Where the deck searches, at the top of the filter bar: the search point
 * (`filters.near` — closer people rank first, cards show the distance) with
 * Change / Set a location, and the distance limit — an "Unlimited" switch,
 * on by default, and a slider it wakes up. Both live in `stores/filters.ts`.
 */
const filtersStore = useFiltersStore()

/** Where the slider starts when the switch goes off. */
const DEFAULT_KM = 25
const MAX_KM = 200

const editing = ref(false)
const near = computed(() => filtersStore.filters.near)
const unlimited = computed(() => filtersStore.filters.maxKm === null)
const km = computed(() => filtersStore.filters.maxKm ?? DEFAULT_KM)

function choose(place: PlacePoint) {
  filtersStore.setNear(place)
  editing.value = false
}

function onUnlimitedChange(event: Event) {
  filtersStore.setMaxKm((event.target as HTMLInputElement).checked ? null : DEFAULT_KM)
}

function onKmInput(event: Event) {
  filtersStore.setMaxKm(Number((event.target as HTMLInputElement).value))
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
      <div class="distance-head tiny">
        <span>{{ t('common.filter_panel.distance') }}</span>
        <label class="unlimited">
          <input
            type="checkbox"
            role="switch"
            :checked="unlimited"
            :disabled="!near"
            data-test="distance-unlimited"
            @change="onUnlimitedChange"
          />
          {{ t('common.filter_panel.unlimited') }}
        </label>
      </div>
      <input
        type="range"
        :min="1"
        :max="MAX_KM"
        step="1"
        :value="km"
        :disabled="!near || unlimited"
        :aria-label="t('common.filter_panel.distance')"
        :aria-valuetext="t('common.filter_panel.within', { km })"
        data-test="distance-slider"
        @input="onKmInput"
      />
      <strong v-if="near && !unlimited" class="tiny" data-test="distance-value">{{
        t('common.filter_panel.within', { km })
      }}</strong>
      <small v-else-if="!near" class="tiny muted">{{
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
  align-items: center;
}

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

.distance input[type='range'] {
  margin: 0.4rem 0 0.2rem;
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
