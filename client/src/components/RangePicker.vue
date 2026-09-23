<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { formatRange, formatRangeValue, type RangeFormat } from '@/utils/formatRange'
import type { NumericRange } from '@/types'

/**
 * Two sliders and an Add button: the input for a `range` category (hours of
 * the day on a profile, hours or age in the filter bar). Adding is explicit
 * rather than live because a category holds *several* spans — "mornings and
 * evenings" is two of them — so dragging has to be able to settle before it
 * means anything.
 *
 * Purely presentational: the spans it has already collected are passed in and
 * removal is emitted upward, so the same control works against the filter
 * store and against the profile form.
 */
const props = withDefaults(
  defineProps<{
    ranges: NumericRange[]
    min: number
    max: number
    format?: RangeFormat
    addLabel?: string
  }>(),
  { format: 'number', addLabel: 'Add' },
)

const emit = defineEmits<{
  add: [NumericRange]
  remove: [number]
}>()

const start = ref(props.min)
const end = ref(props.max)

// Bounds arrive with the category, which can load after this mounts. Watched
// as a pair but read off `props` directly — destructuring the watched array
// widens each element to `number | undefined` under strict index checks.
watch(
  () => [props.min, props.max],
  () => {
    start.value = props.min
    end.value = props.max
  },
)

/** Dragging one handle past the other pushes rather than inverts the span. */
function onStartInput() {
  if (start.value > end.value) end.value = start.value
}

function onEndInput() {
  if (end.value < start.value) start.value = end.value
}

const preview = computed(() => formatRange([start.value, end.value], props.format))

const isDuplicate = computed(() =>
  props.ranges.some((range) => range[0] === start.value && range[1] === end.value),
)
</script>

<template>
  <div class="range-picker">
    <ul v-if="ranges.length" class="range-chips">
      <li v-for="(range, index) in ranges" :key="`${range[0]}-${range[1]}`" class="chip">
        {{ formatRange(range, format) }}
        <button
          type="button"
          class="chip-remove"
          :aria-label="`Remove ${formatRange(range, format)}`"
          @click="emit('remove', index)"
        >
          ×
        </button>
      </li>
    </ul>

    <div class="sliders">
      <label class="tiny">
        <span class="slider-label">From <strong>{{ formatRangeValue(start, format) }}</strong></span>
        <input v-model.number="start" type="range" :min="min" :max="max" @input="onStartInput" />
      </label>

      <label class="tiny">
        <span class="slider-label">To <strong>{{ formatRangeValue(end, format) }}</strong></span>
        <input v-model.number="end" type="range" :min="min" :max="max" @input="onEndInput" />
      </label>
    </div>

    <button
      type="button"
      class="soft-btn add-btn"
      :disabled="isDuplicate"
      @click="emit('add', [start, end])"
    >
      {{ isDuplicate ? 'Already added' : `${addLabel} ${preview}` }}
    </button>
  </div>
</template>

<style scoped lang="scss">
.range-picker {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.range-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  list-style: none;
  padding: 0;
  margin: 0;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.3rem 0.6rem;
  border-radius: 999px;
  border: 1px solid var(--rg-orange-200);
  background: var(--rg-orange-50);
  color: var(--rg-orange-ink);
  font-size: 0.8rem;
  font-weight: 600;
}

.chip-remove {
  width: auto;
  margin: 0;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font-size: 1rem;
  line-height: 1;

  &:hover,
  &:focus-visible {
    background: none;
    color: var(--rg-red);
  }
}

.sliders {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;

  label {
    margin: 0;
  }

  input[type='range'] {
    margin: 0;
  }
}

.slider-label {
  display: block;
  color: var(--rg-muted);
}

.add-btn {
  width: auto;
  align-self: flex-start;
  margin: 0;
  padding: 0.4rem 0.9rem;
  font-size: 0.85rem;
}
</style>
