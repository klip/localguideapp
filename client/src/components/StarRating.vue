<script setup lang="ts">
import { computed } from 'vue'

/**
 * Read-only stars for a grade or an average — a filled row of five clipped
 * to `value / 5` over an empty one, so 4.5 shows four and a half stars.
 * Announced as one image ("Rated 4.5 out of 5"), not five glyphs. The
 * interactive picker is the radio group in `ReviewForm.vue`.
 */
const props = withDefaults(defineProps<{ value: number; size?: 'sm' | 'lg' }>(), { size: 'sm' })

const clamped = computed(() => Math.max(0, Math.min(5, props.value)))
const label = computed(() => `Rated ${Number(clamped.value.toFixed(1))} out of 5`)
</script>

<template>
  <span class="stars" :class="`stars--${size}`" role="img" :aria-label="label">
    <span class="stars-empty" aria-hidden="true">★★★★★</span>
    <span class="stars-filled" aria-hidden="true" :style="{ width: `${(clamped / 5) * 100}%` }"
      >★★★★★</span
    >
  </span>
</template>

<style scoped lang="scss">
.stars {
  position: relative;
  display: inline-block;
  line-height: 1;
  letter-spacing: 0.08em;
  white-space: nowrap;
  vertical-align: middle;
}

.stars--sm {
  font-size: 0.9rem;
}

.stars--lg {
  font-size: 1.25rem;
}

.stars-empty {
  color: var(--rg-line);
}

.stars-filled {
  position: absolute;
  inset: 0 auto 0 0;
  overflow: hidden;
  color: var(--rg-orange-500);
}
</style>
