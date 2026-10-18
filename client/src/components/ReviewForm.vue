<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { t, tn } from '@/i18n'
import { useApi, type Review, type Role } from '@/plugins/api'
import { useMessagesStore } from '@/stores/messages'
import { characterCount, REVIEW_COMMENT_MAX } from '@/utils/reviews'
import { reportApiError } from '@/utils/reportApiError'

/**
 * "Write a review" / "Edit your review", shown on a public profile only when
 * `api.reviews()` says the viewer may (a finished trip together — see
 * `Reviews::eligibleBookingId()`). The server re-checks every rule; the
 * client-side checks are only there to explain problems before a round trip.
 *
 * The star picker is five native radios in a fieldset, so it gets radio-group
 * semantics and arrow-key navigation from the browser; the stars are just
 * their labels.
 */
const props = defineProps<{
  subjectId: number
  subjectName: string
  /** The side being reviewed — the subject's role on the finished trip. */
  role: Role
  existing: Review | null
}>()

const emit = defineEmits<{
  saved: [review: Review]
  deleted: []
}>()

const api = useApi()
const messages = useMessagesStore()
const uid = useId()

const rating = ref<number>(props.existing?.rating ?? 0)
const comment = ref(props.existing?.comment ?? '')
const hover = ref(0)
const saving = ref(false)
const confirmingDelete = ref(false)

// A save or delete hands the parent a new `existing`; start from it.
watch(
  () => props.existing,
  (next) => {
    rating.value = next?.rating ?? 0
    comment.value = next?.comment ?? ''
    confirmingDelete.value = false
  },
)

const length = computed(() => characterCount(comment.value.trim()))
const tooLong = computed(() => length.value > REVIEW_COMMENT_MAX)
const canSubmit = computed(
  () => rating.value >= 1 && rating.value <= 5 && !tooLong.value && !saving.value,
)
const shown = computed(() => hover.value || rating.value)

/** The word for a grade, 1–5. */
const starWord = (grade: number) => t(`common.review_form.grade_${grade}`)

async function submit() {
  if (!canSubmit.value) return
  saving.value = true
  try {
    const saved = await api.submitReview({
      subjectId: props.subjectId,
      role: props.role,
      rating: rating.value,
      comment: comment.value.trim(),
    })
    messages.success(
      props.existing ? t('common.review_form.updated') : t('common.review_form.posted'),
    )
    emit('saved', saved)
  } catch (err) {
    reportApiError(err, t('common.review_form.save_failed'))
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!props.existing) return
  saving.value = true
  try {
    await api.deleteReview({ reviewId: props.existing.id })
    messages.success(t('common.review_form.deleted'))
    emit('deleted')
  } catch (err) {
    reportApiError(err, t('common.review_form.delete_failed'))
  } finally {
    saving.value = false
    confirmingDelete.value = false
  }
}
</script>

<template>
  <form class="review-form" :aria-labelledby="`${uid}-title`" @submit.prevent="submit">
    <h3 :id="`${uid}-title`" class="form-title">
      {{
        existing
          ? t('common.review_form.edit_title')
          : t('common.review_form.write_title', { name: subjectName })
      }}
    </h3>

    <fieldset class="stars-field">
      <legend class="tiny">{{ t('common.review_form.rating') }}</legend>
      <div class="star-picker" @mouseleave="hover = 0">
        <template v-for="value in 5" :key="value">
          <input
            :id="`${uid}-star-${value}`"
            v-model="rating"
            class="visually-hidden star-input"
            type="radio"
            :name="`${uid}-rating`"
            :value="value"
            required
          />
          <label
            :for="`${uid}-star-${value}`"
            class="star"
            :class="{ 'star--on': value <= shown }"
            @mouseenter="hover = value"
          >
            <span aria-hidden="true">★</span>
            <span class="visually-hidden">{{
              tn('common.review_form.star_option', value, { word: starWord(value) })
            }}</span>
          </label>
        </template>
        <span class="tiny muted star-word" aria-hidden="true">{{
          shown ? starWord(shown) : ''
        }}</span>
      </div>
    </fieldset>

    <label :for="`${uid}-comment`" class="tiny"
      >{{ t('common.review_form.comment') }}
      <span class="muted">{{ t('common.review_form.optional') }}</span></label
    >
    <textarea
      :id="`${uid}-comment`"
      v-model="comment"
      rows="3"
      :aria-invalid="tooLong || undefined"
      :aria-describedby="`${uid}-count`"
      :placeholder="t('common.review_form.placeholder')"
    />
    <p
      :id="`${uid}-count`"
      class="tiny counter"
      :class="{ 'counter--over': tooLong }"
      aria-live="polite"
    >
      {{
        tooLong
          ? t('common.review_form.count_too_long', { count: length, max: REVIEW_COMMENT_MAX })
          : t('common.review_form.count', { count: length, max: REVIEW_COMMENT_MAX })
      }}
    </p>

    <div class="actions">
      <button type="submit" :disabled="!canSubmit" :aria-busy="saving || undefined">
        {{ existing ? t('common.review_form.save') : t('common.review_form.post') }}
      </button>

      <template v-if="existing">
        <button
          v-if="!confirmingDelete"
          type="button"
          class="soft-btn"
          :disabled="saving"
          @click="confirmingDelete = true"
        >
          {{ t('common.review_form.delete') }}
        </button>
        <template v-else>
          <span class="tiny">{{ t('common.review_form.delete_confirm') }}</span>
          <button type="button" class="danger" :disabled="saving" @click="remove">
            {{ t('common.review_form.delete_yes') }}
          </button>
          <button
            type="button"
            class="soft-btn"
            :disabled="saving"
            @click="confirmingDelete = false"
          >
            {{ t('common.review_form.cancel') }}
          </button>
        </template>
      </template>
    </div>
  </form>
</template>

<style scoped lang="scss">
.review-form {
  margin-top: 1rem;
  padding: 1rem;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-soft);
  background: var(--rg-surface);
}

.form-title {
  font-size: 1rem;
  margin-bottom: 0.5rem;
}

.stars-field {
  margin-bottom: 0.75rem;
}

.star-picker {
  display: flex;
  align-items: center;
  gap: 0.1rem;
}

.star {
  font-size: 1.75rem;
  line-height: 1;
  padding: 0.1rem;
  margin: 0;
  cursor: pointer;
  color: var(--rg-line);
  border-radius: 0.35rem;
}

.star--on {
  color: var(--rg-orange-500);
}

.star-input:focus-visible + .star {
  outline: 2px solid var(--rg-orange-500);
  outline-offset: 1px;
}

.star-word {
  margin-left: 0.5rem;
}

textarea {
  margin-bottom: 0.25rem;
}

.counter {
  text-align: right;
  margin-bottom: 0.75rem;
}

.counter--over {
  color: var(--rg-red);
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;

  button {
    width: auto;
    margin: 0;
  }
}

.danger {
  background: var(--rg-red);
  border-color: var(--rg-red);
}
</style>
