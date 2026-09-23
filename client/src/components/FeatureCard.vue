<script setup lang="ts">
/** Numbered explainer tile from the "how it works" row. */
defineProps<{
  title: string
  body: string
}>()
</script>

<template>
  <article class="feature">
    <strong>{{ title }}</strong>
    <p class="muted">{{ body }}</p>
  </article>
</template>

<style scoped lang="scss">
.feature {
  background: rgba(255, 255, 255, 0.75);
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-soft);
  padding: 1.15rem;
  margin-bottom: 0;

  p {
    margin-bottom: 0;
  }
}
</style>
