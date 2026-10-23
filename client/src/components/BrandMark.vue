<script setup lang="ts">
/**
 * The logo: "Be [finger heart] Local" — read as "Be Like Local", the domain
 * (belikelocal.club). The hand (FingerHeart.vue, with its bee) is our own
 * drawing and reads as "Like" to screen readers. A link home (`AppHeader`)
 * or plain text (`AppFooter`, the mobile drawer's head). Never translated,
 * and it stays left-to-right in Arabic.
 */
import FingerHeart from './FingerHeart.vue'

withDefaults(defineProps<{ as?: 'link' | 'text' }>(), { as: 'link' })
</script>

<template>
  <component
    :is="as === 'link' ? 'RouterLink' : 'span'"
    class="brand"
    translate="no"
    dir="ltr"
    v-bind="as === 'link' ? { to: '/' } : {}"
  >
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
</style>
