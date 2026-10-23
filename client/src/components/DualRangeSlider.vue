<script setup lang="ts">
import { computed } from 'vue'
import type { NumericRange } from '@/types'

/**
 * One track, two handles: a single span between `min` and `max` (the filter
 * bar's age). Two native range inputs are stacked on the same track — only
 * their thumbs take pointer events — so keyboard, touch and screen readers all
 * get real sliders for free. Dragging one handle past the other pushes it
 * along rather than swapping them.
 *
 * Controlled: the span lives in the parent (`v-model`), so collapsing or
 * re-rendering never resets it.
 */
const props = withDefaults(
  defineProps<{
    modelValue: NumericRange
    min: number
    max: number
    step?: number
    minLabel: string
    maxLabel: string
    /** Muted and inert — e.g. while the filter bar's "Unlimited" switch is on. */
    disabled?: boolean
  }>(),
  { step: 1, disabled: false },
)

const emit = defineEmits<{ 'update:modelValue': [NumericRange] }>()

const low = computed(() => Math.max(props.min, Math.min(props.modelValue[0], props.max)))
const high = computed(() => Math.max(low.value, Math.min(props.modelValue[1], props.max)))

function percent(value: number) {
  return ((value - props.min) / (props.max - props.min || 1)) * 100
}

const fill = computed(() => ({
  left: `${percent(low.value)}%`,
  right: `${100 - percent(high.value)}%`,
}))

function onLow(event: Event) {
  const value = Number((event.target as HTMLInputElement).value)
  emit('update:modelValue', [value, Math.max(value, high.value)])
}

function onHigh(event: Event) {
  const value = Number((event.target as HTMLInputElement).value)
  emit('update:modelValue', [Math.min(value, low.value), value])
}
</script>

<template>
  <div class="dual-range" :class="{ 'dual-range--disabled': disabled }">
    <div class="track" aria-hidden="true"><div class="fill" :style="fill" /></div>
    <input
      type="range"
      class="thumb thumb--low"
      :min="min"
      :max="max"
      :step="step"
      :value="low"
      :disabled="disabled"
      :aria-label="minLabel"
      @input="onLow"
    />
    <input
      type="range"
      class="thumb thumb--high"
      :min="min"
      :max="max"
      :step="step"
      :value="high"
      :disabled="disabled"
      :aria-label="maxLabel"
      @input="onHigh"
    />
  </div>
</template>

<style scoped lang="scss">
$thumb: 1.1rem;

.dual-range {
  position: relative;
  height: $thumb;
  margin: 0.35rem 0.1rem 0.25rem;
}

.track {
  position: absolute;
  top: 50%;
  left: 0;
  right: 0;
  height: 0.3rem;
  transform: translateY(-50%);
  border-radius: 999px;
  background: var(--rg-line);
}

.fill {
  position: absolute;
  top: 0;
  bottom: 0;
  border-radius: inherit;
  background: var(--rg-orange-500);
}

// Both inputs cover the whole track; only the thumbs are interactive, so
// either handle can be grabbed wherever it sits.
.thumb {
  position: absolute;
  inset: 0;
  width: 100%;
  height: $thumb;
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
  appearance: none;
  -webkit-appearance: none;
  pointer-events: none;

  &::-webkit-slider-runnable-track {
    height: $thumb;
    background: transparent;
    border: 0;
  }

  &::-moz-range-track {
    background: transparent;
    border: 0;
  }

  &::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: $thumb;
    height: $thumb;
    margin-top: 0;
    border: 2px solid var(--rg-orange-500);
    border-radius: 50%;
    background: #fff;
    cursor: grab;
    pointer-events: auto;
  }

  &::-moz-range-thumb {
    width: $thumb;
    height: $thumb;
    box-sizing: border-box;
    border: 2px solid var(--rg-orange-500);
    border-radius: 50%;
    background: #fff;
    cursor: grab;
    pointer-events: auto;
  }

  &:focus-visible::-webkit-slider-thumb {
    box-shadow: 0 0 0 0.2rem var(--pico-primary-focus);
  }

  &:focus-visible::-moz-range-thumb {
    box-shadow: 0 0 0 0.2rem var(--pico-primary-focus);
  }
}

.dual-range--disabled {
  opacity: 0.45;

  .fill {
    background: var(--rg-muted);
  }

  .thumb::-webkit-slider-thumb {
    border-color: var(--rg-muted);
    cursor: not-allowed;
  }

  .thumb::-moz-range-thumb {
    border-color: var(--rg-muted);
    cursor: not-allowed;
  }
}

// When both handles meet at the top end, the low one must stay grabbable.
.thumb--low {
  z-index: 2;
}

.thumb--high {
  z-index: 1;
}
</style>
