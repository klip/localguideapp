<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useMessagesStore } from '@/stores/messages'

/** Renders the global message queue. Mounted once, in App.vue. */
const messages = useMessagesStore()
const { items } = storeToRefs(messages)

// Toast height is assumed rather than measured, to keep stacking a pure,
// synchronous layout computation — good enough for how rarely two messages
// share the same anchor at once.
const TOAST_HEIGHT_PX = 48
const GAP_PX = 10
const PAGE_TOP_PX = 16
const INPUT_GAP_PX = 6

interface PositionedToast {
  id: number
  kind: string
  text: string
  place: 'page' | 'element' | 'input'
  style: { top: string; left: string; width?: string }
}

/**
 * Turns each message's `placement` into concrete `top`/`left` (and, for
 * inputs, `width`) values. Messages sharing the same anchor stack downward
 * so they don't overlap.
 */
const positioned = computed<PositionedToast[]>(() => {
  const stackCounts = new Map<string, number>()
  const nextIndex = (key: string) => {
    const index = stackCounts.get(key) ?? 0
    stackCounts.set(key, index + 1)
    return index
  }

  return items.value.map((item) => {
    const { placement } = item

    if (placement.type === 'page') {
      const index = nextIndex('page')
      return {
        id: item.id,
        kind: item.kind,
        text: item.text,
        place: 'page',
        style: { top: `${PAGE_TOP_PX + index * (TOAST_HEIGHT_PX + GAP_PX)}px`, left: '50%' },
      }
    }

    const key = `${placement.type}:${Math.round(placement.rect.left)}:${Math.round(placement.rect.top)}`
    const index = nextIndex(key)

    if (placement.type === 'input') {
      return {
        id: item.id,
        kind: item.kind,
        text: item.text,
        place: 'input',
        style: {
          top: `${placement.rect.bottom + INPUT_GAP_PX + index * (TOAST_HEIGHT_PX + GAP_PX)}px`,
          left: `${placement.rect.left}px`,
          width: `${placement.rect.width}px`,
        },
      }
    }

    return {
      id: item.id,
      kind: item.kind,
      text: item.text,
      place: 'element',
      style: {
        top: `${placement.rect.top + index * (TOAST_HEIGHT_PX + GAP_PX)}px`,
        left: `${placement.rect.left + placement.rect.width / 2}px`,
      },
    }
  })
})
</script>

<template>
  <div class="toaster" role="status" aria-live="polite">
    <TransitionGroup name="toast">
      <div
        v-for="item in positioned"
        :key="item.id"
        class="toast"
        :class="[`toast--${item.kind}`, `toast--place-${item.place}`]"
        :style="item.style"
      >
        <span>{{ item.text }}</span>
        <button type="button" class="dismiss" aria-label="Dismiss" @click="messages.dismiss(item.id)">
          ✕
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped lang="scss">
.toast {
  position: fixed;
  z-index: 100;
  pointer-events: auto;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  max-width: min(22rem, calc(100vw - 2rem));
  padding: 0.7rem 0.85rem;
  border-radius: var(--rg-radius-soft);
  box-shadow: var(--rg-shadow);
  font-size: 0.9rem;
  line-height: 1.35;
  border: 1px solid var(--rg-line);
  background: var(--rg-surface);
  color: var(--rg-ink);

  span {
    flex: 1;
  }
}

// Page-level: centred horizontally, stacked down from the top of the page.
.toast--place-page {
  transform: translateX(-50%);
}

// Element-level: centred on the anchor element's top-middle point.
.toast--place-element {
  transform: translate(-50%, -50%);
}

// Input-level: left/top/width come from the anchor rect (beneath the
// input, matching its width) — no transform needed.
.toast--place-input {
  min-width: 12rem;
}

.toast--success {
  background: var(--rg-green-soft);
  border-color: var(--rg-green-border);
  color: var(--rg-green);
}

.toast--error {
  background: var(--rg-red-soft);
  border-color: var(--rg-red-border);
  color: var(--rg-red);
}

.toast--info {
  background: var(--rg-orange-50);
  border-color: var(--rg-orange-200);
  color: var(--rg-orange-ink);
}

.dismiss {
  all: unset;
  cursor: pointer;
  font-size: 0.8rem;
  line-height: 1;
  color: inherit;
  opacity: 0.6;

  &:hover {
    opacity: 1;
  }
}

.toast-enter-active,
.toast-leave-active {
  transition:
    opacity 0.18s ease,
    transform 0.18s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
}

.toast--place-page.toast-enter-from,
.toast--place-page.toast-leave-to {
  transform: translate(-50%, -6px);
}

.toast--place-element.toast-enter-from,
.toast--place-element.toast-leave-to {
  transform: translate(-50%, calc(-50% - 6px));
}

.toast--place-input.toast-enter-from,
.toast--place-input.toast-leave-to {
  transform: translateY(-6px);
}
</style>
