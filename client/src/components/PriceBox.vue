<script setup lang="ts">
/**
 * Warm gradient panel carrying the £5 price. Used full-width on the landing
 * page and compact inside the unlock panel.
 */
withDefaults(
  defineProps<{
    price?: string
    caption?: string
    compact?: boolean
  }>(),
  { price: '£5', caption: 'one-time selection fee', compact: false },
)
</script>

<template>
  <div class="price-box" :class="{ 'price-box--compact': compact }">
    <slot>
      <div class="price">{{ price }}</div>
      <div class="muted">{{ caption }}</div>
    </slot>
  </div>
</template>

<style scoped lang="scss">
.price-box {
  background: linear-gradient(145deg, #fff7ef, #ffe5d2);
  border: 1px solid #f5ccb0;
  border-radius: var(--rg-radius-panel);
  padding: 1.4rem;
}

.price-box--compact {
  padding: 1rem;
  margin-bottom: 1rem;
}
</style>
