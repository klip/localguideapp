<script setup lang="ts">
withDefaults(defineProps<{ as?: 'link' | 'text' }>(), { as: 'link' })
</script>

<template>
  <RouterLink v-if="as === 'link'" class="brand" to="/">Rock<span>Guide</span></RouterLink>
  <span v-else class="brand">Rock<span>Guide</span></span>
</template>

<style scoped lang="scss">
.brand {
  font-weight: 800;
  letter-spacing: -0.03em;
  color: var(--rg-ink);
  text-decoration: none;

  span {
    color: var(--rg-orange-500);
  }
}
</style>
