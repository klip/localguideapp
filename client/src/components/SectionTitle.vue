<script setup lang="ts">
import PillBadge from './PillBadge.vue'

/**
 * Heading + optional lead, at the top of every page. The eyebrow pill shows
 * only with `showEyebrow` (the marketing page): inside the app every title
 * sits at the same height as the decks' and the profile's (sketches 04/05
 * have no eyebrow). Pages still pass `eyebrow`; it's kept for that page.
 */
defineProps<{
  eyebrow?: string
  heading: string
  lead?: string
  showEyebrow?: boolean
}>()
</script>

<template>
  <div class="section-title">
    <PillBadge v-if="eyebrow && showEyebrow">{{ eyebrow }}</PillBadge>
    <h2>{{ heading }}</h2>
    <p v-if="lead" class="muted">{{ lead }}</p>
    <slot />
  </div>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

// The page title: as far from the header and from the content as the decks'
// (`.page-head`, _layout.scss), and the same size.
.section-title {
  max-width: 700px;
  margin-bottom: 1.5rem;

  h2 {
    margin: 0 0 0.5rem;
    font-size: 1.375rem;

    @include bp.lg {
      font-size: 1.75rem;
    }
  }

  // An eyebrow pill, when there is one, sits just above the heading.
  .pill + h2 {
    margin-top: 0.5rem;
  }

  p {
    margin-bottom: 0;
  }
}
</style>
