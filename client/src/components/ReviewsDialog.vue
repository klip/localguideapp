<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import ReviewItem from './ReviewItem.vue'
import { REVIEWS_PAGE_SIZE, useApi, type Review } from '@/plugins/api'
import { reportApiError } from '@/utils/reportApiError'

/**
 * "Show all" — every review of one person in a native modal `<dialog>`
 * (`showModal()`: the browser supplies the focus trap, the inert background
 * and Escape-to-close), fetched `REVIEWS_PAGE_SIZE` at a time with "Load
 * more" asking for the next page by offset.
 *
 * Opened and closed through `open`; every way of closing it (Escape, the ✕,
 * a click on the backdrop) ends in the native `close` event, which emits
 * `close` so the parent can drop `open` and put focus back on its button.
 */
const props = defineProps<{
  userId: number
  subjectName: string
  open: boolean
}>()

const emit = defineEmits<{ close: [] }>()

const api = useApi()
const uid = useId()

const dialog = ref<HTMLDialogElement | null>(null)
const closeButton = ref<HTMLButtonElement | null>(null)
const reviews = ref<Review[]>([])
const total = ref(0)
const loading = ref(false)
const loadedOnce = ref(false)

const hasMore = computed(() => reviews.value.length < total.value)

async function loadPage() {
  if (loading.value) return
  loading.value = true
  try {
    const page = await api.reviews({
      userId: props.userId,
      limit: REVIEWS_PAGE_SIZE,
      offset: reviews.value.length,
    })
    // Skip anything already shown, in case a review was posted between pages
    // and shifted the offsets by one.
    const seen = new Set(reviews.value.map((review) => review.id))
    reviews.value.push(...page.reviews.filter((review) => !seen.has(review.id)))
    total.value = page.total
    loadedOnce.value = true
  } catch (err) {
    reportApiError(err, 'Could not load reviews.')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.open,
  async (open) => {
    const el = dialog.value
    if (!el) return

    if (open) {
      reviews.value = []
      total.value = 0
      loadedOnce.value = false
      if (!el.open) el.showModal()
      await nextTick()
      closeButton.value?.focus()
      await loadPage()
    } else if (el.open) {
      el.close()
    }
  },
)

function close() {
  dialog.value?.close()
}

/** A click that lands on the `<dialog>` itself, not its content, is a click on the backdrop. */
function onDialogClick(event: MouseEvent) {
  if (event.target === dialog.value) close()
}
</script>

<template>
  <dialog
    ref="dialog"
    class="reviews-dialog"
    :aria-labelledby="`${uid}-title`"
    @close="emit('close')"
    @click="onDialogClick"
  >
    <!-- Pico styles `dialog` itself as the full-screen overlay and `dialog >
         article` as the panel, with `rel="prev"` in its header as the ✕. -->
    <article class="dialog-body">
      <header>
        <button
          ref="closeButton"
          type="button"
          rel="prev"
          aria-label="Close reviews"
          @click="close"
        />
        <h2 :id="`${uid}-title`" class="dialog-title">Reviews of {{ subjectName }}</h2>
      </header>

      <p v-if="loadedOnce" class="tiny muted" aria-live="polite">
        Showing {{ reviews.length }} of {{ total }}
      </p>

      <ReviewItem v-for="review in reviews" :key="review.id" :review="review" />

      <p v-if="loading" class="tiny muted" aria-live="polite">Loading reviews…</p>
      <p v-else-if="loadedOnce && total === 0" class="muted">No reviews yet.</p>

      <button v-if="hasMore && !loading" type="button" class="soft-btn more" @click="loadPage">
        Load more
      </button>
    </article>
  </dialog>
</template>

<style scoped lang="scss">
.dialog-body {
  border-radius: var(--rg-radius-panel);
  background: var(--rg-surface);
  box-shadow: var(--rg-shadow);
}

.dialog-title {
  font-size: 1.2rem;
}

.more {
  width: 100%;
  margin-top: 0.75rem;
}
</style>
