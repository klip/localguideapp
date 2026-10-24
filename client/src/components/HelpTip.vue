<script setup lang="ts">
import { onBeforeUnmount, ref, useId } from 'vue'
import { t } from '@/i18n'

/**
 * A small circled "?" beside a short field label, opening the longer
 * explanation the label left out. Hover or focus shows it; a click (touch)
 * toggles it; Escape or a click elsewhere closes it. Sits inside a <label>
 * safely: a button there doesn't focus the field.
 */
defineProps<{ text: string }>()

const open = ref(false)
const id = useId()
const root = ref<HTMLElement | null>(null)

function onDocumentPointer(event: PointerEvent) {
  if (!root.value?.contains(event.target as Node)) close()
}

function toggle() {
  if (open.value) close()
  else {
    open.value = true
    document.addEventListener('pointerdown', onDocumentPointer)
  }
}

function close() {
  open.value = false
  document.removeEventListener('pointerdown', onDocumentPointer)
}

onBeforeUnmount(close)
</script>

<template>
  <span ref="root" class="help-tip" :class="{ 'help-tip--open': open }">
    <button
      type="button"
      class="help-tip-button"
      :aria-label="t('common.help_tip.label')"
      :aria-expanded="open"
      :aria-describedby="id"
      data-test="help-tip"
      @click.prevent.stop="toggle"
      @keydown.escape.stop="close"
    >
      ?
    </button>
    <span :id="id" role="tooltip" class="help-tip-text">{{ text }}</span>
  </span>
</template>

<style scoped lang="scss">
.help-tip {
  position: relative;
  display: inline-flex;
  flex: 0 0 auto;
  vertical-align: middle;
}

.help-tip-button {
  display: inline-grid;
  place-items: center;
  width: 1.05rem;
  height: 1.05rem;
  margin: 0;
  padding: 0;
  border: 1px solid var(--rg-line-strong);
  border-radius: 50%;
  background: #fff;
  color: var(--rg-muted);
  font-size: 0.6875rem;
  font-weight: 700;
  line-height: 1;
  cursor: help;

  &:hover,
  &:focus-visible {
    border-color: var(--rg-orange-500);
    background: #fff;
    color: var(--rg-orange-700);
  }
}

.help-tip-text {
  position: absolute;
  top: calc(100% + 0.4rem);
  left: 50%;
  z-index: 20;
  width: max-content;
  max-width: min(16rem, 80vw);
  padding: 0.5rem 0.65rem;
  border-radius: 0.5rem;
  background: var(--rg-ink);
  color: #fff;
  font-size: 0.75rem;
  font-weight: 400;
  line-height: 1.4;
  white-space: normal;
  box-shadow: var(--rg-shadow);
  pointer-events: none;
  opacity: 0;
  visibility: hidden;
  translate: -50% -0.25rem;
  transition:
    opacity 0.15s ease,
    translate 0.15s ease,
    visibility 0s linear 0.15s;
}

.help-tip:hover .help-tip-text,
.help-tip:focus-within .help-tip-text,
.help-tip--open .help-tip-text {
  opacity: 1;
  visibility: visible;
  translate: -50% 0;
  transition:
    opacity 0.15s ease,
    translate 0.15s ease;
}
</style>
