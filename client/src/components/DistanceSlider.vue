<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@/i18n'

/**
 * A distance in km as one slider (docs/design/sketches/04/05): the label
 * and the value on one line ("Within 25 km"), an orange-filled track and a
 * white handle with an orange ring. 0 is no limit — `null` — and reads
 * "Unlimited". The filters' distance and a guide's travel radius.
 */
const props = withDefaults(
  defineProps<{
    label: string
    max?: number
    disabled?: boolean
  }>(),
  { max: 200, disabled: false },
)

const model = defineModel<number | null>({ required: true })

const km = computed(() => Math.min(model.value ?? 0, props.max))
const valueLabel = computed(() =>
  km.value === 0
    ? t('common.filter_panel.unlimited')
    : t('common.filter_panel.within', { km: km.value }),
)
/** How far along the track the handle is, for the orange fill. */
const fill = computed(() => `${(km.value / props.max) * 100}%`)

function onInput(event: Event) {
  const value = Number((event.target as HTMLInputElement).value)
  model.value = value === 0 ? null : value
}
</script>

<template>
  <div class="distance" :class="{ 'distance--disabled': disabled }">
    <div class="distance-head">
      <span>{{ label }}</span>
      <strong data-test="distance-value">{{ valueLabel }}</strong>
    </div>
    <input
      type="range"
      class="distance-slider"
      :min="0"
      :max="max"
      step="1"
      :value="km"
      :disabled="disabled"
      :style="{ '--fill': fill }"
      :aria-label="label"
      :aria-valuetext="valueLabel"
      data-test="distance-slider"
      @input="onInput"
    />
  </div>
</template>

<style scoped lang="scss">
.distance-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-size: 0.875rem;

  span {
    font-weight: 600;
  }
}

.distance--disabled {
  opacity: 0.5;
}

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

  &:disabled {
    cursor: default;
  }

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
</style>
