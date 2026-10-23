<script setup lang="ts">
import FingerHeart from './FingerHeart.vue'
import { t } from '@/i18n'
/**
 * The pass / interested pair at the foot of every discovery card: a plain
 * white Pass and the honey primary Interested, marked with the brand's
 * finger heart (no bee at this size).
 */
withDefaults(
  defineProps<{
    rejectLabel?: string
    acceptLabel?: string
    disabled?: boolean
  }>(),
  { disabled: false },
)

const emit = defineEmits<{
  reject: []
  accept: []
}>()
</script>

<template>
  <div class="decision-row">
    <button type="button" class="secondary reject" :disabled="disabled" @click="emit('reject')">
      <span aria-hidden="true">✕</span> {{ rejectLabel ?? t('common.decision_row.reject') }}
    </button>
    <button type="button" class="accept" :disabled="disabled" @click="emit('accept')">
      <FingerHeart class="accept-mark" :bee="false" :weight="7" />{{
        acceptLabel ?? t('common.decision_row.interested')
      }}
    </button>
  </div>
</template>

<style scoped lang="scss">
.decision-row {
  display: grid;
  grid-template-columns: 1fr 1.5fr;
  gap: 0.625rem;
}

button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  height: 3rem;
  margin: 0;
  border-radius: 0.75rem;
  font-size: 1rem;
  font-weight: 600;
}

// The hand on the honey button: cream fill, ink outline.
.accept-mark {
  --fh-skin: var(--rg-cream);
  width: 1.5rem;
  height: 1.5rem;
  margin-block: -0.25rem;
}
</style>
