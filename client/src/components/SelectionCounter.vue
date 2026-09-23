<script setup lang="ts">
/**
 * "3 selections left" strip with an optional unlock action. Shown above the
 * visitor deck and in the desktop shortlist rail.
 */
withDefaults(
  defineProps<{
    headline: string
    caption?: string
    actionLabel?: string
  }>(),
  { actionLabel: '' },
)

const emit = defineEmits<{ action: [] }>()
</script>

<template>
  <div class="counter">
    <span>
      <strong>{{ headline }}</strong>
      <span v-if="caption" class="tiny muted caption">{{ caption }}</span>
    </span>
    <button v-if="actionLabel" type="button" class="link tiny" @click="emit('action')">
      {{ actionLabel }}
    </button>
  </div>
</template>

<style scoped lang="scss">
.counter {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 0.85rem;
  background: var(--rg-orange-50);
  border-radius: 1rem;
  margin: 0.85rem 0;
}

.caption {
  display: block;
}

.link {
  width: auto;
  margin: 0;
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  text-decoration: underline;
  white-space: nowrap;
}
</style>
