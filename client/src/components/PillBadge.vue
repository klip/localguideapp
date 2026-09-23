<script setup lang="ts">
/** Small rounded label — used for eyebrows, counters and trust markers. */
withDefaults(defineProps<{ tone?: 'warm' | 'neutral' }>(), { tone: 'warm' })
</script>

<template>
  <span class="pill" :class="`pill--${tone}`"><slot /></span>
</template>

<style scoped lang="scss">
.pill {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.7rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 700;
  line-height: 1.3;
}

.pill--warm {
  background: var(--rg-orange-100);
  color: var(--rg-orange-ink);
}

.pill--neutral {
  background: #fff;
  border: 1px solid var(--rg-line);
  color: var(--rg-muted);
}
</style>
