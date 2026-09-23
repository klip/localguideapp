<script setup lang="ts">
/** The two-up pass / interested control at the foot of every discovery card. */
withDefaults(
  defineProps<{
    rejectLabel?: string
    acceptLabel?: string
    disabled?: boolean
  }>(),
  { rejectLabel: '✕ Reject', acceptLabel: '♥ Interested', disabled: false },
)

const emit = defineEmits<{
  reject: []
  accept: []
}>()
</script>

<template>
  <div class="decision-row">
    <button type="button" class="reject" :disabled="disabled" @click="emit('reject')">
      {{ rejectLabel }}
    </button>
    <button type="button" class="accept" :disabled="disabled" @click="emit('accept')">
      {{ acceptLabel }}
    </button>
  </div>
</template>

<style scoped lang="scss">
.decision-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
  margin-top: 0.85rem;
}

button {
  margin-bottom: 0;
  font-weight: 600;
}

.reject {
  background: var(--rg-red-soft);
  border-color: var(--rg-red-border);
  color: var(--rg-red);

  &:hover:not(:disabled) {
    background: #ffe4e0;
    border-color: var(--rg-red);
    color: var(--rg-red);
  }
}

.accept {
  background: var(--rg-green-soft);
  border-color: var(--rg-green-border);
  color: var(--rg-green);

  &:hover:not(:disabled) {
    background: #dcefe4;
    border-color: var(--rg-green);
    color: var(--rg-green);
  }
}
</style>
