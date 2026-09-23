<script setup lang="ts">
/** Wrapping row of soft tags — services, time windows, interests. */
defineProps<{ tags: string[] }>()
</script>

<template>
  <ul v-if="tags.length" class="tags">
    <li v-for="tag in tags" :key="tag" class="tag">{{ tag }}</li>
  </ul>
</template>

<style scoped lang="scss">
.tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0.75rem 0;
  padding: 0;
  list-style: none;
}

.tag {
  background: var(--rg-orange-50);
  color: #8d5330;
  border-radius: 999px;
  padding: 0.3rem 0.55rem;
  font-size: 0.76rem;
  list-style: none;
}
</style>
