<script setup lang="ts">
import { nextTick, onMounted, ref, useId, watch } from 'vue'
import { t } from '@/i18n'

/**
 * A native modal `<dialog>` (`showModal()`: the browser supplies the focus
 * trap, the inert background and Escape-to-close), styled by Pico's own
 * `dialog > article` rules — the same approach as `ReviewsDialog.vue`, minus
 * the fetching. Opened and closed through `open`; every way of closing it
 * (Escape, the ✕, a backdrop click) ends in the native `close` event, which
 * emits `close` so the parent can drop `open`.
 */
const props = defineProps<{
  open: boolean
  title: string
  /** Accessible name for the ✕ button. */
  closeLabel?: string
  /** Room for long text (the terms): wider, and the content scrolls between a fixed header and `#footer`. */
  wide?: boolean
}>()

const emit = defineEmits<{ close: [] }>()

const uid = useId()
const dialog = ref<HTMLDialogElement | null>(null)
const closeButton = ref<HTMLButtonElement | null>(null)

async function sync(open: boolean) {
  const el = dialog.value
  if (!el) return
  if (open) {
    if (!el.open) el.showModal()
    await nextTick()
    closeButton.value?.focus()
  } else if (el.open) {
    el.close()
  }
}

watch(() => props.open, sync)
onMounted(() => sync(props.open))

function close() {
  dialog.value?.close()
}

/** A click that lands on the `<dialog>` itself, not its content, is a click on the backdrop. */
function onDialogClick(event: MouseEvent) {
  if (event.target === dialog.value) close()
}

defineExpose({ close })
</script>

<template>
  <dialog
    ref="dialog"
    :aria-labelledby="`${uid}-title`"
    @close="emit('close')"
    @click="onDialogClick"
  >
    <article class="dialog-body" :class="{ wide }">
      <header>
        <button
          ref="closeButton"
          type="button"
          rel="prev"
          :aria-label="closeLabel ?? t('common.modal_dialog.close')"
          @click="close"
        />
        <h2 :id="`${uid}-title`" class="dialog-title">{{ title }}</h2>
      </header>
      <div class="dialog-content">
        <slot />
      </div>
      <footer v-if="$slots.footer" class="dialog-footer">
        <slot name="footer" />
      </footer>
    </article>
  </dialog>
</template>

<style scoped lang="scss">
.dialog-body {
  border-radius: var(--rg-radius-panel);
  background: var(--rg-surface);
  box-shadow: var(--rg-shadow);
  max-width: 26rem;
}

.dialog-body.wide {
  max-width: 44rem;
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 2rem);
  overflow: hidden;

  .dialog-content {
    overflow-y: auto;
    min-height: 0;
    flex: 1 1 auto;
  }
}

.dialog-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.5rem;

  :deep(button) {
    margin: 0;
  }
}

.dialog-title {
  font-size: 1.2rem;
  margin-bottom: 0;
}
</style>
