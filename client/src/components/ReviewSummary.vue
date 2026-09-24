<script setup lang="ts">
import { computed } from 'vue'
import StarRating from './StarRating.vue'
import type { ReviewHistogram } from '@/plugins/api'

/**
 * The Google-Maps-style header: one bar per grade (5 down to 1) on the left,
 * the big average, its stars and the total on the right.
 */
const props = defineProps<{
  average: number | null
  total: number
  histogram: ReviewHistogram
}>()

const GRADES = ['5', '4', '3', '2', '1'] as const

const bars = computed(() =>
  GRADES.map((grade) => {
    const count = props.histogram[grade] ?? 0
    return { grade, count, percent: props.total > 0 ? Math.round((count / props.total) * 100) : 0 }
  }),
)
</script>

<template>
  <div class="summary">
    <ul class="bars" aria-label="Reviews by grade">
      <li v-for="bar in bars" :key="bar.grade" class="bar-row">
        <span class="tiny grade" aria-hidden="true">{{ bar.grade }}</span>
        <span class="track" aria-hidden="true"
          ><span class="fill" :style="{ width: `${bar.percent}%` }"
        /></span>
        <span class="visually-hidden">{{ bar.grade }} stars: {{ bar.count }}</span>
      </li>
    </ul>

    <div class="score">
      <strong class="average" data-test="review-average">{{
        average !== null ? average.toFixed(1) : '–'
      }}</strong>
      <StarRating :value="average ?? 0" size="lg" />
      <span class="tiny muted" data-test="review-total"
        >{{ total }} {{ total === 1 ? 'review' : 'reviews' }}</span
      >
    </div>
  </div>
</template>

<style scoped lang="scss">
.summary {
  display: flex;
  gap: 1.5rem;
  align-items: center;
}

.bars {
  flex: 1;
  list-style: none;
  padding: 0;
  margin: 0;
}

.bar-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0 0 0.3rem;
  list-style: none;
}

.grade {
  width: 0.75rem;
  text-align: right;
}

.track {
  flex: 1;
  height: 0.55rem;
  border-radius: 999px;
  background: var(--rg-orange-50);
  overflow: hidden;
}

.fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--rg-orange-500);
}

.score {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  min-width: 6.5rem;
}

.average {
  font-size: 3rem;
  line-height: 1;
  font-weight: 800;
}
</style>
