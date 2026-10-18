<script setup lang="ts">
import { computed, nextTick, ref, useId } from 'vue'
import ModalDialog from './ModalDialog.vue'
import { t } from '@/i18n'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { reportApiError } from '@/utils/reportApiError'

/**
 * "Report" on a public profile: a quiet link that opens a form — a short
 * title and what happened — and files it with the moderators
 * (`api.reportUser()` → `Complaints.php`). Like `ReviewsSection`, it calls
 * the API itself so both profile pages can drop it in. Shown only to a
 * signed-in user looking at someone else; the person reported isn't told.
 */
const props = defineProps<{ subjectId: number; subjectName: string }>()

const api = useApi()
const auth = useAuthStore()
const messages = useMessagesStore()

/** Must match `Complaints::MAX_TITLE` / `MAX_DESCRIPTION`. */
const MAX_TITLE = 120
const MAX_DESCRIPTION = 2000

const open = ref(false)
const title = ref('')
const description = ref('')
const sending = ref(false)
const triggerRef = ref<HTMLButtonElement | null>(null)

const visible = computed(() => auth.isAuthenticated && auth.user?.id !== props.subjectId)
const titleLength = computed(() => Array.from(title.value).length)
const descriptionLength = computed(() => Array.from(description.value).length)
const valid = computed(
  () =>
    title.value.trim() !== '' &&
    description.value.trim() !== '' &&
    titleLength.value <= MAX_TITLE &&
    descriptionLength.value <= MAX_DESCRIPTION,
)

const uid = useId()

function close() {
  open.value = false
  // After the dialog has closed — focus can't leave an open modal.
  void nextTick(() => triggerRef.value?.focus())
}

async function submit() {
  if (!valid.value || sending.value) return
  sending.value = true
  try {
    await api.reportUser({
      subjectId: props.subjectId,
      title: title.value.trim(),
      description: description.value.trim(),
    })
    title.value = ''
    description.value = ''
    close()
    messages.success(t('common.report_user.sent'), anchorTo('element', triggerRef.value))
  } catch (err) {
    reportApiError(err, t('common.report_user.failed'))
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div v-if="visible" class="report-user">
    <button
      ref="triggerRef"
      type="button"
      class="report-link"
      data-test="report-user"
      @click="open = true"
    >
      <span aria-hidden="true">⚑</span> {{ t('common.report_user.open', { name: subjectName }) }}
    </button>

    <ModalDialog
      :open="open"
      :title="t('common.report_user.title', { name: subjectName })"
      :close-label="t('common.report_user.cancel')"
      @close="close"
    >
      <p class="muted tiny">{{ t('common.report_user.intro') }}</p>
      <form @submit.prevent="submit">
        <label>
          {{ t('common.report_user.title_label') }}
          <input
            v-model="title"
            type="text"
            required
            :placeholder="t('common.report_user.title_placeholder')"
            :aria-invalid="titleLength > MAX_TITLE || undefined"
            :aria-describedby="`${uid}-title-count`"
            data-test="report-title"
          />
          <small
            :id="`${uid}-title-count`"
            class="muted"
            :class="{ over: titleLength > MAX_TITLE }"
            :aria-live="titleLength > MAX_TITLE ? 'polite' : undefined"
            >{{ t('common.report_user.count', { count: titleLength, max: MAX_TITLE }) }}</small
          >
        </label>
        <label>
          {{ t('common.report_user.description_label') }}
          <textarea
            v-model="description"
            rows="5"
            required
            :placeholder="t('common.report_user.description_placeholder')"
            :aria-invalid="descriptionLength > MAX_DESCRIPTION || undefined"
            :aria-describedby="`${uid}-description-count`"
            data-test="report-description"
          />
          <small
            :id="`${uid}-description-count`"
            class="muted"
            :class="{ over: descriptionLength > MAX_DESCRIPTION }"
            :aria-live="descriptionLength > MAX_DESCRIPTION ? 'polite' : undefined"
            >{{
              t('common.report_user.count', { count: descriptionLength, max: MAX_DESCRIPTION })
            }}</small
          >
        </label>
        <p class="muted tiny">{{ t('common.report_user.emergency') }}</p>
        <div class="report-actions">
          <button type="button" class="soft-btn" @click="close">
            {{ t('common.report_user.cancel') }}
          </button>
          <button
            type="submit"
            :aria-busy="sending"
            :disabled="!valid || sending"
            data-test="report-send"
          >
            {{ t('common.report_user.send') }}
          </button>
        </div>
      </form>
    </ModalDialog>
  </div>
</template>

<style scoped lang="scss">
// Flex, not text-align: the dialog lives inside and would inherit it.
.report-user {
  margin-top: 1rem;
  display: flex;
  justify-content: flex-end;
}

.report-link {
  width: auto;
  margin: 0;
  padding: 0.2rem 0.4rem;
  border: none;
  background: none;
  color: var(--rg-muted);
  font-size: 0.85rem;
  text-decoration: underline;

  &:hover,
  &:focus-visible {
    color: var(--rg-red);
    background: none;
  }
}

.over {
  color: var(--rg-red);
}

.report-actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 0.5rem;

  button {
    width: auto;
    margin: 0;
  }
}
</style>
