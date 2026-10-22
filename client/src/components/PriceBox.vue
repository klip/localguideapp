<script setup lang="ts">
import { formatMoney, PACK_PRICE } from '@/utils/money'
import { t } from '@/i18n'
/**
 * Warm gradient panel carrying the pack price (in the viewer's currency, see
 * `utils/money.ts`). Used full-width on the landing page and compact inside
 * the unlock panel.
 */
withDefaults(
  defineProps<{
    price?: string
    caption?: string
    compact?: boolean
  }>(),
  { price: undefined, compact: false },
)
</script>

<template>
  <div class="price-box" :class="{ 'price-box--compact': compact }">
    <slot>
      <div class="price">{{ price ?? formatMoney(PACK_PRICE, { trim: true }) }}</div>
      <div class="muted">{{ caption ?? t('common.price_box.caption') }}</div>
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
