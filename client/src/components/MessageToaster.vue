<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { t } from '@/i18n'
import { useMessagesStore } from '@/stores/messages'

/** Renders the global message queue. Mounted once, in App.vue. */
const messages = useMessagesStore()
const { items } = storeToRefs(messages)

// Toast height is assumed rather than measured, to keep stacking a pure,
// synchronous layout computation — good enough for how rarely two messages
// share the same anchor at once.
const TOAST_HEIGHT_PX = 48
const GAP_PX = 10
const INPUT_GAP_PX = 6

interface PositionedToast {
  id: number
  kind: string
  text: string
  to?: string
  place: 'page' | 'element' | 'input'
  /** Anchored toasts only — page-level ones sit in the `.toaster-page` column. */
  style: { top?: string; left?: string; width?: string }
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

    // Page-level toasts stack in a column (`.toaster-page`) that grows with
    // their real height — notifications run to several lines.
    if (placement.type === 'page') {
      return {
        id: item.id,
        kind: item.kind,
        text: item.text,
        to: item.to,
        place: 'page',
        style: {},
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
/**
 * A toast stays while the pointer is on it or focus is inside it (its link or
 * ✕) — a keyboard or screen-reader user needs time to reach a notification's
 * link before it times out.
 */
const holds = new Map<number, Set<'hover' | 'focus'>>()
function hold(id: number, why: 'hover' | 'focus') {
  const reasons = holds.get(id) ?? new Set()
  if (!reasons.size) messages.pause(id)
  reasons.add(why)
  holds.set(id, reasons)
}
function release(id: number, why: 'hover' | 'focus') {
  const reasons = holds.get(id)
  if (!reasons?.delete(why) || reasons.size) return
  holds.delete(id)
  messages.resume(id)
}
function onFocusOut(id: number, event: FocusEvent) {
  const toast = event.currentTarget as HTMLElement | null
  if (toast && event.relatedTarget instanceof Node && toast.contains(event.relatedTarget)) return
  release(id, 'focus')
}

const pageToasts = computed(() => positioned.value.filter((toast) => toast.place === 'page'))
const anchoredToasts = computed(() => positioned.value.filter((toast) => toast.place !== 'page'))
</script>

<template>
  <div class="toaster" role="status" aria-live="polite">
    <TransitionGroup name="toast" tag="div" class="toaster-page">
      <div
        v-for="item in pageToasts"
        :key="item.id"
        class="toast"
        :class="[`toast--${item.kind}`, 'toast--place-page']"
        @mouseenter="hold(item.id, 'hover')"
        @mouseleave="release(item.id, 'hover')"
        @focusin="hold(item.id, 'focus')"
        @focusout="onFocusOut(item.id, $event)"
      >
        <RouterLink
          v-if="item.to"
          :to="item.to"
          class="toast-link"
          @click="messages.dismiss(item.id)"
        >
          <span>{{ item.text }}</span>
          <span class="toast-go" aria-hidden="true">›</span>
        </RouterLink>
        <span v-else>{{ item.text }}</span>
        <button
          type="button"
          class="dismiss"
          :aria-label="t('common.message_toaster.dismiss')"
          @click="messages.dismiss(item.id)"
        >
          ✕
        </button>
      </div>
    </TransitionGroup>
    <TransitionGroup name="toast">
      <div
        v-for="item in anchoredToasts"
        :key="item.id"
        class="toast"
        :class="[`toast--${item.kind}`, `toast--place-${item.place}`]"
        :style="item.style"
        @mouseenter="hold(item.id, 'hover')"
        @mouseleave="release(item.id, 'hover')"
        @focusin="hold(item.id, 'focus')"
        @focusout="onFocusOut(item.id, $event)"
      >
        <!-- A notification toast is a link to what it's about; tapping it opens that and closes the toast. -->
        <RouterLink
          v-if="item.to"
          :to="item.to"
          class="toast-link"
          @click="messages.dismiss(item.id)"
        >
          <span>{{ item.text }}</span>
          <span class="toast-go" aria-hidden="true">›</span>
        </RouterLink>
        <span v-else>{{ item.text }}</span>
        <button
          type="button"
          class="dismiss"
          :aria-label="t('common.message_toaster.dismiss')"
          @click="messages.dismiss(item.id)"
        >
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

  > span,
  .toast-link > span:first-child {
    flex: 1;
  }
}

// Page-level: a centred column from the top of the page, each toast as tall as its text.
.toaster-page {
  position: fixed;
  z-index: 100;
  top: 16px;
  left: 50%;
  transform: translateX(-50%);
  // Sized by its widest toast (each capped by .toast's max-width), not by the
  // half viewport `left: 50%` would otherwise leave it.
  width: max-content;
  max-width: calc(100vw - 2rem);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  pointer-events: none;
}

.toast--place-page {
  position: relative;
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

.toast-link {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: inherit;
  text-decoration: none;

  &:hover span:first-child,
  &:focus-visible span:first-child {
    text-decoration: underline;
  }
}

.toast-go {
  flex: none;
  font-size: 1.2rem;
  line-height: 1;
  opacity: 0.7;
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

// The rest of the column slides into a closed toast's place instead of jumping.
.toast-move {
  transition: transform 0.18s ease;
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
  transform: translateY(-6px);
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
