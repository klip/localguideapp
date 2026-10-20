<script setup lang="ts">
/**
 * The logo: "Be [thumbs up] Local" — read as "Be Like Local", the domain
 * (belikelocal.club). The thumb is our own drawing, not a third-party icon;
 * it reads as "Like" to screen readers. A link home (`AppHeader`) or plain
 * text (`AppFooter`, the mobile drawer's head). Never translated, and it
 * stays left-to-right in Arabic.
 */
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
    <svg class="brand-thumb" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
      <rect class="brand-thumb-cuff" x="2" y="10" width="4.2" height="11.5" rx="1.2" />
      <path
        class="brand-thumb-hand"
        d="M7.3 10.4 10.7 4.3c.6-1.1 2.2-1.2 2.9-.1.4.7.5 1.5.3 2.3l-.8 3.3h5.8c1.5 0 2.6 1.4 2.3 2.8l-1.4 7.1c-.2 1.1-1.2 1.8-2.3 1.8H7.3z"
      />
      <path class="brand-thumb-fingers" d="M13.4 13.2h6.9M13.4 16.6h6.3" />
    </svg>
    <span class="visually-hidden">Like</span>
    <span class="brand-word brand-accent">Local</span>
  </component>
</template>

<style scoped lang="scss">
.brand {
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1;
  color: var(--rg-ink);
  text-decoration: none;
  white-space: nowrap;
}

.brand-thumb {
  flex-shrink: 0;
  width: 1.3em;
  height: 1.3em;
  // Sits on the text's baseline like a letter, lifted a touch.
  margin-block-end: 0.12em;
}

.brand-thumb-hand {
  fill: var(--rg-orange-500);
}

.brand-thumb-cuff {
  fill: var(--rg-ink);
}

.brand-thumb-fingers {
  fill: none;
  stroke: #fff;
  stroke-width: 1.3;
  stroke-linecap: round;
}

.brand-accent {
  color: var(--rg-orange-500);
}
</style>
