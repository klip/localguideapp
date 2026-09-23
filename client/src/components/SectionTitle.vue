<script setup lang="ts">
import PillBadge from './PillBadge.vue'

/** Eyebrow + heading + optional lead, used at the top of every page section. */
defineProps<{
  eyebrow?: string
  heading: string
  lead?: string
}>()
</script>

<template>
  <div class="section-title">
    <PillBadge v-if="eyebrow">{{ eyebrow }}</PillBadge>
    <h2>{{ heading }}</h2>
    <p v-if="lead" class="muted">{{ lead }}</p>
    <slot />
  </div>
</template>

<style scoped lang="scss">
.section-title {
  max-width: 700px;
  margin-bottom: 1.5rem;

  h2 {
    margin-top: 0.75rem;
    margin-bottom: 0.5rem;
  }

  p {
    margin-bottom: 0;
  }
}
</style>
