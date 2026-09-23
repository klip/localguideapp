<script setup lang="ts">
/**
 * Horizontally scrolling quick filters above a deck. Each chip is a toggle
 * button; the parent owns which ones are active.
 */
export interface Chip {
  id: string
  label: string
}

defineProps<{
  chips: Chip[]
  active: string[]
}>()

const emit = defineEmits<{
  toggle: [id: string]
}>()
</script>

<template>
  <div class="filters" role="group" aria-label="Quick filters">
    <button
      v-for="chip in chips"
      :key="chip.id"
      type="button"
      class="chip"
      :class="{ 'chip--active': active.includes(chip.id) }"
      :aria-pressed="active.includes(chip.id)"
      @click="emit('toggle', chip.id)"
    >
      {{ chip.label }}
    </button>
  </div>
</template>

<style scoped lang="scss">
.filters {
  display: flex;
  gap: 0.5rem;
  overflow-x: auto;
  padding-bottom: 0.25rem;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }
}

.chip {
  white-space: nowrap;
  border: 1px solid var(--rg-line);
  border-radius: 999px;
  padding: 0.45rem 0.75rem;
  font-size: 0.82rem;
  background: #fff;
  color: var(--rg-ink);
  width: auto;
  margin-bottom: 0;
  line-height: 1.2;

  &:hover {
    border-color: var(--rg-orange-300);
    background: var(--rg-orange-50);
    color: var(--rg-ink);
  }
}

.chip--active {
  background: var(--rg-orange-100);
  border-color: #efbf9f;
  color: #8f4f2b;

  &:hover {
    background: var(--rg-orange-100);
  }
}
</style>
