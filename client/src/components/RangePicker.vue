<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import DualRangeSlider from '@/components/DualRangeSlider.vue'
import { t } from '@/i18n'
import { formatRange, type RangeFormat } from '@/utils/formatRange'
import type { NumericRange } from '@/types'

/**
 * One slider with two handles and an Add button: the input for a `range`
 * category with several spans (a guide's hours on their profile). Adding is explicit
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
  { format: 'number' },
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

/** The two handles' span (`DualRangeSlider` keeps them from crossing). */
const span = computed({
  get: (): NumericRange => [start.value, end.value],
  set: (next: NumericRange) => {
    start.value = next[0]
    end.value = next[1]
  },
})

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
          :aria-label="t('common.range_picker.remove', { range: formatRange(range, format) })"
          @click="emit('remove', index)"
        >
          ×
        </button>
      </li>
    </ul>

    <div class="slider">
      <div class="slider-label tiny">
        <span>{{ t('common.range_picker.from') }} – {{ t('common.range_picker.to') }}</span>
        <strong data-test="range-preview">{{ preview }}</strong>
      </div>
      <DualRangeSlider
        v-model="span"
        :min="min"
        :max="max"
        :min-label="t('common.range_picker.from')"
        :max-label="t('common.range_picker.to')"
      />
    </div>

    <button
      type="button"
      class="soft-btn add-btn"
      :disabled="isDuplicate"
      @click="emit('add', [start, end])"
    >
      {{
        isDuplicate
          ? t('common.range_picker.already_added')
          : t('common.range_picker.add_span', {
              label: addLabel ?? t('common.range_picker.add'),
              range: preview,
            })
      }}
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

.slider {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

// "From – to … 09:00–13:00", like the filter bar's age row.
.slider-label {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  color: var(--rg-muted);

  strong {
    color: var(--rg-ink, inherit);
  }
}

.add-btn {
  width: auto;
  align-self: flex-start;
  margin: 0;
  padding: 0.4rem 0.9rem;
  font-size: 0.85rem;
}
</style>
