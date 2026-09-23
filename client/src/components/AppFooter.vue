<script setup lang="ts">
import BrandMark from './BrandMark.vue'
</script>

<template>
  <footer>
    <hr />
    <div class=" shell">
      <div class="row-between wrap">
        <BrandMark as="text" />
        <span class="tiny muted">Concept UI · Mobile first · Pico CSS</span>
      </div>
    </div>
  </footer>
</template>

<style scoped lang="scss">
footer {
  padding: 2.5rem 0 3rem;
  color: var(--rg-muted);
}
</style>
