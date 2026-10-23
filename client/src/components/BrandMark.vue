<script setup lang="ts">
/**
 * The logo: "Be [finger heart] Local" — read as "Be Like Local", the domain
 * (belikelocal.club). The hand (FingerHeart.vue, with its bee) is our own
 * drawing and reads as "Like" to screen readers. A link home (`AppHeader`)
 * or plain text (`AppFooter`, the mobile drawer's head). Never translated,
 * and it stays left-to-right in Arabic.
 *
 * `comb` lays the hand-drawn honeycomb mesh behind it, fading out — the
 * header's background texture.
 */
import FingerHeart from './FingerHeart.vue'
import { honeycombPath } from '@/utils/honeycomb'

withDefaults(defineProps<{ as?: 'link' | 'text'; comb?: boolean }>(), {
  as: 'link',
  comb: false,
})

// Same drawing on every load (fixed seed); built once per module.
const COMB = honeycombPath(320, 120, 15)
</script>

<template>
  <component
    :is="as === 'link' ? 'RouterLink' : 'span'"
    class="brand"
    :class="{ 'brand--comb': comb }"
    translate="no"
    dir="ltr"
    v-bind="as === 'link' ? { to: '/' } : {}"
  >
    <svg v-if="comb" class="brand-comb" viewBox="0 0 320 120" aria-hidden="true" focusable="false">
      <path :d="COMB" />
    </svg>
    <span class="brand-word">Be</span>
    <FingerHeart class="brand-hand" :weight="5.5" />
    <span class="visually-hidden">Like</span>
    <span class="brand-word">Local</span>
  </component>
</template>

<style scoped lang="scss">
.brand {
  position: relative;
  display: inline-flex;
  align-items: center;
  font-family: var(--rg-font-display);
  font-weight: 900;
  letter-spacing: -0.035em;
  line-height: 1;
  color: var(--rg-ink);
  text-decoration: none;
  white-space: nowrap;
}

.brand-word {
  position: relative;
}

// Tight, like the logo slide: the hand tucks in between the words and sits
// a little above the baseline, its bee flying higher still.
.brand-hand {
  position: relative;
  flex-shrink: 0;
  width: 1.45em;
  height: 1.45em;
  margin-inline: -0.1em -0.12em;
  transform: translateY(-0.12em);
}

.brand-comb {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 13em;
  height: 4.9em;
  transform: translate(-50%, -50%);
  pointer-events: none;
  fill: none;
  stroke: #e2ae5c;
  stroke-width: 1.3;
  stroke-linejoin: round;
  opacity: 0.75;
  // Strongest behind the mark, gone by the edges.
  mask-image: radial-gradient(ellipse 50% 50% at 50% 50%, #000 30%, transparent 100%);
}
</style>
