<script setup lang="ts">
import { useId } from 'vue'
import { t } from '@/i18n'
import { useLegalStore } from '@/stores/legal'

/**
 * "Protected by RockGuide" — shown right before every payment (a selection
 * pack on /unlock and in Discover's payment modal, a guide's fee in
 * `BookingPanel`): what paying through the platform covers, and that paying
 * outside it doesn't. "How it works" opens the terms at section 5.1.
 * `kind` picks the wording: a pack buys picks, a fee books a trip.
 */
withDefaults(defineProps<{ kind?: 'pack' | 'fee' }>(), { kind: 'fee' })

const legal = useLegalStore()
const uid = useId()
</script>

<template>
  <div class="protected" role="note" :aria-labelledby="uid" data-test="protected-notice">
    <span class="shield" aria-hidden="true">🛡</span>
    <div>
      <strong :id="uid">{{ t('common.protected_notice.title') }}</strong>
      <p class="tiny">{{ t(`common.protected_notice.body_${kind}`) }}</p>
      <button type="button" class="link tiny" @click="legal.showTerms('5.1')">
        {{ t('common.protected_notice.how_it_works') }}
      </button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.protected {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
  margin: 0.75rem 0;
  padding: 0.6rem 0.75rem;
  border-radius: var(--rg-radius-soft);
  background: var(--rg-green-soft);
  border: 1px solid var(--rg-green-border);
  text-align: left;

  p {
    margin: 0.15rem 0 0.25rem;
  }
}

.shield {
  font-size: 1.2rem;
  line-height: 1.3;
}

.link {
  width: auto;
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  color: var(--rg-green);
  text-decoration: underline;

  &:hover,
  &:focus-visible {
    background: none;
    color: var(--rg-ink);
  }
}
</style>
