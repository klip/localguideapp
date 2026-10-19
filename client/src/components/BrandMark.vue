<script setup lang="ts">
/**
 * The logo: a map pin with a magnifier in it, then the "LocalGuideFinder"
 * wordmark (working name). A link home (`AppHeader`) or plain text
 * (`AppFooter`, the mobile drawer's head). Never translated, and the
 * wordmark stays left-to-right in Arabic.
 */
withDefaults(defineProps<{ as?: 'link' | 'text' }>(), { as: 'link' })
</script>

<template>
  <component
    :is="as === 'link' ? 'RouterLink' : 'span'"
    class="brand"
    translate="no"
    v-bind="as === 'link' ? { to: '/' } : {}"
  >
    <svg class="brand-pin" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
      <path
        d="M12 1.6c-4.6 0-8.3 3.6-8.3 8.1 0 5.9 7.2 12.1 7.5 12.4a1.2 1.2 0 0 0 1.6 0c.3-.3 7.5-6.5 7.5-12.4 0-4.5-3.7-8.1-8.3-8.1z"
        class="brand-pin-body"
      />
      <circle cx="11.3" cy="9.2" r="3.2" fill="none" stroke="#fff" stroke-width="1.9" />
      <line
        x1="13.6"
        y1="11.5"
        x2="15.6"
        y2="13.5"
        stroke="#fff"
        stroke-width="1.9"
        stroke-linecap="round"
      />
    </svg>
    <span class="brand-word" dir="ltr">Local<span class="brand-accent">Guide</span>Finder</span>
  </component>
</template>

<style scoped lang="scss">
.brand {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1;
  color: var(--rg-ink);
  text-decoration: none;
  white-space: nowrap;
}

.brand-pin {
  flex-shrink: 0;
  width: 1.45em;
  height: 1.45em;
}

.brand-pin-body {
  fill: var(--rg-orange-500);
}

.brand-accent {
  color: var(--rg-orange-500);
}
</style>
