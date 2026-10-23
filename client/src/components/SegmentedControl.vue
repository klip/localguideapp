<script setup lang="ts" generic="T extends string">
/**
 * A small choice (two or three options) as one row of segments, the chosen
 * one raised — instead of a select (docs/design/sketches/03-ui-system.png).
 * Real radio buttons underneath, so arrow keys and screen readers treat it
 * as one choice; the legend names it.
 */
defineProps<{
  legend: string
  /** Groups the radios; unique per page. */
  name: string
  options: { value: T; label: string }[]
}>()

const model = defineModel<T>({ required: true })
</script>

<template>
  <fieldset class="segmented">
    <legend>{{ legend }}</legend>
    <div class="segments">
      <label v-for="option in options" :key="option.value" class="segment">
        <input v-model="model" type="radio" :name="name" :value="option.value" />
        <span>{{ option.label }}</span>
      </label>
    </div>
  </fieldset>
</template>

<style scoped lang="scss">
.segmented {
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}

legend {
  margin-bottom: 0.375rem;
  padding: 0;
  font-size: 0.875rem;
  font-weight: 600;
}

.segments {
  display: flex;
  gap: 2px;
  height: var(--rg-control-height);
  padding: 3px;
  border: 1px solid var(--rg-line-strong);
  border-radius: var(--rg-radius-control);
  background: var(--rg-sunken);
}

.segment {
  position: relative;
  flex: 1 1 0;
  display: flex;
  margin: 0;
  font-weight: 500;

  // The radio stays focusable and announced; only the segment is drawn.
  input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    opacity: 0;
    cursor: pointer;
  }

  span {
    flex: 1;
    display: grid;
    place-items: center;
    padding: 0 0.5rem;
    border-radius: 0.4rem;
    color: var(--rg-muted);
    font-size: 0.875rem;
    white-space: nowrap;
  }

  input:checked + span {
    background: #fff;
    color: var(--rg-ink);
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(42, 31, 23, 0.14);
  }

  input:focus-visible + span {
    outline: 2px solid var(--rg-orange-500);
    outline-offset: 1px;
  }

  &:hover span {
    color: var(--rg-ink);
  }
}
</style>
