<script setup lang="ts">
import PanelCard from '@/components/PanelCard.vue'
</script>

<template>
  <section class="section shell">
    <PanelCard class="narrow" title="Page not found" subtitle="That route does not exist.">
      <RouterLink to="/" role="button" class="full">Back to the start</RouterLink>
    </PanelCard>
  </section>
</template>
